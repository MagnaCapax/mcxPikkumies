<?php
/**
 * PanelHtpasswd: the account's panel password file (~/.lighttpd/.htpasswd) as PanelAccess changes it (ADR-0079). PMSS
 * creates and owns the file; it is never created here. A symlink, a hard link, a file that is not regular, a file of
 * another owner or one over 1 MiB is refused, never repaired. Every change runs under flock on
 * ~/.lighttpd/.htpasswd.lock, the lock PMSS's writers take in the account's context, waiting at most $lockWait seconds
 * and never breaking it. Lines are split on "\n" only, so every line that is not the agent's is kept byte for byte.
 */
class PanelHtpasswd
{
    const MAX = 1048576;

    public $path;
    public $uid;
    /** Seconds to wait for the lock (PMSS waits 10 as well); a test seam. */
    public $lockWait = 10;

    public function __construct($path, $uid)
    {
        $this->path = $path;
        $this->uid = (int) $uid;
    }

    public function exists()
    {
        clearstatcache(true, $this->path);
        return @lstat($this->path) !== false;
    }

    /** '' when the file may be read and changed, else why not, in one plain line. */
    public function problem()
    {
        $shown = Config::shown($this->path);
        clearstatcache(true, $this->path);
        $dir = @lstat(dirname($this->path));
        if ($dir === false || ($dir['mode'] & 0170000) !== 0040000) {
            return Config::shown(dirname($this->path)) . ' is not a directory';
        }
        $st = @lstat($this->path);
        if ($st === false) {
            return "$shown does not exist (the panel creates it; Pikkumies never does)";
        }
        if (($st['mode'] & 0170000) !== 0100000) {
            return "$shown is " . (($st['mode'] & 0170000) === 0120000 ? 'a symlink' : 'not a regular file') . '; not touching it';
        }
        if ((int) $st['uid'] !== $this->uid) {
            return "$shown belongs to uid {$st['uid']}, not to this account";
        }
        if ((int) $st['nlink'] !== 1) {
            return "$shown has other hard links; not touching it";
        }
        return (int) $st['size'] > self::MAX ? "$shown is larger than 1 MiB" : '';
    }

    /** $fn() under the lock, with problem() checked again once it is held; [false, why] when the lock is not had in time. */
    public function locked(callable $fn)
    {
        $lock = $this->path . '.lock';
        clearstatcache(true, $lock);
        $st = @lstat($lock);
        if ($st !== false && ($st['mode'] & 0170000) !== 0100000) {
            return [false, Config::shown($lock) . ' is not a regular file; not locking through it'];
        }
        $h = @fopen($lock, 'c');
        $h = $h !== false ? $h : @fopen($lock, 'r'); // a lock file another writer made read-only still takes flock
        if ($h === false) {
            return [false, 'cannot open ' . Config::shown($lock)];
        }
        try {
            $until = microtime(true) + $this->lockWait;
            while (!flock($h, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $until) {
                    return [false, 'another program held ' . Config::shown($lock) . " for {$this->lockWait} s; nothing changed"];
                }
                usleep(100000);
            }
            $problem = $this->problem();
            return $problem !== '' ? [false, $problem] : $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** The bytes, or null when the file cannot be read or is over the limit. */
    public function read()
    {
        clearstatcache(true, $this->path);
        $b = @file_get_contents($this->path, false, null, 0, self::MAX + 1);
        return $b === false || strlen($b) > self::MAX ? null : $b;
    }

    /** Writes only what $after adds to $before at the end (the file keeps its inode, owner and mode); '' or why not. */
    public function append($before, $after)
    {
        $tail = (string) substr($after, strlen($before));
        if ($tail === '' || strncmp($after, $before, strlen($before)) !== 0) {
            return 'not an append';
        }
        $h = @fopen($this->path, 'a');
        if ($h === false) {
            return 'cannot open ' . Config::shown($this->path) . ' for writing';
        }
        $st = fstat($h);
        if ((int) $st['size'] !== strlen($before)) {
            fclose($h);
            return Config::shown($this->path) . ' changed before the write; nothing written';
        }
        if (fwrite($h, $tail) !== strlen($tail)) {
            ftruncate($h, strlen($before));
            fclose($h);
            return 'the write to ' . Config::shown($this->path) . ' was cut short; cut back to its old length';
        }
        return fclose($h) ? '' : 'cannot finish the write to ' . Config::shown($this->path);
    }

    /** Replaces the file with $bytes: a new file next to it (O_EXCL), read back, the old mode and group, then a rename. */
    public function replace($bytes)
    {
        clearstatcache(true, $this->path);
        $st = @lstat($this->path);
        if ($st === false) {
            return Config::shown($this->path) . ' is gone';
        }
        $tmp = $this->path . '.pikkumies-tmp-' . bin2hex(random_bytes(4));
        $old = umask(0077);
        $h = @fopen($tmp, 'x');
        umask($old);
        if ($h === false) {
            return 'cannot create a temporary file next to ' . Config::shown($this->path);
        }
        $ok = fwrite($h, $bytes) === strlen($bytes);
        $ok = fclose($h) && $ok;
        $ok = $ok && @chmod($tmp, $st['mode'] & 0777);
        $gid = function_exists('posix_getegid') ? posix_getegid() : getmygid();
        $ok = $ok && ((int) $st['gid'] === (int) $gid || @chgrp($tmp, (int) $st['gid']));
        clearstatcache(true, $tmp);
        $ok = $ok && @file_get_contents($tmp) === $bytes && @rename($tmp, $this->path);
        if (!$ok) {
            @unlink($tmp);
            return 'cannot replace ' . Config::shown($this->path) . '; nothing changed';
        }
        return '';
    }

    /** The lines of $bytes, split on "\n" only (a "\r" stays part of its line), without the piece after a final newline. */
    public static function lines($bytes)
    {
        $lines = explode("\n", (string) $bytes);
        if (end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /** The lines for user $name. */
    public static function named(array $lines, $name)
    {
        return array_values(array_filter($lines, function ($l) use ($name) {
            return strncmp($l, $name . ':', strlen($name) + 1) === 0;
        }));
    }

    /** Every other line, in order. */
    public static function others(array $lines, $name)
    {
        return array_values(array_filter($lines, function ($l) use ($name) {
            return strncmp($l, $name . ':', strlen($name) + 1) !== 0;
        }));
    }

    /** $bytes with $line at the end on a line of its own (a missing final newline is added first). */
    public static function appended($bytes, $line)
    {
        return $bytes . ($bytes !== '' && substr($bytes, -1) !== "\n" ? "\n" : '') . $line . "\n";
    }
}
