<?php
/**
 * lib/GatewayWait.php (GH #12, ADR-0113): the wait for a gateway that is still starting, after Hermes gave up on its fixed
 * 60 s. Scripted seams stand in for the gateway, the clock and sleep, so no test waits; the lock probe, the socket probe and
 * the process scan are each tried once against real files, a real socket and a fake /proc.
 */
if (!function_exists('gatewayWaitRun')) {
    /** Runs GatewayWait::run with scripted answers (one per check, the last repeats); returns [result, printed lines, sleeps]. */
    function gatewayWaitRun(array $state, array $held, array $booting)
    {
        $lines = [];
        $sleeps = 0;
        $clock = 1000;
        $next = function (array &$list) {
            return count($list) > 1 ? array_shift($list) : $list[0];
        };
        $result = GatewayWait::run('/nonexistent/hermes-home', function ($l) use (&$lines) {
            $lines[] = $l;
        }, [
            'state' => function () use (&$state, $next) {
                return $next($state);
            },
            'held' => function () use (&$held, $next) {
                return $next($held);
            },
            'booting' => function () use (&$booting, $next) {
                return $next($booting);
            },
            'sleep' => function ($s) use (&$sleeps, &$clock) {
                $sleeps++;
                $clock += $s;
                check($sleeps < 2000, 'the wait never ended');
            },
            'now' => function () use (&$clock) {
                return $clock;
            },
        ]);
        return [$result, $lines, $sleeps];
    }
}

return [
    'gateway wait: only the exact fixed-deadline error from a run that did no work triggers it' => function () {
        check(GatewayWait::triggered('Gateway starting: deadline', false), 'the deadline error with nothing done');
        check(!GatewayWait::triggered('Gateway starting: deadline', true), 'the run streamed text or called a tool: it did something');
        check(!GatewayWait::triggered('Gateway inaccessible: authorization', false), 'another gateway failure');
        check(!GatewayWait::triggered('Gateway starting: not_ready', false), 'another reason');
        check(!GatewayWait::triggered('', false) && !GatewayWait::triggered('The model failed. Gateway starting: deadline', false), 'no error, or the text only inside another');
        check(GatewayWait::triggered('Gateway connection failed; no local fallback', false), 'the connection failure with nothing done (finding 4)');
        check(!GatewayWait::triggered('Gateway connection failed; no local fallback', true), 'the connection failure after the run did something');
    },
    'gateway wait: waits while the lock is held and the gateway says it is starting, then is ready on any other state' => function () {
        list($r, $lines, $sleeps) = gatewayWaitRun(['', '', 'starting', 'starting', 'ready'], [true], [false]);
        check($r === 'ready' && $sleeps === 4, "ready after four checks: $r / $sleeps");
        check(count($lines) === 1 && strpos($lines[0], 'Waiting for the agent gateway to finish starting (0s)') === 0, 'one progress line at the start: ' . json_encode($lines));
        list($r) = gatewayWaitRun(['draining'], [true], [true]);
        check($r === 'ready', 'any answer but starting is Hermes\' to judge');
    },
    'gateway wait: progress repeats every EVERY seconds of waiting, not every check' => function () {
        $state = array_merge(array_fill(0, 100, 'starting'), ['ready']);
        list($r, $lines, $sleeps) = gatewayWaitRun($state, [true], [false]);
        check($r === 'ready' && $sleeps === 100, "100 checks of POLL seconds: $sleeps");
        check(count($lines) === 4 && strpos($lines[3], '(3m0s)') !== false, 'a line at 0s, 60s, 120s and 180s of waiting: ' . json_encode($lines));
    },
    'gateway wait: gone when the lock is let go, no gateway process is starting and no socket answers' => function () {
        list($r, , $sleeps) = gatewayWaitRun([''], [true, true, false], [false]);
        check($r === 'gone' && $sleeps === 2, "gone once the lock is released: $r / $sleeps");
        list($r) = gatewayWaitRun([''], [false], [false]);
        check($r === 'gone', 'nothing starting at all');
    },
    'gateway wait: a gateway process that has not taken its lock yet is still waited for' => function () {
        list($r, , $sleeps) = gatewayWaitRun([''], [false], [true, true, true, false]);
        check($r === 'gone' && $sleeps === 3, "waited while the process was there: $r / $sleeps");
        list($r) = gatewayWaitRun(['', 'ready'], [false], [false]);
        check($r === 'ready', 'a gateway that answers as the lock is looked at is ready, not gone');
    },
    'gateway wait: the lock probe sees another holder, lets its own probe go, and treats a missing file as not held' => function () {
        $dir = testTempDir('pk-gwwait');
        try {
            $lock = "$dir/gateway.lock";
            check(GatewayWait::lockHeld($lock) === false, 'no lock file: nothing holds it');
            file_put_contents($lock, '');
            $holder = fopen($lock, 'r+');
            check(flock($holder, LOCK_EX | LOCK_NB), 'the test takes the lock');
            check(GatewayWait::lockHeld($lock) === true, 'held by another open file');
            flock($holder, LOCK_UN);
            check(GatewayWait::lockHeld($lock) === false, 'released');
            check(flock($holder, LOCK_EX | LOCK_NB), 'the probe must not leave the lock taken');
            fclose($holder);
        } finally {
            testRmTree($dir);
        }
    },
    'gateway wait: the socket probe reads the state from identify, directly and through the pointer file; no socket or no answer is no state' => function () {
        $dir = testTempDir('pk-gwwait');
        $proc = null;
        try {
            check(GatewayWait::state($dir) === '', 'no socket');
            $sock = "$dir/gateway.sock";
            // Hermes' answer: {"ok":true,"protocol":1,"id":1,"result":{...}} (hermes_cli/gateway_runtime_discovery.py _identify_response),
            // its state inside result (gateway/run_bootstrap.py _identify_runtime). One answer per connection, in this order.
            $real = function ($state) {
                return ['ok' => true, 'protocol' => 1, 'id' => 1, 'result' => ['instance_id' => 'i1', 'runtime_protocol' => 1, 'authority_epoch' => 1,
                    'state' => $state, 'served_profiles' => [], 'capabilities' => ['session-authority-v1'], 'supervisor' => 'none']];
            };
            file_put_contents("$dir/answers.json", json_encode([$real('starting'), $real('starting'), $real('ready'),
                ['state' => 'ready', 'protocol' => 1], ['ok' => false, 'protocol' => 1, 'id' => 1, 'error' => 'denied']]));
            file_put_contents("$dir/server.php", '<?php $s = stream_socket_server("unix://" . $argv[1], $e, $m); echo "up\n"; '
                . 'foreach (json_decode(file_get_contents($argv[2]), true) as $a) { $c = stream_socket_accept($s, 30); if ($c) { fgets($c); fwrite($c, json_encode($a) . "\n"); fclose($c); } }');
            $proc = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/server.php") . ' ' . escapeshellarg($sock) . ' ' . escapeshellarg("$dir/answers.json"), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(fgets($pipes[1]) === "up\n", 'the fake gateway is listening');
            check(GatewayWait::state($dir) === 'starting', 'direct socket, state starting');
            mkdir("$dir/home2");
            file_put_contents("$dir/home2/gateway.sock.path", "$sock\n");
            check(GatewayWait::state("$dir/home2") === 'starting', 'through gateway.sock.path');
            check(GatewayWait::state($dir) === 'ready', 'a ready gateway\'s full answer');
            check(GatewayWait::state($dir) === '', 'a state outside result is not Hermes\' answer');
            check(GatewayWait::state($dir) === '', 'a refused identify is no state');
            check(GatewayWait::state("$dir/home3") === '', 'a home with neither');
            file_put_contents("$dir/home2/gateway.sock.path", "relative.sock\n");
            check(GatewayWait::state("$dir/home2") === '', 'a pointer that is not an absolute path');
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
            testRmTree($dir);
        }
    },
    'gateway wait: the process scan finds this account\'s gateway run for this HERMES_HOME and nothing else' => function () {
        $dir = testTempDir('pk-gwwait');
        try {
            $home = "$dir/hermes";
            mkdir($home);
            $fake = function ($pid, array $args, array $env) use ($dir) {
                mkdir("$dir/proc/$pid", 0700, true);
                file_put_contents("$dir/proc/$pid/cmdline", implode("\0", $args) . "\0");
                file_put_contents("$dir/proc/$pid/environ", implode("\0", $env) . "\0");
            };
            $fake(10, ['python', '-m', 'hermes_cli.main', 'chat', '--query=x'], ["HERMES_HOME=$home"]);
            $fake(11, ['python', '-m', 'hermes_cli.main', 'gateway', 'run', '--quiet'], ['HERMES_HOME=/somewhere/else']);
            check(GatewayWait::bootingProcess($home, "$dir/proc") === false, 'a chat client, and a gateway of another home, are not it');
            $fake(12, ['python', '-m', 'hermes_cli.main', '--profile', 'default', 'gateway', 'run', '--quiet', '--idle-exit'], ['PATH=/bin', "HERMES_HOME=$home"]);
            check(GatewayWait::bootingProcess($home, "$dir/proc") === true, 'the gateway run of this home');
            symlink($home, "$dir/link");
            check(GatewayWait::bootingProcess("$dir/link", "$dir/proc") === true, 'the same home spelled through a symlink');
        } finally {
            testRmTree($dir);
        }
    },
    'lost turn: only Hermes\' disconnect text is one, by its start, and the zero-work hold does not take it (ADR-0122)' => function () {
        $full = 'Gateway disconnected; turn outcome is unknown. Resume the printed session ID to check whether it completed or was interrupted; do not resend the work.';
        check(GatewayWait::lostTurn($full) && GatewayWait::lostTurn('Gateway disconnected; turn outcome is unknown') && GatewayWait::lostTurn("  $full "), 'the text, whole, bare or padded');
        check(!GatewayWait::lostTurn('') && !GatewayWait::lostTurn('Gateway starting: deadline') && !GatewayWait::lostTurn('Gateway connection failed; no local fallback')
            && !GatewayWait::lostTurn('The model failed: Gateway disconnected; turn outcome is unknown'), 'other errors, and the text only inside another');
        check(!GatewayWait::triggered($full, false), 'a run that did work is never held for a re-run, whatever the token count says');
    },
    'lost turn: the report says the work was not run again, names the session and the way to look at it, and never tells the person to send text' => function () {
        $home = testTempDir('pk-lost');
        try {
            $none = ['state' => function () { return ''; }, 'held' => function () { return false; }, 'booting' => function () { return false; }];
            $lines = GatewayWait::lostReport($home, 'sess-1', GatewayWait::logMark($home), $none);
            $all = implode("\n", $lines);
            check(strpos($all, 'was not asked again') !== false && strpos($all, 'pays for it again') !== false, 'not asked again: ' . $all);
            check(strpos($all, 'pikkumies --resume sess-1` and send no new text') !== false, 'the session and the no-text instruction: ' . $all);
            check(strpos($all, 'dropped') === false && strpos($all, 'crashed') === false && strpos($all, 'send a message') === false, 'no cause claimed, no message suggested: ' . $all);
            check(strpos($all, 'Gateway now: no gateway is running.') !== false, 'gateway state: ' . $all);
            check(strpos($all, 'Gateway log: not determined (no agent.log).') !== false, 'no log is not determined: ' . $all);
            check(strpos(implode("\n", GatewayWait::lostReport($home, '', [0, 0], $none)), 'No session ID was printed.') !== false, 'no session ID');
            $answers = $none;
            $answers['state'] = function () { return 'ready'; };
            check(strpos(implode("\n", GatewayWait::lostReport($home, 's', [0, 0], $answers)), 'Gateway now: answering (state ready).') !== false, 'an answering gateway');
            $answers['state'] = function () { return ''; };
            $answers['held'] = function () { return true; };
            check(strpos(implode("\n", GatewayWait::lostReport($home, 's', [0, 0], $answers)), 'a gateway process exists and does not answer') !== false, 'a held lock without an answer');
            $answers['held'] = function () { throw new Exception('probe failed'); };
            check(strpos(implode("\n", GatewayWait::lostReport($home, 's', [0, 0], $answers)), 'Gateway state and log: not determined.') !== false, 'a probe that throws is not determined');
            check(strpos(implode("\n", GatewayWait::lostReport('', 's', [0, 0])), 'not determined (the agent home is not known here)') !== false, 'no home: nothing probed');
        } finally {
            testRmTree($home);
        }
    },
    'lost turn: a close line is printed only when exactly one was written since the mark; none, two, a rotated log and over-long lines say what they are' => function () {
        $home = testTempDir('pk-lost');
        try {
            mkdir("$home/logs");
            $log = "$home/logs/agent.log";
            $none = ['state' => function () { return ''; }, 'held' => function () { return false; }, 'booting' => function () { return false; }];
            $close = function ($peer, $reason) {
                return "2026-11-22 12:00:01,000 INFO tui_gateway.ws: ws closed peer=$peer reason=$reason messages=9 parse_errors=0 dispatch_crashes=0 send_failures=0 reaped_sessions=0 detached_sessions=1\n";
            };
            $words = function ($mark) use ($home, $none) {
                return implode("\n", GatewayWait::lostReport($home, 's', $mark, $none));
            };
            // A close from before the run is never this run's.
            file_put_contents($log, $close('127.0.0.1:1111', 'client_disconnect(code=1000)'));
            $mark = GatewayWait::logMark($home);
            check(strpos($words($mark), 'no close line since the run started') !== false && strpos($words($mark), '1111') === false, 'an earlier close is outside the window: ' . $words($mark));
            file_put_contents($log, $close('127.0.0.1:2222', 'client_disconnect(code=1011,reason=keepalive ping timeout)'), FILE_APPEND);
            check(strpos($words($mark), 'peer=127.0.0.1:2222 reason=client_disconnect(code=1011,reason=keepalive ping timeout)') !== false && strpos($words($mark), '1111') === false, 'exactly one: printed, as written: ' . $words($mark));
            file_put_contents($log, "2026-11-22 12:00:02,000 INFO agent: something else\n" . $close('127.0.0.1:3333', 'client_disconnect(code=1000)'), FILE_APPEND);
            $two = $words($mark);
            check(strpos($two, '2 close lines since the run started; which one is this run\'s is not determined') !== false && strpos($two, '2222') === false && strpos($two, '3333') === false, 'two: no reason printed: ' . $two);
            // An over-long line that contains the marker text after its first 8 KiB is not a line of its own.
            $mark2 = GatewayWait::logMark($home);
            file_put_contents($log, str_repeat('x', 9000) . $close('peer-long:5', 'inside a long line'), FILE_APPEND);
            check(strpos($words($mark2), 'no close line since the run started') !== false, 'the tail of an over-long line is skipped: ' . $words($mark2));
            // A line that is not UTF-8 is still one close line, printed clean.
            $mark3 = GatewayWait::logMark($home);
            file_put_contents($log, "2026-11-22 12:00:03,000 INFO tui_gateway.ws: ws closed peer=\xff\xfe:1 reason=x\x01y\n", FILE_APPEND);
            $bad = $words($mark3);
            check(strpos($bad, 'Gateway log: ') !== false && strpos($bad, "\x01") === false && strpos($bad, "\xff") === false, 'control and non-UTF-8 bytes do not reach the terminal: ' . json_encode($bad));
            // Rotation during the run: the file is smaller than the mark, or another file.
            $mark4 = GatewayWait::logMark($home);
            file_put_contents($log, $close('peer-rotated:9', 'after rotation'));
            check(strpos($words($mark4), 'not determined (agent.log was rotated during the run)') !== false, 'a log shorter than the mark: ' . $words($mark4));
            check(strpos($words([filesize($log), 12345]), 'rotated') !== false, 'another file under the same name');
        } finally {
            testRmTree($home);
        }
    },
];
