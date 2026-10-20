<?php
return [
    'eternal: a live server HUP quits, while a marked stop or vanished server resumes' => function () {
        $root = testTempDir('eternal-hup');
        @mkdir($root . '/bin', 0700);
        @mkdir($root . '/records', 0700);
        $tmux = $root . '/bin/tmux';
        file_put_contents($tmux, "#!/bin/sh\nexit 0\n");
        chmod($tmux, 0700);
        $path = getenv('PATH');
        putenv('PATH=' . $root . '/bin:' . $path);
        EternalRecord::$base = $root . '/records';
        Eternal::$tmuxDirectory = $root;
        try {
            EternalRecord::write('pm2', ['sessionId' => 'old', 'fails' => 0, 'lastStart' => 1]);
            Eternal::afterExit('pm2', 129, time(), SIGHUP);
            check(EternalRecord::read('pm2') === null, 'closed extra window restarted');
            EternalRecord::write('pm1', ['sessionId' => 'old', 'fails' => 0, 'lastStart' => 1]);
            Eternal::afterExit('pm1', 129, time(), SIGHUP);
            check(EternalRecord::read('pm1')['sessionId'] === '', 'closed Primary reused conversation');
            check(is_file(Eternal::quitPath('pm1')), 'closed Primary did not report quit');
            EternalRecord::write('pm2', ['sessionId' => 'old', 'fails' => 0, 'lastStart' => 1]);
            check(Eternal::shutdown('pm2'), 'shutdown could not mark stop');
            Eternal::afterExit('pm2', 129, time(), SIGHUP);
            check(EternalRecord::read('pm2')['sessionId'] === 'old', 'marked stop lost record');
            unlink(Eternal::stopPath('pm2'));
            file_put_contents($tmux, "#!/bin/sh\nexit 1\n");
            Eternal::afterExit('pm2', 129, time(), SIGHUP);
            check(EternalRecord::read('pm2')['sessionId'] === 'old', 'reboot lost record');
            Eternal::afterExit('pm2', 1, time(), 0);
            check(EternalRecord::read('pm2')['fails'] === 1, 'preflight failure did not back off');
        } finally {
            EternalRecord::$base = null;
            Eternal::$tmuxDirectory = null;
            putenv('PATH=' . $path);
            testRmTree($root);
        }
    },
    'eternal: wrapper ignores Ctrl-C so a later deliberate quit closes the window' => function () {
        $root = testTempDir('eternal-interrupt-quit');
        $source = dirname(__DIR__) . '/pikkumies';
        foreach (['bin', 'lib', 'tools', 'doctrine'] as $dir) {
            exec('cp -R ' . escapeshellarg($source . '/' . $dir) . ' ' . escapeshellarg($root) . '/');
        }
        @mkdir($root . '/include', 0700);
        @mkdir($root . '/home', 0700);
        @mkdir($root . '/hermes/.hermes/bin', 0700, true);
        file_put_contents($root . '/enabled', "1\n");
        file_put_contents($root . '/include/.hermes-ready', "ready\n");
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Test -c user.email=test@example.invalid commit -q --allow-empty -m seed');
        $hermes = $root . '/hermes/.hermes/bin/hermes';
        file_put_contents($hermes, "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do [ \"\$(readlink \"\$fd\")\" = '" . $root . "/local/eternals/pm2.run' ] && echo leaked > '" . $root . "/run-lock-leak'; done\necho started > '" . $root . "/started'\nwhile [ ! -f '" . $root . "/allow-exit' ]; do sleep 0.1; done\nexit 0\n");
        chmod($hermes, 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; HermesInstall::$root = __DIR__; Eternal::$hostMarker = __DIR__ . "/enabled"; Update::$crontab = "/bin/false"; Config::acceptTerms(); HermesInstall::markVerified(HermesInstall::head()); EternalRecord::write("pm2", EternalRecord::initial()); require __DIR__ . "/bin/pikkumies";');
        $process = null;
        try {
            testWithEnv(['HOME' => $root . '/home', 'PIKKUMIES_KEY' => 'fixture-key'], function () use ($runner, $root, &$process) {
                $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' run --eternal pm2';
                $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/stdout', 'w'], 2 => ['file', $root . '/stderr', 'w']], $pipes);
                check(is_resource($process), 'could not start resident');
                for ($i = 0; $i < 100 && !is_file($root . '/started') && proc_get_status($process)['running']; $i++) {
                    usleep(100000);
                }
                check(is_file($root . '/started'), 'resident did not start: ' . (string) @file_get_contents($root . '/stdout') . ' ' . (string) @file_get_contents($root . '/stderr'));
                check(!is_file($root . '/run-lock-leak'), 'Hermes child inherited the per-window run lock');
                posix_kill(proc_get_status($process)['pid'], SIGINT);
                usleep(100000);
                file_put_contents($root . '/allow-exit', "1\n");
                $code = proc_close($process);
                $process = null;
                check($code === 0 && !is_file($root . '/local/eternals/pm2'), 'SIGINT then child exit did not close the window: ' . $code . ' ' . (string) @file_get_contents($root . '/stdout'));
            });
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            testRmTree($root);
        }
    },
    'eternal: transfer waits for resident run locks to close' => function () {
        $root = testTempDir('eternal-wait-stop');
        EternalRecord::$base = $root;
        try {
            EternalRecord::write('pm2', EternalRecord::initial());
            $lock = EternalLock::take(EternalRecord::path('pm2') . '.run');
            check(is_resource($lock) && !Eternal::waitStopped(0.01), 'hold treated a live resident as stopped');
            fclose($lock);
            check(Eternal::waitStopped(0.01), 'hold did not see the resident exit');
        } finally {
            EternalRecord::$base = null;
            testRmTree($root);
        }
    },
    'eternal: ensure runs before a held cron job lock, and absent marker is inert' => function () {
        $root = testTempDir('eternal-tick');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/bin') . ' ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/local', 0700);
        @mkdir($root . '/fake-bin', 0700);
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\necho called >> '" . $root . "/tmux-called'\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        $marker = $root . '/enabled';
        $bootstrap = $root . '/bootstrap.php';
        file_put_contents($bootstrap, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; require __DIR__ . "/bin/pikkumies";');
        $lock = fopen($root . '/local/cron.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            list($code) = runPhp($bootstrap, ['cron-tick']);
            check($code === 0 && !is_file($root . '/local/eternals/pm1.state') && !is_file($root . '/tmux-called'), 'missing marker changed Eternal state or called tmux');
            file_put_contents($marker, "1\n");
            list($code) = runPhp($bootstrap, ['cron-tick']);
            check($code === 0 && is_file($root . '/local/eternals/pm1.state'), 'ensure skipped because cron job lock was held');
            $state = file_get_contents($root . '/local/eternals/pm1.state');
            unlink($marker);
            list($code) = runPhp($bootstrap, ['cron-tick']);
            check($code === 0 && file_get_contents($root . '/local/eternals/pm1.state') === $state && !is_file($root . '/tmux-called'), 'missing marker changed existing Eternal state or called tmux');
        } finally {
            fclose($lock);
            putenv('PATH=' . $oldPath);
            testRmTree($root);
        }
    },
    'eternal: transfer hold persists before stopping a managed pane' => function () {
        $root = testTempDir('eternal-hold-stop');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/bin', 0700);
        @mkdir($root . '/fake-bin', 0700);
        file_put_contents($root . '/enabled', "1\n");
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/pikkumies') . ' run --eternal pm1';
        $resident = proc_open('exec sleep 30', [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        check(is_resource($resident), 'could not start test resident');
        $residentPid = proc_get_status($resident)['pid'];
        file_put_contents($root . '/row', 'pm1|' . $command . '|' . $residentPid . "\n");
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do [ \"\$(readlink \"\$fd\")\" = '" . $root . "/local/eternals.lock' ] && echo leaked >> '" . $root . "/leak'; done\nif [ \"\$1\" = list-windows ]; then cat '" . $root . "/row'; fi\necho \"\$1\" >> '" . $root . "/calls'\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; exit(EternalCli::run(array_slice($argv, 1)));');
        $oldPath = getenv('PATH');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        try {
            list($code) = runPhp($runner, ['hold', '--until', (string) (time() + 3600)]);
            check($code === 0 && is_file($root . '/local/eternals.hold'), 'hold was not persisted');
            check(is_file($root . '/local/eternals/pm1.stop'), 'managed pane lacked stop marker: ' . (string) @file_get_contents($root . '/calls') . ' row=' . (string) @file_get_contents($root . '/row'));
            check(strpos(file_get_contents($root . '/calls'), 'kill-window') !== false, 'managed pane was not stopped');
            check(!proc_get_status($resident)['running'], 'hold did not send SIGTERM to resident');
            check(!is_file($root . '/leak'), 'hold lock leaked into tmux child');
        } finally {
            proc_terminate($resident);
            proc_close($resident);
            putenv('PATH=' . $oldPath);
            testRmTree($root);
        }
    },
    'eternal: without marker interactive tmux uses the ordinary server and plain run' => function () {
        $root = testTempDir('eternal-legacy-tmux');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/bin') . ' ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/fake-bin', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/missing-marker"; require __DIR__ . "/bin/pikkumies";');
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\necho \"\$@\" >> '" . $root . "/calls'\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $oldPath = getenv('PATH');
        $oldTmux = getenv('TMUX');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        try {
            putenv('TMUX=/ordinary/socket,1,0');
            list($code) = runPhp($runner, ['tmux', '2']);
            $calls = (string) file_get_contents($root . '/calls');
            check($code === 0 && strpos($calls, 'switch-client') !== false && strpos($calls, 'attach-session') === false, 'legacy tmux did not switch');
            check(strpos($calls, 'run --eternal') === false && strpos($calls, '-L ') === false && !is_dir($root . '/local/eternals'), 'markerless tmux moved socket or made Eternal state');
            list($residentCode, $residentOut) = runPhp($runner, ['run', '--eternal', 'pm2']);
            check($residentCode === 1 && strpos($residentOut, 'not enabled') !== false, 'markerless resident was accepted');
            check(!is_dir($root . '/local/tmux') && !is_dir($root . '/local/tmp') && !file_exists($root . '/local/eternals/pm2.run'), 'markerless resident wrote Eternal state');
        } finally {
            putenv('PATH=' . $oldPath);
            $oldTmux === false ? putenv('TMUX') : putenv('TMUX=' . $oldTmux);
            testRmTree($root);
        }
    },
    'eternal: without marker missing tmux prints the upstream guidance' => function () {
        $root = testTempDir('eternal-missing-tmux');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/bin') . ' ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/missing-marker"; require __DIR__ . "/bin/pikkumies";');
        try {
            testWithEnv(['PATH' => $root], function () use ($runner) {
                list($code, $out) = runPhp($runner, ['tmux']);
                // Exact 8b17c97 tmux-not-installed output; kept inline for shallow CI checkouts.
                $upstream = 'tmux is not installed on this machine. Plain `pikkumies` starts a session without it; install tmux (or ask your provider) to use `pikkumies tmux`.' . "\n";
                check($code === 1 && $out === $upstream, 'markerless tmux guidance differs from 8b17c97: ' . $out);
            });
        } finally {
            testRmTree($root);
        }
    },
    'eternal: without marker dashboard build leaves tmux untouched' => function () {
        $root = testTempDir('eternal-dashboard-build');
        $source = dirname(__DIR__) . '/pikkumies';
        foreach (['bin', 'lib', 'tools', 'doctrine'] as $dir) {
            exec('cp -R ' . escapeshellarg($source . '/' . $dir) . ' ' . escapeshellarg($root) . '/');
        }
        @mkdir($root . '/include', 0700);
        @mkdir($root . '/home', 0700);
        @mkdir($root . '/fake-bin', 0700);
        @mkdir($root . '/hermes/.hermes/bin', 0700, true);
        file_put_contents($root . '/include/.hermes-ready', "ready\n");
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Test -c user.email=test@example.invalid commit -q --allow-empty -m seed');
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\necho called >> '" . $root . "/tmux-calls'\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $hermes = $root . '/hermes/.hermes/bin/hermes';
        file_put_contents($hermes, "#!/bin/sh\nif [ \"\$1\" = --run-module ]; then mkdir -p '" . $root . "/hermes/hermes_cli/web_dist'; echo ready > '" . $root . "/hermes/hermes_cli/web_dist/index.html'; echo built > '" . $root . "/built'; fi\nexit 0\n");
        chmod($hermes, 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; HermesInstall::$root = __DIR__; Eternal::$hostMarker = __DIR__ . "/missing-marker"; require __DIR__ . "/bin/pikkumies";');
        try {
            testWithEnv(['HOME' => $root . '/home', 'PATH' => $root . '/fake-bin:' . getenv('PATH'), 'HERMES_WEB_DIST' => null], function () use ($runner, $root) {
                list($code, $out) = runPhp($runner, ['run', 'dashboard', '--host', '0.0.0.0']);
                check(is_file($root . '/built'), 'dashboard build path was not reached: ' . $code . ' ' . $out);
                check(!is_file($root . '/tmux-calls') && !is_file($root . '/local/eternals.lock'), 'markerless dashboard build touched Eternal state');
            });
        } finally {
            testRmTree($root);
        }
    },
    'eternal: transfer hold refuses manual tmux and new before creating a window' => function () {
        $root = testTempDir('eternal-manual-hold');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/bin') . ' ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/local', 0700);
        file_put_contents($root . '/enabled', "1\n");
        file_put_contents($root . '/local/eternals.hold', json_encode(['until' => time() + 600]));
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; require __DIR__ . "/bin/pikkumies";');
        try {
            list($tmuxCode, $tmuxOut) = runPhp($runner, ['tmux', '2']);
            list($newCode, $newOut) = runPhp($runner, ['eternal', 'new']);
            check($tmuxCode === 1 && strpos($tmuxOut, 'held for transfer') !== false, 'manual tmux ignored hold');
            check($newCode === 1 && strpos($newOut, 'held for transfer') !== false, 'eternal new ignored hold');
            check(!is_dir($root . '/local/eternals') && !is_dir($root . '/local/tmux'), 'hold created a window or private socket');
        } finally {
            testRmTree($root);
        }
    },
];
