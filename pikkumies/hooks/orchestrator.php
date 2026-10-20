<?php
/**
 * Hook orchestrator (ADR-0030, ADR-0054): `php orchestrator.php <event> [<entry timeout>]`. Hermes runs this one
 * shell hook per event; it runs every hook unit of that event (hooks/<category>/<hook>/, lib/HookUnits.php) in name
 * order, then the user's local units (local/hooks/, lib/LocalHooks.php), each as `php <hook>.php` with the same
 * stdin JSON and its own timeout, and prints one answer. A tool hook (hooks/tools/<tool>/<hook>/) runs only for
 * calls of its tool (lib/HookPayload.php selects). A local unit runs only as recorded at the last start, is started
 * only while the entry timeout leaves room for it, always fails open, and its failure is named to the agent; a tool or
 * local unit's modify is ignored. Reading local hooks is wrapped: anything thrown there turns them off for this call.
 *
 * Each unit's output is read, and the answers merged, by lib/HookAnswers.php, the way Hermes does (agent/shell_hooks.py,
 * Hermes v2026.9.24: _evaluate_result, _parse_response):
 * - pre_tool_call (hermes_cli/plugins.py _get_pre_tool_call_directive_details): a block with a
 *   message wins over any approve whatever the order; modify args are shallow-merged in order; anything else
 *   is no opinion. One approve is passed on as it is. Two or more become one approve holding every question
 *   unaltered, keyed pikkumies-multi:<sha1 of the sorted keys>, or a block naming the units when together they are too
 *   long to show (HookUnits::APPROVE_SHOWN): Hermes would keep only the first, and drop the delete guard's question.
 *   Before the merge lib/AskPolicy.php drops every question or turns it into a block, so no approve is ever printed:
 *   Hermes runs with approvals.mode off, which approves an approve answer without asking anyone (ADR-0073).
 * - pre_llm_call (agent/turn_context.py _collect_pre_llm_call_context): contexts joined by a blank
 *   line, capped at 9000 characters (Hermes spills a hook's context to disk above 10000): shipped units first,
 *   then Pikkumies' notes and the hook notes (lib/HookNotes.php, untrusted), local units last, each labelled with its
 *   id, so they are cut first.
 * - post_tool_call: Hermes ignores the answer; a unit's context becomes a hook note for the next turn.
 * - pre_verify (agent/turn_stop_gates.py _pre_verify_nudge, hermes_cli/plugins.py
 *   get_pre_verify_continue_message): the first unit answering {"action":"continue","message":"..."}
 *   wins; the message is what Hermes appends as the next-turn nudge (ADR-0047).
 * A failClosed unit that crashes, times out, exits non-zero without an answer or prints something that
 * is not a JSON object makes the answer a block. A fail-open unit's failure is ignored. On pre_tool_call
 * a broken unit (bad or missing hook.md) blocks whatever it declares, and so does a fatal error of the
 * orchestrator itself, also while loading lib/ (Hermes runs it with display_errors=stdout, HermesConfig).
 * One JSONL line per unit run goes to local/logs/hooks/YYYYMMDD.jsonl (UTC; 7 daily files kept):
 * time, event, unit, milliseconds and outcome, never the payload.
 * Interval units (event transcript, ADR-0067) are not run here: lib/HookFeed.php records each turn for them, adds what
 * they left after the other contexts, holds one tool call for a notice when no unit had an opinion, and starts the due
 * ones in the background (hooks/worker.php) after the answer is printed.
 */
$hookEvent = isset($argv[1]) ? (string) $argv[1] : '';
$hookDone = false;
$hookError = '';
// The orchestrator itself must not fail open on pre_tool_call: a fatal error prints a block. Registered
// before anything is loaded, so a broken or missing lib file blocks too.
register_shutdown_function(function () use (&$hookDone, &$hookError, $hookEvent) {
    if (!$hookDone && $hookEvent === 'pre_tool_call') {
        $e = error_get_last();
        $why = $hookError !== '' ? $hookError : ($e ? $e['message'] : '');
        echo "\n" . json_encode(['action' => 'block', 'message' => 'Pikkumies hook orchestrator failed' . ($why !== '' ? ': ' . substr($why, 0, 200) : '') . '. Not run; tell the user this safety check is failing.']) . "\n";
    }
});
try {
    require_once dirname(__DIR__) . '/lib/Config.php';
    require_once dirname(__DIR__) . '/lib/HookUnits.php';
    require_once dirname(__DIR__) . '/lib/HookPayload.php';
    require_once dirname(__DIR__) . '/lib/NestedAgent.php';
    require_once dirname(__DIR__) . '/lib/Units.php';
    require_once dirname(__DIR__) . '/lib/Launch.php';
    require_once dirname(__DIR__) . '/lib/LocalHooks.php';
    require_once dirname(__DIR__) . '/lib/HookNotes.php';
    require_once dirname(__DIR__) . '/lib/HookAnswers.php';
    require_once dirname(__DIR__) . '/lib/AskPolicy.php';
    require_once dirname(__DIR__) . '/lib/HookSpawn.php';
    require_once dirname(__DIR__) . '/lib/HookLog.php';
    require_once dirname(__DIR__) . '/lib/HookFeed.php';
} catch (\Throwable $e) {
    $hookError = $e->getMessage();
    exit(1);
}

$stdin = (string) stream_get_contents(STDIN);
$payload = json_decode($stdin, true, 100000);
$payload = is_array($payload) ? $payload : [];
if ($hookEvent === 'pre_tool_call' && $payload) {
    // `pikkumies <tool> ...` runs the tool inside an agent (ADR-0103): every unit sees it as `php <script> ...`, the form
    // the guards check. A command the rewrite cannot read is blocked here, never passed on unrewritten.
    $normalized = HookPayload::normalize($payload);
    $encoded = $normalized === null ? false : json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        $hookDone = true;
        HookLog::add($hookEvent, 'normalize', 0, 'blocked');
        echo json_encode(['action' => 'block', 'message' => 'Pikkumies could not read this command to check it (too long or complex). Not run; split it into simpler commands.']) . "\n";
        exit(0);
    }
    if ($normalized !== $payload) {
        $payload = $normalized;
        $stdin = $encoded;
        HookLog::add($hookEvent, 'normalize', 0, 'rewritten');
    }
}
list(, , $callTexts) = HookPayload::read($payload);
$session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
$feed = HookFeed::start($hookEvent, $payload); // null unless an interval unit is on
$units = HookUnits::forEvent($hookEvent);
try {
    $local = LocalHooks::scan(dirname(__DIR__));
} catch (\Throwable $e) {
    // The user's files must never stop a shipped check: anything thrown while reading them turns local hooks off.
    $local = ['units' => [], 'pending' => [], 'problems' => [], 'off' => 'they could not be read (' . substr($e->getMessage(), 0, 120) . ')'];
}
// The entry's own timeout comes from the command (HermesConfig::hookEntries); without it, the shipped units' sum.
// On pre_tool_call the ask review's time is part of it and kept free of local units (ADR-0073).
$reserve = $hookEvent === 'pre_tool_call' ? AskReview::TIMEOUT : 0;
$budget = 2 + $reserve;
foreach ($units as $u) {
    $budget += $u['timeout'];
}
$budget = min(300, isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : $budget);
$started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
$results = [];
$localResults = [];
$notes = []; // [unit id, text, written by the hook?]: post_tool_call contexts, and local units that failed or were not started
foreach (array_merge($units, LocalHooks::forEvent($hookEvent, $local)) as $u) {
    $isLocal = !empty($u['local']);
    $id = $isLocal ? $u['id'] : "{$u['category']}/{$u['name']}";
    $start = microtime(true);
    if ($u['errors']) {
        // A broken unit blocks a tool call (its hook.md cannot be trusted to say whether it fails closed); elsewhere it is skipped.
        $closed = $hookEvent === 'pre_tool_call';
        $results[] = $closed
            ? ['action' => 'block', 'message' => "Pikkumies hook $id failed closed: " . implode('; ', $u['errors']) . '. Not run; tell the user this safety check is failing.']
            : null;
        HookLog::add($hookEvent, $id, 0, $closed ? 'failed-closed' : 'invalid');
        continue;
    }
    if (!HookPayload::selects($u, $payload, $callTexts)) {
        continue; // a tool hook for another tool
    }
    if ($isLocal && $start - $started + $u['timeout'] + 2 + $reserve > $budget) {
        // Started now it could outlast the Hermes entry, whose timeout blocks every tool call (fail_closed).
        HookLog::add($hookEvent, $id, 0, 'not-started');
        $notes[] = [$id, "was not started: its {$u['timeout']}s timeout does not fit in what is left of the {$budget}s hook entry; run pikkumies generate", false];
        continue;
    }
    if ($isLocal) {
        $u['failClosed'] = false; // a local unit never blocks by failing (ADR-0054)
    }
    $run = HookSpawn::run($u['script'], $stdin, $u['timeout']);
    list($directive, $outcome) = HookAnswers::evaluate($hookEvent, $u, $run);
    if (is_array($directive) && isset($directive['action']) && $directive['action'] === 'modify' && ($isLocal || $u['tool'] !== '')) {
        list($directive, $outcome) = [null, 'modify-ignored']; // only shipped hooks outside hooks/tools/ may change a call
    }
    if ($isLocal && $directive === null && ($run['timedOut'] || $run['error'] !== '' || $run['code'] !== 0)) {
        $notes[] = [$id, "failed ($outcome) and was skipped; it is in " . Config::shown($u['dir']), false];
    }
    if ($hookEvent === 'post_tool_call' && is_array($directive) && isset($directive['context'])) {
        $notes[] = [$id, $directive['context'], true];
    }
    if ($hookEvent === 'pre_tool_call' && is_array($directive) && $directive['action'] === 'approve') {
        $directive['unit'] = $id; // named when merged questions are too long to show (HookAnswers::mergeApproves)
        $directive['local'] = $isLocal; // the person's own question: the ask policy keeps it (ADR-0073)
    }
    if ($isLocal && $hookEvent === 'pre_llm_call' && is_array($directive) && isset($directive['context'])) {
        $directive['context'] = "Local hook $id (written on this machine): " . $directive['context'];
    }
    if ($isLocal) {
        $localResults[] = $directive;
    } else {
        $results[] = $directive;
    }
    HookLog::add($hookEvent, $id, (int) round((microtime(true) - $start) * 1000), $outcome);
}
if ($hookEvent === 'pre_llm_call') {
    // Shipped context first, then what Pikkumies and hooks noted since the last turn, then local context: the cap cuts from the end.
    $said = [];
    if ($local['off'] !== '') {
        $said[] = 'Pikkumies: local hooks are off because ' . $local['off'] . '.';
    }
    foreach ($local['problems'] as $id => $why) {
        $said[] = "Pikkumies: skipped the local hook $id: $why.";
    }
    foreach ($local['pending'] as $id => $u) {
        $said[] = "Pikkumies: the local hook $id is new or changed since the last start; it runs from the next start, after the user is told.";
    }
    foreach ($notes as $n) {
        $said[] = "Pikkumies: the local hook {$n[0]} {$n[1]}.";
    }
    $said[] = HookNotes::drain(Config::localDir(), $session);
    $said = implode("\n", array_filter($said));
    $async = HookFeed::contexts($feed, $payload); // after Pikkumies' own notes, before local units: the cap cuts it before those notes
    $answer = HookAnswers::mergeContext(array_merge($results, $said !== '' ? [['context' => $said]] : [], $async !== '' ? [['context' => $async]] : [], $localResults));
} else {
    foreach ($notes as $n) {
        if (!HookNotes::add(Config::localDir(), $session, $n[0], $n[1], $n[2])) {
            HookLog::add($hookEvent, $n[0], 0, 'note-dropped'); // the session's notes are full
        }
    }
    $all = array_merge($results, $localResults);
    if ($hookEvent === 'pre_tool_call') {
        // Every question is dropped or turned into a block (ADR-0073): Hermes runs with approvals off, which would approve
        // an approve answer silently. Counts only, never the payload, go to the log.
        $askStart = microtime(true);
        list($all, $asked) = AskPolicy::apply($all, isset($payload['tool_name']) ? (string) $payload['tool_name'] : '',
            AskPolicy::evidenceFor($session), (int) floor($budget - ($askStart - $started) - 1));
        if (array_sum($asked) > 0) {
            HookLog::add($hookEvent, 'ask-policy', (int) round((microtime(true) - $askStart) * 1000), json_encode($asked));
        }
    }
    $answer = $hookEvent === 'pre_tool_call' ? HookAnswers::mergePreToolCall($all) : ($hookEvent === 'pre_verify' ? HookAnswers::mergePreVerify($all) : null);
    if ($hookEvent === 'pre_tool_call' && is_array($answer) && $answer['action'] === 'approve') {
        $answer = ['action' => 'block', 'message' => AskPolicy::refused(true)]; // never reached: AskPolicy leaves no approve
    }
    if ($hookEvent === 'pre_tool_call' && $answer === null) {
        $answer = HookFeed::hold($feed, $payload); // an interval unit's notice, only when no unit had an opinion
    }
}
if ($answer !== null) {
    $json = json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false && $answer['action'] === 'block') {
        $json = json_encode(['action' => 'block', 'message' => HookAnswers::DEFAULT_BLOCK]); // a block must not turn into no answer
    }
    echo ($json === false ? '' : $json . "\n");
}
$hookDone = true;
HookFeed::kick($feed); // every lock is released; the worker is detached
