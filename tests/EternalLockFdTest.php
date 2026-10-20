<?php
return [
    'eternal: tmux launcher child does not inherit the lifecycle lock' => function () {
        $root = testTempDir('eternal-tmux-fd');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/bin') . ' ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/fake-bin', 0700);
        file_put_contents($root . '/enabled', "1\n");
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do [ \"\$(readlink \"\$fd\")\" = '" . $root . "/local/eternals.lock' ] && echo leaked >> '" . $root . "/leak'; done\necho \"\$@\" >> '" . $root . "/calls'\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; require __DIR__ . "/bin/pikkumies";');
        $oldPath = getenv('PATH');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        try {
            list($code) = runPhp($runner, ['tmux', '2']);
            check($code === 0 && is_file($root . '/local/eternals/pm2'), 'managed tmux did not create record');
            check(!is_file($root . '/leak'), 'tmux child inherited lifecycle lock');
        } finally {
            putenv('PATH=' . $oldPath);
            testRmTree($root);
        }
    },
    'eternal: ensure and new start tmux without inheriting their lifecycle locks' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (!Eternal::cgroupAllows(@file_get_contents('/proc/self/cgroup'), $uid)) {
            skip('requires an account user slice');
        }
        $usage = Cgroup::usageAtLimit();
        if (($usage['pids_max'] !== null && $usage['pids_current'] !== null && $usage['pids_max'] - $usage['pids_current'] < 1)
            || ($usage['memory_max'] !== null && $usage['memory_current'] !== null && $usage['memory_max'] - $usage['memory_current'] < 134217728)) {
            skip('requires room for one more window');
        }
        $root = testTempDir('eternal-spawn-fd');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/tools/system', 0700, true);
        exec('cp -R ' . escapeshellarg($source . '/tools/system/credits') . ' ' . escapeshellarg($root . '/tools/system') . '/');
        @mkdir($root . '/fake-bin', 0700);
        @mkdir($root . '/include', 0700);
        @mkdir($root . '/local', 0700);
        @mkdir($root . '/hermes/.hermes/bin', 0700, true);
        file_put_contents($root . '/enabled', "1\n");
        file_put_contents($root . '/include/.hermes-ready', "ready\n");
        file_put_contents($root . '/hermes/.hermes/bin/hermes', "#!/bin/sh\nexit 0\n");
        chmod($root . '/hermes/.hermes/bin/hermes', 0700);
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Test -c user.email=test@example.invalid commit -q --allow-empty -m seed');
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do [ \"\$(readlink \"\$fd\")\" = '" . $root . "/local/eternals.lock' ] && echo leaked >> '" . $root . "/leak'; done\n[ \"\$1\" = -L ] && shift 2\necho \"\$1\" >> '" . $root . "/calls'\n[ \"\$1\" = list-windows ] && exit 1\nexit 0\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; HermesInstall::$root = __DIR__; Eternal::$hostMarker = __DIR__ . "/enabled"; Eternal::$tmuxDirectory = __DIR__; Eternal::$tmuxSocket = "fixture"; Update::$crontab = "/bin/false"; CronTick::$mightEscalateOverride = false; HermesInstall::markVerified(HermesInstall::head()); if ($argv[1] === "ensure") { Eternal::ensure(); exit(0); } exit(EternalCli::run(["new"]));');
        $oldPath = getenv('PATH');
        $oldKey = getenv('PIKKUMIES_KEY');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        putenv('PIKKUMIES_KEY=fixture-key');
        try {
            list($code, $out) = runPhp($runner, ['ensure']);
            check($code === 0 && is_file($root . '/local/eternals/pm1'), 'ensure did not start Primary: ' . $code . ' ' . $out . ' files=' . json_encode(glob($root . '/local/eternals/*')));
            list($code, $out) = runPhp($runner, ['new']);
            check($code === 0 && trim($out) === 'pm2', 'new did not start extra window: ' . $code . ' ' . $out);
            check(!is_file($root . '/leak'), 'ensure or new passed lifecycle lock to tmux');
        } finally {
            putenv('PATH=' . $oldPath);
            $oldKey === false ? putenv('PIKKUMIES_KEY') : putenv('PIKKUMIES_KEY=' . $oldKey);
            testRmTree($root);
        }
    },
    'eternal: UI child does not inherit a held lifecycle lock' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (!Eternal::cgroupAllows(@file_get_contents('/proc/self/cgroup'), $uid)) {
            skip('requires an account user slice');
        }
        $root = testTempDir('eternal-ui-fd');
        exec('cp -R ' . escapeshellarg(dirname(__DIR__) . '/pikkumies/lib') . ' ' . escapeshellarg($root) . '/');
        file_put_contents($root . '/enabled', "1\n");
        $binary = $root . '/ui-child';
        file_put_contents($binary, "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do [ \"\$(readlink \"\$fd\")\" = '" . $root . "/local/eternals.lock' ] && echo leaked >> '" . $root . "/leak'; done\necho done > '" . $root . "/done'\n");
        chmod($binary, 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; Eternal::$tmuxDirectory = __DIR__; EternalUi::$binary = __DIR__ . "/ui-child"; $lock = Eternal::lock(); $problem = EternalUi::start(); fclose($lock); echo $problem; exit($problem === "" ? 0 : 1);');
        $names = ['AGENTBOARD_LAUNCHER_URL', 'AGENTBOARD_ALLOWED_ORIGIN', 'AGENTBOARD_FRAME_ANCESTORS'];
        $old = [];
        foreach ($names as $name) {
            $old[$name] = getenv($name);
            putenv($name . '=https://example.invalid');
        }
        try {
            list($code, $out) = runPhp($runner, []);
            for ($n = 0; $n < 20 && !is_file($root . '/done'); $n++) {
                usleep(100000);
            }
            check($code === 0 && is_file($root . '/done'), 'UI child did not start: ' . $out);
            check(!is_file($root . '/leak'), 'UI child inherited lifecycle lock');
        } finally {
            foreach ($old as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
            testRmTree($root);
        }
    },
    'eternal: update child does not inherit lifecycle locks' => function () {
        $root = testTempDir('eternal-update-fd');
        $source = dirname(__DIR__) . '/pikkumies';
        foreach (['bin', 'lib', 'tools', 'doctrine'] as $dir) {
            exec('cp -R ' . escapeshellarg($source . '/' . $dir) . ' ' . escapeshellarg($root) . '/');
        }
        @mkdir($root . '/include', 0700);
        @mkdir($root . '/local', 0700);
        @mkdir($root . '/home', 0700);
        @mkdir($root . '/fake-bin', 0700);
        @mkdir($root . '/hermes/.hermes/bin', 0700, true);
        file_put_contents($root . '/include/.hermes-ready', "ready\n");
        $binary = $root . '/hermes/.hermes/bin/hermes';
        file_put_contents($binary, "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do path=\$(readlink \"\$fd\"); case \"\$path\" in '" . $root . "/local/eternals.lock'|'" . $root . "/local/eternals/'*.run) echo leaked >> '" . $root . "/leak';; esac; done\necho invoked > '" . $root . "/updater-ran'\nexit 0\n");
        chmod($binary, 0700);
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Test -c user.email=test@example.invalid commit -q --allow-empty -m seed');
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' remote add origin ' . escapeshellarg('file://' . $root . '/remote'));
        file_put_contents($root . '/fake-bin/tmux', "#!/bin/sh\necho called >> '" . $root . "/tmux-calls'\nexit 1\n");
        chmod($root . '/fake-bin/tmux', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; HermesInstall::$root = __DIR__; HermesInstall::$repo = "file://" . __DIR__ . "/remote"; Eternal::$hostMarker = __DIR__ . "/missing-marker"; Eternal::$tmuxDirectory = __DIR__; require __DIR__ . "/bin/pikkumies";');
        $oldPath = getenv('PATH');
        $oldHome = getenv('HOME');
        $oldKey = getenv('PIKKUMIES_KEY');
        $oldTmux = getenv('TMUX');
        putenv('PATH=' . $root . '/fake-bin:' . $oldPath);
        putenv('HOME=' . $root . '/home');
        putenv('PIKKUMIES_KEY=fixture-key');
        putenv('TMUX=/ordinary/socket,1,0');
        try {
            list($code, $out) = runPhp($runner, ['run', 'update']);
            check(is_file($root . '/updater-ran'), 'update child did not run: ' . $code . ' ' . $out);
            check(!is_file($root . '/leak'), 'update child inherited a lifecycle lock');
            check(!is_file($root . '/tmux-calls'), 'markerless update called tmux');
            check(!is_dir($root . '/local/tmux') && !is_dir($root . '/local/tmp') && !is_file($root . '/local/eternals.lock'), 'markerless update made Eternal state');
        } finally {
            putenv('PATH=' . $oldPath);
            $oldHome === false ? putenv('HOME') : putenv('HOME=' . $oldHome);
            $oldKey === false ? putenv('PIKKUMIES_KEY') : putenv('PIKKUMIES_KEY=' . $oldKey);
            $oldTmux === false ? putenv('TMUX') : putenv('TMUX=' . $oldTmux);
            testRmTree($root);
        }
    },
    'eternal: without marker Hermes child inherits the shared runtime lock' => function () {
        $root = testTempDir('hermes-lock-inherit');
        @mkdir($root . '/lib', 0700);
        copy(dirname(__DIR__) . '/pikkumies/lib/HermesInstall.php', $root . '/lib/HermesInstall.php');
        copy(dirname(__DIR__) . '/pikkumies/lib/Eternal.php', $root . '/lib/Eternal.php');
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php require __DIR__ . "/lib/HermesInstall.php"; require __DIR__ . "/lib/Eternal.php"; Eternal::$hostMarker = __DIR__ . "/missing-marker"; HermesInstall::$root = __DIR__; $lock = HermesInstall::lock(false); if (!$lock) exit(2); passthru("/bin/sh " . escapeshellarg(__DIR__ . "/child.sh"), $code); exit($code);');
        file_put_contents($root . '/child.sh', "#!/bin/sh\nfor fd in /proc/\$\$/fd/*; do [ \"\$(readlink \"\$fd\")\" = '" . $root . "/local/hermes.lock' ] && exit 0; done\nexit 1\n");
        try {
            list($code) = runPhp($runner, []);
            check($code === 0, 'Hermes child lost the shared runtime lock');
        } finally {
            testRmTree($root);
        }
    },
];
