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
if (!function_exists('haltRunTree')) {
    /** A throwaway tree with a copy of lib/ (HaltRun's own dependency chain) and local/config.php pointed at the fake model. */
    function haltRunTree($modelPort)
    {
        $tmp = sys_get_temp_dir() . '/pk-haltrun-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies/lib';
        @mkdir("$tmp/pikkumies/lib", 0700, true);
        foreach ((array) glob("$src/*.php") as $f) {
            copy($f, "$tmp/pikkumies/lib/" . basename($f));
        }
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/key", "key=k\n");
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export(['provider' =>
            ['endpoint' => "http://127.0.0.1:$modelPort/v1", 'model' => 'm', 'keyFile' => "$tmp/key"]], true) . ";\n");
        return $tmp;
    }

    /** A fixture "hermes": refuses --usage-file after chat (exit 2, as Hermes v0.22.2 does), prints plan.json[call index] "output" (last entry repeats) plus a stream-json result event with its "session_id", exits its "code". Every call is logged to calls.jsonl. */
    function haltRunFakeHermes($tree)
    {
        $path = "$tree/fake-hermes";
        $body = '#!' . PHP_BINARY . "\n<?php\n"
            . '$dir = getenv("PK_FAKE_HERMES_DIR");' . "\n"
            . '$callsFile = "$dir/calls.jsonl";' . "\n"
            . '$n = is_file($callsFile) ? count(array_filter(explode("\n", (string) file_get_contents($callsFile)), "strlen")) : 0;' . "\n"
            . '$plan = json_decode((string) @file_get_contents("$dir/plan.json"), true);' . "\n"
            . '$plan = is_array($plan) ? $plan : [];' . "\n"
            . '$step = isset($plan[$n]) ? $plan[$n] : ($plan ? end($plan) : ["output" => "", "code" => 0, "session_id" => "fakesess"]);' . "\n"
            . 'file_put_contents($callsFile, json_encode(["argv" => array_slice($argv, 1)]) . "\n", FILE_APPEND);' . "\n"
            // Like Hermes v0.22.2: after the `chat` subcommand an option chat does not define is an argparse error.
            . '$chatAt = array_search("chat", $argv, true);' . "\n"
            . 'if ($chatAt !== false) {' . "\n"
            . '    foreach (array_slice($argv, $chatAt + 1) as $a) {' . "\n"
            . '        if (strpos($a, "--usage-file") === 0) { fwrite(STDERR, "hermes: error: unrecognized arguments: $a\n"); exit(2); }' . "\n"
            . '    }' . "\n"
            . '}' . "\n"
            . 'echo isset($step["output"]) ? $step["output"] : "";' . "\n"
            // A stream-json run ends with a result event that carries the session id.
            . 'if (!empty($step["session_id"])) { echo "\n" . json_encode(["type" => "result", "session_id" => $step["session_id"], "exit_code" => 0]) . "\n"; }' . "\n"
            . 'exit(isset($step["code"]) ? (int) $step["code"] : 0);' . "\n";
        file_put_contents($path, $body);
        chmod($path, 0755);
        return $path;
    }

    /** A fake OpenAI-compatible endpoint whose reply is replies.json[request index] (last entry repeats); requests.jsonl counts calls. */
    function haltRunFakeModel($dir)
    {
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/router.php", '<?php
$d = __DIR__;
$n = is_file("$d/requests.jsonl") ? count(array_filter(explode("\n", (string) file_get_contents("$d/requests.jsonl")), "strlen")) : 0;
file_put_contents("$d/requests.jsonl", json_encode(["n" => $n]) . "\n", FILE_APPEND);
$replies = json_decode((string) @file_get_contents("$d/replies.json"), true);
$replies = is_array($replies) ? $replies : [];
$reply = isset($replies[$n]) ? $replies[$n] : ($replies ? end($replies) : "");
header("Content-Type: application/json");
echo json_encode(["choices" => [["message" => ["role" => "assistant", "content" => $reply]]]]);
');
        for ($try = 0; $try < 5; $try++) {
            $port = testFreePort();
            $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port router.php",
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($s) {
                    fclose($s);
                    if (proc_get_status($p)['running']) {
                        return [$p, $port];
                    }
                    break;
                }
                usleep(100000);
            }
            proc_terminate($p);
        }
        throw new Exception('fake model did not start');
    }

    /** Seeds the halt-snapshot HaltRun::run() will read, optionally pre-marking it as already judged, then runs HaltRun::run() in a subprocess. [code, stdout]. */
    function haltRunDrive($tree, $hermesBin, $sessionId, $alreadyJudged = false)
    {
        $script = "$tree/driver-" . mt_rand() . '.php';
        $body = "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltState.php", true) . ";\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n"
            . 'HaltState::writeSnapshot(' . var_export($sessionId, true) . ", 'please fix the thing', '[user] please fix the thing');\n";
        if ($alreadyJudged) {
            $body .= '$s = HaltState::load(' . var_export($sessionId, true) . ');' . "\n"
                . 'HaltState::markJudged(' . var_export($sessionId, true) . ", \$s['turn_started']);\n";
        }
        $body .= '$code = HaltRun::run(' . var_export($hermesBin, true) . ", ['chat', '--query=please fix the thing']);\n"
            . 'fwrite(STDOUT, "\nHALT_EXIT:" . $code . "\n");' . "\n";
        file_put_contents($script, $body);
        list(, $out) = runPhp($script, []);
        return [(int) (preg_match('/HALT_EXIT:(-?\d+)/', $out, $m) ? $m[1] : -999), $out];
    }

    function haltRunCalls($tree)
    {
        return is_file("$tree/calls.jsonl") ? count(array_filter(explode("\n", file_get_contents("$tree/calls.jsonl")), 'strlen')) : 0;
    }
}

$continueReply = "Q1: no\nQ2: yes\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no";
$doneReply = "Q1: no\nQ2: yes\nQ3: yes\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no";
$chatReply = "Q1: yes\nQ2: no\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no";

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
];
