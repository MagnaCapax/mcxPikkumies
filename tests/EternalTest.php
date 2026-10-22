<?php
return [
    'eternal: ask rejects slash commands and root, and quota counts only new sends' => function () {
        $marker = testTempDir('eternal-marker') . '/enabled';
        file_put_contents($marker, "1\n");
        Eternal::$hostMarker = $marker;
        try {
            check(EternalAsk::send("  /yolo\n") === [1, 'Ask cannot send slash commands. Open Primary to enter one.'], 'slash command accepted');
            check(EternalAsk::send("\xC2\xA0/model") === [1, 'Ask cannot send slash commands. Open Primary to enter one.'], 'unicode space before slash command accepted');
            check(EternalAsk::rootRefused(0) && !EternalAsk::rootRefused(1000), 'root refusal changed');
            check(EternalAsk::quota('nonce123', ['count' => 5], ['nonce123' => time()]) === 0, 'replayed nonce was rate capped');
            check(EternalAsk::quota('newnonce', ['count' => 5], []) === 4, 'sixth question was allowed');
            check(EternalAsk::quota('newnonce', ['count' => 4], []) === -1, 'fifth question was blocked');
            check(EternalAsk::screenStatus("This answer mentions approvals.\n────────\n❯ \n────────\n") === 0, 'ordinary answer misread as approval prompt');
        } finally {
            Eternal::$hostMarker = null;
            @unlink($marker);
            @rmdir(dirname($marker));
        }
    },
    'eternal: listing reports paused records as quit with the cause' => function () {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/eternals/listing.json'), true);
        $root = testTempDir('eternal-listing');
        $marker = $root . '/enabled';
        file_put_contents($marker, "1\n");
        Eternal::$hostMarker = $marker;
        EternalRecord::$base = $root . '/records';
        EternalRecord::write('pm2', EternalRecord::initial());
        $off = Config::localDir() . '/eternals.off';
        $oldOff = is_file($off) ? file_get_contents($off) : null;
        $hold = Config::localDir() . '/eternals.hold';
        $oldHold = is_file($hold) ? file_get_contents($hold) : null;
        try {
            @file_put_contents($off, "off\n");
            $rows = EternalCli::listing();
            $pm2 = array_values(array_filter($rows, function ($row) { return $row['id'] === 'pm2'; }))[0];
            check($pm2['state'] === $fixture['paused']['off'] && $pm2['reason'] === 'off', 'off record not reported as quit');
            check(in_array($pm2['state'], $fixture['states'], true), 'state outside contract');
            @unlink($off);
            file_put_contents($hold, json_encode(['until' => time() + 3600]));
            $rows = EternalCli::listing();
            $pm2 = array_values(array_filter($rows, function ($row) { return $row['id'] === 'pm2'; }))[0];
            check($pm2['state'] === $fixture['paused']['hold'] && $pm2['reason'] === 'hold', 'held record not reported as quit');
            @unlink($hold);
            file_put_contents(Eternal::quitPath('pm2'), "quit\n");
            $rows = EternalCli::listing();
            $pm2 = array_values(array_filter($rows, function ($row) { return $row['id'] === 'pm2'; }))[0];
            check($pm2['state'] === 'quit' && !isset($pm2['reason']), 'explicit quit not reported');
        } finally {
            $oldOff === null ? @unlink($off) : file_put_contents($off, $oldOff);
            $oldHold === null ? @unlink($hold) : file_put_contents($hold, $oldHold);
            Eternal::$hostMarker = null;
            EternalRecord::$base = null;
            testRmTree($root);
        }
    },
    'eternal: tmux argv has only the explicit environment allowlist' => function () {
        $old = getenv('SECRET_FOR_TEST');
        putenv('SECRET_FOR_TEST=private');
        $root = testTempDir('eternal-env');
        Eternal::$tmuxDirectory = $root;
        try {
            $argv = Eternal::tmuxArgv(['has-session']);
            check(array_slice($argv, 0, 2) === ['env', '-i'], 'tmux inherits caller environment');
            check(!in_array('SECRET_FOR_TEST=private', $argv, true), 'secret leaked to tmux server');
            check(in_array('TMUX_TMPDIR=' . $root . '/tmux', $argv, true), 'private socket path missing');
        } finally {
            Eternal::$tmuxDirectory = null;
            $old === false ? putenv('SECRET_FOR_TEST') : putenv('SECRET_FOR_TEST=' . $old);
            testRmTree($root);
        }
    },
    'eternal: dead UI instance directories are removed and live ones retained' => function () {
        $root = testTempDir('eternal-ui-clean');
        @mkdir($root . '/ui-dead/sub', 0700, true);
        @mkdir($root . '/ui-live', 0700, true);
        $child = proc_open('sleep 0.1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($child), 'could not start UI fixture child');
        $childPid = proc_get_status($child)['pid'];
        file_put_contents($root . '/ui-dead/ui.pid', $childPid . "\n");
        file_put_contents($root . '/ui-dead/tmux-server.pid', getmypid() . "\n");
        file_put_contents($root . '/ui-dead/sub/state', 'old');
        file_put_contents($root . '/ui-live/ui.pid', getmypid() . "\n");
        try {
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($child);
            EternalUi::removeDeadInstances($root);
            check(!is_dir($root . '/ui-dead') && is_dir($root . '/ui-live'), 'UI instance cleanup failed');
        } finally {
            testRmTree($root);
        }
    },
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
            $first = EternalUi::environment('/home/fixtureuser', $base, $base . '/ui-first');
            $second = EternalUi::environment('/home/fixtureuser', $base, $base . '/ui-second');
            foreach (['AGENTBOARD_DATA_DIR', 'LOG_FILE', 'AGENTBOARD_TMUX_PID_FILE'] as $name) {
                check($first[$name] !== $second[$name], $name . ' was shared');
            }
            check($first['TMUX_TMPDIR'] === $second['TMUX_TMPDIR'] && $first['TMPDIR'] === $root . '/tmp', 'UI tmux environment drifted');
            check($first['AGENTBOARD_SOCKET'] === $second['AGENTBOARD_SOCKET'], 'UI socket contract drifted');
            // The panel launcher starts the UI with the web server's PATH; the UI must still find `pikkumies` in ~/bin.
            @mkdir($root . '/fixturehome/bin', 0700, true);
            $ui = EternalUi::environment($root . '/fixturehome', $base, $base . '/ui-path');
            check(strpos($ui['PATH'], $root . '/fixturehome/bin:') === 0, 'UI PATH lacks the account bin directory');
        } finally {
            @rmdir($root . '/fixturehome/bin');
            @rmdir($root . '/fixturehome');
            Eternal::$tmuxDirectory = null;
            @rmdir($root . '/tmux');
            @rmdir($root . '/tmp');
            @rmdir($root);
        }
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
        $process = proc_open('exec sleep 2', [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            check(is_resource($process), 'child did not start');
            $status = proc_get_status($process);
            // proc_open returns while its shell may still hold inherited fds before exec.
            for ($i = 0; $i < 100 && trim((string) @file_get_contents('/proc/' . $status['pid'] . '/comm')) !== 'sleep'; $i++) {
                usleep(10000);
            }
            check(trim((string) @file_get_contents('/proc/' . $status['pid'] . '/comm')) === 'sleep', 'child did not exec sleep');
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
];
