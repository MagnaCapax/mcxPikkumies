<?php
/**
 * hooks/trace/command-log/command-log.php (ADR-0044): commands over COMMAND_LOG_MAX (4000 bytes)
 * are cut before they are written to local/logs/commands/*.jsonl, and flagged truncated (D161).
 * The rest of the record's shape (fields, 0600/0700, never the output) belongs to another builder's
 * batch (tests/CommandLogTest.php); this file covers only the truncation edge, which that file does
 * not exercise. commandLogRecord() is a pure function — requiring the unit file is safe here: its
 * top-level run-as-script guard (`if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)`) only
 * fires when the file is executed directly, never when it is require'd.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/Config.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Redact.php';
require_once dirname(__DIR__) . '/pikkumies/hooks/trace/command-log/command-log.php';

/** A minimal post_tool_call payload for a terminal command, success exit. */
function cmdTruncPayload($command)
{
    return ['tool_name' => 'terminal', 'tool_input' => ['command' => $command], 'extra' => ['result' => json_encode(['exit_code' => 0, 'output' => ''])]];
}

return [
    'command log truncation: a command over COMMAND_LOG_MAX is cut to exactly that length and flagged truncated; one under it is not' => function () {
        $long = 'echo ' . str_repeat('x', 5000);
        check(strlen($long) > COMMAND_LOG_MAX, 'the fixture command must exceed the limit to test anything');
        $rec = commandLogRecord(cmdTruncPayload($long));
        check(strlen($rec['command']) === COMMAND_LOG_MAX, 'the stored command must be cut to exactly COMMAND_LOG_MAX bytes, got ' . strlen($rec['command']));
        check($rec['command'] === substr($long, 0, COMMAND_LOG_MAX), 'truncation must keep the FIRST bytes (the command and its early flags), not the last: ' . substr($rec['command'], -20));
        check(isset($rec['truncated']) && $rec['truncated'] === true, 'a command over the limit must be flagged truncated: ' . json_encode($rec));
        $short = commandLogRecord(cmdTruncPayload('ls -la'));
        check(!array_key_exists('truncated', $short), 'a command well under the limit must carry no truncated key at all: ' . json_encode($short));
    },
    'command log truncation: exactly COMMAND_LOG_MAX bytes is not truncated; one byte more is' => function () {
        $exact = str_repeat('a', COMMAND_LOG_MAX);
        $recExact = commandLogRecord(cmdTruncPayload($exact));
        check($recExact['command'] === $exact && !array_key_exists('truncated', $recExact), 'exactly COMMAND_LOG_MAX bytes must not be flagged truncated: ' . json_encode(['len' => strlen($recExact['command']), 'truncated' => isset($recExact['truncated'])]));
        $over = $exact . 'b';
        $recOver = commandLogRecord(cmdTruncPayload($over));
        check(strlen($recOver['command']) === COMMAND_LOG_MAX && $recOver['truncated'] === true, 'one byte over COMMAND_LOG_MAX must be cut and flagged: ' . json_encode(['len' => strlen($recOver['command']), 'truncated' => isset($recOver['truncated']) ? $recOver['truncated'] : null]));
    },
];
