<?php
/**
 * lib/HaltRun.php when the connection to the gateway ends in the middle of a turn that did work (GH #19, ADR-0122
 * amendment): Hermes ends the run with "Gateway disconnected; turn outcome is unknown" and, in its real failed result, all
 * token counts 0. The run is never asked again, never judged or resumed, and says what is known. The fake Hermes prints the
 * real shape: init, steps, then a result with the error and zero tokens.
 */
require_once __DIR__ . '/haltRunSupport.php';

if (!function_exists('haltRunLostEvents')) {
    /** Hermes' text (hermes_cli/gateway_client.py _read). */
    function haltRunLostText()
    {
        return 'Gateway disconnected; turn outcome is unknown. Resume the printed session ID to check whether it completed or was interrupted; do not resend the work.';
    }

    /** A run that did work (two steps) and then lost its turn: the failed result carries zero tokens, as Hermes sends it. */
    function haltRunLostEvents($error = null, $sessionId = 'fakesess')
    {
        $error = $error === null ? haltRunLostText() : $error;
        return [['type' => 'system', 'subtype' => 'init', 'model' => 'm', 'session_id' => $sessionId],
            ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => 'df -h']],
            ['type' => 'tool_result', 'name' => 'terminal', 'is_error' => false],
            ['type' => 'tool_use', 'name' => 'terminal', 'input' => ['command' => 'du -sh /tmp']],
            ['type' => 'result', 'session_id' => $sessionId, 'exit_code' => 1, 'text' => '', 'error' => $error,
                'tokens' => ['input' => 0, 'output' => 0, 'total' => 0, 'cache_read' => 0, 'cache_write' => 0]]];
    }

    function haltRunLostClose($peer, $reason = 'client_disconnect(code=1011,reason=keepalive ping timeout)')
    {
        return "2026-11-22 12:00:01,000 INFO tui_gateway.ws: ws closed peer=$peer reason=$reason messages=9 parse_errors=0 dispatch_crashes=0 send_failures=0 reaped_sessions=0 detached_sessions=1";
    }

    /** HaltRun::run() in a subprocess, a renderer (progress shown or not) or none; the turn already judged or not: [code, stdout, stderr]. */
    function haltRunLostDrive($tree, $hermesBin, $alreadyJudged, array $passArgs, $renderer, $show = true, $stdin = '')
    {
        $script = "$tree/ldriver-" . mt_rand() . '.php';
        $body = "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltState.php", true) . ";\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n"
            . "HaltState::writeSnapshot('fakesess', 'please fix the thing', '[user] please fix the thing');\n";
        if ($alreadyJudged) {
            $body .= "\$s = HaltState::load('fakesess');\nHaltState::markJudged('fakesess', \$s['turn_started']);\n";
        }
        $body .= ($renderer ? '$r = new StreamProgress(' . ($show ? 'true' : 'false') . ", true, null);\n" : "\$r = null;\n")
            . 'exit(HaltRun::run(' . var_export($hermesBin, true) . ', ' . var_export($passArgs, true) . ", \$r));\n";
        file_put_contents($script, $body);
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /** Runs $body($tree, $home, $fakeModel) with a fake model, a fresh tree and HERMES_HOME set; cleans up after. */
    function haltRunLostCase(callable $body, array $modelReplies = [])
    {
        $fakeModel = testTempDir('pk-haltrun-model');
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltRunTree($port);
        $home = testTempDir('pk-gwhome');
        try {
            file_put_contents("$fakeModel/replies.json", json_encode($modelReplies));
            putenv('PK_FAKE_HERMES_DIR=' . $tree);
            putenv('HERMES_HOME=' . $home);
            $body($tree, $home, $fakeModel);
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

list($continueReply, $doneReply, $chatReply) = haltRunReplies();
$args = ['chat', '--query=please fix the thing', '--format=stream-json'];

return [
    'lost turn: a run that did work and lost its connection is run once, not judged, and says what is known; the exit code stays Hermes\'' => function () use ($continueReply, $args) {
        haltRunLostCase(function ($tree, $home) use ($args) {
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunLostEvents(), 'code' => 1, 'log' => [haltRunLostClose('127.0.0.1:41000')]]]));
            list($code, $out, $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), false, $args, true);
            check($code === 1 && $out === '', 'Hermes\' exit code, no answer: ' . json_encode([$code, $out]));
            check(haltRunCalls($tree) === 1, 'run once, never again: ' . haltRunCalls($tree));
            check(strpos($err, 'Not finished, continuing') === false && strpos($err, 'Wrapping up') === false, 'a judge that always says continue was not asked, or its answer was not acted on: ' . $err);
            check(substr_count($err, 'Hermes: Gateway disconnected; turn outcome is unknown') === 1, 'Hermes\' error printed once, as before: ' . $err);
            check(strpos($err, 'The question was not asked again') !== false, 'not asked again: ' . $err);
            check(strpos($err, 'Session fakesess: look at what it holds with `pikkumies --resume fakesess` and send no new text.') !== false, 'the session and the no-text way to look: ' . $err);
            check(strpos($err, 'Gateway now: no gateway is running.') !== false, 'gateway state: ' . $err);
            check(strpos($err, 'peer=127.0.0.1:41000 reason=client_disconnect(code=1011,reason=keepalive ping timeout)') !== false, 'the one close line of this run: ' . $err);
            $decisions = (string) @file_get_contents($tree . '/pikkumies/local/logs/halt-guard/' . gmdate('Ymd') . '.jsonl');
            check(strpos($decisions, '"verdict":"lost-turn"') !== false, 'the skipped judge is recorded: ' . $decisions);
        }, [$continueReply, $continueReply]); // every judge answer is a continue: a judged run would be resumed, and calls would be 2
    },
    'lost turn: a close line from before the run, or two since it, is never printed as this run\'s cause; a run with no close line says so' => function () use ($args) {
        haltRunLostCase(function ($tree, $home) use ($args) {
            mkdir("$home/logs", 0700, true);
            file_put_contents("$home/logs/agent.log", haltRunLostClose('127.0.0.1:40001') . "\n");
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunLostEvents(), 'code' => 1]]));
            list(, , $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), true, $args, true);
            check(strpos($err, 'no close line since the run started') !== false && strpos($err, '40001') === false, 'an earlier close is not this run\'s: ' . $err);
            @unlink("$tree/calls.jsonl");
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunLostEvents(), 'code' => 1, 'log' => [haltRunLostClose('127.0.0.1:40002'), haltRunLostClose('127.0.0.1:40003', 'client_disconnect(code=1000)')]]]));
            list(, , $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), true, $args, true);
            check(strpos($err, '2 close lines since the run started; which one is this run\'s is not determined') !== false && strpos($err, '40002') === false && strpos($err, '40003') === false, 'two closes: no reason printed: ' . $err);
        });
    },
    'lost turn: a continue that loses its turn ends the run: no second resume, no wrap-up, the judge is not asked again' => function () use ($continueReply, $args) {
        // The fake judge model sometimes gives no answer to its first request (HaltRunRenderTest's continue case does the same on an
        // unchanged tree), and the run then ends after one answer: no continue exists to lose. Such a try is repeated on a new model.
        $ran = false;
        for ($try = 0; $try < 4 && !$ran; $try++) {
            haltRunLostCase(function ($tree, $home) use ($args, &$ran) {
                file_put_contents("$tree/plan.json", json_encode([
                    ['events' => haltRunRenderEvents('first part'), 'code' => 0],
                    ['events' => haltRunLostEvents(), 'code' => 1],
                    ['events' => haltRunRenderEvents('never reached'), 'code' => 0],
                ]));
                list($code, $out, $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), false, $args, true);
                if (strpos($err, 'Not finished, continuing') === false) {
                    return;
                }
                $ran = true;
                check(haltRunCalls($tree) === 2, 'the first run and the continue, nothing after: ' . haltRunCalls($tree) . json_encode([$code, $out, $err]));
                check($out === "first part\n" && $code === 1, 'only the first answer reached stdout: ' . json_encode([$code, $out, $err]));
                check(substr_count($err, 'Not finished, continuing (1 of 3)') === 1 && substr_count($err, 'The question was not asked again') === 1, 'one continue, one report: ' . $err);
                check(strpos($err, 'Wrapping up') === false, 'no wrap-up after the lost turn: ' . $err);
            }, [$continueReply, $continueReply, $continueReply]); // every judge answer is a continue, so no reply order decides the path
        }
        check($ran, 'the fake judge model gave no continue in 4 tries');
    },
    'lost turn: the support job\'s own stream-json (no renderer, HERMES_HOME set) gets the report on stderr, its stream unchanged on stdout, and no second run' => function () use ($continueReply) {
        haltRunLostCase(function ($tree, $home) {
            $supportArgs = ['chat', '--query-file', '-', '--format', 'stream-json'];
            $events = haltRunLostEvents();
            file_put_contents("$tree/plan.json", json_encode([['events' => $events, 'code' => 1, 'log' => [haltRunLostClose('127.0.0.1:42000')]]]));
            list($code, $out, $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), false, $supportArgs, false, true, "disk?\n");
            $stream = '';
            foreach ($events as $e) {
                $stream .= json_encode($e) . "\n";
            }
            check($code === 1 && $out === $stream, 'the caller reads the stream as Hermes wrote it: ' . json_encode([$code, $out]));
            check(haltRunCalls($tree) === 1, 'one run, and no resume from a judge that always says continue: ' . haltRunCalls($tree));
            check(strpos($err, 'Pikkumies: The connection to the agent gateway ended while the question was running') === 0 && strpos($err, 'pikkumies --resume fakesess` and send no new text') !== false
                && strpos($err, 'peer=127.0.0.1:42000') !== false, 'the report, with the one close line: ' . $err);
        }, [$continueReply, $continueReply]);
    },
    'lost turn: the report prints with --no-progress too (errors always print), and another error, or a clean run, prints no report' => function () use ($args) {
        haltRunLostCase(function ($tree, $home) use ($args) {
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunLostEvents(), 'code' => 1]]));
            list(, , $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), true, $args, true, false);
            check(strpos($err, 'The question was not asked again') !== false && strpos($err, 'Session fakesess') !== false, 'quiet progress still reports: ' . $err);
            @unlink("$tree/calls.jsonl");
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunLostEvents('The model returned an error.'), 'code' => 1]]));
            list(, , $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), true, $args, true);
            check(strpos($err, 'not asked again') === false && substr_count($err, 'Hermes: The model returned an error.') === 1, 'another error is printed as before: ' . $err);
            @unlink("$tree/calls.jsonl");
            file_put_contents("$tree/plan.json", json_encode([['events' => haltRunRenderEvents('41 GB free'), 'code' => 0]]));
            list($code, $out, $err) = haltRunLostDrive($tree, haltRunFakeHermes($tree), true, $args, true);
            check($code === 0 && $out === "41 GB free\n" && strpos($err, 'not asked again') === false, 'a clean run: ' . json_encode([$out, $err]));
        });
    },
];
