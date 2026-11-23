<?php
/**
 * The bounded stop of an Eternal window (ADR-0075, amendment 2026-11-23): SIGTERM ends Hermes and what it started
 * within a grace, and never a torrent client or another known service.
 */

/** Stat line of the stub /proc: state, ppid, 17 fields nobody reads, start time (field 22). */
function boundedStopStat($pid, $comm, $ppid, $start, $state = 'S')
{
    return "$pid ($comm) " . implode(' ', array_merge([$state, $ppid], array_fill(0, 17, '0'), [$start, '0'])) . "\n";
}

/** A resident fixture: a copy of the runtime, a Hermes script made from $hermesBody, and the runner that starts it. */
function boundedStopFixture($hermesBody)
{
    $root = testTempDir('eternal-bounded-stop');
    $source = dirname(__DIR__) . '/pikkumies';
    foreach (['bin', 'lib', 'tools', 'doctrine'] as $dir) {
        exec('cp -R ' . escapeshellarg($source . '/' . $dir) . ' ' . escapeshellarg($root) . '/');
    }
    foreach (['include', 'home', 'local'] as $dir) {
        @mkdir($root . '/' . $dir, 0700);
    }
    file_put_contents($root . '/local/config.php', '<?php return ' . var_export(['provider' => ['model' => 'fixture', 'endpoint' => 'http://127.0.0.1:9/v1']], true) . ';');
    @mkdir($root . '/hermes/.hermes/bin', 0700, true);
    file_put_contents($root . '/enabled', "1\n");
    file_put_contents($root . '/include/.hermes-ready', "ready\n");
    exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
    exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Test -c user.email=test@example.invalid commit -q --allow-empty -m seed');
    $hermes = $root . '/hermes/.hermes/bin/hermes';
    file_put_contents($hermes, "#!/bin/sh\nD='" . $root . "'\n" . $hermesBody);
    chmod($hermes, 0700);
    // A process named like a known service, as an agent may start one from inside its window. Not a torrent client's name:
    // test machines also run other suites that stop those by name, and such a stop ended a fixture of that name in 4 of 9 runs.
    symlink(trim((string) shell_exec('command -v sleep')), $root . '/jellyfin');
    file_put_contents($root . '/runner.php', '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; HermesInstall::$root = __DIR__; Eternal::$hostMarker = __DIR__ . "/enabled"; Update::$crontab = "/bin/false"; Config::acceptTerms(); HermesInstall::markVerified(HermesInstall::head()); EternalRecord::write("pm2", EternalRecord::initial()); require __DIR__ . "/bin/pikkumies";');
    return $root;
}

/** Starts the resident; returns the process handle. */
function boundedStopStart($root)
{
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/runner.php') . ' run --eternal pm2';
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/stdout', 'w'], 2 => ['file', $root . '/stderr', 'w']], $pipes);
    check(is_resource($process), 'could not start resident');
    for ($i = 0; $i < 100 && !is_file($root . '/ready') && proc_get_status($process)['running']; $i++) {
        usleep(100000);
    }
    check(is_file($root . '/ready'), 'fixture Hermes did not start: ' . (string) @file_get_contents($root . '/stdout') . ' ' . (string) @file_get_contents($root . '/stderr'));
    return $process;
}

/** Seconds until the resident has ended, or $max. */
function boundedStopWait($process, $max)
{
    $start = microtime(true);
    $GLOBALS['boundedStopCode'] = null;
    while (microtime(true) - $start < $max) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $GLOBALS['boundedStopCode'] = $status['exitcode']; // PHP gives the code once only
            break;
        }
        usleep(50000);
    }
    return microtime(true) - $start;
}

/** Seconds until the window's run lock is free, which is what Eternal::waitStopped() polls (eternal off, hold); false after $max. */
function boundedStopCallerWait($root, $max)
{
    $start = microtime(true);
    do {
        $lock = EternalLock::take($root . '/local/eternals/pm2.run');
        if ($lock) {
            fclose($lock);
            return microtime(true) - $start;
        }
        usleep(100000);
    } while (microtime(true) - $start < $max);
    return false;
}

function boundedStopPid($root, $name)
{
    return (int) trim((string) @file_get_contents($root . '/pid-' . $name));
}

/** Kills what a fixture left running, whatever the test did. */
function boundedStopClean($root, $process)
{
    foreach (['hermes', 'setsid', 'child', 'service'] as $name) {
        $pid = boundedStopPid($root, $name);
        if ($pid > 1) {
            @posix_kill($pid, 9);
        }
    }
    if (is_resource($process)) {
        proc_terminate($process, 9);
        proc_close($process);
    }
    testRmTree($root);
}

// Hermes that ignores TERM, with a helper in its own session, a plain child that ignores it too, and a service-named process.
const BOUNDED_STOP_STUBBORN = <<<'SH'
trap '' TERM HUP
echo $$ > "$D/pid-hermes"
sh -c '"$0/jellyfin" 300 & echo $! > "$0/pid-service"; wait $!' "$D" &
setsid sh -c 'trap "" TERM HUP; echo $$ > "$0/pid-setsid"; while :; do sleep 1; done' "$D" &
sh -c 'trap "" TERM HUP; echo $$ > "$0/pid-child"; while :; do sleep 1; done' "$D" &
sleep 1
echo ready > "$D/ready"
while :; do sleep 1; done
SH;

return [
    'bounded stop: killable finds Hermes and what it started, never a client, its parents or its children' => function () {
        $proc = testTempDir('bounded-stop-proc');
        try {
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            // 41000 Hermes -> 41001 sh -> 41002 helper; 41000 -> 41003 sh -> 41004 rtorrent -> 41005 child;
            // 41000 -> 41006 "rtorrent main" (a name with a space); 41000 -> 41007 zombie; 41008 reparented helper (known);
            // 41009 reparented, unknown; 41010 pid reused (known with another start time); 41011 an unrelated process.
            $rows = [[41000, 'hermes', 1, 10], [41001, 'sh', 41000, 11], [41002, 'helper', 41001, 12], [41003, 'sh', 41000, 13],
                [41004, 'rtorrent', 41003, 14], [41005, 'worker', 41004, 15], [41006, 'rtorrent main', 41000, 16], [41007, 'gone', 41000, 17, 'Z'],
                [41008, 'detached (gw)', 1, 18], [41009, 'other', 1, 19], [41010, 'reused', 1, 99], [41011, 'unrelated', 1, 21]];
            foreach ($rows as $r) {
                @mkdir($proc . '/' . $r[0], 0700);
                file_put_contents($proc . '/' . $r[0] . '/stat', boundedStopStat($r[0], $r[1], $r[2], $r[3], isset($r[4]) ? $r[4] : 'S'));
            }
            $spare = ['rtorrent', 'rtorrent main', 'qbittorrent-nox'];
            $got = OwnProcs::killable(41000, [41008 => '18', 41009 => '77', 41010 => '20', 41005 => '15'], $spare, $uid, $proc);
            ksort($got);
            check($got === [41000 => '10', 41001 => '11', 41002 => '12', 41008 => '18'], 'killable returned ' . json_encode($got));
            check(OwnProcs::killable(-1, [41008 => '18'], $spare, $uid, $proc) === [41008 => '18'], 'with no root only the known pairs may be returned');
            check(OwnProcs::killable(41000, [], $spare, $uid + 1, $proc) === [], 'another account\'s processes must never be returned');
        } finally {
            testRmTree($proc);
        }
    },
    'bounded stop: SIGTERM ends a Hermes tree that ignores it within the grace and leaves a known service started under it running' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                $pids = ['hermes' => boundedStopPid($root, 'hermes'), 'setsid' => boundedStopPid($root, 'setsid'), 'child' => boundedStopPid($root, 'child')];
                foreach ($pids as $name => $pid) {
                    check($pid > 1 && OwnProcs::started($pid) !== null, "fixture $name is not running");
                }
                if (OwnProcs::started(boundedStopPid($root, 'service')) === null) {
                    skip('the fixture service did not start (sleep is not a plain program here)');
                }
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                $took = boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'], 'the resident did not end within three times the grace');
                check($took >= EternalChild::GRACE - 1, "the fixture must ignore TERM, but the stop took $took s");
                // About GRACE + 1 s on an idle machine; the margin keeps a busy test machine from failing it. Minutes would be the old behaviour.
                check($took <= EternalChild::GRACE * 2, "the stop took $took s");
                for ($i = 0; $i < 20; $i++) {
                    usleep(100000);
                }
                foreach ($pids as $name => $pid) {
                    check(OwnProcs::started($pid) === null, "the $name process is still running after the stop");
                }
                $client = boundedStopPid($root, 'service');
                check(OwnProcs::started($client) !== null, 'the known service started under Hermes was ended');
                check(is_file($root . '/local/eternals/pm2.stop'), 'the stop marker was not written');
                check(is_file($root . '/local/eternals/pm2'), 'a signalled stop must keep the window record');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: SIGTERM is passed on to a Hermes that obeys it, and the window ends' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture("echo \$\$ > \"\$D/pid-hermes\"\ntrap 'echo obeyed > \"\$D/obeyed\"; exit 0' TERM\necho ready > \"\$D/ready\"\nwhile :; do sleep 0.1; done\n");
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'], 'the resident did not end');
                check(is_file($root . '/obeyed'), 'Hermes did not get SIGTERM, or was killed before it could act on it');
                check(is_file($root . '/local/eternals/pm2.stop') && is_file($root . '/local/eternals/pm2'), 'marker or record missing after the stop');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: the callers\' 10 s wait (eternal off, hold) sees the window gone when Hermes and its helpers ignore SIGTERM' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                $took = boundedStopCallerWait($root, 10);
                fwrite(STDERR, sprintf("[measured] the run lock was free %.2f s after SIGTERM (callers wait 10 s)\n", $took));
                check($took !== false, 'the window still held its run lock 10 s after SIGTERM, which is what eternal off and hold wait');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: processes of other accounts and of other sessions are left untouched by a stop' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $others = [];
        foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
            $pid = (int) substr($dir, 6);
            if (@fileowner($dir) !== $uid && $pid > 1 && OwnProcs::started($pid, '/proc') !== null) {
                $others[$pid] = OwnProcs::started($pid, '/proc');
            }
        }
        $others[1] = OwnProcs::started(1, '/proc');
        $mine = [getmypid() => OwnProcs::started(getmypid(), '/proc')];
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process, $others, $mine) {
                $process = boundedStopStart($root);
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'], 'the resident did not end');
                foreach ($others + $mine as $pid => $start) {
                    check(OwnProcs::started($pid, '/proc') === $start, "process $pid (not part of the window) was ended or replaced by the stop");
                }
                fwrite(STDERR, sprintf("[measured] %d foreign processes and the test runner itself were still running after the stop\n", count($others) + count($mine)));
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: a service an agent detached from the window (own session, reparented) is not part of the tree and keeps running' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture("trap '' TERM HUP\necho \$\$ > \"\$D/pid-hermes\"\n(setsid sh -c 'echo \$\$ > \"\$0/pid-service\"; exec sleep 300' \"\$D\" &)\nsleep 1\necho ready > \"\$D/ready\"\nwhile :; do sleep 1; done\n");
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                $service = boundedStopPid($root, 'service');
                check($service > 1 && OwnProcs::started($service) !== null, 'the detached fixture service is not running');
                $ppid = (int) OwnProcs::tree(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())[$service]['ppid'];
                check($ppid !== boundedStopPid($root, 'hermes'), 'the fixture service is still Hermes\' child, so it is not detached');
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'] && OwnProcs::started(boundedStopPid($root, 'hermes')) === null, 'the window did not stop');
                check(OwnProcs::started($service) !== null, 'the detached service was ended by the stop');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: torrent client and service names started under Hermes (rtorrent, rtorrent main, rclone, jellyfin) survive while its other children do not' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $body = "trap '' TERM HUP\necho \$\$ > \"\$D/pid-hermes\"\n";
        foreach (['rtorrent' => 'a', 'rtorrent main' => 'b', 'rclone' => 'c', 'jellyfin' => 'd'] as $name => $key) {
            $body .= "\"\$D/$name\" 300 &\necho \$! > \"\$D/pid-app$key\"\n";
        }
        $body .= "sh -c 'trap \"\" TERM HUP; echo \$\$ > \"\$0/pid-child\"; while :; do sleep 1; done' \"\$D\" &\nsleep 1\necho ready > \"\$D/ready\"\nwhile :; do sleep 1; done\n";
        $root = boundedStopFixture($body);
        // Scripts, not links to sleep: where sleep is a multi-call program it runs only under its own name. A script's
        // process name is its file name, so each one is seen as rtorrent, "rtorrent main", rclone or jellyfin.
        @unlink($root . '/jellyfin');
        foreach (['rtorrent', 'rtorrent main', 'rclone', 'jellyfin'] as $name) {
            file_put_contents($root . '/' . $name, "#!/bin/sh\nwhile :; do sleep 1; done\n");
            chmod($root . '/' . $name, 0700);
        }
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                $apps = [];
                foreach (['a', 'b', 'c', 'd'] as $key) {
                    $apps[$key] = boundedStopPid($root, 'app' . $key);
                    check($apps[$key] > 1 && OwnProcs::started($apps[$key]) !== null, "fixture app $key is not running");
                }
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'], 'the resident did not end');
                check(OwnProcs::started(boundedStopPid($root, 'child')) === null, 'the plain child is still running after the stop');
                foreach ($apps as $key => $pid) {
                    check(OwnProcs::started($pid) !== null, "the known service app $key started under Hermes was ended by the stop");
                }
            });
        } finally {
            foreach (['a', 'b', 'c', 'd'] as $key) {
                $pid = boundedStopPid($root, 'app' . $key);
                if ($pid > 1) {
                    @posix_kill($pid, 9);
                }
            }
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: a Hermes that dies of SIGTERM ends the resident with 128 + 15 and the stop marker, and an ordinary failing exit is still its own code' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture("echo \$\$ > \"\$D/pid-hermes\"\necho ready > \"\$D/ready\"\nwhile :; do sleep 0.1; done\n");
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                posix_kill(proc_get_status($process)['pid'], SIGTERM);
                boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'], 'the resident did not end');
                check($GLOBALS['boundedStopCode'] === 143, 'the resident exited ' . var_export($GLOBALS['boundedStopCode'], true) . ', not 143 (128 + SIGTERM)');
                check(is_file($root . '/local/eternals/pm2.stop') && is_file($root . '/local/eternals/pm2'), 'marker or record missing');
            });
        } finally {
            boundedStopClean($root, $process);
        }
        $root = boundedStopFixture("echo ready > \"\$D/ready\"\nsleep 1\nexit 3\n");
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                boundedStopWait($process, 10);
                check(!proc_get_status($process)['running'], 'the resident did not end after Hermes exited 3');
                check($GLOBALS['boundedStopCode'] === 3, 'the resident exited ' . var_export($GLOBALS['boundedStopCode'], true) . ', not 3');
                $record = json_decode((string) @file_get_contents($root . '/local/eternals/pm2'), true);
                check(is_array($record) && $record['fails'] === 1 && !is_file($root . '/local/eternals/pm2.stop'), 'a failing exit must count one failure and write no stop marker: ' . json_encode($record));
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: what Hermes prints reaches the window through the resident, as it did under passthru' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture("printf 'first line\\n'\nprintf 'no newline at the end'\nprintf 'to stderr\\n' >&2\necho ready > \"\$D/ready\"\nsleep 1\nexit 0\n");
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                boundedStopWait($process, 10);
                check(!proc_get_status($process)['running'], 'the resident did not end');
                check($GLOBALS['boundedStopCode'] === 0, 'a Hermes exit 0 must end the resident with 0: ' . var_export($GLOBALS['boundedStopCode'], true));
                // The runner requires bin/pikkumies, so the script's own shebang line comes out first; that is the fixture, not Hermes.
                $stdout = (string) @file_get_contents($root . '/stdout');
                check(substr($stdout, -strlen("first line\nno newline at the end")) === "first line\nno newline at the end" && substr_count($stdout, 'first line') === 1, 'stdout was ' . json_encode($stdout));
                check(strpos((string) @file_get_contents($root . '/stderr'), "to stderr\n") !== false, 'stderr did not reach the window');
                check(!is_file($root . '/local/eternals/pm2'), 'a user quit (Hermes exit 0) must remove an extra window\'s record');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: after a bounded stop the same window starts again and is bounded again (the restart a new build needs)' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                for ($round = 1; $round <= 2; $round++) {
                    @unlink($root . '/ready');
                    @unlink($root . '/local/eternals/pm2.stop');
                    $process = boundedStopStart($root);
                    $hermes = boundedStopPid($root, 'hermes');
                    check($hermes > 1 && OwnProcs::started($hermes) !== null, "round $round: Hermes did not start");
                    posix_kill(proc_get_status($process)['pid'], SIGTERM);
                    $took = boundedStopWait($process, EternalChild::GRACE * 3);
                    check(!proc_get_status($process)['running'] && $took <= EternalChild::GRACE * 2, "round $round: the stop took $took s");
                    check(OwnProcs::started($hermes) === null, "round $round: Hermes is still running after the stop");
                    check(is_file($root . '/local/eternals/pm2'), "round $round: the window record was lost");
                    proc_close($process);
                    $process = null;
                }
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: SIGHUP without a stop marker (a person closing the window) is not a bounded stop' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                posix_kill(proc_get_status($process)['pid'], SIGHUP);
                boundedStopWait($process, 3);
                check(proc_get_status($process)['running'] && OwnProcs::started(boundedStopPid($root, 'hermes')) !== null, 'a HUP without a marker ended the window');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
    'bounded stop: SIGHUP once a stop marker exists (the update, off and hold stops) ends the tree within the grace' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                file_put_contents($root . '/local/eternals/pm2.stop', "stop\n");
                posix_kill(proc_get_status($process)['pid'], SIGHUP);
                $took = boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'] && $took <= EternalChild::GRACE * 2, "a HUP with the marker did not end the window within the grace ($took s)");
                check(OwnProcs::started(boundedStopPid($root, 'hermes')) === null, 'Hermes is still running after a marked HUP');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
];
