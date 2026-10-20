<?php
/**
 * Hook unit (post_tool_call, run by hooks/orchestrator.php, ADR-0030): every shell command the agent ran goes to
 * local/logs/commands/YYYYMMDD.jsonl (UTC), one JSON line per `terminal` call, so a session can be traced
 * later (ADR-0044). The line holds the time, session, tool call id, directory, the command with secrets removed
 * (Cli::cleanString plus option, header and variable forms), exit code, status, duration and output size;
 * never the output. Kept as long as the session history (sessions.retentionDays, 0 = forever). Prints
 * nothing: post_tool_call is an observer. A failure to write never fails the tool call.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/Redact.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

const COMMAND_LOG_TOOLS = ['terminal'];
const COMMAND_LOG_MAX = 4000;

/** The command with the secrets a shell line carries removed (lib/Redact.php, shared with /feedback). */
function commandLogRedact($command)
{
    // Shared with /feedback (ADR-0056): Cli::cleanString, option/header/variable forms, mysql -p, PEM blocks and common
    // token forms, plus the whole-shell-word match for *KEY=/*TOKEN=/*SECRET=/*PASSWORD=-style values (ADR-0049).
    return Redact::secrets($command);
}

/** The log line for a post_tool_call payload, or null when it is not a shell command. */
function commandLogRecord(array $p)
{
    $tool = isset($p['tool_name']) ? (string) $p['tool_name'] : '';
    $in = isset($p['tool_input']) && is_array($p['tool_input']) ? $p['tool_input'] : [];
    if (!in_array($tool, COMMAND_LOG_TOOLS, true) || !isset($in['command'])) {
        return null;
    }
    $extra = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $result = isset($extra['result']) ? $extra['result'] : null;
    $r = is_string($result) ? json_decode($result, true) : (is_array($result) ? $result : null);
    $command = commandLogRedact($in['command']);
    $rec = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'session_id' => isset($p['session_id']) ? (string) $p['session_id'] : '',
        'tool_call_id' => isset($extra['tool_call_id']) ? (string) $extra['tool_call_id'] : '',
        'cwd' => Cli::cleanString(isset($p['cwd']) ? (string) $p['cwd'] : ''), 'tool' => $tool,
        'command' => substr($command, 0, COMMAND_LOG_MAX), 'background' => !empty($in['background']),
        'exit_code' => is_array($r) && isset($r['exit_code']) && is_numeric($r['exit_code']) ? (int) $r['exit_code'] : null,
        'status' => isset($extra['status']) ? (string) $extra['status'] : '',
        'duration_ms' => isset($extra['duration_ms']) ? (int) $extra['duration_ms'] : 0,
        'output_bytes' => is_array($r) && isset($r['output']) ? strlen((string) $r['output']) : (is_string($result) ? strlen($result) : 0)];
    if (strlen($command) > COMMAND_LOG_MAX) {
        $rec['truncated'] = true;
    }
    if (is_array($r) && !empty($r['error'])) {
        $rec['error'] = substr(Cli::cleanString((string) $r['error']), 0, 300);
    }
    return $rec;
}

/** Append the line; a new day's file prunes files older than the session retention. Never throws. */
function commandLogWrite(array $rec, $dir, $retentionDays)
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
    $new = !is_file($file);
    $old = umask(0077);
    $ok = @file_put_contents($file, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX) !== false;
    umask($old);
    if ($new && $retentionDays > 0) {
        $cut = gmdate('Ymd', time() - $retentionDays * 86400);
        foreach ((array) glob($dir . '/[0-9]*.jsonl') as $f) {
            if (PathName::base($f, '.jsonl') < $cut) {
                @unlink($f);
            }
        }
    }
    return $ok;
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    $rec = is_array($payload) ? commandLogRecord($payload) : null;
    if ($rec !== null) {
        $config = Config::load();
        commandLogWrite($rec, Config::localDir() . '/logs/commands', (int) $config['sessions']['retentionDays']);
    }
}
