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
    'gateway wait: only the exact fixed-deadline error with no tokens and no tool calls triggers it' => function () {
        check(GatewayWait::triggered('Gateway starting: deadline', 0, 0), 'the deadline error with nothing done');
        check(!GatewayWait::triggered('Gateway starting: deadline', 12, 0), 'tokens were spent: the run did something');
        check(!GatewayWait::triggered('Gateway starting: deadline', 0, 1), 'a tool was called: the run did something');
        check(!GatewayWait::triggered('Gateway inaccessible: authorization', 0, 0), 'another gateway failure');
        check(!GatewayWait::triggered('Gateway starting: not_ready', 0, 0), 'another reason');
        check(!GatewayWait::triggered('', 0, 0) && !GatewayWait::triggered('The model failed. Gateway starting: deadline', 0, 0), 'no error, or the text only inside another');
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
            file_put_contents("$dir/server.php", '<?php $s = stream_socket_server("unix://" . $argv[1], $e, $m); echo "up\n"; '
                . 'foreach ([$argv[2], $argv[2]] as $state) { $c = stream_socket_accept($s, 30); if ($c) { fgets($c); fwrite($c, json_encode(["state" => $state, "protocol" => 1]) . "\n"); fclose($c); } }');
            $proc = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/server.php") . ' ' . escapeshellarg($sock) . ' starting', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(fgets($pipes[1]) === "up\n", 'the fake gateway is listening');
            check(GatewayWait::state($dir) === 'starting', 'direct socket, state starting');
            mkdir("$dir/home2");
            file_put_contents("$dir/home2/gateway.sock.path", "$sock\n");
            check(GatewayWait::state("$dir/home2") === 'starting', 'through gateway.sock.path');
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
];
