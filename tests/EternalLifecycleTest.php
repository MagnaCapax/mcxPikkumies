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
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\necho \"\$@\" >> '" . $root . "/calls'\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        try {
            list($code) = runPhp($root . '/bin/pikkumies', ['tmux', '2']);
            $calls = (string) file_get_contents($root . '/calls');
            check($code === 0 && strpos($calls, 'attach-session') !== false, 'legacy tmux did not attach');
            check(strpos($calls, 'run --eternal') === false && strpos($calls, '-L ') === false && !is_dir($root . '/local/eternals'), 'markerless tmux moved socket or made Eternal state');
        } finally {
            putenv('PATH=' . $oldPath);
            testRmTree($root);
        }
    },
];
