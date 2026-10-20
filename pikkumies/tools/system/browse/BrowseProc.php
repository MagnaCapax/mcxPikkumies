<?php
/**
 * BrowseProc: the process operations the supervisor needs, behind one small object so tests swap in a fake and never
 * start unshare, slirp4netns or Chromium. Every command is an argv (ToolProc::line quotes each word); nothing is
 * built from user text.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class BrowseProc
{
    /**
     * Start $argv detached (own session, stdin from /dev/null, output appended to $log) and return its pid, 0 on
     * failure. setsid does not fork here: a background job of a non-interactive sh is not a group leader, so $! is the
     * program itself. $env: the complete environment (env -i), or null to inherit.
     */
    public function spawn(array $argv, $log, array $env = null)
    {
        $cmd = $env === null ? $argv : array_merge(['env', '-i'], self::envWords($env), $argv);
        $line = 'setsid ' . ToolProc::line($cmd) . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $out = [];
        exec($line, $out, $code);
        $pid = isset($out[0]) ? (int) trim($out[0]) : 0;
        return $code === 0 && $pid > 1 ? $pid : 0;
    }

    /** Run $argv to the end (at most $seconds), with $env as the complete environment when given. ['code', 'out', 'err']. */
    public function run(array $argv, array $env = null, $seconds = 30, $stdin = null)
    {
        $cmd = array_merge(['timeout', '-k', '5', (string) (int) $seconds], $env === null ? [] : array_merge(['env', '-i'], self::envWords($env)), $argv);
        $p = proc_open('exec ' . ToolProc::line($cmd), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => '', 'err' => 'could not start ' . $argv[0]];
        }
        fwrite($pipes[0], (string) $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => (string) $out, 'err' => (string) $err];
    }

    /** Start time of $pid (OwnProcs::started), null when gone. Same pid and start time is the same process. */
    public function started($pid)
    {
        return $pid > 0 ? OwnProcs::started($pid) : null;
    }

    public function alive($pid, $started)
    {
        return $started !== null && $this->started($pid) === $started;
    }

    public function kill($pid, $signal = 'TERM')
    {
        if ($pid > 1) {
            exec('kill -' . escapeshellarg($signal) . ' ' . (int) $pid . ' 2>/dev/null');
        }
    }

    /** The account's processes in network namespace $net. */
    public function members($net)
    {
        return BrowseIsolation::members($net, BrowsePaths::uid());
    }

    /**
     * oom_score_adj 1000 for $pids (raising one's own is never refused). Chrome writes its own scores after start (the
     * browser 0, renderers 200 to 300, seen on the development host), so the inherited value from choom does not stick
     * and the watcher sets it again every round.
     */
    public function raiseOom(array $pids)
    {
        foreach ($pids as $pid) {
            $f = '/proc/' . (int) $pid . '/oom_score_adj';
            if (trim((string) @file_get_contents($f)) !== '1000') {
                @file_put_contents($f, "1000\n");
            }
        }
    }

    /** readlink /proc/<pid>/ns/net ('' when unreadable); $pid 'self' for this process. */
    public function netns($pid)
    {
        return (string) @readlink('/proc/' . $pid . '/ns/net');
    }

    /** Pids whose parent is $pid (the inner holder is the outer one's only child). */
    public function children($pid)
    {
        $out = [];
        foreach ((array) glob('/proc/[0-9]*/stat') as $f) {
            $stat = (string) @file_get_contents($f);
            $tail = strrpos($stat, ')') !== false ? explode(' ', substr($stat, strrpos($stat, ')') + 2)) : [];
            if (isset($tail[1]) && (int) $tail[1] === (int) $pid) {
                $out[] = (int) PathName::base(dirname($f));
            }
        }
        return $out;
    }

    public function which($name)
    {
        return ToolProc::which($name);
    }

    public function sleep($seconds)
    {
        usleep((int) ($seconds * 1000000));
    }

    public function now()
    {
        return time();
    }

    private static function envWords(array $env)
    {
        $words = [];
        foreach ($env as $k => $v) {
            $words[] = "$k=$v";
        }
        return $words;
    }
}
