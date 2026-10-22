<?php
/** `pikkumies eternal activity` (lib/EternalActivity.php, ADR-0075 amendment 2026-10-22): Primary's state and newest activity line. */

/** One spool record as lib/HookSpool.php stores it. */
function eaRec($seq, $kind, array $extra = [])
{
    return ['seq' => $seq, 'ts' => gmdate('Y-m-d\TH:i:s\Z', 1760000000 + $seq), 'kind' => $kind] + $extra + ['scheduled' => false];
}

/** A fake /proc entry: stat with $ppid and start time $start, and an environ of $env. */
function eaProc($proc, $pid, $ppid, $start, array $env = [])
{
    @mkdir("$proc/$pid", 0700, true);
    file_put_contents("$proc/$pid/stat", "$pid (x) S $ppid 1 1 0 -1 4194560 100 0 0 0 1 2 0 0 20 0 1 0 $start 1000 50 18446744073709551615\n");
    $pairs = [];
    foreach ($env as $k => $v) {
        $pairs[] = "$k=$v";
    }
    file_put_contents("$proc/$pid/environ", implode("\0", $pairs) . "\0");
}

/** Write $records as spool $hash under $local. */
function eaSpool($local, $hash, array $records)
{
    @mkdir(dirname(HookSpool::file($local, $hash)), 0700, true);
    file_put_contents(HookSpool::file($local, $hash), implode("\n", array_map('json_encode', $records)) . "\n");
}

return [
    'eternal activity: the newest tool line or agent text start, never the user\'s request, cut and without control characters' => function () {
        $none = EternalActivity::latest([eaRec(1, 'user', ['text' => 'my private request'])]);
        check($none === ['line' => '', 'at' => 0], 'user text only: ' . json_encode($none));
        $tools = EternalActivity::latest([eaRec(1, 'user', ['text' => 'please check the disk']), eaRec(2, 'tool', ['tool' => 'terminal', 'args' => '{"command":"ls -la"}', 'status' => 'ok']),
            eaRec(3, 'tool', ['tool' => 'terminal', 'args' => '{"command":"df -h"}', 'status' => 'ok'])]);
        check($tools === ['line' => 'terminal: df -h', 'at' => 1760000003], 'newest tool line: ' . json_encode($tools));
        $text = EternalActivity::latest([eaRec(1, 'user', ['text' => 'hi']), eaRec(2, 'assistant', ['text' => "Checking the disk now.\nThen the logs."])]);
        check($text === ['line' => 'Checking the disk now. Then the logs.', 'at' => 1760000002], 'agent text start: ' . json_encode($text));
        $after = EternalActivity::latest([eaRec(1, 'tool', ['tool' => 'read_file', 'args' => '{"path":"/x/y"}']), eaRec(2, 'user', ['text' => 'SECRET-REQUEST'])]);
        check($after['line'] === 'read_file: /x/y' && strpos(json_encode($after), 'SECRET') === false, 'a later user record was printed: ' . json_encode($after));
        $long = EternalActivity::latest([eaRec(1, 'assistant', ['text' => str_repeat('ä', 500)])]);
        check(preg_match_all('/./us', $long['line']) === 200, 'not cut to 200 characters: ' . preg_match_all('/./us', $long['line']));
        $ctl = EternalActivity::latest([eaRec(1, 'reply', ['text' => "ok\x1b[31m red\x07\xC2\x9Bdone\x00"])]);
        check(preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $ctl['line']) === 0 && strpos($ctl['line'], 'red') !== false && strpos($ctl['line'], 'done') !== false, 'control characters kept: ' . json_encode($ctl));
    },
    'eternal activity: the spool of the shallowest agent under the pane, found by its run key, depth, pid and start time' => function () {
        $root = testTempDir('eternal-activity-spool');
        try {
            $local = "$root/local";
            $proc = "$root/proc";
            $run = 'abcdef0123456789';
            eaProc($proc, 500, 1, 100);
            eaProc($proc, 501, 500, 101, ['PIKKUMIES_RUN' => $run, 'PIKKUMIES_DEPTH' => '1']);           // sh: no spool of its own
            eaProc($proc, 502, 501, 102, ['PIKKUMIES_RUN' => $run, 'PIKKUMIES_DEPTH' => '1']);           // Primary's Hermes
            eaProc($proc, 600, 502, 300, ['PIKKUMIES_RUN' => $run, 'PIKKUMIES_DEPTH' => '2']);           // a hermes the agent started
            eaProc($proc, 700, 1, 400, ['PIKKUMIES_RUN' => $run, 'PIKKUMIES_DEPTH' => '1']);             // another pane
            check(EternalActivity::spool($local, 500, $proc) === '', 'a spool found where none exists');
            $primary = HookRunCount::runHash($run, 1, '502:102');
            eaSpool($local, $primary, [eaRec(1, 'tool', ['tool' => 'terminal', 'args' => '{"command":"uptime"}'])]);
            eaSpool($local, HookRunCount::runHash($run, 2, '600:300'), [eaRec(1, 'tool', ['tool' => 'terminal', 'args' => '{"command":"nested"}'])]);
            eaSpool($local, HookRunCount::runHash($run, 1, '700:400'), [eaRec(1, 'tool', ['tool' => 'terminal', 'args' => '{"command":"other"}'])]);
            touch(HookSpool::file($local, $primary), time() - 100);
            check(EternalActivity::spool($local, 500, $proc) === $primary, 'not Primary\'s own spool');
            check(EternalActivity::spool($local, 999, $proc) === '', 'a pane without processes found a spool');
        } finally {
            testRmTree($root);
        }
    },
    'eternal activity: the command reports absent, off, busy with the newest line, and refuses root' => function () {
        $root = testTempDir('eternal-activity-cli');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/fake-bin', 0700);
        @mkdir($root . '/local/hermes/terminal-sessions', 0700, true);
        file_put_contents($root . '/enabled', "1\n");
        $pane = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $root . '/bin/pikkumies', 'run', '--eternal', 'pm1']));
        $fake = "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($root . '/calls') . "\ncase \"\$1\" in\n"
            . "display-message) printf '%s\\n' " . escapeshellarg($pane . '|/dev/pts/42|500|0') . " ;;\n"
            . "capture-pane) printf 'working on it\\n' ;;\nesac\nexit 0\n";
        file_put_contents($root . '/fake-bin/tmux', $fake);
        chmod($root . '/fake-bin/tmux', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; EternalActivity::$proc = __DIR__ . "/proc";'
            . ' if ($argv[1] === "root") { list($code, $text) = EternalActivity::run(true, 0); echo $text; exit($code); } exit(EternalCli::run(array_slice($argv, 1)));');
        $oldPath = getenv('PATH');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        try {
            list($code, $out) = runPhp($runner, ['root']);
            check($code === 1 && strpos($out, 'not root') !== false && !is_file($root . '/calls'), 'root was not refused: ' . $out);
            list($code, $out) = runPhp($runner, ['activity', '--json']);
            check($code === 0 && json_decode($out, true) === ['state' => 'absent', 'line' => '', 'at' => 0], 'no Primary: ' . $out);
            @mkdir($root . '/local/tmux', 0700);
            @mkdir($root . '/local/tmp', 0700);
            EternalRecord::$base = $root . '/local/eternals';
            EternalRecord::write('pm1', ['sessionId' => '', 'fails' => 0, 'lastStart' => time() - 5]);
            EternalRecord::$base = null;
            file_put_contents($root . '/local/hermes/terminal-sessions/tty-dev-pts-42', "fixture-session\n");
            $run = '0123456789abcdef';
            eaProc($root . '/proc', 500, 1, 10);
            eaProc($root . '/proc', 501, 500, 11, ['PIKKUMIES_RUN' => $run, 'PIKKUMIES_DEPTH' => '1']);
            eaSpool($root . '/local', HookRunCount::runHash($run, 1, '501:11'), [eaRec(1, 'user', ['text' => 'SECRET-USER-REQUEST']),
                eaRec(2, 'tool', ['tool' => 'terminal', 'args' => '{"command":"df -h /home"}', 'status' => 'ok'])]);
            list($code, $out) = runPhp($runner, ['activity', '--json']);
            check($code === 0 && json_decode($out, true) === ['state' => 'busy', 'line' => 'terminal: df -h /home', 'at' => 1760000002], 'busy Primary: ' . $out);
            list($code, $out) = runPhp($runner, ['activity']);
            check($code === 0 && trim($out) === 'Primary: busy; latest: terminal: df -h /home' && strpos($out, 'SECRET') === false, 'plain: ' . $out);
            file_put_contents($root . '/local/eternals.off', "off\n");
            list($code, $out) = runPhp($runner, ['activity', '--json']);
            check($code === 0 && json_decode($out, true) === ['state' => 'off', 'line' => '', 'at' => 0], 'off: ' . $out);
            unlink($root . '/enabled');
            list($code, $out) = runPhp($runner, ['activity', '--json']);
            check($code === 1 && strpos($out, 'not enabled') !== false, 'markerless host answered: ' . $out);
        } finally {
            putenv('PATH=' . $oldPath);
            EternalRecord::$base = null;
            testRmTree($root);
        }
    },
];
