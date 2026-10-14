<?php
/**
 * ArrShell: the only way an *ARR unit runs another program (docker, tmux, the PMSS installer).
 * The command is an argv array, each word quoted with escapeshellarg (the pattern of lib/Update.php
 * exec()); the app name in it is a per-unit constant, never text from the command line or an API.
 * The API key is never part of a command line or of the environment passed on.
 */
class ArrShell
{
    /** [exit code, stdout, stderr]; $env adds variables to this process's own environment. */
    public static function run(array $argv, array $env = [], $cwd = null)
    {
        $full = null;
        if ($env) {
            $full = getenv();
            foreach ($env as $k => $v) {
                $full[$k] = $v;
            }
        }
        $p = @proc_open(implode(' ', array_map('escapeshellarg', $argv)), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $full);
        if (!is_resource($p)) {
            return [127, '', 'could not start ' . $argv[0]];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /** True when $name is an executable file in PATH (looked at, never run). */
    public static function has($name)
    {
        foreach (array_filter(explode(':', (string) getenv('PATH')), 'strlen') as $d) {
            if (is_file("$d/$name") && is_executable("$d/$name")) {
                return true;
            }
        }
        return false;
    }

    /** The user's own Docker daemon socket, or '' (the rule the media units use too: lib/Host.php dockerSocket). */
    public static function dockerSocket($uid)
    {
        return Host::dockerSocket($uid);
    }

    /** `tmux has-session -t =<app>` as the user, the PMSS watchdog's own test: running, stopped or unknown. */
    public static function tmuxState($app)
    {
        if (!self::has('tmux')) {
            return 'unknown';
        }
        list($code) = self::run(['tmux', 'has-session', '-t', '=' . $app]);
        return $code === 0 ? 'running' : 'stopped';
    }
}
