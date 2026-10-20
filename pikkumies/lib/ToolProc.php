<?php
/**
 * ToolProc: runs a program found in PATH with an argv, never a shell string built from user text
 * (storage, backup, containers, performance and media units). PHP 7.3 proc_open takes a string, so
 * every word is escapeshellarg'd, as lib/Update.php exec() does, and `exec` replaces the shell so a
 * stop signal reaches the program. Nothing here installs anything. Moved from tools/storage/lib/ to
 * pikkumies/lib/ once a third category (media, ADR-0045) needed it (tools/storage/README.md). Error
 * text (tools/storage/lib/ToolText.php) and listing size (tools/storage/lib/ToolList.php) stay their
 * own classes in storage/lib: storage, backup and media use ToolText, only storage and backup ToolList.
 */
require_once __DIR__ . '/PathName.php';

class ToolProc
{
    const MAX_OUT = 8388608;  // stdout bytes kept when no line reader is given
    const MAX_ERR = 1048576;  // stderr bytes kept (borg prints its --list output there)

    /** Absolute path of $name from PATH, or null. */
    public static function which($name)
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if (substr($dir, 0, 1) === '/' && is_file("$dir/$name") && is_executable("$dir/$name")) { // never . or a relative entry
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
            throw new CliError('could not start ' . PathName::base((string) $argv[0]), 'check that it is installed and runnable');
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
}
