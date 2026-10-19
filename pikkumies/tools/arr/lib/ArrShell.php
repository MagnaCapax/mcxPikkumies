<?php
/**
 * ArrShell: the only way an *ARR unit runs another program (docker, tmux, the PMSS installer).
 * The command is an argv array, each word quoted with escapeshellarg (the pattern of lib/Update.php
 * exec()); the app name in it is a per-unit constant, never text from the command line or an API.
 * The API key is never part of a command line or of the environment passed on.
 */
class ArrShell
{
    /**
     * [exit code, stdout, stderr]; $env adds variables to this process's own environment. $stdin: text written to the
     * program's standard input, then closed (a password for autobrrctl: never in argv or the environment); null: /dev/null.
     */
    public static function run(array $argv, array $env = [], $cwd = null, $stdin = null)
    {
        $full = null;
        if ($env) {
            $full = getenv();
            foreach ($env as $k => $v) {
                $full[$k] = $v;
            }
        }
        $in = $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'];
        $p = @proc_open(implode(' ', array_map('escapeshellarg', $argv)), [0 => $in, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $full);
        if (!is_resource($p)) {
            return [127, '', 'could not start ' . $argv[0]];
        }
        if ($stdin !== null) {
            @fwrite($pipes[0], (string) $stdin);
            fclose($pipes[0]);
        }
        list($out, $err) = self::drain($pipes[1], $pipes[2]);
        return [proc_close($p), $out, $err];
    }

    /**
     * stdout and stderr read together with stream_select until both end, so a program that fills one pipe (64 KiB) while
     * this reads the other cannot block both sides. Each pipe is closed here.
     */
    private static function drain($stdout, $stderr)
    {
        $got = [(int) $stdout => '', (int) $stderr => ''];
        $open = [$stdout, $stderr];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }
        while ($open) {
            $read = $open;
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 60) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $got[(int) $pipe] .= $chunk;
                } elseif (feof($pipe)) {
                    fclose($pipe);
                    $open = array_values(array_filter($open, function ($o) use ($pipe) {
                        return $o !== $pipe;
                    }));
                }
            }
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        return [$got[(int) $stdout], $got[(int) $stderr]];
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
