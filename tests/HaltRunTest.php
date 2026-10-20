<?php
/**
 * lib/HaltRun.php, the one-shot halt-guard loop (ADR-0047): bounded continues/wrap-up, no double
 * judging once the live hook already judged, and the bin/pikkumies TTY gate that only ever calls it
 * for a seeded prompt without a terminal on stdin or stdout. HaltRun::run() itself runs against a
 * fake "hermes" (rejects --usage-file after chat, prints a scripted answer and a stream-json result event) and a fake light-model endpoint
 * (php -S), in a throwaway pikkumies/lib copy so nothing here touches this checkout's real local/.
 * A real TTY cannot be allocated in this harness, so "never loops when interactive" is checked the
 * way tests/LaunchTest.php already checks other TTY-only behaviour: against the bin/pikkumies source.
 */
require_once __DIR__ . '/haltRunSupport.php';

list($continueReply, $doneReply, $chatReply) = haltRunReplies();

return [
    'bin/pikkumies: only a seeded prompt (chat) without a TTY on stdin or stdout reaches HaltRun; interactive runs keep the plain passthru' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'run':(.*?)\n    case '/s", $bin, $m) === 1, 'could not isolate the run case');
        $run = $m[1];
        check(preg_match('/if\s*\(isset\(\$passArgs\[0\]\)\s*&&\s*\$passArgs\[0\]\s*===\s*\'chat\'\s*&&\s*\(!Launch::isTty\(STDIN\)\s*\|\|\s*!Launch::isTty\(STDOUT\)\)/', $run) === 1,
            'the HaltRun gate must check passArgs[0]===chat and stdin-or-stdout not a tty: ' . $run);
        check(strpos($run, 'exit(HaltRun::run(hermesBin(), $passArgs));') !== false, 'the gate must call HaltRun::run and exit with its code');
        check(preg_match('/HaltRun::run.*?\n.*?passthru\(escapeshellarg\(hermesBin/s', $run) === 1, 'the plain passthru must still follow, for interactive sessions the gate skips');
        check(strpos($run, "empty(Config::load()['halt']['disabled'])") !== false, 'the gate must respect halt.disabled');
    },
    'HaltRun::run: with no usable usage file or session id, runs once and returns that exit code, nothing else' => function () use ($continueReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode([$continueReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => 'answer', 'code' => 0, 'session_id' => '']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'sess-nosession');
            check($code === 0, 'exit code must be the single call\'s own code: ' . $out);
            check(haltRunCalls($tree) === 1, 'an unusable session id must never trigger a resume: ' . haltRunCalls($tree) . ' calls');
            check(!is_file("$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl'), 'nothing should have been judged at all');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun::run: a session with no matching snapshot returns at once, without asking the model' => function () use ($continueReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode([$continueReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            // The fake hermes reports a DIFFERENT session id than the one halt-snapshot wrote for.
            file_put_contents("$tree/plan.json", json_encode([['output' => 'answer', 'code' => 0, 'session_id' => 'sess-other']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'sess-seeded');
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 1, 'no snapshot for the reported session must stop after the first call: ' . haltRunCalls($tree));
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 0, 'the model must never be asked with no snapshot to judge against');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun::run: a session the live hook already judged is never re-judged (no resume, no model call)' => function () use ($continueReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode([$continueReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => 'answer', 'code' => 0, 'session_id' => 'fakesess']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess', true);
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 1, 'an already-judged turn must never be resumed: ' . haltRunCalls($tree) . ' calls');
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 0, 'an already-judged turn must never ask the model again');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun::run: a persistently CONTINUE verdict stops after exactly 3 resumes (4 calls total), never forcing a wrap-up' => function () use ($continueReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode([$continueReply])); // repeats for every request
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => 'partial', 'code' => 0, 'session_id' => 'fakesess']])); // repeats for every call
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess');
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 4, 'expected 1 initial + 3 bounded continues = 4 hermes calls, got ' . haltRunCalls($tree));
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 4, 'expected 4 judged passes (one per call)');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun::run: 2 continues then a wrap-up then a terminal verdict stop the loop; the resume messages are the fixed sentences' => function () use ($continueReply, $doneReply, $chatReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode([$continueReply, $continueReply, $doneReply, $chatReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => 'partial', 'code' => 0, 'session_id' => 'fakesess']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess');
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 4, 'expected 1 initial + 2 continues + 1 wrap-up = 4 calls, got ' . haltRunCalls($tree));
            $calls = array_values(array_filter(array_map(function ($l) {
                return json_decode($l, true);
            }, explode("\n", file_get_contents("$tree/calls.jsonl")))));
            $resumeQuery = function ($argv) {
                foreach ($argv as $a) {
                    if (strpos($a, '--query=') === 0) {
                        return substr($a, strlen('--query='));
                    }
                }
                return null;
            };
            check(strpos($resumeQuery($calls[1]['argv']), HaltJudge::CONTINUE_SENTENCES[3]) !== false, 'first resume must carry the Q3 fixed sentence: ' . $resumeQuery($calls[1]['argv']));
            check($resumeQuery($calls[3]['argv']) === HaltJudge::WRAPUP, 'the fourth call must be the one wrap-up resume: ' . $resumeQuery($calls[3]['argv']));
            check($calls[0]['argv'][0] === 'chat' && strpos(implode(' ', $calls[0]['argv']), '--resume=') === false, 'the first call must be the seeded prompt, not a resume: ' . json_encode($calls[0]['argv']));
            foreach ([1, 2, 3] as $i) {
                check(in_array('--resume=fakesess', $calls[$i]['argv'], true), "call $i must resume the same session: " . json_encode($calls[$i]['argv']));
            }
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    // Antipatterns (ADR-0088): lib/HaltRun.php calls Antipatterns::forTurn()/apply() right after HaltJudge::evaluate() every judged pass.
    'HaltRun + Antipatterns: a chat verdict (no message of its own) with a flagged id gets exactly one resume carrying resumeMessage(); the check never runs twice inside its interval' => function () use ($chatReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTreeWithAntipatterns($port);
        try {
            $flagged = 'the agent replied: load of 40.02 is basically pegging every one of the four cpu cores to the max';
            file_put_contents("$fakeModel/replies.json", json_encode([$chatReply, 'load-is-not-cpu | pegging every one of the four cpu cores', $chatReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => $flagged, 'code' => 0, 'session_id' => 'fakesess']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess');
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 2, 'expected 1 initial + exactly 1 antipatterns resume = 2 hermes calls, got ' . haltRunCalls($tree));
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 3,
                'expected 3 model requests (judge, antipatterns, judge again) -- a 4th would mean the check ran twice inside its interval');
            $calls = array_values(array_filter(array_map(function ($l) {
                return json_decode($l, true);
            }, explode("\n", file_get_contents("$tree/calls.jsonl")))));
            $resumeQuery = function ($argv) {
                foreach ($argv as $a) {
                    if (strpos($a, '--query=') === 0) {
                        return substr($a, strlen('--query='));
                    }
                }
                return null;
            };
            check($resumeQuery($calls[1]['argv']) === Antipatterns::resumeMessage(['load-is-not-cpu']), 'the resume query must be exactly resumeMessage(): ' . $resumeQuery($calls[1]['argv']));
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun + Antipatterns: antipatterns.resume=false suppresses the extra resume even though the check still flags an id' => function () use ($chatReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTreeWithAntipatterns($port);
        try {
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['provider' =>
                ['endpoint' => "http://127.0.0.1:$port/v1", 'model' => 'm', 'keyFile' => "$tree/key"], 'antipatterns' => ['resume' => false]], true) . ";\n");
            $flagged = 'the agent replied: load of 40.02 is basically pegging every one of the four cpu cores to the max';
            file_put_contents("$fakeModel/replies.json", json_encode([$chatReply, 'load-is-not-cpu | pegging every one of the four cpu cores']));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => $flagged, 'code' => 0, 'session_id' => 'fakesess']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess');
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 1, 'antipatterns.resume=false must suppress the extra resume even with a flagged id: ' . haltRunCalls($tree) . ' calls');
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 2, 'judge + antipatterns = 2 model requests, no third');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun + Antipatterns: a wrap-up verdict with a flagged id gets the lines appended to the SAME resume, never a second one' => function () use ($doneReply, $chatReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTreeWithAntipatterns($port);
        try {
            $flagged = 'the agent replied: load of 40.02 is basically pegging every one of the four cpu cores to the max';
            file_put_contents("$fakeModel/replies.json", json_encode([$doneReply, 'load-is-not-cpu | pegging every one of the four cpu cores', $chatReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['output' => $flagged, 'code' => 0, 'session_id' => 'fakesess']]));
            $hermes = haltRunFakeHermes($tree);
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess');
            check($code === 0, 'exit code: ' . $out);
            check(haltRunCalls($tree) === 2, 'expected 1 initial + exactly 1 wrap-up resume (never two) = 2 calls, got ' . haltRunCalls($tree));
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 3, 'judge + antipatterns + judge again = 3 model requests');
            $calls = array_values(array_filter(array_map(function ($l) {
                return json_decode($l, true);
            }, explode("\n", file_get_contents("$tree/calls.jsonl")))));
            $resumeQuery = function ($argv) {
                foreach ($argv as $a) {
                    if (strpos($a, '--query=') === 0) {
                        return substr($a, strlen('--query='));
                    }
                }
                return null;
            };
            $expect = HaltJudge::WRAPUP . "\n" . Antipatterns::lines(['load-is-not-cpu']);
            check($resumeQuery($calls[1]['argv']) === $expect, 'the one resume must carry the wrap-up text plus the lines, not a second resume: ' . $resumeQuery($calls[1]['argv']));
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
];
