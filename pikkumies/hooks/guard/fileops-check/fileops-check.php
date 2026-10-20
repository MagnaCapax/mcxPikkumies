<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030/ADR-0048): a second look before commands that change
 * permissions, owners, attributes or names in bulk, and a quick list of what was there first. For `terminal` commands
 * and `execute_code` code (lib/FileopsClassify.php):
 * - a question for the orchestrator's ask policy (approve, rule_key pikkumies-fileops:<hash of the command>; kind delete
 *   for an mv that replaces a file, ADR-0073): mv onto an existing file under the home
 *   (or two sources colliding in one directory), a recursive change on the home itself, ~/.ssh or the pikkumies
 *   directory, and targets holding more entries than the list can take;
 * - blocks once with a fixed message telling the agent to say what the command changes and run it again: recursive
 *   chmod/chown/chgrp, find -exec of them or mv, chattr, rename, setfacl. The identical command in the same session
 *   within 10 minutes then passes (marker local/state/fileops/<sha1 of session id NUL command>). No valid session id,
 *   or a marker that cannot be written, is a question instead. The ask tier never reads the marker.
 * Before any answer it writes the list (lib/FileopsList.php): local/logs/fileops/<sha1 of the tool call id>.jsonl.gz.
 * Fails open (5 s): a slow walk or a crash lets the command run, and guard-destructive still runs. A safety prompt for
 * the agent's own mistakes, not a security boundary (AGENTS.md). Off with fileopsCheck.disabled.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/FileopsClassify.php';
require_once dirname(__DIR__, 3) . '/lib/FileopsList.php';
require_once dirname(__DIR__, 3) . '/lib/HookUnits.php'; // HookUnits::approveShown: the one approve-message-fits-the-panel check (ADR-0054)
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

const FILEOPS_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
const FILEOPS_PASS_SECONDS = 600;
const FILEOPS_ATTR_ENTRIES = 200;
const FILEOPS_WHAT = ['chmod' => 'changes permissions', 'chown' => 'changes owners', 'chgrp' => 'changes groups',
    'setfacl' => 'changes access control lists', 'chattr' => 'changes file attributes (such as immutable or append-only)',
    'rename' => 'renames files by a pattern', 'mv' => 'moves files'];

/** A path for a message: the home as ~. */
function fileopsShow($path, $home)
{
    return $home !== '' && FileopsList::within($path, $home) ? '~' . substr($path, strlen($home)) : $path;
}

/** Targets mv would replace: an existing non-directory under the home, or two sources landing on one name. */
function fileopsCollisions(array $m, array $sources, array $dest, $home)
{
    if (count($dest) !== 1 || $home === '') {
        return [];
    }
    $d = $dest[0];
    $into = $m['intoDir'] || (is_dir($d) && !$m['noTargetDir']);
    $landing = $into ? array_map(function ($s) use ($d) {
        return rtrim($d, '/') . '/' . PathName::base($s);
    }, $sources) : (count($sources) === 1 ? [$d] : []);
    $out = [];
    foreach (array_count_values($landing) as $t => $n) {
        $parent = realpath(dirname($t));
        if ($parent !== false && FileopsList::within($parent, $home) && ($n > 1 || ((file_exists($t) || is_link($t)) && !is_dir($t)))) {
            $out[] = $t;
        }
    }
    return $out;
}

/** 'pass' when the identical command was blocked in this session within FILEOPS_PASS_SECONDS, 'block' after recording it, 'ask' when it cannot be recorded. */
function fileopsMarker($localDir, $sessionId, $command)
{
    if (!preg_match(FILEOPS_SESSION_RE, $sessionId)) {
        return 'ask';
    }
    $dir = "$localDir/state/fileops";
    $marker = $dir . '/' . sha1($sessionId . "\0" . $command);
    clearstatcache();
    $m = @filemtime($marker);
    if ($m !== false && $m >= time() - FILEOPS_PASS_SECONDS && $m <= time() + 5) {
        return 'pass';
    }
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || !@touch($marker)) {
        return 'ask';
    }
    foreach ((array) glob("$dir/*") as $old) {
        if (@filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    return 'block';
}

/** The hook's answer for a pre_tool_call payload, or null. $limits ['cap', 'seconds'] is the test seam for the walk. */
function fileopsCheckAnswer(array $payload, array $config, $localDir, array $limits = [])
{
    $tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
    $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
    $field = $tool === 'terminal' ? 'command' : ($tool === 'execute_code' ? 'code' : '');
    if (!empty($config['fileopsCheck']['disabled']) || $field === '' || !isset($input[$field]) || !is_string($input[$field])) {
        return null;
    }
    $command = $input[$field];
    $c = FileopsClassify::classify($command, $tool === 'execute_code');
    if (!$c['matches']) {
        return null;
    }
    $homeReal = (string) realpath((string) getenv('HOME'));
    $homeRaw = rtrim((string) getenv('HOME'), '/');
    $workdir = isset($input['workdir']) && is_string($input['workdir']) ? $input['workdir'] : '';
    $sensitive = array_values(array_filter(array_unique([$homeReal, $homeReal === '' ? '' : "$homeReal/.ssh", (string) realpath(dirname($localDir))])));
    $ctx = FileopsList::context(isset($limits['cap']) ? $limits['cap'] : FileopsList::CAP, isset($limits['seconds']) ? $limits['seconds'] : FileopsList::SECONDS);
    $tier = '';
    $asks = [];
    $targets = [];
    $relative = false;
    $verbs = [];
    $inside = []; // the sensitive paths the targets are or hold
    $replaces = false; // an mv over an existing file: a delete for the ask policy (ADR-0073)
    foreach ($c['matches'] as $m) {
        $paths = [];
        $dest = [];
        foreach ($c['complex'] ? [] : array_merge($m['paths'], $m['dest'] ? [$m['dest']] : []) as $k => $w) {
            $r = FileopsList::expand($w, $workdir, $homeRaw, $ctx);
            $relative = $relative || $r['relative'];
            if ($m['dest'] && $k === count($m['paths'])) {
                $dest = $r['paths'];
            } else {
                $paths = array_merge($paths, $r['paths']);
            }
        }
        if ($m['tier'] === 'mv') {
            $hit = fileopsCollisions($m, $paths, $dest, $homeReal);
            if ($hit) {
                $replaces = true;
                $asks[] = 'mv replaces ' . implode(', ', array_map(function ($p) use ($homeReal) {
                    return fileopsShow($p, $homeReal);
                }, array_slice($hit, 0, 3))) . (count($hit) > 3 ? ' and ' . (count($hit) - 3) . ' more' : '') . ', unrecoverable';
                $targets = array_merge($targets, $paths, $hit);
            }
            continue;
        }
        $tier = 'block';
        $verbs[] = $m['verb'] . ($m['recursive'] && strpos($m['verb'], 'find') !== 0 ? ' -R' : '');
        $targets = array_merge($targets, $paths);
        $matchInside = []; // this match's recursive sensitive hits, merged into one ask below (a chmod -R on the
        // home itself also reaches ~/.ssh underneath it, and each hit repeating the full warning overflows the panel)
        foreach ($paths as $p) {
            $r = realpath($p);
            foreach ($r === false ? [] : $sensitive as $s) {
                if (!FileopsList::within($s, $r)) {
                    continue;
                }
                $inside[$s] = $s === $homeReal ? 'the home itself' : fileopsShow($s, $homeReal);
                if ($m['recursive']) {
                    $matchInside[$s] = $inside[$s];
                }
            }
        }
        if ($matchInside) {
            $asks[] = end($verbs) . ' changes ' . implode(' and ', array_unique($matchInside)) . ', which can lock you out of SSH or break Pikkumies';
        }
    }
    if (!$asks && $tier === '') {
        return null;
    }
    // The list, written before any answer (also before a pass), unless a relative path had nothing to resolve against.
    $entries = $relative ? [] : FileopsList::walk($targets, $homeReal, $ctx);
    if ($ctx['truncated']) {
        $asks[] = 'its targets hold over ' . $ctx['cap'] . ' entries, more than can be listed for a check';
    }
    $verbText = implode(' ', $verbs);
    if ($entries && (count($entries) <= FILEOPS_ATTR_ENTRIES || strpos($verbText, 'chattr') !== false)) {
        FileopsList::addAttributes($entries);
    }
    $toolCallId = isset($payload['extra']['tool_call_id']) && is_scalar($payload['extra']['tool_call_id']) ? (string) $payload['extra']['tool_call_id'] : '';
    $sessionId = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
    $logId = sha1($toolCallId !== '' ? $toolCallId : $sessionId . "\0" . $command . microtime());
    $file = $relative ? false : FileopsList::write("$localDir/logs/fileops", $logId,
        ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'session_id' => $sessionId, 'tool_call_id' => $toolCallId, 'command' => Cli::cleanString($command),
            'targets' => $c['complex'] ? 'not captured' : array_values(array_unique($targets)), 'truncated' => $ctx['truncated']],
        $entries, isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0);
    if ($c['complex']) {
        $about = 'Its targets are built when the command runs (a pipe, ;, &&, a variable, a substitution or code), so they were not listed.';
    } elseif ($relative) {
        $about = 'A target is a relative path and the call has no workdir, so nothing was listed; use absolute paths.';
    } elseif (!$entries) {
        $about = 'Nothing under the home was listed.';
    } else {
        // "inside" is named only when something sensitive actually is inside; otherwise the sentence would say
        // nothing and only cost characters (HookUnits::APPROVE_SHOWN is tight once the log id below is added).
        $about = 'Targets hold ' . ($ctx['truncated'] ? 'over ' . $ctx['cap'] : count($entries)) . ' entries'
            . ($inside ? '; inside: ' . implode(', ', $inside) : '') . '.';
    }
    // A short id, not the log file's real 40-hex name: the user does not need the exact name to decide, only that
    // the full list (permissions, owners, sizes) is on disk if they want it (`ls local/logs/fileops/<id>*`).
    $about .= $file === false ? '' : ' Details: ~/pikkumies/local/logs/fileops/' . substr($logId, 0, 8) . '.jsonl.gz.';
    $id = substr(sha1($command), 0, 16);
    $marker = $asks ? 'ask' : fileopsMarker($localDir, $sessionId, $command); // the ask tier never reads the marker
    if ($marker === 'pass') {
        return null;
    }
    if ($marker === 'block') {
        $what = array_unique(array_map(function ($v) {
            $base = preg_replace('/^find -exec | -R$/', '', $v);
            return $v . ' ' . (array_key_exists($base, FILEOPS_WHAT) ? FILEOPS_WHAT[$base] : 'changes files');
        }, $verbs));
        return ['action' => 'block', 'message' => 'Pikkumies fileops check: ' . implode('; ', $what) . ". Not run. $about Check what it touches, say in your answer in one line what it changes, and run the identical command again (within 10 minutes it proceeds), or change the command."];
    }
    $why = $asks ? implode('; ', array_unique($asks)) : "$verbText needs a check, and this session's check could not be recorded";
    $approveMessage = "Pikkumies: $why. $about Command [$id]: $command";
    if (!HookUnits::approveShown($approveMessage)) {
        return ['action' => 'block', 'message' => 'Pikkumies fileops check: a command for the ask review, and it is too long to show the user in full. Not run. Split it into smaller commands.'];
    }
    return ['action' => 'approve', 'rule_key' => 'pikkumies-fileops:' . $id, 'message' => $approveMessage] + ($replaces ? ['kind' => 'delete'] : []);
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    $answer = is_array($payload) ? fileopsCheckAnswer($payload, Config::load(), Config::localDir()) : null;
    echo $answer === null ? '' : json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}
