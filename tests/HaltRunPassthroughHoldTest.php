<?php
/**
 * lib/HaltRun.php for a caller's own `--format stream-json` with no renderer: `pikkumies run chat --query-file -
 * --format stream-json`, the form a support job asks with (GH #12, ADR-0113). bin/pikkumies routes it to
 * HaltRun::run(hermesBin(), $passArgs) with no renderer, so these tests call HaltRun::run the same way.
 */
require_once __DIR__ . '/haltRunSupport.php';

if (!function_exists('haltRunRawDrive')) {
    /** HaltRun::run() with no renderer in a subprocess, $stdin piped in, the turn already judged: [code, stdout, stderr]. */
    function haltRunRawDrive($tree, $hermesBin, array $passArgs, $stdin = '')
    {
        $script = "$tree/xdriver-" . mt_rand() . '.php';
        file_put_contents($script, "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltState.php", true) . ";\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n"
            . "HaltState::writeSnapshot('fakesess', 'disk?', '[user] disk?');\n"
            . "\$s = HaltState::load('fakesess');\nHaltState::markJudged('fakesess', \$s['turn_started']);\n"
            . 'exit(HaltRun::run(' . var_export($hermesBin, true) . ', ' . var_export($passArgs, true) . "));\n");
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /** The bytes the fake Hermes prints for a planned run: one JSON line per event. */
    function haltRunRawStream(array $events)
    {
        $s = '';
        foreach ($events as $e) {
            $s .= json_encode($e) . "\n";
        }
        return $s;
    }

    /** Runs $body($tree, $home) with a fake model, a fresh tree and HERMES_HOME set; cleans up after. */
    function haltRunRawCase(callable $body)
    {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        $home = testTempDir('pk-gwhome');
        try {
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            putenv('HERMES_HOME=' . $home);
            $body($tree, $home);
        } finally {
            putenv('PK_FAKE_HERMES_DIR');
            putenv('HERMES_HOME');
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
            testRmTree($home);
        }
    }
}

$supportArgs = ['chat', '--query-file', '-', '--format', 'stream-json']; // TicketJobRemote's ask, after `run`

return [
    'HaltRun passthrough: the support job\'s form is routed to HaltRun::run with no renderer, so the hold below is the one it gets (GH #12)' => function () use ($supportArgs) {
        $route = Launch::routeWithOptions(array_merge(['pikkumies', 'run'], $supportArgs));
        check($route === ['run', $supportArgs], 'the run passthrough, no options: ' . json_encode($route));
        check(!Launch::oneShotRender($supportArgs, null, false, false), 'not the render path');
        check(HaltRun::formatArgs($supportArgs) === ['--format=stream-json'], 'its format is read as stream-json');
    },
    'HaltRun passthrough: a stdin question that hits the gateway deadline waits, runs again with the same question, and the caller gets one clean stream (GH #12)' => function () use ($supportArgs) {
        haltRunRawCase(function ($tree, $home) use ($supportArgs) {
            $lock = fopen("$home/gateway.lock", 'c');
            check(flock($lock, LOCK_EX | LOCK_NB), 'the test holds the gateway lock');
            $gateway = haltRunFakeGateway($home, 1);
            try {
                file_put_contents("$tree/plan.json", json_encode([
                    ['events' => haltRunRenderFailed('Gateway starting: deadline'), 'code' => 1],
                    ['events' => haltRunRenderEvents('41 GB free'), 'code' => 0],
                ]));
                list($code, $out, $err) = haltRunRawDrive($tree, haltRunFakeHermes($tree), $supportArgs, "how much disk is free?\n");
                check($code === 0, 'exit ' . $code . ' ' . $err);
                check($out === haltRunRawStream(haltRunRenderEvents('41 GB free')), 'stdout is the second run\'s stream only: ' . json_encode($out));
                check(strpos($out, 'deadline') === false, 'no deadline result on stdout');
                check(strpos($err, 'Waiting for the agent gateway to finish starting') !== false, 'the wait is said on stderr: ' . $err);
                check(strpos($err, 'Hermes: Gateway') === false, 'no error line of ours: ' . $err);
                $calls = array_map(function ($l) { return json_decode($l, true); }, array_filter(explode("\n", (string) file_get_contents("$tree/calls.jsonl")), 'strlen'));
                check(count($calls) === 2 && $calls[0]['argv'] === $calls[1]['argv'] && !in_array('-', $calls[0]['argv'], true), 'two runs, the same kept file: ' . json_encode($calls));
                check($calls[0]['query_file_text'] === "how much disk is free?\n" && $calls[1]['query_file_text'] === "how much disk is free?\n", 'both runs got the question');
                check(glob("$tree/pikkumies/local/state/oneshot/question-*") === [], 'the kept question is removed');
            } finally {
                fclose($lock);
                proc_terminate($gateway, 9);
                proc_close($gateway);
            }
        });
    },
    'HaltRun passthrough: when the gateway is gone, or the run spent tokens, the stream is written unchanged and Hermes runs once' => function () use ($supportArgs) {
        haltRunRawCase(function ($tree) use ($supportArgs) {
            $failed = haltRunRenderFailed('Gateway starting: deadline');
            file_put_contents("$tree/plan.json", json_encode([['events' => $failed, 'code' => 1]]));
            list($code, $out, $err) = haltRunRawDrive($tree, haltRunFakeHermes($tree), $supportArgs, "disk?\n");
            check($code === 1 && $out === haltRunRawStream($failed), 'gone: the run\'s own stream, unchanged: ' . json_encode([$code, $out]));
            check(haltRunCalls($tree) === 1 && strpos($err, 'Waiting for') === false, 'gone: one run, no wait: ' . $err);
            check(substr_count($err, 'Pikkumies: Hermes: Gateway starting: deadline (the agent gateway stopped before it finished starting, so the question did not run)') === 1, 'gone: one line names what stopped: ' . $err);
            check(strpos($err, '❌') === false && strpos($err, 'FAILED') === false, 'the parse-only reader prints nothing itself: ' . $err);
            @unlink("$tree/calls.jsonl");
            $spent = haltRunRenderFailed('Gateway starting: deadline', 5);
            file_put_contents("$tree/plan.json", json_encode([['events' => $spent, 'code' => 1]]));
            list(, $out, $err) = haltRunRawDrive($tree, haltRunFakeHermes($tree), $supportArgs, "disk?\n");
            check($out === haltRunRawStream($spent) && haltRunCalls($tree) === 1, 'tokens spent: unchanged, one run: ' . json_encode($out));
            check(strpos($err, 'Waiting for') === false && strpos($err, 'did not run') === false, 'tokens spent: nothing added: ' . $err);
        });
    },
    'HaltRun passthrough: a working run streams byte for byte as before, and a plain-text passthrough is never held' => function () use ($supportArgs) {
        haltRunRawCase(function ($tree) use ($supportArgs) {
            $events = haltRunRenderEvents('41 GB free');
            file_put_contents("$tree/plan.json", json_encode([['events' => $events, 'code' => 0]]));
            list($code, $out) = haltRunRawDrive($tree, haltRunFakeHermes($tree), $supportArgs, "disk?\n");
            check($code === 0 && $out === haltRunRawStream($events) && haltRunCalls($tree) === 1, 'unchanged: ' . json_encode([$code, $out]));
            @unlink("$tree/calls.jsonl");
            $failed = haltRunRenderFailed('Gateway starting: deadline');
            file_put_contents("$tree/plan.json", json_encode([['events' => $failed, 'code' => 1]]));
            list(, $out, $err) = haltRunRawDrive($tree, haltRunFakeHermes($tree), ['chat', '--query=disk?']);
            check($out === haltRunRawStream($failed) && haltRunCalls($tree) === 1 && strpos($err, 'Waiting for') === false && strpos($err, 'Pikkumies:') === false, 'no --format: untouched: ' . $err);
        });
    },
    'HaltRun passthrough: "Gateway connection failed" with nothing done runs once more; twice in a row writes the last stream and says the cause (GH #12 finding 4)' => function () use ($supportArgs) {
        haltRunRawCase(function ($tree) use ($supportArgs) {
            $failed = haltRunRenderFailed('Gateway connection failed; no local fallback');
            file_put_contents("$tree/plan.json", json_encode([['events' => $failed, 'code' => 1], ['events' => haltRunRenderEvents('41 GB free'), 'code' => 0]]));
            list($code, $out, $err) = haltRunRawDrive($tree, haltRunFakeHermes($tree), $supportArgs, "disk?\n");
            check($code === 0 && $out === haltRunRawStream(haltRunRenderEvents('41 GB free')) && haltRunCalls($tree) === 2, 'answered on the second run: ' . json_encode([$code, $out]));
            check(strpos($err, 'Pikkumies: The agent gateway did not take the connection; asking again') !== false, 'the re-run is said: ' . $err);
            @unlink("$tree/calls.jsonl");
            file_put_contents("$tree/plan.json", json_encode([['events' => $failed, 'code' => 1]]));
            list($code, $out, $err) = haltRunRawDrive($tree, haltRunFakeHermes($tree), $supportArgs, "disk?\n");
            check($code === 1 && $out === haltRunRawStream($failed) && haltRunCalls($tree) === 2, 'twice: one stream, two runs: ' . json_encode([$code, $out, haltRunCalls($tree)]));
            check(substr_count($err, 'Pikkumies: Hermes: Gateway connection failed; no local fallback (the agent gateway did not take the connection twice in a row, so the question did not run)') === 1, 'twice: the cause once: ' . $err);
        });
    },
];
