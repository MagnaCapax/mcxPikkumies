<?php
/**
 * Scratch: one private scratch folder per session, /tmp/pk-<uid>/<32 hex> (ADR-0093), for the agent's small temporary
 * files, so they never land in a working directory or in the Pikkumies tree. /tmp is shared by every account (PMSS gives
 * no private /tmp), so the folders are made the way a shared directory needs (CWE-377, CWE-379): /tmp itself must be a
 * real directory, sticky when others can write it; the account's parent pk-<uid> is made 0700 or, when it exists, must be
 * a real directory owned by the account with no group or other bits (a parent another account made is refused, never
 * used); each session folder is 128 random bits, made with one mkdir(2) under umask 077, which fails on any existing
 * name, and checked again with lstat. Inside the private parent no other account can take a name a session used before.
 * Which folder belongs to which session is recorded in local/state/scratch/<sha1 of the session id>.
 * prune() removes this account's folders untouched for KEEP_DAYS: checked with lstat; never through a symlink, never into
 * another filesystem, and never a folder with a mount point at or below it (/proc/self/mountinfo, which also shows a bind
 * mount from the same filesystem); such a folder is left and counted as failed.
 */
require_once __DIR__ . '/PathName.php';

class Scratch
{
    const KEEP_DAYS = 7;
    const SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
    const NAME_RE = '/^[0-9a-f]{32}$/';

    /** Test seams: a base other than /tmp, in this process or (PIKKUMIES_SCRATCH_BASE) in a hook the tests start; another mountinfo. */
    public static $base = null;
    public static $mountinfo = null;

    public static function base()
    {
        $env = (string) getenv('PIKKUMIES_SCRATCH_BASE');
        return rtrim(self::$base !== null ? self::$base : ($env !== '' ? $env : '/tmp'), '/');
    }

    public static function uid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** $uid's private parent folder. */
    public static function parent($uid)
    {
        return self::base() . '/pk-' . (int) $uid;
    }

    /** True when $path is a real directory owned by $uid with no group or other bits. */
    private static function isPrivate($path, $uid)
    {
        clearstatcache(true, $path); // PHP 7.3 keeps a stale lstat mode after chmod
        $st = @lstat($path);
        return is_array($st) && ($st['mode'] & 0170000) === 0040000 && $st['uid'] === (int) $uid && ($st['mode'] & 0077) === 0;
    }

    /** True when $path is a session folder only $uid can use: in its private parent, named by NAME_RE, private itself. */
    public static function safe($path, $uid)
    {
        return is_string($path) && dirname($path) === self::parent($uid) && preg_match(self::NAME_RE, PathName::base($path)) === 1
            && self::isPrivate(self::parent($uid), $uid) && self::isPrivate($path, $uid);
    }

    /** The base must be a real directory owned by root or $uid, and sticky when others can write it (a shared /tmp). */
    public static function baseSafe($base, $uid)
    {
        clearstatcache(true, $base);
        $st = @lstat($base);
        return is_array($st) && ($st['mode'] & 0170000) === 0040000 && in_array($st['uid'], [0, (int) $uid], true)
            && (($st['mode'] & 0002) === 0 || ($st['mode'] & 01000) !== 0);
    }

    /** A new session folder for $uid, or '' when the base or the parent is not safe or no folder could be made and checked. */
    public static function create($uid = null)
    {
        $uid = $uid === null ? self::uid() : (int) $uid;
        $parent = self::parent($uid);
        if (!self::baseSafe(self::base(), $uid)) {
            return '';
        }
        $mask = umask(077);
        try {
            @mkdir($parent, 0700); // fails when it exists; then it must pass the same check as one made here
            if (!self::isPrivate($parent, $uid)) {
                return '';
            }
            for ($try = 0; $try < 4; $try++) {
                $path = "$parent/" . bin2hex(random_bytes(16));
                if (@mkdir($path, 0700)) {
                    return self::safe($path, $uid) ? $path : '';
                }
            }
        } catch (Exception $e) {
            return ''; // no randomness
        } finally {
            umask($mask);
        }
        return '';
    }

    /**
     * [path, new]: this session's folder, made when the session has none or the one it had is gone or not safe (a reboot
     * empties a tmpfs /tmp); a reused folder and its record are touched, so prune() sees it in use. ['', false] when the
     * session id is unusable or no folder can be made.
     */
    public static function forSession($localDir, $session, $uid = null)
    {
        $uid = $uid === null ? self::uid() : (int) $uid;
        if (!is_string($session) || !preg_match(self::SESSION_RE, $session)) {
            return ['', false];
        }
        $dir = rtrim($localDir, '/') . '/state/scratch';
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) || is_link($dir)) {
            return ['', false];
        }
        $file = "$dir/" . sha1($session);
        $path = is_link($file) ? '' : trim((string) @file_get_contents($file));
        if ($path !== '' && self::safe($path, $uid)) {
            @touch($file);
            @touch($path);
            return [$path, false];
        }
        $path = self::create($uid);
        if ($path === '') {
            return ['', false];
        }
        $tmp = "$file." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $path . "\n") === false || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
        return [$path, true];
    }

    /** Removes $uid's session folders untouched for KEEP_DAYS and old session records; [removed, failed]. */
    public static function prune($localDir, $now = null, $uid = null)
    {
        $uid = $uid === null ? self::uid() : (int) $uid;
        $cut = ($now === null ? time() : (int) $now) - self::KEEP_DAYS * 86400;
        $parent = self::parent($uid);
        list($removed, $failed) = [0, 0];
        $names = self::isPrivate($parent, $uid) ? @scandir($parent) : false;
        $mounts = $names ? self::mountPoints() : [];
        foreach ($names === false ? [] : $names as $name) {
            $path = "$parent/$name";
            if (!preg_match(self::NAME_RE, $name) || !self::safe($path, $uid)) {
                continue; // a symlink, another owner's, or not a session folder: never touched
            }
            $st = @lstat($path);
            if ($st === false || $st['mtime'] >= $cut) {
                continue;
            }
            if ($mounts === null || self::hasMount($path, $mounts) || !self::remove($path, $st['dev'], $uid, 0)) {
                $failed++; // a mount below it, or mountinfo unreadable: left as it is
            } else {
                $removed++;
            }
        }
        foreach ((array) glob(rtrim($localDir, '/') . '/state/scratch/*') as $record) {
            if (is_string($record) && !is_link($record) && is_file($record) && @filemtime($record) < $cut) {
                @unlink($record);
            }
        }
        return [$removed, $failed];
    }

    /** Every mount point in mountinfo (field 5, octal escapes undone), or null when it cannot be read. */
    private static function mountPoints()
    {
        $raw = @file_get_contents(self::$mountinfo !== null ? self::$mountinfo : '/proc/self/mountinfo');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $f = explode(' ', $line);
            if (isset($f[4])) {
                $out[] = (string) preg_replace_callback('/\\\\([0-7]{3})/', function ($m) {
                    return chr(octdec($m[1]));
                }, $f[4]);
            }
        }
        return $out;
    }

    private static function hasMount($path, array $mounts)
    {
        foreach ($mounts as $m) {
            if ($m === $path || strpos($m, $path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /** Remove $path and what is below it, lstat first: a symlink is unlinked, never followed; another filesystem is left. */
    private static function remove($path, $dev, $uid, $depth)
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false) {
            return true;
        }
        if (($st['mode'] & 0170000) !== 0040000) {
            return @unlink($path);
        }
        if ($st['dev'] !== $dev || $depth > 64) {
            return false;
        }
        if ($st['uid'] === $uid && ($st['mode'] & 0700) !== 0700) {
            @chmod($path, 0700); // a folder a program left unwritable; lstat said it is a directory, not a link
        }
        $ok = true;
        $names = @scandir($path);
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..') {
                $ok = self::remove("$path/$name", $dev, $uid, $depth + 1) && $ok;
            }
        }
        return @rmdir($path) && $ok;
    }
}
