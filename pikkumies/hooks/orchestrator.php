<?php
/**
 * Hook orchestrator (ADR-0030): `php orchestrator.php <event>`. Hermes runs this one shell hook per
 * event; it runs every hook unit of that event (hooks/<category>/<hook>/, lib/HookUnits.php) in name
 * order, each as `php <hook>.php` with the same stdin JSON and its own timeout, and prints one answer.
 *
 * Each unit's output is read the way Hermes reads a shell hook (agent/shell_hooks.py, Hermes
 * v2026.9.24: _evaluate_result, _parse_response). The answers are merged the way Hermes merges hooks:
 * - pre_tool_call (hermes_cli/plugins.py _get_pre_tool_call_directive_details): a block with a
 *   message wins over any approve whatever the order; else the first approve; modify args are
 *   shallow-merged in order; anything else is no opinion.
 * - pre_llm_call (agent/turn_context.py _collect_pre_llm_call_context): contexts joined by a blank
 *   line, capped at 9000 characters (Hermes spills a hook's context to disk above 10000).
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
} catch (\Throwable $e) {
    $hookError = $e->getMessage();
    exit(1);
}

const HOOK_BLOCK_EXIT = 2;                 // agent/shell_hooks.py BLOCK_EXIT_CODE
const HOOK_DEFAULT_BLOCK = 'Blocked by shell hook.';
const HOOK_STDERR_LIMIT = 400;
const HOOK_CONTEXT_MAX = 9000;
const HOOK_OUTPUT_MAX = 1048576;          // a unit printing more than this is not giving a hook answer
const HOOK_LOG_DAYS = 7;

/** Python truthiness of a decoded JSON value (json_decode without assoc: objects are stdClass). */
function hookTruthy($v)
{
    return is_object($v) ? (bool) get_object_vars($v) : (bool) $v;
}

/** agent/shell_hooks.py _parse_response: stdout -> directive array or null. */
function hookParse($event, $stdout)
{
    $stdout = trim((string) $stdout);
    if ($stdout === '') {
        return null;
    }
    $data = json_decode($stdout);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
        return null;
    }
    $get = function ($k) use ($data) {
        return property_exists($data, $k) ? $data->$k : null;
    };
    if ($event === 'pre_verify') {
        // agent/shell_hooks.py _parse_pre_verify: "continue" (Hermes) or "block" (Claude-Code Stop)
        // both mean keep going; no message is a no-op.
        $action = hookTruthy($get('action')) ? $get('action') : $get('decision');
        $message = hookTruthy($get('message')) ? $get('message') : $get('reason');
        $action = strtolower(trim((string) $action));
        return in_array($action, ['continue', 'block'], true) && is_string($message) && trim($message) !== ''
            ? ['action' => 'continue', 'message' => trim($message)] : null;
    }
    if ($event !== 'pre_tool_call') {
        $context = $get('context');
        return is_string($context) && trim($context) !== '' ? ['context' => $context] : null;
    }
    // Dialects in check order: Hermes "action", then Claude-Code "decision" (_PRE_TOOL_DIALECTS).
    $dialects = [['action', 'message', 'reason', 'args'], ['decision', 'reason', 'message', 'tool_input']];
    foreach ($dialects as $d) {
        if ($get($d[0]) === 'block') {
            $raw = hookTruthy($get($d[1])) ? $get($d[1]) : $get($d[2]);
            return ['action' => 'block', 'message' => is_string($raw) && $raw !== '' ? $raw : HOOK_DEFAULT_BLOCK];
        }
    }
    foreach ($dialects as $d) {
        if ($get($d[0]) === 'modify' && is_object($get($d[3]))) {
            return ['action' => 'modify', 'args' => $get($d[3])];
        }
    }
    if ($get('action') === 'approve') {
        $out = ['action' => 'approve'];
        foreach (['message', 'rule_key'] as $k) {
            if (is_string($get($k)) && trim($get($k)) !== '') {
                $out[$k] = trim($get($k));
            }
        }
        return $out;
    }
    return null;
}

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

/**
 * agent/shell_hooks.py _evaluate_result for one unit: [directive|null, outcome for the log].
 * One addition: a failClosed unit that exits non-zero with no answer (a crash whose error went to
 * stderr) blocks; Hermes would let that call run.
 */
function hookEvaluate($event, array $unit, array $r)
{
    $id = $unit['category'] . '/' . $unit['name'];
    $failClosed = $unit['failClosed'] && $event === 'pre_tool_call';
    $closed = function ($reason) use ($id) {
        return [['action' => 'block', 'message' => "Pikkumies hook $id failed closed: $reason. Not run; tell the user this safety check is failing."], 'failed-closed'];
    };
    if ($r['error'] !== '' || $r['timedOut']) {
        $reason = $r['error'] !== '' ? $r['error'] : "timed out after {$unit['timeout']}s";
        return $failClosed ? $closed($reason) : [null, $r['timedOut'] ? 'timeout' : 'error'];
    }
    if ($r['code'] === HOOK_BLOCK_EXIT && $event === 'pre_tool_call') {
        $parsed = hookParse($event, $r['stdout']);
        if (is_array($parsed) && $parsed['action'] === 'block') {
            return [$parsed, 'block'];
        }
        $stderr = substr(trim($r['stderr']), 0, HOOK_STDERR_LIMIT);
        return [['action' => 'block', 'message' => $stderr !== '' ? $stderr : HOOK_DEFAULT_BLOCK], 'block'];
    }
    $stdout = trim($r['stdout']);
    $parsed = hookParse($event, $stdout);
    if ($parsed === null && $failClosed && $stdout !== '' && !is_object(json_decode($stdout))) {
        return $closed('unparseable output (expected a JSON object)');
    }
    if ($parsed === null && $failClosed && $r['code'] !== 0) {
        return $closed("exited with code {$r['code']}");
    }
    if ($parsed === null) {
        return [null, $r['code'] === 0 ? 'none' : 'exit-' . $r['code']];
    }
    return [$parsed, isset($parsed['action']) ? $parsed['action'] : 'context'];
}

/** hermes_cli/plugins.py _get_pre_tool_call_directive_details over the units' directives, as one hook answer. */
function hookMergePreToolCall(array $results)
{
    $modified = null;
    $firstApprove = null;
    foreach ($results as $result) {
        if (!is_array($result) || !isset($result['action'])) {
            continue;
        }
        if ($result['action'] === 'modify') {
            $partial = isset($result['args']) && is_object($result['args']) ? get_object_vars($result['args']) : [];
            if ($partial) {
                $modified = array_merge($modified === null ? [] : $modified, $partial);
            }
            continue;
        }
        if (!in_array($result['action'], ['block', 'approve'], true)) {
            continue;
        }
        $message = isset($result['message']) && is_string($result['message']) && $result['message'] !== '' ? $result['message'] : null;
        if ($result['action'] === 'block' && $message === null) {
            continue; // a block needs a message (it becomes the tool result)
        }
        if ($result['action'] === 'block') {
            return ['action' => 'block', 'message' => $message];
        }
        if ($firstApprove === null) {
            $firstApprove = ['action' => 'approve'];
            if ($message !== null) {
                $firstApprove['message'] = $message;
            }
            if (isset($result['rule_key']) && is_string($result['rule_key']) && trim($result['rule_key']) !== '') {
                $firstApprove['rule_key'] = trim($result['rule_key']);
            }
        }
    }
    // One shell hook answers with one directive (agent/shell_hooks.py _parse_pre_tool_call), so an
    // approve together with a modify keeps the approve: asking the user is the safety part.
    if ($firstApprove !== null) {
        return $firstApprove;
    }
    return $modified === null ? null : ['action' => 'modify', 'args' => (object) $modified];
}

/** hermes_cli/plugins.py get_pre_verify_continue_message: the first unit's continue answer wins; the rest are ignored. */
function hookMergePreVerify(array $results)
{
    foreach ($results as $result) {
        if (is_array($result) && isset($result['action']) && $result['action'] === 'continue') {
            return $result;
        }
    }
    return null;
}

/** agent/turn_context.py: contexts joined by a blank line; capped at HOOK_CONTEXT_MAX characters. */
function hookMergeContext(array $results)
{
    $parts = [];
    foreach ($results as $result) {
        if (is_array($result) && isset($result['context'])) {
            $parts[] = $result['context'];
        }
    }
    if (!$parts) {
        return null;
    }
    $text = implode("\n\n", $parts);
    if (preg_match('/^.{0,' . HOOK_CONTEXT_MAX . '}/su', $text, $m)) {
        $text = $m[0];
    } else {
        $text = substr($text, 0, HOOK_CONTEXT_MAX); // not valid UTF-8: cut by bytes
    }
    return ['context' => $text];
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
$units = HookUnits::forEvent($hookEvent);
$results = [];
foreach ($units as $u) {
    $start = microtime(true);
    if ($u['errors']) {
        // A broken unit blocks a tool call (its hook.md cannot be trusted to say whether it fails closed); elsewhere it is skipped.
        $closed = $hookEvent === 'pre_tool_call';
        $results[] = $closed
            ? ['action' => 'block', 'message' => "Pikkumies hook {$u['category']}/{$u['name']} failed closed: " . implode('; ', $u['errors']) . '. Not run; tell the user this safety check is failing.']
            : null;
        hookLog($hookEvent, "{$u['category']}/{$u['name']}", 0, $closed ? 'failed-closed' : 'invalid');
        continue;
    }
    list($directive, $outcome) = hookEvaluate($hookEvent, $u, hookSpawn($u['script'], $stdin, $u['timeout']));
    $results[] = $directive;
    hookLog($hookEvent, "{$u['category']}/{$u['name']}", (int) round((microtime(true) - $start) * 1000), $outcome);
}
if ($hookEvent === 'pre_tool_call') {
    $answer = hookMergePreToolCall($results);
} elseif ($hookEvent === 'pre_verify') {
    $answer = hookMergePreVerify($results);
} else {
    $answer = hookMergeContext($results);
}
if ($answer !== null) {
    $json = json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false && $answer['action'] === 'block') {
        $json = json_encode(['action' => 'block', 'message' => HOOK_DEFAULT_BLOCK]); // a block must not turn into no answer
    }
    echo ($json === false ? '' : $json . "\n");
}
$hookDone = true;
