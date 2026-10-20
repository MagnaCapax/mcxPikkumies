<?php
/** One tmux window and one resident Pikkumies process per requested Eternal. */
class Eternal
{
    public static $tmuxSocket = null;
    public static $tmuxDirectory = null; // private test socket root; production uses local/
    public static $hostMarker = null;
    const HOST_MARKER = '/etc/seedbox/config/pikkumiesEternals.enabled';

    public static function enabled()
    {
        return is_file(self::$hostMarker !== null ? self::$hostMarker : self::HOST_MARKER);
    }

    public static function insidePrivateTmux($tmux)
    {
        $base = self::$tmuxDirectory !== null ? self::$tmuxDirectory : Config::localDir();
        return $tmux !== '' && strpos(explode(',', $tmux, 2)[0], $base . '/tmux/') === 0;
    }

    public static function lock($wait = false)
    {
        return EternalLock::take(Config::localDir() . '/eternals.lock', $wait);
    }

    public static function cgroupAllows($body, $uid)
    {
        foreach (preg_split('/\r?\n/', (string) $body) as $line) {
            $parts = explode(':', $line, 3);
            if (count($parts) === 3 && preg_match('#(?:^|/)user-' . (int) $uid . '\.slice(?:/|$)#', $parts[2])) {
                return true;
            }
        }
        return false;
    }

    public static function held($now = null)
    {
        $now = $now === null ? time() : $now;
        return self::sourceHeld(Config::localDir() . '/eternals.hold', $now);
    }

    public static function sourceHeld($file, $now)
    {
        $hold = json_decode((string) @file_get_contents($file), true);
        if (is_array($hold) && isset($hold['until']) && is_int($hold['until']) && $hold['until'] > $now) {
            return true;
        }
        return false;
    }

    public static function ready()
    {
        if (HermesInstall::bin() === '') {
            return 'waiting-for-setup';
        }
        $provider = Provider::resolve(Config::load());
        if (Launch::missingKeyMessage($provider) !== '') {
            return 'not-ready';
        }
        require_once __DIR__ . '/../tools/system/credits/CreditsLine.php';
        $credits = CreditsLine::cached($provider, CreditsLine::stateDir(), 600);
        if ($credits !== null && isset($credits['balance']) && $credits['balance'] <= 0) {
            return 'not-ready';
        }
        $runtimeLock = HermesInstall::lock(false);
        if (!$runtimeLock) {
            return 'not-ready';
        }
        fclose($runtimeLock);
        if (HermesInstall::checkBeforeStart()) {
            return 'not-ready';
        }
        return '';
    }

    public static function transition($id, $code, $message)
    {
        $stateFile = Config::localDir() . '/eternals/' . $id . '.state';
        $last = json_decode((string) @file_get_contents($stateFile), true);
        if (is_array($last) && isset($last['code']) && $last['code'] === $code) {
            return;
        }
        CronTick::logIfChanged(Config::localDir() . '/update.log', $stateFile, $code, 'Eternal ' . $id . ': ' . $message);
    }

    public static function backoffTicks($fails)
    {
        return min(30, (int) pow(2, min(max(0, (int) $fails - 1), 5)));
    }

    /** Port of the account's recent-reboot stagger: pure data, no sleep in a cron tick. */
    public static function bootStaggerReady($uptime, $user)
    {
        $seconds = (float) $uptime;
        if ($seconds <= 0 || $seconds >= 600) {
            return true;
        }
        $hash = crc32((string) $user);
        $delay = ($hash < 0 ? $hash & 0x7FFFFFFF : $hash) % 301;
        return $seconds >= $delay;
    }

    public static function primaryMissing($windows)
    {
        return $windows === null || !in_array('pm1', $windows, true);
    }

    public static function withPrimary($windows)
    {
        return $windows === null ? ['pm1'] : array_merge($windows, ['pm1']);
    }

    /** This is called before cron.lock and every scheduled-job early return. */
    public static function ensure()
    {
        if (!is_file(self::$hostMarker !== null ? self::$hostMarker : self::HOST_MARKER)) {
            return;
        }
        if (is_file(Config::localDir() . '/eternals.off') || self::held()) {
            return;
        }
        $records = [];
        foreach (range(1, 9) as $n) {
            $id = 'pm' . $n;
            if (is_file(EternalRecord::path($id))) {
                $records[$id] = EternalRecord::read($id);
            }
        }
        if (!$records) {
            $records['pm1'] = EternalRecord::initial();
        }
        $ready = self::ready();
        if ($ready !== '' || !self::cgroupAllows(@file_get_contents('/proc/self/cgroup'), function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())) {
            self::transition('pm1', 75, $ready !== '' ? $ready : 'host needs update');
            return;
        }
        Update::ensureScheduled(Config::load(), function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(), CronTick::mightEscalate());
        $identity = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $uptime = explode(' ', trim((string) @file_get_contents('/proc/uptime')))[0];
        if (!self::bootStaggerReady($uptime, $identity ? $identity['name'] : (string) getmyuid())) {
            return;
        }
        $lock = self::lock();
        if (!$lock) {
            return;
        }
        list($code, $rows) = self::tmux(['list-windows', '-t', '=pikkumies', '-F', '#W|#{pane_start_command}|#{pane_dead}|#{pane_tty}']);
        $windows = [];
        if ($code === 0) {
            foreach (explode("\n", trim($rows)) as $row) {
                $parts = explode('|', $row, 4);
                if (count($parts) === 4) {
                    $windows[$parts[0]] = $parts;
                }
            }
        }
        foreach ($records as $id => $data) {
            if ($data === null) {
                continue; // malformed record needs a human decision, not a guessed restart
            }
            $hasSession = $code === 0;
            if (isset($windows[$id])) {
                // A pre-existing user's pmN window is never taken over.
                $row = $windows[$id];
                if (!self::paneOwns($row[1], $id)) {
                    if ($row[2] !== '1') {
                        continue;
                    }
                    self::tmux(['kill-window', '-t', '=pikkumies:' . $id]);
                    $hasSession = count($windows) > 1;
                } else {
                    self::capture($id, $data, $row[3]);
                    if ($row[2] !== '1') {
                        self::transition($id, 0, 'running');
                        continue;
                    }
                    self::tmux(['kill-window', '-t', '=pikkumies:' . $id]);
                    $hasSession = count($windows) > 1;
                }
            }
            $runLock = EternalLock::take(EternalRecord::path($id) . '.run');
            if (!$runLock) {
                continue; // a renamed pane may still own the resident process
            }
            fclose($runLock);
            $wait = self::backoffTicks($data['fails']);
            if ($data['lastStart'] > 0 && time() - $data['lastStart'] < $wait * 60) {
                continue;
            }
            $data['lastStart'] = time();
            EternalRecord::write($id, $data); // never spawn if the state cannot be persisted
            if (self::start($id, $hasSession)) {
                self::transition($id, 1, 'starting');
            } else {
                self::transition($id, 2, 'tmux start failed');
            }
            break; // per-tick start cap, Primary first
        }
    }

    public static function tmux(array $args)
    {
        $out = [];
        $argv = array_merge(['timeout', '5'], self::tmuxArgv($args));
        exec(implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public static function tmuxEnvironment()
    {
        $base = self::$tmuxDirectory !== null ? self::$tmuxDirectory : Config::localDir();
        foreach ([$base . '/tmux', $base . '/tmp'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
                throw new RuntimeException('Could not prepare private tmux directories.');
            }
            @chmod($dir, 0700);
            clearstatcache(true, $dir);
            if (!is_dir($dir) || (fileperms($dir) & 0077) !== 0
                || (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid())) {
                throw new RuntimeException('Tmux directory is not private.');
            }
        }
        return ['TMUX_TMPDIR' => $base . '/tmux', 'TMPDIR' => $base . '/tmp'];
    }

    public static function tmuxArgv(array $args)
    {
        $allowed = ['HOME', 'USER', 'LOGNAME', 'SHELL', 'PATH', 'LANG', 'TERM', 'XDG_RUNTIME_DIR', 'DOCKER_HOST'];
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (in_array($name, $allowed, true) || strpos($name, 'LC_') === 0) {
                $environment[$name] = $value;
            }
        }
        if (isset($args[0]) && $args[0] === 'switch-client' && self::insidePrivateTmux((string) getenv('TMUX'))) {
            $environment['TMUX'] = getenv('TMUX');
        }
        $environment = array_merge($environment, self::tmuxEnvironment());
        $argv = ['env', '-i'];
        foreach ($environment as $name => $value) {
            $argv[] = $name . '=' . $value;
        }
        $argv[] = 'tmux';
        if (self::$tmuxSocket !== null) {
            array_push($argv, '-L', self::$tmuxSocket);
        }
        return array_merge($argv, $args);
    }

    public static function start($id, $hasSession)
    {
        @unlink(self::stopPath($id));
        $command = implode(' ', array_map('escapeshellarg', self::invocation($id)));
        $args = $hasSession ? ['new-window', '-d', '-t', '=pikkumies:', '-n', $id, $command]
            : ['new-session', '-d', '-s', 'pikkumies', '-n', $id, $command];
        foreach (self::environment() as $name => $value) {
            if ($value !== false && strpos($value, "\n") === false) {
                array_splice($args, 1, 0, ['-e', $name . '=' . $value]);
            }
        }
        $started = self::tmux($args)[0] === 0;
        if ($started) {
            @unlink(self::quitPath($id));
        }
        return $started;
    }

    public static function invocation($id)
    {
        if (!EternalRecord::validId($id)) {
            throw new InvalidArgumentException('invalid Eternal id');
        }
        return [PHP_BINARY, Config::root() . '/bin/pikkumies', 'run', '--eternal', $id];
    }

    public static function paneOwns($command, $id)
    {
        if (!EternalRecord::validId($id)) {
            return false;
        }
        $command = trim((string) $command);
        if (strlen($command) >= 2 && $command[0] === '"' && substr($command, -1) === '"') {
            $command = substr($command, 1, -1); // tmux's pane_start_command display quotes the shell command
        }
        $argv = self::invocation($id);
        $quoted = implode(' ', array_map('escapeshellarg', $argv));
        $manual = escapeshellarg($argv[0]) . ' ' . escapeshellarg($argv[1]) . ' run --eternal ' . $id;
        return $command === $quoted || $command === $manual;
    }

    public static function environment()
    {
        $home = (string) getenv('HOME');
        $path = (string) getenv('PATH');
        foreach ([$home . '/bin', $home . '/.local/bin'] as $bin) {
            if (is_dir($bin) && !in_array($bin, explode(':', $path), true)) {
                $path = $bin . ':' . $path;
            }
        }
        $runtime = getenv('XDG_RUNTIME_DIR');
        if ($runtime === false || $runtime === '') {
            $runtime = '/run/user/' . (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        }
        return array_merge(['PATH' => $path, 'XDG_RUNTIME_DIR' => $runtime,
            'DOCKER_HOST' => getenv('DOCKER_HOST'), 'LANG' => getenv('LANG')], self::tmuxEnvironment());
    }

    /** Stop only our windows during an update; records survive and the next tick resumes them. */
    public static function stopManaged()
    {
        list($code, $rows) = self::tmux(['list-windows', '-t', '=pikkumies', '-F', '#W|#{pane_start_command}|#{pane_pid}']);
        if ($code !== 0) {
            return true;
        }
        $stopped = true;
        foreach (explode("\n", $rows) as $row) {
            $parts = explode('|', $row, 3);
            if (count($parts) === 3 && self::paneOwns($parts[1], $parts[0]) && ctype_digit($parts[2])) {
                $stopPath = self::stopPath($parts[0]);
                if (!self::shutdown($parts[0])) {
                    $stopped = false;
                    continue; // no marker means HUP would be mistaken for a user quit
                }
                if (function_exists('posix_kill')) {
                    @posix_kill((int) $parts[2], 15);
                }
                if (self::tmux(['kill-window', '-t', '=pikkumies:' . $parts[0]])[0] !== 0) {
                    @unlink($stopPath);
                    $stopped = false;
                }
            }
        }
        return $stopped;
    }

    public static function stopPath($id)
    {
        return EternalRecord::path($id) . '.stop';
    }

    public static function shutdown($id)
    {
        $path = self::stopPath($id);
        @mkdir(dirname($path), 0700, true);
        return @file_put_contents($path, "stop\n") !== false;
    }

    /** Called after releasing the lifecycle lock: residents need it to finish afterExit. */
    public static function waitStopped($seconds = 10)
    {
        $until = microtime(true) + $seconds;
        do {
            $running = false;
            foreach (range(1, 9) as $n) {
                $id = 'pm' . $n;
                $runPath = EternalRecord::path($id) . '.run';
                if (!is_file($runPath)) {
                    continue;
                }
                $lock = EternalLock::take($runPath);
                if (!$lock) {
                    $running = true;
                    break;
                }
                fclose($lock);
            }
            if (!$running) {
                return true;
            }
            usleep(100000);
        } while (microtime(true) < $until);
        return false;
    }

    public static function quitPath($id)
    {
        return EternalRecord::path($id) . '.quit';
    }

    public static function capture($id, array $record, $tty)
    {
        $pointer = self::pointer($tty, $record['lastStart']);
        if (time() - $record['lastStart'] < 600) {
            return; // a newly created session must survive before it replaces a resume pointer
        }
        if ($pointer !== '' && $record['sessionId'] !== $pointer) {
            $record['sessionId'] = $pointer;
            EternalRecord::write($id, $record);
        }
    }

    public static function pointer($tty, $lastStart, $home = null)
    {
        if (!preg_match('#^/dev/pts/([0-9]+)$#', $tty, $m)) {
            return '';
        }
        $path = ($home !== null ? $home : HermesConfig::home()) . '/terminal-sessions/tty-dev-pts-' . $m[1];
        if (!is_file($path) || (int) @filemtime($path) < $lastStart) {
            return '';
        }
        $pointer = trim((string) @file_get_contents($path));
        return EternalRecord::validSession($pointer) ? $pointer : '';
    }

    public static function afterExit($id, $code, $started, $signalled)
    {
        $lock = self::lock(true);
        if (!$lock) {
            return; // a concurrent quit/off/update owns the lifecycle decision
        }
        $record = EternalRecord::read($id);
        if ($record === null) {
            return;
        }
        // A HUP from a live tmux server means the person closed the window. During an
        // update or transfer, stopManaged marks the pane before signalling it.
        if (defined('SIGHUP') && $signalled === SIGHUP && !is_file(self::stopPath($id)) && self::tmux(['has-session', '-t', '=pikkumies'])[0] === 0) {
            $code = 0;
        } elseif ($signalled || $code === 75) {
            return;
        }
        if ($code === 0) {
            if ($id === 'pm1') {
                $record['sessionId'] = '';
                $record['fails'] = 0;
                EternalRecord::write($id, $record);
                @file_put_contents(self::quitPath($id), "quit\n");
            } else {
                @unlink(EternalRecord::path($id));
            }
            return;
        }
        $record['fails'] = time() - $started >= 600 ? 1 : $record['fails'] + 1;
        EternalRecord::write($id, $record);
    }
}
