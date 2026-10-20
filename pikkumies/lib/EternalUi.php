<?php
/** PMSS launcher entry point; no UI process is started by the cron tick. */
class EternalUi
{
    public static $binary = null; // test seam; production uses the platform-installed binary

    public static function start()
    {
        if (!is_file(Eternal::$hostMarker !== null ? Eternal::$hostMarker : Eternal::HOST_MARKER)) {
            return 'UI is not enabled on this host.';
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (!Eternal::cgroupAllows(@file_get_contents('/proc/self/cgroup'), $uid)) {
            return 'UI needs the account user slice.';
        }
        $account = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        if (!$account || empty($account['dir'])) {
            return 'UI cannot find the account home.';
        }
        $binary = self::$binary !== null ? self::$binary : '/opt/pikkumies-agentboard/agentboard';
        if (!is_file($binary) || !is_executable($binary)) {
            return 'UI is not installed on this host.';
        }
        $base = Config::localDir() . '/ui';
        self::removeDeadInstances($base);
        $instance = $base . '/ui-' . bin2hex(random_bytes(8));
        foreach ([$base, $base . '/tokens', $instance] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
                return 'Could not prepare the UI directory.';
            }
            @chmod($dir, 0700);
        }
        $vars = self::environment($account['dir'], $base, $instance);
        foreach (['AGENTBOARD_LAUNCHER_URL', 'AGENTBOARD_ALLOWED_ORIGIN', 'AGENTBOARD_FRAME_ANCESTORS'] as $name) {
            $vars[$name] = getenv($name);
        }
        foreach ($vars as $name => $value) {
            if ($value === false || $value === '' || strpos($value, "\n") !== false) {
                return 'UI launcher configuration is incomplete.';
            }
        }
        $parts = ['env', '-u', 'TMUX'];
        foreach ($vars as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        $parts[] = 'setsid';
        $parts[] = '-f';
        $parts[] = $binary;
        $log = $instance . '/launcher-error.log';
        exec(implode(' ', array_map('escapeshellarg', $parts)) . ' </dev/null >>' . escapeshellarg($log) . ' 2>&1', $out, $code);
        return $code === 0 ? '' : 'UI could not start; see ' . $log;
    }

    public static function removeDeadInstances($base)
    {
        foreach (glob($base . '/ui-*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $pid = trim((string) @file_get_contents($dir . '/tmux-server.pid'));
            if (!ctype_digit($pid) || is_dir('/proc/' . $pid)) {
                continue;
            }
            try {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    if ($file->isDir() && !$file->isLink()) {
                        @rmdir($file->getPathname());
                    } else {
                        @unlink($file->getPathname());
                    }
                }
                @rmdir($dir);
            } catch (UnexpectedValueException $x) {
                // A directory removed concurrently needs no cleanup here.
            }
        }
    }

    public static function environment($home, $base, $instance)
    {
        return array_merge([
            'HOME' => $home, 'AGENTBOARD_MODE' => 'pikkumies',
            'AGENTBOARD_SOCKET' => $base . '/ui.sock', 'AGENTBOARD_TOKEN_DIR' => $base . '/tokens',
            'AGENTBOARD_DATA_DIR' => $instance, 'LOG_FILE' => $instance . '/agentboard.log',
            'AGENTBOARD_TMUX_PID_FILE' => $instance . '/tmux-server.pid',
            'TMUX_SESSION' => 'pikkumies', 'TERMINAL_MODE' => 'pty',
        ], Eternal::tmuxEnvironment());
    }
}
