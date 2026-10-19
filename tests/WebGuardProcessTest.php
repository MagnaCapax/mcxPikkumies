<?php
/**
 * guard.php as a process: it refuses to start on bad input, writes the ready file only after the existing target is
 * guarded, logs a blocked request without its query string, and exits non-zero when the browser connection ends
 * (the supervisor treats that as the browser's stop). The browser is tests/fixtures/browse/fake-devtools.php.
 */

/** Starts the fake endpoint in $mode; returns [process, port]. */
function wgpFake($mode, $record)
{
    $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/fixtures/browse/fake-devtools.php', $mode, $record])); // a string: PHP 7.3
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    return [$p, $port];
}

/** Runs guard.php with $args; returns [exit code, stderr]. Kills it after $secs. */
function wgpGuard(array $args, $secs = 15)
{
    $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, dirname(__DIR__) . '/pikkumies/tools/system/browse/guard.php'], $args)));
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[2], false);
    $err = '';
    $end = microtime(true) + $secs;
    do {
        usleep(50000);
        $err .= (string) stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
    } while ($st['running'] && microtime(true) < $end);
    if ($st['running']) {
        proc_terminate($p, 9);
    }
    $err .= (string) stream_get_contents($pipes[2]);
    proc_close($p);
    return [$st['running'] ? -1 : $st['exitcode'], $err];
}

return [
    'browse guard: guard.php refuses to start without its options, off loopback, with a bad login file or with nothing listening' => function () {
        $dir = testTempDir('pk-webguard');
        try {
            $log = "$dir/log.jsonl";
            $port = testFreePort();
            $ws = "ws://127.0.0.1:$port/devtools/browser/x";
            file_put_contents("$dir/auth-bad", "Basic abc\r\nX-Other: 1\n");
            chmod("$dir/auth-bad", 0600);
            foreach ([
                [[], 'no options'],
                [['--cdp', $ws, '--log', $log], 'no --self'],
                [['--cdp', $ws, '--log', $log, '--self', '../x'], 'a bad account name'],
                [['--cdp', $ws, '--log', $log, '--self', 'exampleuser'], 'no --host'],
                [['--cdp', 'ws://192.0.2.1:9222/devtools/browser/x', '--log', $log, '--self', 'exampleuser', '--host', 'panel.example.com'], 'a DevTools address off loopback'],
                [['--cdp', "http://127.0.0.1:$port/", '--log', $log, '--self', 'exampleuser', '--host', 'panel.example.com'], 'an http address'],
                [['--cdp', $ws, '--log', $log, '--self', 'exampleuser', '--host', 'panel.example.com', '--auth-file', "$dir/missing"], 'a missing login file'],
                [['--cdp', $ws, '--log', $log, '--self', 'exampleuser', '--host', 'panel.example.com', '--auth-file', "$dir/auth-bad"], 'a login file with a second line'],
                [['--cdp', $ws, '--log', $log, '--self', 'exampleuser', '--host', 'panel.example.com', '--bogus', 'x'], 'an unknown option'],
                [['--cdp', $ws, '--log', $log, '--self', 'exampleuser', '--host', 'panel.example.com'], 'nothing listening on the port'],
            ] as $c) {
                list($code, $err) = wgpGuard($c[0], 10);
                check($code === 1 && strpos($err, 'browse guard: ') === 0, "{$c[1]}: exit $code, stderr " . trim($err));
            }
        } finally {
            testRmTree($dir);
        }
    },
    'browse guard: guard.php writes the ready file after the existing page is guarded, logs a block without the query, and exits 2 when the browser goes' => function () {
        $dir = testTempDir('pk-webguard');
        try {
            file_put_contents("$dir/auth", "Basic ZXhhbXBsZXVzZXI6cHctZXhhbXBsZXVzZXI=\n");
            chmod("$dir/auth", 0600);
            list($fake, $port) = wgpFake('ready', "$dir/record.jsonl");
            check($port > 0, 'the fake endpoint did not start');
            list($code, $err) = wgpGuard(['--cdp', "ws://127.0.0.1:$port/devtools/browser/x", '--log', "$dir/log.jsonl", '--self', 'exampleuser',
                '--host', 'panel.example.com', '--intent', "$dir/intent", '--auth-file', "$dir/auth", '--ready', "$dir/ready"]);
            proc_close($fake);
            check($code === 2, "exit $code when the browser went, stderr: " . trim($err));
            check(is_file("$dir/ready") && (fileperms("$dir/ready") & 0777) === 0600, 'no 0600 ready file');
            $sent = array_map(function ($l) {
                return json_decode($l, true)['method'];
            }, file("$dir/record.jsonl", FILE_IGNORE_NEW_LINES));
            check(array_search('Fetch.enable', $sent, true) < array_search('Runtime.runIfWaitingForDebugger', $sent, true), 'resumed before interception: ' . json_encode($sent));
            check(in_array('Fetch.failRequest', $sent, true), 'the neighbour request was not failed: ' . json_encode($sent));
            $log = file_get_contents("$dir/log.jsonl");
            $rec = json_decode(trim($log), true);
            check($rec['action'] === 'block' && $rec['prefix'] === '/user-otheruser/' && strpos($log, 'token') === false && strpos($log, 'Basic') === false,
                "log: $log");
            check((fileperms("$dir/log.jsonl") & 0777) === 0600, 'the log is not 0600');
            check(strpos($err, 'ZXhhbXBsZXVzZXI') === false, 'the login reached stderr');
        } finally {
            testRmTree($dir);
        }
    },
    'browse guard: guard.php runs without a login file, and exits 1 without a ready file when the browser refuses to attach' => function () {
        $dir = testTempDir('pk-webguard');
        try {
            list($fake, $port) = wgpFake('refuse', "$dir/record.jsonl");
            list($code, $err) = wgpGuard(['--cdp', "ws://127.0.0.1:$port/devtools/browser/x", '--log', "$dir/log.jsonl", '--self', 'exampleuser',
                '--host', 'panel.example.com', '--ready', "$dir/ready"]);
            proc_terminate($fake);
            proc_close($fake);
            check($code === 1 && strpos($err, 'refused to attach') !== false, "exit $code, stderr: " . trim($err));
            check(!file_exists("$dir/ready"), 'a ready file without an attach');
        } finally {
            testRmTree($dir);
        }
    },
    'browse guard: guard.php exits 0 on SIGTERM, the supervisor\'s own stop' => function () {
        if (!function_exists('pcntl_async_signals')) {
            skip('no pcntl here: SIGTERM ends the guard the default way');
        }
        $dir = testTempDir('pk-webguard');
        try {
            list($fake, $port) = wgpFake('hold', "$dir/record.jsonl");
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__) . '/pikkumies/tools/system/browse/guard.php',
                '--cdp', "ws://127.0.0.1:$port/devtools/browser/x", '--log', "$dir/log.jsonl", '--self', 'exampleuser', '--host', 'panel.example.com',
                '--ready', "$dir/ready"]));
            $g = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($i = 0; $i < 200 && !file_exists("$dir/ready"); $i++) {
                usleep(50000);
            }
            check(file_exists("$dir/ready"), 'the guard never became ready');
            proc_terminate($g, 15);
            for ($i = 0; $i < 100 && ($st = proc_get_status($g)) && $st['running']; $i++) {
                usleep(50000);
            }
            proc_close($g);
            proc_terminate($fake);
            proc_close($fake);
            check(!$st['running'] && $st['exitcode'] === 0, 'exit after SIGTERM: ' . json_encode($st));
        } finally {
            testRmTree($dir);
        }
    },
];
