<?php
/**
 * ToolProc: runs a program found in PATH with an argv, never a shell string built from user text
 * (storage and backup units). PHP 7.3 proc_open takes a string, so every word is escapeshellarg'd,
 * as lib/Update.php exec() does, and `exec` replaces the shell so a stop signal reaches the program.
 * Nothing here installs anything. The backup units use it too; it belongs in pikkumies/lib once a
 * third category needs it.
 */
class ToolProc
{
    const MAX_OUT = 8388608;  // stdout bytes kept when no line reader is given
    const MAX_ERR = 1048576;  // stderr bytes kept (borg prints its --list output there)
    const CAP = 10000;        // entries one listing reads: --offset pages within it, --limit 0 reads this many

    /** Absolute path of $name from PATH, or null. */
    public static function which($name)
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("$dir/$name") && is_executable("$dir/$name")) {
                return "$dir/$name";
            }
        }
        return null;
    }

    /** The program's path, or "not installed" (exit 1). Never installs it. */
    public static function need($name, $hint)
    {
        $path = self::which($name);
        if ($path === null) {
            throw new CliError("$name is not installed on this machine (not found in PATH); nothing was done", $hint);
        }
        return $path;
    }

    public static function line(array $argv)
    {
        return implode(' ', array_map('escapeshellarg', array_map('strval', $argv)));
    }

    /** Low priority and a time limit for scans: nice 19, idle I/O class, timeout (storage README, rule 2). */
    public static function gentle(array $argv, $seconds = 0)
    {
        foreach (['nice', 'ionice', 'timeout'] as $p) {
            self::need($p, 'coreutils and util-linux provide it; this tool never scans without it');
        }
        $wrap = ['nice', '-n', '19', 'ionice', '-c3'];
        return array_merge($wrap, $seconds > 0 ? ['timeout', (string) (int) $seconds] : [], $argv);
    }

    /**
     * Runs $argv. $onLine(string $line) gets each stdout record (ended by $sep: a newline, or "\0" for du -0 and find
     * -printf ...\0, so a file name holding a newline stays one record) and may return false to stop early (the
     * program is then terminated). Without it stdout is collected. $env adds variables to the current
     * environment; a null value removes one. Returns ['code', 'out', 'err', 'stopped', 'cut'].
     */
    public static function run(array $argv, array $env = [], $onLine = null, $cwd = null, $sep = "\n")
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            if ($v === null) {
                unset($vars[$k]);
            } else {
                $vars[$k] = (string) $v;
            }
        }
        $io = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open('exec ' . self::line($argv), $io, $pipes, $cwd, $vars);
        if (!is_resource($p)) {
            throw new CliError('could not start ' . basename((string) $argv[0]), 'check that it is installed and runnable');
        }
        $r = ['code' => 0, 'out' => '', 'err' => '', 'stopped' => false, 'cut' => false];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $s) {
            stream_set_blocking($s, false);
        }
        $buf = '';
        while ($open && !$r['stopped']) {
            $read = array_values($open);
            $w = null;
            $e = null;
            if (@stream_select($read, $w, $e, 1) === false) {
                break;
            }
            foreach ($open as $k => $s) {
                $chunk = fread($s, 65536);
                if ($chunk === '' || $chunk === false) {
                    if (feof($s)) {
                        unset($open[$k]);
                    }
                    continue;
                }
                if ($k === 2) {
                    $r['err'] .= strlen($r['err']) < self::MAX_ERR ? $chunk : '';
                } elseif ($onLine === null) {
                    $r['cut'] = $r['cut'] || strlen($r['out']) >= self::MAX_OUT;
                    $r['out'] .= $r['cut'] ? '' : $chunk;
                } else {
                    $buf .= $chunk;
                    while (!$r['stopped'] && ($nl = strpos($buf, $sep)) !== false) {
                        $r['stopped'] = call_user_func($onLine, substr($buf, 0, $nl)) === false;
                        $buf = (string) substr($buf, $nl + 1);
                    }
                }
            }
        }
        if (!$r['stopped'] && $onLine !== null && $buf !== '') {
            call_user_func($onLine, $buf);
        }
        if ($r['stopped']) {
            proc_terminate($p);
        }
        foreach ($pipes as $s) {
            if (is_resource($s)) {
                fclose($s);
            }
        }
        $r['code'] = proc_close($p);
        return $r;
    }

    /** Entries a listing reads so Cli::emit pages it: offset + limit + 1 (the extra one marks `truncated`), CAP for --limit 0. */
    public static function want(array $opt)
    {
        $limit = isset($opt['limit']) ? max(0, (int) $opt['limit']) : Cli::LIMIT;
        $offset = isset($opt['offset']) ? max(0, (int) $opt['offset']) : 0;
        return $limit > 0 ? min(self::CAP, $offset + $limit + 1) : self::CAP;
    }

    /** Status of a listing that read $count of at most $want entries: listed_all = it read to the end. */
    public static function listed($count, $want)
    {
        return ['listed_all' => $count < $want]
            + ($count >= self::CAP ? ['note' => 'read the first ' . self::CAP . ' entries only; list a narrower path for the rest'] : []);
    }

    /**
     * The last lines of a program's error output, cleaned, for an error message. When the line naming the error comes
     * before them (a borg traceback ends in a Platform, PID and sys.argv footer), it is put first and the tail is cut.
     */
    public static function tail($text, $chars = 400)
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $text)), 'strlen'));
        $cause = '';
        foreach (array_reverse(array_slice($lines, 0, -4)) as $l) {
            if (preg_match('/error|exception|fatal/i', $l) && !preg_match('/^(sys\.argv|SSH_ORIGINAL_COMMAND|Platform|PID):/', $l)) {
                $cause = substr($l, 0, (int) ($chars / 2)) . ' | ... | ';
                break;
            }
        }
        $t = implode(' | ', array_slice($lines, -4));
        $room = $chars - strlen($cause);
        return Cli::cleanString(self::redact($cause . (strlen($t) > $room ? '...' . substr($t, -$room) : $t)));
    }

    /**
     * Removes the password of scheme://user:password@host, also one with an unencoded @: restic takes
     * rest:https://user:pass@host:8000/ (doc/030_preparing_a_new_repo.rst:208, 0.14.0) and parses it with Go's
     * url.Parse (internal/backend/rest/config.go:36), which splits the userinfo at the LAST @ (net/url/url.go:577,
     * go1.19.8). Cli::cleanString stops at the first @ and would leave the rest of such a password.
     */
    public static function redact($s)
    {
        return $s === null ? null : (string) preg_replace('#(://[^/\s:@]*:)[^/\s]*@#', '$1REDACTED@', (string) $s);
    }
}
