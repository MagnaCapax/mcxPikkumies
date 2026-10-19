<?php
/**
 * HookRun: one run of an interval hook unit for one session (ADR-0067), the record the worker (hooks/worker.php) and any
 * synchronous caller of the same unit share: beginRun() takes the unit's run lock without waiting and writes a new
 * stamp, endRun() moves the cursor, saves the state and releases; again() sets the unit to be tried later; slot() takes
 * one of SLOTS account-wide worker slots. State lives beside HookAsync::path().
 */
require_once __DIR__ . '/HookAsync.php';

class HookRun
{
    const SLOTS = 2;        // workers at once per account
    const RETRY = 30;       // seconds until a run that found its unit or every slot busy is tried again

    /** Take the run: the unit's lock (never waiting) and a new stamp. The lock handle, or false when another run holds it. */
    public static function beginRun($localDir, array $u, $hash)
    {
        $f = HookAsync::path($localDir, $u, $hash, '');
        $old = umask(0077); // a synchronous caller has no umask of its own for these files
        $made = is_dir(dirname($f)) || @mkdir(dirname($f), 0700, true) || is_dir(dirname($f)); // another process may have just made it
        $lock = $made ? @fopen("$f.lock", 'c') : false;
        umask($old);
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return false;
        }
        HookAsync::put("$f.stamp", (string) time());
        return $lock;
    }

    /** Finish a run: the cursor to $cursor, the state replaced unless null, the lock released. */
    public static function endRun($localDir, array $u, $hash, $lock, $cursor, $state = null)
    {
        $f = HookAsync::path($localDir, $u, $hash, '');
        HookAsync::put("$f.cursor", (string) (int) $cursor);
        if ($state !== null) {
            HookAsync::put("$f.state.json", json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** A run that could not go ahead (busy) or chose to wait (skip): try again after RETRY seconds, or at once. */
    public static function again($localDir, array $u, $hash, $delay = self::RETRY)
    {
        HookAsync::put(HookAsync::path($localDir, $u, $hash, '.stamp'), (string) (time() - $u['every'] + $delay));
    }

    /** One of SLOTS account-wide worker slots, never waiting: the lock handle, or false when all are busy. */
    public static function slot($localDir)
    {
        for ($i = 1; $i <= self::SLOTS; $i++) {
            $fh = @fopen(HookSpool::dir($localDir) . "/slot-$i.lock", 'c');
            if ($fh !== false && flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            if ($fh !== false) {
                fclose($fh);
            }
        }
        return false;
    }

    public static function cursor($localDir, array $u, $hash)
    {
        return (int) @file_get_contents(HookAsync::path($localDir, $u, $hash, '.cursor'));
    }

    /** What the unit returned as state last time ([] when none); read by the worker and by a synchronous caller. */
    public static function state($localDir, array $u, $hash)
    {
        $d = json_decode((string) @file_get_contents(HookAsync::path($localDir, $u, $hash, '.state.json'), false, null, 0, 65536), true);
        return is_array($d) ? $d : [];
    }

    /** True when the unit has never run for this session. */
    public static function firstRun($localDir, array $u, $hash)
    {
        return !is_file(HookAsync::path($localDir, $u, $hash, '.cursor'));
    }
}
