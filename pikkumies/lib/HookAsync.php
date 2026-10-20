<?php
/**
 * HookAsync: when an interval hook unit runs (ADR-0067). Per unit and session, under HookSpool::unitDir():
 * <sha1>.wake (a record of its wake kinds arrived), .stamp (the time the last run was started), .lock (held by
 * the run), .cursor (the last record seq it was given) and .state.json (what it asked to get back). due() only looks:
 * flag set and the interval passed. kick() starts the worker detached; the run itself is lib/HookRun.php's, which a
 * synchronous caller (an end-of-turn check of the same unit) uses too, so both share one stamp, cursor and state.
 *
 * The detached start: `timeout -k 5 <timeout+10> [setpriv --no-new-privs] [nice -n 19] php hooks/worker.php <unit>
 * <sha1> </dev/null > local/logs/hook-async.last 2>&1 &` (the last worker's output kept, ADR-0099). The shell exits at once, so `timeout` is reparented to init and sits in its own
 * process group: Hermes' kill of a timed-out hook (its process group and its descendants, agent/deadline.py
 * kill_process_tree) does not reach it, and Hermes' read of the hook's pipes ends because no pipe is inherited.
 * `timeout --foreground` would stay in the hook's group, so it is never used. Every lock is released before the start.
 * A shell string, not proc_open's array form, because the product runs on PHP 7.3.
 */
require_once __DIR__ . '/HookSpool.php';
require_once __DIR__ . '/HookInterval.php';

class HookAsync
{
    const ONESHOT_ENV = 'PIKKUMIES_ONESHOT'; // set by a one-shot run (lib/HaltRun.php): post_llm_call records but starts nothing

    public static function sessionHash($sessionId)
    {
        return sha1((string) $sessionId);
    }

    /** True when the unit has something new of its wake kinds and its interval has passed since its last start. */
    public static function due($localDir, array $u, $hash, $now = null)
    {
        $f = self::path($localDir, $u, $hash, '');
        return is_file("$f.wake") && self::passed((int) @file_get_contents("$f.stamp"), $u, $now);
    }

    /**
     * Start every due unit's worker in the background, at most once per interval: the stamp is written under its lock
     * (LOCK_NB, so two tool calls never both start one) and released before the start. Returns the ids started.
     * $hash is the spool's (lib/HookRunCount.php); null: the sha1 of $sessionId.
     */
    public static function kick($localDir, array $units, $sessionId, $worker, $hash = null)
    {
        $hash = $hash === null ? self::sessionHash($sessionId) : (string) $hash;
        $timeout = self::bin('timeout');
        $started = [];
        $old = umask(0077);
        foreach ($units as $id => $u) {
            if (!is_file(self::path($localDir, $u, $hash, '.wake'))) {
                continue; // nothing new of its wake kinds; the interval is checked once, under the stamp's lock
            }
            if ($timeout === '') {
                HookLog::add('async', $id, 0, 'async-start-failed'); // no stamp: the unit is not silently skipped forever
                continue;
            }
            $f = self::path($localDir, $u, $hash, '');
            $fh = @fopen("$f.stamp", 'c+');
            if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
                if ($fh !== false) {
                    fclose($fh);
                }
                continue;
            }
            $go = self::passed((int) stream_get_contents($fh), $u) && ftruncate($fh, 0) && rewind($fh) && fwrite($fh, (string) time()) > 0;
            flock($fh, LOCK_UN);
            fclose($fh);
            if (!$go) {
                continue;
            }
            $log = "$localDir/logs/hook-async.last"; // the last worker's output and errors, never /dev/null (ADR-0099)
            @mkdir(dirname($log), 0700, true);
            exec(self::detached([PHP_BINARY, $worker, $id, $hash], $u['timeout'] + 10, $log));
            $started[] = $id;
        }
        umask($old);
        return $started;
    }

    /** The shell line that starts $argv detached, killed after $limit seconds, its output in $log (the class comment says why each part is there). */
    public static function detached(array $argv, $limit, $log)
    {
        $argv = array_merge([self::bin('timeout'), '-k', '5', (string) $limit], self::bin('setpriv') !== '' ? [self::bin('setpriv'), '--no-new-privs'] : [],
            self::bin('nice') !== '' ? [self::bin('nice'), '-n', '19'] : [], $argv);
        return implode(' ', array_map('escapeshellarg', $argv)) . ' </dev/null > ' . escapeshellarg($log) . ' 2>&1 &';
    }

    /** True when no run was started yet ($started 0) or the interval has passed since $started. */
    public static function passed($started, array $u, $now = null)
    {
        return $started <= 0 || ($now !== null ? $now : time()) - $started >= $u['every'];
    }

    public static function path($localDir, array $u, $hash, $suffix)
    {
        return HookSpool::unitDir($localDir, HookInterval::id($u)) . "/$hash$suffix";
    }

    /** Write through a temporary file and a rename (0600); false when it could not. */
    public static function put($path, $content)
    {
        $old = umask(0077);
        $tmp = $path . '.tmp-' . getmypid();
        $ok = @file_put_contents($tmp, $content) === strlen($content) && @rename($tmp, $path);
        if (!$ok) {
            @unlink($tmp);
        }
        umask($old);
        return $ok;
    }

    /** An absolute path to $name found on PATH ('' when there is none), as CronTick::lowPriority finds nice. */
    public static function bin($name)
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if (substr($dir, 0, 1) === '/' && is_file("$dir/$name") && is_executable("$dir/$name")) {
                return "$dir/$name";
            }
        }
        return '';
    }
}
