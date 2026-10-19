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
    require_once dirname(__DIR__) . '/lib/LocalHooks.php';
    require_once dirname(__DIR__) . '/lib/HookNotes.php';
    require_once dirname(__DIR__) . '/lib/HookAnswers.php';
    require_once dirname(__DIR__) . '/lib/AskPolicy.php';
} catch (\Throwable $e) {
    $hookError = $e->getMessage();
    exit(1);
}

const HOOK_OUTPUT_MAX = 1048576;          // a unit printing more than this is not giving a hook answer
const HOOK_LOG_DAYS = 7;

/** Run one unit: ['code' => int|null, 'stdout', 'stderr', 'timedOut' => bool, 'error' => string]. */
function hookSpawn($script, $stdin, $timeout)
{
    $r = ['code' => null, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'error' => ''];
    // exec: the shell becomes php, so a timeout kills the hook itself.
    $p = @proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        $r['error'] = 'could not start';
        return $r;
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $deadline = microtime(true) + $timeout;
    $written = 0;
    $open = [0 => true, 1 => true, 2 => true];
    if ($stdin === '') {
        fclose($pipes[0]);
        $open[0] = false;
    }
    while ($open[1] || $open[2]) { // the hook closed both outputs: done, whatever it left unread
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            $r['timedOut'] = true;
            break;
        }
        $read = [];
        foreach ([1, 2] as $i) {
            if ($open[$i]) {
                $read[] = $pipes[$i];
            }
        }
        $write = $open[0] ? [$pipes[0]] : [];
        $except = null;
        if (@stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1000000)) === false) {
            usleep(10000); // interrupted by a signal; the deadline still holds
            continue;
        }
        if ($write) {
            $n = @fwrite($pipes[0], substr($stdin, $written, 65536));
            $written += $n ? $n : 0;
            if (!$n || $written >= strlen($stdin)) {
                fclose($pipes[0]); // done, or the hook stopped reading (writable but nothing written: a broken pipe)
                $open[0] = false;
            }
        }
        foreach ($read as $pipe) {
            $i = $pipe === $pipes[1] ? 1 : 2;
            $chunk = fread($pipe, 65536);
            if ($chunk === false || ($chunk === '' && feof($pipe))) {
                fclose($pipe);
                $open[$i] = false;
                continue;
            }
            $key = $i === 1 ? 'stdout' : 'stderr';
            if (strlen($r[$key]) < HOOK_OUTPUT_MAX) {
                $r[$key] .= $chunk;
            }
        }
    }
    // Pipes closed: wait for the exit, still within the deadline.
    while (!$r['timedOut']) {
        $status = proc_get_status($p);
        if (!$status['running']) {
            $r['code'] = $status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $r['timedOut'] = true;
            break;
        }
        usleep(5000);
    }
    if ($r['timedOut']) {
        proc_terminate($p, 9);
    }
    foreach ($open as $i => $isOpen) {
        if ($isOpen) {
            fclose($pipes[$i]);
        }
    }
    proc_close($p);
    if (strlen($r['stdout']) >= HOOK_OUTPUT_MAX) {
        $r['stdout'] = 'output too large'; // not a JSON object, so a failClosed unit blocks
    }
    return $r;
}

/** Append one JSONL line; a new day's file prunes all but the newest HOOK_LOG_DAYS files. Never fails the hook. */
function hookLog($event, $unit, $ms, $outcome)
{
    $dir = Config::localDir() . '/logs/hooks';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return;
    }
    $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
    $new = !is_file($file);
    @file_put_contents($file, json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event, 'unit' => $unit, 'ms' => $ms, 'outcome' => $outcome], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if ($new) {
        $files = (array) glob($dir . '/[0-9]*.jsonl');
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - HOOK_LOG_DAYS)) as $old) {
            @unlink($old);
        }
    }
}

$stdin = (string) stream_get_contents(STDIN);
$payload = json_decode($stdin, true, 100000);
$payload = is_array($payload) ? $payload : [];
list(, , $callTexts) = HookPayload::read($payload);
$session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
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
        hookLog($hookEvent, $id, 0, $closed ? 'failed-closed' : 'invalid');
        continue;
    }
    if (!HookPayload::selects($u, $payload, $callTexts)) {
        continue; // a tool hook for another tool
    }
    if ($isLocal && $start - $started + $u['timeout'] + 2 + $reserve > $budget) {
        // Started now it could outlast the Hermes entry, whose timeout blocks every tool call (fail_closed).
        hookLog($hookEvent, $id, 0, 'not-started');
        $notes[] = [$id, "was not started: its {$u['timeout']}s timeout does not fit in what is left of the {$budget}s hook entry; run pikkumies generate", false];
        continue;
    }
    if ($isLocal) {
        $u['failClosed'] = false; // a local unit never blocks by failing (ADR-0054)
    }
    $run = hookSpawn($u['script'], $stdin, $u['timeout']);
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
    hookLog($hookEvent, $id, (int) round((microtime(true) - $start) * 1000), $outcome);
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
    $answer = HookAnswers::mergeContext(array_merge($results, $said !== '' ? [['context' => $said]] : [], $localResults));
} else {
    foreach ($notes as $n) {
        if (!HookNotes::add(Config::localDir(), $session, $n[0], $n[1], $n[2])) {
            hookLog($hookEvent, $n[0], 0, 'note-dropped'); // the session's notes are full
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
            hookLog($hookEvent, 'ask-policy', (int) round((microtime(true) - $askStart) * 1000), json_encode($asked));
        }
    }
    $answer = $hookEvent === 'pre_tool_call' ? HookAnswers::mergePreToolCall($all) : ($hookEvent === 'pre_verify' ? HookAnswers::mergePreVerify($all) : null);
    if ($hookEvent === 'pre_tool_call' && is_array($answer) && $answer['action'] === 'approve') {
        $answer = ['action' => 'block', 'message' => AskPolicy::BLOCK_UNCHECKED]; // never reached: AskPolicy leaves no approve
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
