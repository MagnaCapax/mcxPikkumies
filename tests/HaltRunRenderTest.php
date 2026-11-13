<?php
/** lib/HaltRun.php with a StreamProgress renderer (ADR-0109): stdout carries only answers, the steps and halt markers go to stderr. */
require_once __DIR__ . '/haltRunSupport.php';

if (!function_exists('haltRunRenderDrive')) {
    /** Like haltRunDrive, with a renderer and stderr kept: [code, stdout, stderr]. */
    function haltRunRenderDrive($tree, $hermesBin, $sessionId, $alreadyJudged, array $passArgs)
    {
        $script = "$tree/rdriver-" . mt_rand() . '.php';
        $body = "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltState.php", true) . ";\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n"
            . 'HaltState::writeSnapshot(' . var_export($sessionId, true) . ", 'please fix the thing', '[user] please fix the thing');\n";
        if ($alreadyJudged) {
            $body .= '$s = HaltState::load(' . var_export($sessionId, true) . ');' . "\n"
                . 'HaltState::markJudged(' . var_export($sessionId, true) . ", \$s['turn_started']);\n";
        }
        $body .= '$r = new StreamProgress(true, true, null);' . "\n"
            . 'exit(HaltRun::run(' . var_export($hermesBin, true) . ', ' . var_export($passArgs, true) . ", \$r));\n";
        file_put_contents($script, $body);
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /** A fake gateway: listens on $home/gateway.sock and answers "starting" to the first $starting identify requests, then "ready". */
    function haltRunFakeGateway($home, $starting)
    {
        file_put_contents("$home/gateway-server.php", '<?php $s = stream_socket_server("unix://" . $argv[1], $e, $m); echo "up\n"; $n = 0; '
            . 'while ($c = stream_socket_accept($s, 60)) { fgets($c); fwrite($c, json_encode(["state" => $n++ < (int) $argv[2] ? "starting" : "ready"]) . "\n"); fclose($c); }');
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$home/gateway-server.php") . ' ' . escapeshellarg("$home/gateway.sock") . ' ' . (int) $starting, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(fgets($pipes[1]) === "up\n", 'the fake gateway is listening');
        return $p;
    }

    /** The events of a run Hermes ended at once with $error: no step, no text, tokens as given. */
    function haltRunRenderFailed($error, $tokens = 0, $sessionId = 'fakesess')
    {
        return [['type' => 'system', 'subtype' => 'init', 'model' => 'm', 'session_id' => $sessionId],
            ['type' => 'result', 'session_id' => $sessionId, 'exit_code' => 1, 'text' => '', 'error' => $error, 'tokens' => ['input' => $tokens, 'output' => 0, 'total' => $tokens]]];
    }

    function haltRunRenderEvents($answer, $sessionId = 'fakesess')
    {
        return [['type' => 'system', 'subtype' => 'init', 'model' => 'm', 'session_id' => $sessionId],
            ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => 'df -h']],
            ['type' => 'tool_result', 'name' => 'terminal', 'is_error' => false],
            ['type' => 'text', 'text' => $answer],
            ['type' => 'result', 'session_id' => $sessionId, 'exit_code' => 0, 'text' => $answer, 'tokens' => ['input' => 1, 'output' => 1, 'total' => 2]]];
    }
}

list($continueReply, $doneReply, $chatReply) = haltRunReplies();

return [
    'HaltRun render: an already-judged run prints its steps on stderr and only the answer on stdout' => function () {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunRenderEvents('41 GB free'), 'code' => 0]]));
            list($code, $out, $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', true, ['chat', '--query=disk?', '--format=stream-json']);
            check($code === 0, 'exit ' . $code . ' ' . $err);
            check($out === "41 GB free\n", 'stdout must be the answer only: ' . json_encode($out));
            check(preg_match('/^\[\d\d:\d\d:\d\d\] ran df -h$/m', $err) === 1, 'the step line: ' . $err);
            check(strpos($err, '{"type"') === false, 'raw stream-json leaked to stderr: ' . $err);
            check(haltRunCalls($tree) === 1, 'one Hermes run');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun render: a continue is announced on stderr, both answers reach stdout with a blank line, the wrap-up text goes to stderr' => function () use ($continueReply, $doneReply, $chatReply) {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode([$continueReply, $doneReply, $chatReply]));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([
                ['events' => haltRunRenderEvents('first part'), 'code' => 0],
                ['events' => haltRunRenderEvents('the rest'), 'code' => 0],
                ['events' => haltRunRenderEvents("noted for next time \x1b]0;owned\x07 end"), 'code' => 0],
            ]));
            list($code, $out, $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', false, ['chat', '--query=please fix the thing', '--format=stream-json']);
            check($out === "first part\n\nthe rest\n", 'stdout: ' . json_encode($out) . ' stderr: ' . $err);
            check(strpos($err, 'Not finished, continuing (1 of 3)') !== false, 'continue marker: ' . $err);
            check(strpos($err, 'Wrapping up') !== false && strpos($err, 'noted for next time') !== false, 'wrap-up on stderr: ' . $err);
            check(strpos($err, "\x1b") === false && strpos($err, "\x07") === false, 'the wrap-up text reached stderr with its escape: ' . json_encode($err));
            check(haltRunCalls($tree) === 3, 'three Hermes runs: ' . haltRunCalls($tree));
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun render: with the halt guard off Hermes runs once, unjudged, still rendered; a Hermes that cannot start prints only the start error' => function () {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            $cfg = "$tree/pikkumies/local/config.php";
            $c = include $cfg;
            $c['halt'] = ['disabled' => true];
            file_put_contents($cfg, '<?php return ' . var_export($c, true) . ";\n");
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunRenderEvents('free: 41 GB'), 'code' => 0]]));
            list($code, $out, $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', false, ['chat', '--query=please fix the thing', '--format=stream-json']);
            check($code === 0 && $out === "free: 41 GB\n" && haltRunCalls($tree) === 1, 'halt off: ' . json_encode([$code, $out, $err]));
            check(preg_match('/^\[\d\d:\d\d:\d\d\] ran df -h$/m', $err) === 1 && strpos($err, 'continuing') === false, 'still rendered, never judged: ' . $err);
            list($code, $out, $err) = haltRunRenderDrive($tree, "$tree/no-such-hermes", 'fakesess', true, ['chat', '--query=x', '--format=stream-json']);
            check($out === '' && strpos($err, 'Could not start Hermes') !== false && strpos($err, 'ended without finishing') === false, 'start failure: ' . json_encode([$code, $out, $err]));
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun render: Hermes ending without a result says so on stderr and keeps the exit code; the fake Hermes refuses the launcher switches' => function () {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        try {
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            file_put_contents("$tree/plan.json", json_encode([['events' => [['type' => 'system', 'subtype' => 'init', 'session_id' => 'fakesess'], ['type' => 'text', 'text' => 'half']], 'code' => 3]]));
            list($code, $out, $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', true, ['chat', '--query=x', '--format=stream-json']);
            check($code === 3, 'exit code kept: ' . $code);
            check($out === "half\n" && strpos($err, 'Hermes ended without finishing (exit 3)') !== false, json_encode([$out, $err]));
            $hermes = haltRunFakeHermes($tree);
            list($rc) = runPhp($hermes, ['chat', '--query=x', '--stats']);
            check($rc === 2, 'the fake Hermes must refuse --stats like the real one would: ' . $rc);
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'HaltRun render: a run Hermes ends with the gateway deadline waits for the gateway, runs again, and prints one answer and no deadline error (GH #12)' => function () {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        $home = testTempDir('pk-gwhome');
        $gateway = null;
        $lock = null;
        try {
            // The gateway is still starting for the first look: it holds its lock and says so; the wait ends when it says ready.
            $lock = fopen("$home/gateway.lock", 'c');
            check(flock($lock, LOCK_EX | LOCK_NB), 'the test holds the gateway lock');
            $gateway = haltRunFakeGateway($home, 1);
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            putenv('HERMES_HOME=' . $home);
            file_put_contents("$tree/plan.json", json_encode([
                ['events' => haltRunRenderFailed('Gateway starting: deadline'), 'code' => 1],
                ['events' => haltRunRenderEvents('41 GB free'), 'code' => 0],
            ]));
            list($code, $out, $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', true, ['chat', '--query=disk?', '--format=stream-json']);
            check($code === 0, 'exit ' . $code . ' ' . $err);
            check($out === "41 GB free\n", 'one answer on stdout: ' . json_encode($out));
            check(strpos($err, 'Waiting for the agent gateway to finish starting') !== false, 'progress on stderr: ' . $err);
            check(strpos($err, 'deadline') === false && strpos($err, 'Hermes:') === false, 'no deadline error in the output: ' . $err);
            check(haltRunCalls($tree) === 2, 'the same command, run twice: ' . haltRunCalls($tree));
            $calls = array_map('json_decode', array_filter(explode("\n", (string) file_get_contents("$tree/calls.jsonl")), 'strlen'));
            check($calls[0]->argv === $calls[1]->argv, 'the identical command both times');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            putenv('HERMES_HOME');
            if (is_resource($lock)) {
                fclose($lock);
            }
            foreach ([$gateway, $proc] as $p) {
                if (is_resource($p)) {
                    proc_terminate($p, 9);
                    proc_close($p);
                }
            }
            testRmTree($tree);
            testRmTree($fakeModel);
            testRmTree($home);
        }
    },
    'HaltRun render: the deadline error is still printed, once and without a second run, when the gateway is gone, when the run spent tokens, or when the question comes from stdin' => function () {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        $home = testTempDir('pk-gwhome');
        try {
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            putenv('HERMES_HOME=' . $home);
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunRenderFailed('Gateway starting: deadline'), 'code' => 1]]));
            list($code, $out, $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', true, ['chat', '--query=disk?', '--format=stream-json']);
            check($code === 1 && $out === '' && substr_count($err, 'Hermes: Gateway starting: deadline') === 1, 'gone: the error is printed once: ' . json_encode([$code, $out, $err]));
            check(haltRunCalls($tree) === 1, 'gone: no second run: ' . haltRunCalls($tree));
            @unlink("$tree/calls.jsonl");
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunRenderFailed('Gateway starting: deadline', 5), 'code' => 1]]));
            list(, , $err) = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', true, ['chat', '--query=disk?', '--format=stream-json']);
            check(substr_count($err, 'Hermes: Gateway starting: deadline') === 1 && strpos($err, 'Waiting for') === false && haltRunCalls($tree) === 1, 'tokens spent: not the cold-start failure: ' . $err);
            $reads = new ReflectionMethod('HaltRun', 'readsStdin');
            $reads->setAccessible(true);
            check($reads->invoke(null, ['chat', '--query-file=-']) && $reads->invoke(null, ['chat', '--query-file', '-']), 'stdin forms are seen');
            check(!$reads->invoke(null, ['chat', '--query=-']) && !$reads->invoke(null, ['chat', '--query-file=/tmp/q']), 'a file or a literal dash question is not');
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            putenv('HERMES_HOME');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
            testRmTree($home);
        }
    },
];
