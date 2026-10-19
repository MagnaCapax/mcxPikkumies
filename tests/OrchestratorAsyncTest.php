<?php
/** Interval hook units through the orchestrator and the worker (ADR-0067): when they run, what they see, and the detached start. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'async: a unit started from post_tool_call sees the change made in the same turn on its first run, and the orchestrator answers before the unit finishes' => function () {
        $tree = asyncTree(['guard/watch' => ['wake' => 'change']]);
        try {
            file_put_contents("$tree/pikkumies/hooks/guard/watch/sleep", '3');
            asyncRun($tree, 'post_tool_call', asyncTool('s1', 'terminal', ['command' => 'ls -la']));
            check(!is_file(asyncFile($tree, 'guard/watch', 's1', '.stamp')), 'a read woke a unit that wakes on changes');
            list($out, $took) = asyncRun($tree, 'post_tool_call', asyncTool('s1', 'terminal', ['command' => 'rm -f /tmp/x']));
            check($out === '' && $took < 2, "the orchestrator must answer at once while the unit sleeps: {$took}s");
            check(asyncWait(function () use ($tree) {
                return is_file("$tree/pikkumies/hooks/guard/watch/done");
            }, 10), 'the unit never ran');
            $runs = asyncRuns($tree, 'guard/watch');
            $tools = array_column($runs[0]['records'], 'args');
            check(count($runs) === 1 && $runs[0]['unit'] === 'guard/watch' && $runs[0]['first_run'] === true && $runs[0]['session'] === sha1('s1'), 'stdin: ' . json_encode($runs));
            check(count($tools) === 2 && strpos($tools[1], 'rm -f') !== false && $runs[0]['records'][1]['change'] === true && $runs[0]['records'][0]['change'] === false, 'records: ' . json_encode($runs[0]['records']));
            check(strpos(json_encode($runs), 'SECRET OUTPUT') === false, 'a tool\'s output reached the unit');
            // Its interval passed, but only a read came since: nothing of its wake kinds, so no run.
            file_put_contents(asyncFile($tree, 'guard/watch', 's1', '.stamp'), (string) (time() - 61));
            asyncRun($tree, 'post_tool_call', asyncTool('s1', 'terminal', ['command' => 'cat /etc/hostname']));
            asyncWait(function () { return false; }, 0.8);
            check(count(asyncRuns($tree, 'guard/watch')) === 1 && (int) file_get_contents(asyncFile($tree, 'guard/watch', 's1', '.stamp')) < time() - 50, 'a read started a unit that wakes on changes');
        } finally {
            asyncWait(function () { return false; }, 0.3);
            testRmTree($tree);
        }
    },
    'async: the stamp allows one run per interval; with the stamp backdated it runs again and gets only the new records; pre_llm_call starts nothing' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            $done = function ($n) use ($tree) {
                return asyncWait(function () use ($tree, $n) {
                    return count(asyncRuns($tree, 'guard/watch')) >= $n && is_file(asyncFile($tree, 'guard/watch', 's2', '.cursor'));
                }, 10);
            };
            asyncRun($tree, 'pre_llm_call', ['session_id' => 's2', 'extra' => ['user_message' => 'please list files', 'is_first_turn' => true, 'turn_id' => 't1']]);
            asyncWait(function () { return false; }, 0.5);
            check(asyncRuns($tree, 'guard/watch') === [], 'pre_llm_call started a run');
            asyncRun($tree, 'post_tool_call', asyncTool('s2', 'terminal', ['command' => 'ls']));
            check($done(1), 'the first run never finished');
            asyncRun($tree, 'post_tool_call', asyncTool('s2', 'terminal', ['command' => 'df -h']));
            asyncWait(function () { return false; }, 0.8);
            check(count(asyncRuns($tree, 'guard/watch')) === 1, 'a second run inside the interval');
            file_put_contents(asyncFile($tree, 'guard/watch', 's2', '.stamp'), (string) (time() - 61));
            asyncRun($tree, 'post_tool_call', asyncTool('s2', 'terminal', ['command' => 'du -sh .']));
            check($done(2), 'no run after the stamp was backdated');
            $runs = asyncRuns($tree, 'guard/watch');
            check(array_column($runs[0]['records'], 'kind') === ['user', 'tool'] && $runs[0]['records'][0]['text'] === 'please list files', 'first window: ' . json_encode($runs[0]['records']));
            check(array_column($runs[1]['records'], 'seq') === [3, 4] && $runs[1]['first_run'] === false, 'second window must hold only the new records: ' . json_encode($runs[1]['records']));
        } finally {
            asyncWait(function () { return false; }, 0.3);
            testRmTree($tree);
        }
    },
    'async: a one-shot run (PIKKUMIES_ONESHOT) records at post_llm_call but starts nothing there; an interactive one starts the reply-woken unit' => function () {
        $tree = asyncTree(['review/check' => ['wake' => 'reply']]);
        try {
            $turn = function ($s) {
                return ['session_id' => $s, 'extra' => ['user_message' => 'fix the config', 'assistant_response' => 'Done, I changed it.', 'turn_id' => 't1',
                    'conversation_history' => [['role' => 'user', 'content' => 'fix the config'], ['role' => 'assistant', 'content' => 'Looking.'], ['role' => 'assistant', 'content' => 'Done, I changed it.']]]];
            };
            asyncRun($tree, 'post_llm_call', $turn('one'), ['PIKKUMIES_ONESHOT' => '1']);
            asyncWait(function () { return false; }, 0.8);
            check(asyncRuns($tree, 'review/check') === [] && !is_file(asyncFile($tree, 'review/check', 'one', '.stamp')), 'a one-shot post_llm_call started a run');
            check(is_file(asyncFile($tree, 'review/check', 'one', '.wake')), 'the one-shot turn must still be recorded and wake the unit for a later caller');
            $spool = (string) file_get_contents("$tree/pikkumies/local/state/async/transcript/" . sha1('one') . '.jsonl');
            check(substr_count($spool, '"kind":"assistant"') === 1 && substr_count($spool, '"kind":"reply"') === 1, 'one-shot records: ' . $spool);
            asyncRun($tree, 'post_llm_call', $turn('two'));
            check(asyncWait(function () use ($tree) {
                return count(asyncRuns($tree, 'review/check')) === 1;
            }, 10), 'an interactive post_llm_call did not start the reply-woken unit');
        } finally {
            asyncWait(function () { return false; }, 0.3);
            testRmTree($tree);
        }
    },
    'async: with both worker slots held the worker exits and sets the unit to retry in 30 s; after a kick no lock is left with the orchestrator' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            $dir = "$tree/pikkumies/local/state/async";
            @mkdir($dir, 0700, true);
            $held = [];
            foreach ([1, 2] as $i) {
                $held[$i] = fopen("$dir/slot-$i.lock", 'c');
                flock($held[$i], LOCK_EX);
            }
            asyncRun($tree, 'post_tool_call', asyncTool('s3', 'terminal', ['command' => 'ls']));
            $stamp = asyncFile($tree, 'guard/watch', 's3', '.stamp');
            check(asyncWait(function () use ($stamp) {
                $t = (int) @file_get_contents($stamp);
                return $t > 0 && $t < time() - 20;
            }, 10), 'the stamp was not set back for a retry: ' . @file_get_contents($stamp));
            check(abs((int) file_get_contents($stamp) - (time() - 60 + 30)) <= 3 && asyncRuns($tree, 'guard/watch') === [], 'retry stamp or a run despite busy slots');
            foreach ($held as $h) {
                flock($h, LOCK_UN);
                fclose($h);
            }
            file_put_contents("$tree/pikkumies/hooks/guard/watch/sleep", '2');
            file_put_contents($stamp, '0');
            asyncRun($tree, 'post_tool_call', asyncTool('s3', 'terminal', ['command' => 'df']));
            // While the worker runs, everything the orchestrator touched is free; only the worker's own run lock and slot are held.
            check(asyncWait(function () use ($tree) {
                return count(asyncRuns($tree, 'guard/watch')) === 1;
            }, 10), 'no run after the slots were freed');
            $locks = array_merge([asyncFile($tree, 'guard/watch', 's3', '.stamp'), asyncFile($tree, 'guard/watch', 's3', '.plock'), "$dir/transcript/" . sha1('s3') . '.jsonl']);
            foreach ($locks as $l) {
                $fh = fopen($l, 'c');
                check(flock($fh, LOCK_EX | LOCK_NB), "$l is still locked while the worker runs");
                flock($fh, LOCK_UN);
                fclose($fh);
            }
            $run = fopen(asyncFile($tree, 'guard/watch', 's3', '.lock'), 'c');
            check(!flock($run, LOCK_EX | LOCK_NB), 'the worker does not hold its run lock');
            fclose($run);
            $free = 0;
            foreach ([1, 2] as $i) {
                $fh = fopen("$dir/slot-$i.lock", 'c');
                $free += flock($fh, LOCK_EX | LOCK_NB) ? 1 : 0;
                fclose($fh);
            }
            check($free === 1, "the worker must hold exactly one slot, free: $free");
        } finally {
            asyncWait(function () use ($tree) { return is_file("$tree/pikkumies/hooks/guard/watch/done"); }, 5);
            testRmTree($tree);
        }
    },
    'async: {"skip": true} keeps the cursor, so the next run sees the earlier records too, and the next record of its wake kinds starts it at once' => function () {
        $tree = asyncTree(['search/review' => ['wake' => 'reply', 'body' => asyncRecorder() . ' $d = json_decode($in, true); if (count($d["records"]) < 3) { echo json_encode(["skip" => true]); }']]);
        try {
            $turn = function ($n, $first) {
                return ['session_id' => 's4', 'extra' => ['user_message' => "request $n", 'assistant_response' => "answer $n", 'turn_id' => "t$n",
                    'conversation_history' => [['role' => 'user', 'content' => "request $n"], ['role' => 'assistant', 'content' => "answer $n"]]]];
            };
            asyncRun($tree, 'pre_llm_call', ['session_id' => 's4', 'extra' => ['user_message' => 'request 1', 'is_first_turn' => true, 'turn_id' => 't1']]);
            asyncRun($tree, 'post_llm_call', $turn(1, true));
            check(asyncWait(function () use ($tree) { return count(asyncRuns($tree, 'search/review')) === 1; }, 10), 'turn 1 did not start the unit');
            check(asyncWait(function () use ($tree) { return (int) @file_get_contents(asyncFile($tree, 'search/review', 's4', '.stamp')) < time() - 50; }, 5), 'a skip must make the unit due again');
            check(!is_file(asyncFile($tree, 'search/review', 's4', '.cursor')), 'a skip must not move the cursor');
            asyncRun($tree, 'pre_llm_call', ['session_id' => 's4', 'extra' => ['user_message' => 'request 2', 'is_first_turn' => false, 'turn_id' => 't2']]);
            asyncRun($tree, 'post_llm_call', $turn(2, false));
            check(asyncWait(function () use ($tree) { return count(asyncRuns($tree, 'search/review')) === 2 && is_file(asyncFile($tree, 'search/review', 's4', '.cursor')); }, 10), 'turn 2 did not run');
            $second = asyncRuns($tree, 'search/review')[1]['records'];
            $users = array_values(array_filter($second, function ($r) { return $r['kind'] === 'user'; }));
            check(array_column($users, 'text') === ['request 1', 'request 2'] && $users[0]['first_turn'] === true, 'turn 2 must see both requests: ' . json_encode($second));
        } finally {
            asyncWait(function () { return false; }, 0.3);
            testRmTree($tree);
        }
    },
    'async: without a timeout command on PATH nothing is started, no stamp is written, and the hook log says so' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            asyncRun($tree, 'post_tool_call', asyncTool('s5', 'terminal', ['command' => 'ls']), ['PATH' => '/nonexistent']);
            check(!is_file(asyncFile($tree, 'guard/watch', 's5', '.stamp')), 'a stamp was written although nothing could start');
            $log = (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl');
            check(strpos($log, '"outcome":"async-start-failed"') !== false && strpos($log, '"unit":"guard/watch"') !== false, 'log: ' . $log);
        } finally {
            testRmTree($tree);
        }
    },
    'async: parallel tool calls start one worker per interval: the stamp is checked again under its lock, so no second worker finds the run busy' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            file_put_contents("$tree/pikkumies/hooks/guard/watch/sleep", '1');
            $payload = json_encode(asyncTool('par', 'terminal', ['command' => 'rm -f /tmp/nothing-here']));
            $procs = [];
            for ($i = 0; $i < 8; $i++) {
                $procs[$i] = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/pikkumies/hooks/orchestrator.php") . ' post_tool_call', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes[$i]);
                fwrite($pipes[$i][0], $payload);
                fclose($pipes[$i][0]);
            }
            foreach ($procs as $i => $p) {
                stream_get_contents($pipes[$i][1]);
                proc_close($p);
            }
            check(asyncWait(function () use ($tree) { return is_file("$tree/pikkumies/hooks/guard/watch/done"); }, 10), 'no run');
            asyncWait(function () { return false; }, 1.5);
            $log = (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl');
            check(count(asyncRuns($tree, 'guard/watch')) === 1 && strpos($log, 'async-busy') === false, 'runs: ' . count(asyncRuns($tree, 'guard/watch')) . '; log: ' . $log);
            check((int) file_get_contents(asyncFile($tree, 'guard/watch', 'par', '.stamp')) >= time() - 10, 'a busy worker set the stamp back for a retry');
        } finally {
            asyncWait(function () { return false; }, 0.3);
            testRmTree($tree);
        }
    },
];
