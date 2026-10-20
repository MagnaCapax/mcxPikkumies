<?php
return [
    'eternal: resident argv matches the node probe contract fixture' => function () {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/eternals/invocation.json'), true);
        check(array_slice(Eternal::invocation('pm1'), -3) === $fixture['argvTail'], 'resident argv drifted');
        check($fixture['tmuxSession'] === Launch::TMUX_SESSION && $fixture['window'] === 'pm1', 'tmux identity drifted');
    },
    'eternal: pane identity matches the resident invocation and leaves foreign windows alone' => function () {
        $argv = Eternal::invocation('pm1');
        $quoted = implode(' ', array_map('escapeshellarg', $argv));
        $manual = escapeshellarg($argv[0]) . ' ' . escapeshellarg($argv[1]) . ' run --eternal pm1';
        check(Eternal::paneOwns('"' . $quoted . '"', 'pm1'), 'quoted resident pane missed');
        check(Eternal::paneOwns('"' . $manual . '"', 'pm1'), 'manual tmux pane missed');
        check(!Eternal::paneOwns('"' . $quoted . '"', 'pm2'), 'wrong id adopted');
        check(!Eternal::paneOwns('echo run --eternal pm1; sleep 30', 'pm1'), 'foreign window adopted');
    },
    'eternal: new chooses a free name without taking a foreign window' => function () {
        check(EternalCli::nextFreeId([], []) === 'pm2', 'first extra window changed');
        check(EternalCli::nextFreeId(['pm2'], ['pm3']) === 'pm4', 'occupied or foreign window reused');
        check(EternalCli::nextFreeId(['pm2', 'pm3', 'pm4', 'pm5', 'pm6', 'pm7', 'pm8', 'pm9'], []) === null, 'cap not enforced');
        check(Eternal::primaryMissing(null) && Eternal::primaryMissing(['pm2']), 'new session or missing Primary was accepted');
        check(!Eternal::primaryMissing(['pm1', 'pm2']), 'existing Primary was treated as missing');
        check(Eternal::withPrimary(null) === ['pm1'] && Eternal::withPrimary(['pm2']) === ['pm2', 'pm1'], 'existing window was lost when Primary started');
    },
    'eternal: records reject extra fields and unsafe session ids' => function () {
        $dir = sys_get_temp_dir() . '/eternal-record-' . getmypid();
        @mkdir($dir, 0700, true);
        EternalRecord::$base = $dir;
        try {
            EternalRecord::write('pm2', ['sessionId' => 'abc-123', 'fails' => 2, 'lastStart' => 42]);
            check(EternalRecord::read('pm2')['sessionId'] === 'abc-123', 'valid session lost');
            file_put_contents($dir . '/pm2', json_encode(['sessionId' => '-bad;id', 'fails' => 2, 'lastStart' => 42]));
            check(EternalRecord::read('pm2')['sessionId'] === '', 'unsafe session id used');
            file_put_contents($dir . '/pm2', json_encode(['sessionId' => 'ok', 'fails' => 2, 'lastStart' => 42, 'cwd' => '/tmp']));
            check(EternalRecord::read('pm2') === EternalRecord::initial(), 'unknown field used');
            check(!EternalRecord::validId('../pm1'), 'path traversal id accepted');
        } finally {
            EternalRecord::$base = null;
            @unlink($dir . '/pm2');
            @rmdir($dir);
        }
    },
    'eternal: a missing stored session falls back to fresh without guessing on an unreadable store' => function () {
        if (!class_exists('SQLite3')) {
            check(EternalRecord::sessionExists('known', '/no/database') === null, 'unavailable store must be unknown');
            return;
        }
        $dir = sys_get_temp_dir() . '/eternal-sessions-' . getmypid();
        @mkdir($dir, 0700, true);
        $path = $dir . '/state.db';
        try {
            check(EternalRecord::sessionExists('known', $path) === false, 'missing database was accepted');
            $db = new SQLite3($path);
            $db->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY)');
            $db->exec("INSERT INTO sessions (id) VALUES ('known')");
            $db->close();
            check(EternalRecord::sessionExists('known', $path) === true, 'stored session lost');
            check(EternalRecord::sessionExists('gone', $path) === false, 'missing row accepted');
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    },
    'eternal: the pane tty breadcrumb must be current and contain an allowlisted id' => function () {
        $home = sys_get_temp_dir() . '/eternal-tty-' . getmypid();
        @mkdir($home . '/terminal-sessions', 0700, true);
        $path = $home . '/terminal-sessions/tty-dev-pts-17';
        try {
            file_put_contents($path, "session_123\n");
            touch($path, time() - 20);
            check(Eternal::pointer('/dev/pts/17', time() - 10, $home) === '', 'old breadcrumb accepted');
            touch($path, time());
            clearstatcache();
            check(Eternal::pointer('/dev/pts/17', time() - 10, $home) === 'session_123', 'current breadcrumb lost');
            file_put_contents($path, "-unsafe;id\n");
            check(Eternal::pointer('/dev/pts/17', time() - 10, $home) === '', 'unsafe pointer accepted');
            check(Eternal::pointer('/dev/pts/../17', 0, $home) === '', 'unsafe tty accepted');
        } finally {
            @unlink($path);
            @rmdir($home . '/terminal-sessions');
            @rmdir($home);
        }
    },
    'eternal: cgroup accepts only this uid user slice on v1 or v2' => function () {
        check(Eternal::cgroupAllows("0::/user.slice/user-123.slice/session-1.scope\n", 123), 'v2 refused');
        check(Eternal::cgroupAllows("7:memory:/user.slice/user-123.slice\n", 123), 'v1 refused');
        check(!Eternal::cgroupAllows("0::/system.slice/cron.service\n", 123), 'system slice accepted');
        check(!Eternal::cgroupAllows(false, 123), 'unreadable accepted');
        check(!Eternal::cgroupAllows("0::/user.slice/user-124.slice\n", 123), 'another uid accepted');
    },
    'eternal: transfer hold expires and malformed hold does not persist' => function () {
        $file = sys_get_temp_dir() . '/eternal-hold-' . getmypid();
        try {
            file_put_contents($file, json_encode(['until' => 200]));
            check(Eternal::sourceHeld($file, 199), 'live hold ignored');
            check(!Eternal::sourceHeld($file, 200), 'expired hold applied');
            file_put_contents($file, json_encode(['until' => 'forever']));
            check(!Eternal::sourceHeld($file, 199), 'malformed hold applied');
        } finally {
            @unlink($file);
        }
    },
    'eternal: retries grow by ticks and cap at thirty' => function () {
        check(array_map(['Eternal', 'backoffTicks'], [0, 1, 2, 3, 4, 5, 6, 100]) === [1, 1, 2, 4, 8, 16, 30, 30], 'backoff changed');
    },
    'eternal: reboot stagger is deterministic and never sleeps' => function () {
        $user = 'some-account';
        $hash = crc32($user);
        $delay = ($hash < 0 ? $hash & 0x7FFFFFFF : $hash) % 301;
        check(Eternal::bootStaggerReady(600, $user), 'old boot delayed');
        check(Eternal::bootStaggerReady($delay, $user), 'account did not start at its slot');
        if ($delay > 0) {
            check(!Eternal::bootStaggerReady($delay - 0.1, $user), 'account started before slot');
        }
    },
    'eternal: ask strips control bytes but leaves bracketed paste bytes literal' => function () {
        check(EternalAsk::clean("A\x1b[201~\rB\xC2\x80\xC2\x9F\n\t") === "A[201~B\n\t", 'control stripping changed');
    },
    'eternal: ask accepts only a visible empty input line' => function () {
        check(EternalAsk::idleAtInput("Earlier output\nStatus\n────────\n❯ \n────────\n"), 'empty prompt refused');
        check(!EternalAsk::idleAtInput("Earlier output\nStatus\n────────\n❯ draft\n────────\n"), 'draft text accepted');
        check(EternalAsk::heldAtInput("Earlier output\nStatus\n────────\n❯ draft\n────────\n"), 'draft text not detected');
        check(!EternalAsk::idleAtInput("────────\n☤ \n────────\n"), 'busy glyph accepted');
        check(!EternalAsk::idleAtInput("old ❯\nworking now"), 'old prompt accepted');
        check(!EternalAsk::idleAtInput("Working...\n"), 'running turn accepted');
        check(!EternalAsk::idleAtInput(''), 'unknown screen accepted');
    },
    'eternal: each UI instance has separate fork state and shares the private tmux socket' => function () {
        $root = sys_get_temp_dir() . '/eternal-ui-' . getmypid();
        @mkdir($root, 0700);
        Eternal::$tmuxDirectory = $root;
        try {
            $base = $root . '/ui';
            $first = EternalUi::environment('/home/example', $base, $base . '/ui-first');
            $second = EternalUi::environment('/home/example', $base, $base . '/ui-second');
            foreach (['AGENTBOARD_DATA_DIR', 'LOG_FILE', 'AGENTBOARD_TMUX_PID_FILE'] as $name) {
                check($first[$name] !== $second[$name], $name . ' was shared');
            }
            check($first['TMUX_TMPDIR'] === $second['TMUX_TMPDIR'] && $first['TMPDIR'] === $root . '/tmp', 'UI tmux environment drifted');
            check($first['AGENTBOARD_SOCKET'] === $second['AGENTBOARD_SOCKET'], 'UI socket contract drifted');
        } finally {
            Eternal::$tmuxDirectory = null;
            @rmdir($root . '/tmux');
            @rmdir($root . '/tmp');
            @rmdir($root);
        }
    },
    'eternal: cron ensure precedes the job lock and both job exits' => function () {
        $source = file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $section = substr($source, strpos($source, "case 'cron-tick':"), strpos($source, "case 'tmux':") - strpos($source, "case 'cron-tick':"));
        $ensure = strpos($section, 'Eternal::ensure()');
        $lock = strpos($section, "'/cron.lock'");
        $bin = strpos($section, "hermesBin() === ''");
        $jobs = strpos($section, 'CronTick::hasJobs');
        check($ensure !== false && $lock !== false && $bin !== false && $jobs !== false
            && $ensure < $lock && $ensure < $bin && $ensure < $jobs, 'ensure moved after a scheduled-job early exit');
    },
    'eternal: private tmux socket accepts a window and closes it' => function () {
        exec('command -v tmux 2>/dev/null', $out, $code);
        if ($code !== 0) {
            if (getenv('CI')) {
                throw new Exception('tmux is required in CI');
            }
            skip('tmux is not installed');
        }
        $socket = 'pikkumies-test-' . getmypid();
        $oldTmuxDir = getenv('TMUX_TMPDIR');
        $oldTmux = getenv('TMUX');
        $foreign = sys_get_temp_dir() . '/eternal-foreign-' . getmypid();
        $root = sys_get_temp_dir() . '/eternal-private-' . getmypid();
        @mkdir($foreign, 0700);
        @mkdir($root, 0700);
        $blockedDefault = $foreign . '/tmux-' . (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        file_put_contents($blockedDefault, 'occupied');
        putenv('TMUX_TMPDIR=' . $foreign);
        putenv('TMUX=/no/such/socket,1,0');
        Eternal::$tmuxSocket = $socket;
        Eternal::$tmuxDirectory = $root;
        try {
            $env = Eternal::tmuxEnvironment();
            check($env['TMUX_TMPDIR'] === $root . '/tmux' && $env['TMPDIR'] === $root . '/tmp', 'private directories drifted');
            check((fileperms($env['TMUX_TMPDIR']) & 0077) === 0 && (fileperms($env['TMPDIR']) & 0077) === 0, 'private directories are accessible to others');
            Eternal::tmux(['kill-server']);
            check(Eternal::tmux(['new-session', '-d', '-s', 'pikkumies', '-n', 'pm1', 'sleep 30'])[0] === 0, 'private server start failed');
            list($code, $rows) = Eternal::tmux(['list-windows', '-t', '=pikkumies', '-F', '#W']);
            check($code === 0 && trim($rows) === 'pm1', 'private window missing');
            check(Eternal::tmux(['copy-mode', '-t', '=pikkumies:pm1'])[0] === 0, 'copy mode could not start');
            check(trim(Eternal::tmux(['display-message', '-p', '-t', '=pikkumies:pm1', '#{pane_in_mode}'])[1]) === '1', 'copy mode absent');
            check(EternalAsk::cancelCopyMode('1'), 'ask did not cancel copy mode');
            check(trim(Eternal::tmux(['display-message', '-p', '-t', '=pikkumies:pm1', '#{pane_in_mode}'])[1]) === '0', 'copy mode remained');
        } finally {
            Eternal::tmux(['kill-server']);
            Eternal::$tmuxSocket = null;
            Eternal::$tmuxDirectory = null;
            $oldTmuxDir === false ? putenv('TMUX_TMPDIR') : putenv('TMUX_TMPDIR=' . $oldTmuxDir);
            $oldTmux === false ? putenv('TMUX') : putenv('TMUX=' . $oldTmux);
            @unlink($blockedDefault);
            @rmdir($foreign);
            @rmdir($root . '/tmux/tmux-' . (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()));
            @rmdir($root . '/tmux');
            @rmdir($root . '/tmp');
            @rmdir($root);
        }
    },
    'eternal: close-on-exec lifecycle lock is absent from a spawned child' => function () {
        $dir = sys_get_temp_dir() . '/eternal-lock-' . getmypid();
        @mkdir($dir, 0700, true);
        $path = $dir . '/state.lock';
        $lock = EternalLock::take($path);
        check(is_resource($lock), 'lock not acquired');
        $process = proc_open('sleep 2', [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            check(is_resource($process), 'child did not start');
            $status = proc_get_status($process);
            $fds = glob('/proc/' . $status['pid'] . '/fd/*');
            check(is_array($fds), 'child fds could not be read');
            foreach ($fds as $fd) {
                check(@readlink($fd) !== $path, 'lifecycle lock leaked into child');
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            fclose($lock);
            @unlink($path);
            @rmdir($dir);
        }
    },
    'eternal: feature locks use the shared close-on-exec helper' => function () {
        $lib = dirname(__DIR__) . '/pikkumies/lib';
        check(strpos(file_get_contents($lib . '/EternalLock.php'), "fopen(\$path, 'ce')") !== false, 'lock lost close-on-exec mode');
        foreach (glob($lib . '/Eternal*.php') as $file) {
            check(preg_match('/fopen\([^;]*[\'\"]c[\'\"]/', file_get_contents($file)) !== 1, basename($file) . ' opens a plain inheritable lock');
        }
    },
];
