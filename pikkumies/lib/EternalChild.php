<?php
/**
 * EternalChild: runs the Hermes of an Eternal window and, once the resident is told to stop, ends Hermes and the
 * processes it started within a fixed grace (ADR-0075, amendment 2026-11-23). Systemd's scope stop sends SIGTERM to
 * everything in the account's session and then waits the host's full stop timeout for whatever ignores it.
 *
 * Why not passthru(): PHP runs a signal handler only between two opcodes, so inside one blocking passthru() the
 * resident's SIGTERM closure runs after Hermes has already ended. This loop polls the child instead.
 *
 * What it ends: Hermes (a child of this process) and its descendants by parent pid, each as a (pid, start time) pair
 * that is checked again before every signal. Never a torrent client or another known service (Detect::KNOWN_APPS),
 * nor anything above or below one: see OwnProcs::killable(). Never a process group, never the cgroup scope.
 */
class EternalChild
{
    const GRACE = 7;        // seconds from the stop to SIGKILL: grace + reap + afterExit stay under Eternal::waitStopped()'s 10 s
    const REAP = 1;         // seconds after SIGKILL before the loop gives up on a process that does not go (uninterruptible I/O)
    const SCAN = 3;         // seconds between looks at what Hermes started while it runs, so helpers it detached stay known
    const TICK = 200000;    // microseconds between polls

    /** True while run() waits; the SIGALRM check must not exit() then, because exit would wait for the child. */
    public static $running = false;

    /** Whether the resident's last signal means "stop": SIGTERM, or SIGHUP once a stop marker exists (ADR-0075). */
    public static function stopping($signalled, $stopPath)
    {
        return (defined('SIGTERM') && $signalled === SIGTERM) || (defined('SIGHUP') && $signalled === SIGHUP && is_file($stopPath));
    }

    /**
     * Runs $command (an already escaped command line) with this process's stdin and stderr and its stdout relayed;
     * returns its exit code, 128 + signal when a signal ended it, -1 when it could not start. $stopping() true starts
     * the bounded stop. $spare, $uid, $proc and $grace are test seams.
     */
    public static function run($command, callable $stopping, array $spare = null, $uid = null, $proc = '/proc', $grace = self::GRACE)
    {
        if ($spare === null) {
            $spare = call_user_func_array('array_merge', array_values(Detect::KNOWN_APPS));
        }
        if ($uid === null) {
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        }
        // exec: the shell becomes Hermes, so the pid is Hermes' own. Stdout stays a pipe, as it is under passthru().
        $p = @proc_open('exec ' . $command, [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($p)) {
            return -1;
        }
        $out = $pipes[1];
        stream_set_blocking($out, false);
        $status = proc_get_status($p);
        $pid = (int) $status['pid'];
        $code = null;
        $known = [];
        $nextScan = 0.0;
        $stopAt = null;
        $killAt = null;
        self::$running = true;
        while (true) {
            $eof = self::relay($out);
            if ($code === null) {
                $status = proc_get_status($p);
                if (!$status['running']) {
                    $code = $status['signaled'] ? 128 + (int) $status['termsig'] : (int) $status['exitcode']; // the first reading only
                }
            }
            $now = microtime(true);
            $root = $code === null ? $pid : -1; // a pid of a child that has ended may belong to someone else now
            if ($stopAt === null) {
                if ($stopping()) {
                    $stopAt = $now + $grace;
                    $known = OwnProcs::killable($root, $known, $spare, $uid, $proc);
                    self::signal($known, 15);
                } elseif ($code !== null) {
                    break;
                } elseif ($now >= $nextScan) {
                    $known = OwnProcs::killable($root, $known, $spare, $uid, $proc);
                    $nextScan = $now + self::SCAN;
                }
            } else {
                $known = OwnProcs::killable($root, $known, $spare, $uid, $proc);
                if ($code !== null && !$known) {
                    break;
                }
                if ($killAt === null && $now >= $stopAt) {
                    self::signal($known, 9);
                    $killAt = $now + self::REAP;
                } elseif ($killAt !== null && $now >= $killAt) {
                    break;
                }
            }
            if ($eof) {
                usleep(self::TICK);
            } else {
                $r = [$out];
                $w = $e = null;
                @stream_select($r, $w, $e, 0, self::TICK);
            }
        }
        self::$running = false;
        self::relay($out);
        fclose($out);
        if ($code === null) {
            $code = 128 + 9; // still there after SIGKILL: the caller goes on without waiting for it
        } else {
            proc_close($p);
        }
        return $code;
    }

    /** Copies what Hermes wrote to this process's stdout; true once its end of the pipe is closed. */
    private static function relay($out)
    {
        while (($chunk = fread($out, 8192)) !== false && $chunk !== '') {
            fwrite(STDOUT, $chunk);
        }
        return feof($out);
    }

    /** $signal to each (pid => start time) that still has that start time. */
    private static function signal(array $set, $signal)
    {
        foreach ($set as $pid => $start) {
            if (function_exists('posix_kill') && OwnProcs::started($pid) === (string) $start) {
                @posix_kill($pid, $signal);
            }
        }
    }
}
