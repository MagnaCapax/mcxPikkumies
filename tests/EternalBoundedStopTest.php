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
    file_put_contents($root . '/local/config.php', '<?php return ' . var_export(['provider' => ['model' => 'fixture']], true) . ';');
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
    while (proc_get_status($process)['running'] && microtime(true) - $start < $max) {
        usleep(50000);
    }
    return microtime(true) - $start;
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
    'bounded stop: SIGHUP without a stop marker (a person closing the window) is not a bounded stop; with the marker it is' => function () {
        if (!function_exists('pcntl_signal')) {
            skip('needs pcntl');
        }
        $root = boundedStopFixture(BOUNDED_STOP_STUBBORN);
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($root, &$process) {
                $process = boundedStopStart($root);
                $resident = proc_get_status($process)['pid'];
                posix_kill($resident, SIGHUP);
                boundedStopWait($process, 3);
                check(proc_get_status($process)['running'] && OwnProcs::started(boundedStopPid($root, 'hermes')) !== null, 'a HUP without a marker ended the window');
                file_put_contents($root . '/local/eternals/pm2.stop', "stop\n");
                posix_kill($resident, SIGHUP);
                $took = boundedStopWait($process, EternalChild::GRACE * 3);
                check(!proc_get_status($process)['running'] && $took <= EternalChild::GRACE * 2, "a HUP with the marker did not end the window within the grace ($took s)");
                check(OwnProcs::started(boundedStopPid($root, 'hermes')) === null, 'Hermes is still running after a marked HUP');
            });
        } finally {
            boundedStopClean($root, $process);
        }
    },
];
