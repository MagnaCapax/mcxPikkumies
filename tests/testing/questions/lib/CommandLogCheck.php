<?php
/**
 * CommandLogCheck: the question suite's check of the command log (ADR-0044, ADR-0039). Every terminal call the agent makes
 * should add one line to the sandbox install's local/logs/commands/YYYYMMDD.jsonl; question-suite run 3 kept no trace of
 * it and the throwaway install was removed, so the log was never seen working live. Before each question the runner takes
 * counts(), after it newLines() and grade(); checks() fails a question whose transcript has a terminal call but whose log
 * gained no line. local/logs is not swept by Sandbox::reset(), so the files grow through the run and counts() marks where
 * each question starts.
 */
class CommandLogCheck
{
    /** The fields ADR-0044 gives every line (`error` and `truncated` only when they apply). */
    const FIELDS = ['ts', 'session_id', 'tool_call_id', 'cwd', 'tool', 'command', 'background', 'exit_code', 'status', 'duration_ms', 'output_bytes'];

    /** The command-log directory of a sandbox root. */
    public static function dir($root)
    {
        return "$root/checkout/pikkumies/local/logs/commands";
    }

    /** [file name => line count] for every day file now in $dir (the run's UTC days). */
    public static function counts($dir)
    {
        $out = [];
        foreach (glob("$dir/*.jsonl") ?: [] as $f) {
            $out[basename($f)] = count(self::fileLines($f));
        }
        return $out;
    }

    /** The lines added to $dir's day files since $counts (a file not in $counts is new: all its lines). */
    public static function newLines($dir, array $counts)
    {
        $new = [];
        foreach (self::newLinesByDay($dir, $counts) as $lines) {
            $new = array_merge($new, $lines);
        }
        return $new;
    }

    /** The same, as [file name => lines] for the day files that gained any. */
    public static function newLinesByDay($dir, array $counts)
    {
        $out = [];
        foreach (glob("$dir/*.jsonl") ?: [] as $f) {
            $seen = isset($counts[basename($f)]) ? $counts[basename($f)] : 0;
            $lines = array_slice(self::fileLines($f), $seen);
            if ($lines) {
                $out[basename($f)] = $lines;
            }
        }
        return $out;
    }

    /**
     * The row fields for one question: how many lines it added, whether every one is a JSON object with all FIELDS,
     * and whether every one carries $sessionId (null when the transcript named no session).
     */
    public static function grade(array $lines, $sessionId)
    {
        $fields = true;
        $session = $sessionId === '' ? null : true;
        foreach ($lines as $line) {
            $j = json_decode($line, true);
            $fields = $fields && is_array($j) && !array_diff(self::FIELDS, array_keys($j));
            if ($session !== null) {
                $session = $session && is_array($j) && isset($j['session_id']) && $j['session_id'] === $sessionId;
            }
        }
        return ['command_log_new' => count($lines), 'command_log_fields_ok' => $fields, 'command_log_session_ok' => $session];
    }

    /** The check added to a question's checks: a transcript with a terminal call must have left a command-log line. */
    public static function checks(array $rec, array $grade)
    {
        $terminal = count(array_filter(isset($rec['tools']) ? $rec['tools'] : [], function ($t) {
            return isset($t['name']) && $t['name'] === 'terminal';
        }));
        if ($terminal === 0) {
            return [];
        }
        $pass = $grade['command_log_new'] > 0;
        return [['name' => 'command-log-missing', 'pass' => $pass, 'detail' => $pass ? '' : "$terminal terminal calls, no new line in the command log"]];
    }

    /** The session id of the last stream-json event in $rawFile that names one; '' when none does. */
    public static function sessionId($rawFile)
    {
        $id = '';
        foreach (is_file($rawFile) ? self::fileLines($rawFile) : [] as $line) {
            $j = $line !== '' && $line[0] === '{' ? json_decode($line, true) : null;
            $id = is_array($j) && isset($j['session_id']) && is_string($j['session_id']) ? $j['session_id'] : $id;
        }
        return $id;
    }

    private static function fileLines($file)
    {
        return array_values(array_filter(array_map('trim', (array) @file($file)), 'strlen'));
    }
}
