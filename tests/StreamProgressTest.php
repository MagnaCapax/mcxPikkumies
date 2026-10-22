<?php
/** lib/StreamProgress.php (ADR-0109): the step lines, what they may and may not show, the answer, errors and the stats. */

if (!function_exists('streamProgressRun')) {
    /** Feeds $events (arrays or raw strings, one line each) in $chunk-byte pieces; returns [stderr, answer, renderer]. */
    function streamProgressRun(array $events, $show = true, $narrator = null, $chunk = 7, $exit = 0)
    {
        $err = fopen('php://memory', 'w+');
        $sp = new StreamProgress($show, true, $narrator, $err, function () {
            return 43323; // 12:02:03 UTC
        });
        $raw = '';
        foreach ($events as $e) {
            $raw .= (is_string($e) ? $e : json_encode($e, JSON_UNESCAPED_UNICODE)) . "\n";
        }
        foreach (str_split($raw, $chunk) as $piece) {
            $sp->feed($piece);
        }
        $answer = $sp->finishRun($exit);
        rewind($err);
        return [stream_get_contents($err), $answer, $sp];
    }
}

return [
    'StreamProgress: one line per step with the time, a plain verb, the target and the agent\'s last sentence; the answer comes back, commentary does not' => function () {
        list($err, $answer, $sp) = streamProgressRun([
            ['type' => 'system', 'subtype' => 'init', 'model' => 'm-small', 'session_id' => 's1'],
            ['type' => 'text', 'text' => 'Let me look. I will check the disk usage first.'],
            ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => 'df -h /home']],
            ['type' => 'tool_result', 'name' => 'terminal', 'output' => '...', 'is_error' => false],
            ['type' => 'tool_use', 'name' => 'read_file', 'input' => ['path' => '~/notes.txt']],
            ['type' => 'tool_use', 'name' => 'some_new_tool', 'input' => ['secret_arg' => 'x']],
            ['type' => 'text', 'text' => 'You have 41 GB free.'],
            ['type' => 'result', 'session_id' => 's1', 'exit_code' => 0, 'text' => 'You have 41 GB free.', 'tokens' => ['input' => 10, 'output' => 5, 'total' => 15, 'cache_read' => 2, 'cache_write' => 0]],
        ]);
        $lines = explode("\n", trim($err));
        check($lines[0] === '[12:02:03] ran df -h /home — I will check the disk usage first.', 'step 1: ' . $lines[0]);
        check($lines[1] === '[12:02:03] read ~/notes.txt', 'step 2: ' . $lines[1]);
        check($lines[2] === '[12:02:03] used some new tool', 'an unknown tool shows its name only, never its input: ' . $lines[2]);
        check(count($lines) === 3, 'exactly three lines: ' . $err);
        check($answer === 'You have 41 GB free.', 'answer: ' . $answer);
        $s = $sp->stats();
        check($s['tools'] === 3 && $s['runs'] === 1 && $s['model'] === 'm-small' && $sp->sessionId() === 's1' && $s['tokens']['total'] === 15, json_encode($s));
    },
    'StreamProgress: model-written escapes, line breaks and secrets never reach the terminal; file content never shows' => function () {
        list($err) = streamProgressRun([
            ['type' => 'text', 'text' => "Now \x1b]52;c;ZXZpbA==\x07 copying.\nAnd \x1b[1A\x1b[2K hiding"],
            ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => "mysql -u root -pS3cretPassw0rd db\n[12:00:00] ran fake line"]],
            ['type' => 'tool_use', 'name' => 'write_file', 'input' => ['path' => "/tmp/x\x1b[31m.conf", 'content' => 'api_key=SHOULD-NOT-SHOW']],
            ['type' => 'tool_use', 'name' => "evil\x1b[2Jname", 'input' => []],
            ['type' => 'result', 'text' => 'ok'],
        ]);
        check(strpos($err, "\x1b") === false && strpos($err, "\x07") === false, 'escape bytes reached stderr: ' . json_encode($err));
        check(substr_count(trim($err), "\n") === 2, 'one line per step, an embedded newline must not make a fake line: ' . json_encode($err));
        check(strpos($err, 'S3cretPassw0rd') === false, 'the mysql password was printed: ' . $err);
        check(strpos($err, 'SHOULD-NOT-SHOW') === false && strpos($err, 'api_key') === false, 'file content was printed: ' . $err);
        check(strpos($err, '[12:02:03] wrote /tmp/x') === 0 || strpos($err, "\n[12:02:03] wrote /tmp/x") !== false, 'the write step shows its path: ' . $err);
    },
    'StreamProgress: a target that cannot be checked for secrets is never printed raw' => function () {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $t = StreamProgress::target('terminal', ['command' => 'curl -u admin:VerySecretValue123 https://example.com/' . str_repeat('a', 300)]);
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
        check(strpos($t, 'VerySecretValue123') === false, 'raw target printed when redaction could not run: ' . $t);
    },
    'StreamProgress: a line over 1 MiB split across 64 KiB reads is dropped, its step still counted and shown, and the next event still parses' => function () {
        $err = fopen('php://memory', 'w+');
        $sp = new StreamProgress(true, true, null, $err, function () {
            return 0;
        });
        $huge = '{"type": "tool_use", "name": "write_file", "input": {"path": "/x", "content": "' . str_repeat('A', 2200000) . '"}}' . "\n";
        $tail = json_encode(['type' => 'result', 'text' => 'done']) . "\n";
        $before = memory_get_usage();
        foreach (str_split($huge . $tail, 65536) as $piece) {
            $sp->feed($piece);
        }
        $answer = $sp->finishRun(0);
        rewind($err);
        $out = stream_get_contents($err);
        check($answer === 'done', 'the event after the long line was lost: ' . $answer);
        check($sp->stats()['tools'] === 1 && strpos($out, 'wrote') !== false && strpos($out, 'too large') !== false, 'the long step was not counted or shown: ' . $out);
        check(memory_get_usage() - $before < 4 * 1048576, 'memory grew with the long line: ' . (memory_get_usage() - $before));
    },
    'StreamProgress: a failed step is marked; the run\'s error and an end without a result are said even with progress off' => function () {
        list($err) = streamProgressRun([
            ['type' => 'tool_use', 'name' => 'read_file', 'input' => ['path' => '/nope']],
            ['type' => 'tool_result', 'name' => 'read_file', 'is_error' => true],
            ['type' => 'result', 'text' => '', 'error' => 'provider said: insufficient credits'],
        ]);
        check(strpos($err, '❌ read failed') !== false && strpos($err, '❌ Hermes: provider said: insufficient credits') !== false, $err);
        list($quiet, $answer) = streamProgressRun([
            ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => 'ls']],
            ['type' => 'text', 'text' => 'partial answer'],
        ], false, null, 7, 9);
        check($quiet === "❌ Hermes ended without finishing (exit 9)\n", 'progress off: only the error line: ' . json_encode($quiet));
        check($answer === 'partial answer', 'the text so far is still the answer: ' . $answer);
    },
    'StreamProgress: several Hermes runs add up; the answer separator puts a blank line before each later answer' => function () {
        $err = fopen('php://memory', 'w+');
        $sp = new StreamProgress(false, true, null, $err);
        foreach ([3, 4] as $n) {
            $sp->feed(json_encode(['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => 'x']]) . "\n" . json_encode(['type' => 'result', 'text' => "a$n", 'tokens' => ['input' => $n, 'output' => 1, 'total' => $n + 1]]) . "\n");
            check($sp->finishRun(0) === "a$n", "answer of run $n");
        }
        $s = $sp->stats();
        check($s['runs'] === 2 && $s['tools'] === 2 && $s['tokens']['input'] === 7 && $s['tokens']['total'] === 9, json_encode($s));
        check($sp->answerSeparator() === '' && $sp->answerSeparator() === "\n", 'separator');
    },
    'StreamProgress: the narrator runs once per read for the newest step, gets the last steps as data, and its line is cleaned' => function () {
        $calls = [];
        $narrator = function (array $lines) use (&$calls) {
            $calls[] = $lines;
            return "Checking space \x1b[31mnow\nsecond line";
        };
        $events = [];
        for ($i = 1; $i <= 8; $i++) {
            $events[] = ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => "step$i"]];
        }
        list($err) = streamProgressRun($events, true, $narrator, 1000000);
        check(count($calls) === 1, 'one read, one narration: ' . count($calls));
        check(count($calls[0]) === StreamProgress::NARRATE_TAIL && strpos(end($calls[0]), 'step8') !== false, 'the last six steps, newest last: ' . json_encode($calls[0]));
        check(strpos($err, '↳ Checking space [31mnow second line') !== false && strpos($err, "\x1b") === false, $err);
        streamProgressRun($events, false, $narrator, 1000000);
        check(count($calls) === 1, 'progress off: no narration');
    },
    'StreamProgress: the stats lines' => function () {
        $lines = StreamProgress::statsLines(['runs' => 2, 'tools' => 5, 'failed' => 1, 'model' => 'm', 'session' => 's',
            'tokens' => ['input' => 100, 'output' => 20, 'cache_read' => 50, 'cache_write' => 0, 'total' => 120]], 252.4, null);
        check($lines[0] === 'wall time       4m 12s', $lines[0]);
        check(strpos($lines[2], 'model requests  unknown') === 0, $lines[2]);
        check($lines[3] === 'tool calls      5 (1 failed)', $lines[3]);
        check(strpos($lines[4], 'Hermes runs     2 (the question and 1 more started by the halt guard)') === 0, $lines[4]);
        check(strpos($lines[5], 'in 100, out 20, cache read 50, cache write 0, total 120') !== false, $lines[5]);
        check(StreamProgress::statsLines(['runs' => 1, 'tools' => 0, 'failed' => 0, 'model' => '', 'session' => '', 'tokens' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'total' => 0]], 7, 3)[2] === 'model requests  3   (turns, helper agents included)', 'requests');
        check(strpos(implode("\n", $lines), '€') === false && stripos(implode("\n", $lines), 'credit') === false, 'no money figure');
    },
];
