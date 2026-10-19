<?php
/**
 * Hook unit (post_tool_call, run by hooks/orchestrator.php, ADR-0030): one more tool call, and the lines of code the call
 * added and removed, for the tmux pane in TMUX_PANE (lib/SessionCounters.php, ADR-0061). Lines come from the `diff` a
 * `patch` result carries (Hermes v0.22.2 tools/file_operations_common.py PatchResult) and from the content of a
 * `write_file`; a failed call, or a result that is not JSON (cut to size), counts as a call with no lines. Prints nothing:
 * post_tool_call is an observer. A failure to write never fails the tool call.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/SessionCounters.php';

/**
 * [added, removed] lines of a unified diff, counted inside hunks only, by the lengths in each hunk header: a file
 * header ("--- a/x") never counts, and a removed line that itself starts with "--" still does.
 */
function sessionCountersDiffLines($diff)
{
    list($add, $del, $old, $new) = [0, 0, 0, 0];
    foreach (explode("\n", (string) $diff) as $l) {
        if ($old <= 0 && $new <= 0) {
            if (preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $l, $m)) {
                $old = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 1;
                $new = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
            }
            continue;
        }
        $c = $l === '' ? ' ' : $l[0];
        if ($c === '+') {
            list($add, $new) = [$add + 1, $new - 1];
        } elseif ($c === '-') {
            list($del, $old) = [$del + 1, $old - 1];
        } elseif ($c !== '\\') { // "\ No newline at end of file" belongs to the line before it
            list($old, $new) = [$old - 1, $new - 1];
        }
    }
    return [$add, $del];
}

/** What one post_tool_call payload adds: ['tools' => 1, 'added' => n, 'removed' => n]. */
function sessionCountersDelta(array $p)
{
    $d = ['tools' => 1, 'added' => 0, 'removed' => 0];
    $tool = isset($p['tool_name']) ? (string) $p['tool_name'] : '';
    $in = isset($p['tool_input']) && is_array($p['tool_input']) ? $p['tool_input'] : [];
    $result = isset($p['extra']['result']) ? $p['extra']['result'] : null;
    $r = is_string($result) ? json_decode($result, true) : $result;
    if (!is_array($r) || !empty($r['error'])) {
        return $d;
    }
    if ($tool === 'patch' && isset($r['diff']) && is_string($r['diff'])) {
        list($d['added'], $d['removed']) = sessionCountersDiffLines($r['diff']);
    } elseif ($tool === 'write_file' && isset($in['content']) && is_string($in['content']) && $in['content'] !== '') {
        $d['added'] = substr_count($in['content'], "\n") + (substr($in['content'], -1) === "\n" ? 0 : 1);
    }
    return $d;
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $pane = (string) getenv('TMUX_PANE');
    if (preg_match(SessionCounters::PANE_RE, $pane)) { // outside tmux: nothing to read or write
        $payload = json_decode((string) stream_get_contents(STDIN), true);
        SessionCounters::update(Config::localDir(), $pane, sessionCountersDelta(is_array($payload) ? $payload : []));
    }
}
