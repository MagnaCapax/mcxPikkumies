<?php
/** The question suite's command-log check (ADR-0044, ADR-0039): new lines per question, their fields and session, and the missing-log failure. */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/CommandLogCheck.php';

return [
    'questions: CommandLogCheck counts only the lines a question added, checks their ADR-0044 fields and session, and fails a terminal call with no line' => function () {
        $dir = testTempDir('pk-cmdlog');
        try {
            $lines = file(dirname(__DIR__) . '/tests/fixtures/question-suite/commands-20261019.jsonl', FILE_IGNORE_NEW_LINES);
            file_put_contents("$dir/20261019.jsonl", $lines[0] . "\n"); // an earlier question's line
            $counts = CommandLogCheck::counts($dir);
            check($counts === ['20261019.jsonl' => 1], 'counts: ' . json_encode($counts));
            file_put_contents("$dir/20261019.jsonl", $lines[1] . "\n", FILE_APPEND);
            file_put_contents("$dir/20261020.jsonl", ''); // a new UTC day, still empty
            $new = CommandLogCheck::newLines($dir, $counts);
            check($new === [$lines[1]], 'only the line this question added: ' . json_encode($new));
            check(array_keys(CommandLogCheck::newLinesByDay($dir, $counts)) === ['20261019.jsonl'], 'a day file with no new line is not listed');
            check(CommandLogCheck::grade($new, 's-qa1') === ['command_log_new' => 1, 'command_log_fields_ok' => true, 'command_log_session_ok' => true], 'a good line');
            check(CommandLogCheck::grade($new, '')['command_log_session_ok'] === null, 'no session in the transcript: not judged');
            $bad = CommandLogCheck::grade([$lines[1], $lines[2]], 's-qa1'); // the third fixture line lacks `background` and is another session's
            check($bad['command_log_new'] === 2 && $bad['command_log_fields_ok'] === false && $bad['command_log_session_ok'] === false, 'a bad line: ' . json_encode($bad));
            check(CommandLogCheck::grade(['not json'], 'x')['command_log_fields_ok'] === false, 'a line that is not JSON');
            file_put_contents("$dir/20261020.jsonl", $lines[0] . "\n");
            check(count(CommandLogCheck::newLines($dir, $counts)) === 2, 'a line in the next day\'s file counts too');
            check(array_keys(CommandLogCheck::newLinesByDay($dir, $counts)) === ['20261019.jsonl', '20261020.jsonl'], 'new lines grouped by day file');
            $noBytes = json_decode($lines[0], true);
            unset($noBytes['output_bytes']);
            check(CommandLogCheck::grade([json_encode($noBytes)], 's-qa1')['command_log_fields_ok'] === false, 'a line without output_bytes lacks a field');

            $terminal = ['tools' => [['name' => 'read_file', 'input' => []], ['name' => 'terminal', 'input' => ['command' => 'df -h']]]];
            $missing = CommandLogCheck::checks($terminal, CommandLogCheck::grade([], 's-qa1'));
            check(count($missing) === 1 && $missing[0]['name'] === 'command-log-missing' && $missing[0]['pass'] === false, 'a terminal call with no line must fail: ' . json_encode($missing));
            check(CommandLogCheck::checks($terminal, CommandLogCheck::grade($new, 's-qa1'))[0]['pass'] === true, 'a terminal call with a line passes');
            check(CommandLogCheck::checks(['tools' => [['name' => 'read_file', 'input' => []]]], CommandLogCheck::grade([], '')) === [], 'no terminal call: no check');

            file_put_contents("$dir/raw.jsonl", json_encode(['type' => 'system', 'subtype' => 'init', 'session_id' => 's-qa1']) . "\nplain text\n"
                . json_encode(['type' => 'result', 'session_id' => 's-qa1']) . "\n");
            check(CommandLogCheck::sessionId("$dir/raw.jsonl") === 's-qa1' && CommandLogCheck::sessionId("$dir/none") === '', 'session id from the stream');
        } finally {
            testRmTree($dir);
        }
    },
    'questions: run.php counts the command log before each question, adds the check and the row fields after it, and keeps the log as evidence' => function () {
        // run.php is the top-level runner (it needs keys and a QA account), so its wiring is checked in its source.
        $src = (string) file_get_contents(dirname(__DIR__) . '/tests/testing/questions/run.php');
        $before = strpos($src, '$logCounts = CommandLogCheck::counts(');
        $ask = strpos($src, '$rec = Asker::ask(');
        check($before !== false && $ask !== false && $before < $ask, 'the counts must be taken right before the question is asked');
        check(strpos($src, 'CommandLogCheck::checks($rec, $log)') !== false && strpos($src, ', $rec, $judge) + $log;') !== false, 'the check and the row fields must be added');
        check(strpos($src, 'CommandLogCheck::newLinesByDay(CommandLogCheck::dir($box->root), $runLogCounts)') !== false, 'only this run\'s lines must be copied');
        check(preg_match('#\$out/command-log/\$day", \$report->mask\(#', $src) === 1 && strpos($src, 'chmod("$out/command-log/$day", 0600)') !== false, 'the copy must mask keys like the transcripts (paths as written, so ~ is the sandbox home) and be 0600');
    },
];
