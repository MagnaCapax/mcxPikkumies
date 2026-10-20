<?php
/**
 * HomePath: which paths a storage, backup or media verb may touch (tools/storage/README.md, safety
 * rules; ADR-0045). A path is resolved with realpath and must be inside $HOME, unless the user named it with --path,
 * and even then never / or a system directory, and only a directory this account owns. Restores go only to a new or empty directory that
 * is not inside a FUSE mount. FUSE mounts come from /proc/self/mountinfo (Linux
 * Documentation/filesystems/proc.rst §3.5, v6.12); the mount's owner is its `user_id=` super option
 * (Documentation/filesystems/fuse.rst, v6.12). Moved from tools/storage/lib/ to pikkumies/lib/ once a
 * third category (media) needed it.
 */
require_once __DIR__ . '/PathName.php';

class HomePath
{
    const SYSTEM = ['/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/libx32', '/proc', '/root', '/run', '/sbin', '/srv',
        '/sys', '/usr', '/var'];

    /** The real home directory, or a CliError. */
    public static function home()
    {
        $home = (string) getenv('HOME');
        $real = $home !== '' ? realpath($home) : false;
        if ($real === false || !is_dir($real) || $real === '/') {
            throw new CliError('HOME is not set to a directory', 'run the tool as the account whose files it is about');
        }
        return $real;
    }

    public static function inside($real, $home)
    {
        return $real === $home || strpos($real . '/', rtrim($home, '/') . '/') === 0;
    }

    /** An existing directory: inside $home, or ($explicit) anywhere but / and system directories. */
    public static function dir($path, $home, $explicit)
    {
        $real = is_string($path) && $path !== '' && $path[0] === '/' ? realpath($path) : false;
        if ($real === false || !is_dir($real)) {
            throw new CliError("not an existing directory given as an absolute path: $path", 'give a full path, e.g. ' . $home . '/data');
        }
        if (!$explicit && !self::inside($real, $home)) {
            throw new CliError("$real is outside the home directory", 'name it with --path to scan it anyway');
        }
        if (!self::inside($real, $home) && ($real === '/' || self::isSystem($real))) {
            throw new CliError("$real is a system directory; not scanned", 'scan your own files: ' . $home);
        }
        // Outside the home only a folder this account owns: never /home, /tmp, /mnt or another account's home.
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (!self::inside($real, $home) && @fileowner($real) !== $uid) {
            throw new CliError("$real belongs to another account; not scanned", 'outside the home only folders this account owns'
                . ' are scanned');
        }
        return $real;
    }

    /** An existing file or directory inside $home (backup sources, rclone local paths). */
    public static function existing($path, $home)
    {
        $real = is_string($path) && $path !== '' && $path[0] === '/' ? realpath($path) : false;
        if ($real === false || !self::inside($real, $home)) {
            throw new CliError("not an existing path inside the home directory: $path", 'give a full path under ' . $home);
        }
        return $real;
    }

    /**
     * A restore target: a new directory (created here, 0700) or an existing empty one, inside $home,
     * not inside a FUSE mount, never a symlink. Returns [real path, created by us].
     */
    public static function newTarget($path, $home, array $mounts)
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new CliError('--target must be a full path to a new directory', 'e.g. --target ' . $home . '/restore-' . date('Ymd'));
        }
        $path = rtrim($path, '/');
        if (is_link($path)) {
            throw new CliError("$path is a symlink; nothing restored", 'name a new directory');
        }
        $parent = realpath(dirname($path));
        if ($parent === false || !self::inside($parent . '/' . PathName::base($path), $home) || $parent . '/' . PathName::base($path) === $home) {
            throw new CliError("$path is not a new directory inside the home directory; nothing restored",
                'restores go only to a new directory under ' . $home);
        }
        $real = $parent . '/' . PathName::base($path);
        if (self::inFuse($real, $mounts)) {
            throw new CliError("$real is inside a FUSE mount (rclone, sshfs...); nothing restored",
                'restore to a local directory under ' . $home);
        }
        if (file_exists($real)) {
            $entries = is_dir($real) ? @scandir($real) : false;
            if ($entries === false || count($entries) > 2) {
                throw new CliError("$real already exists and is not an empty directory; nothing restored (a restore never overwrites)",
                    'name a new directory, e.g. ' . $real . '-' . date('His'));
            }
            return [$real, false];
        }
        if (!@mkdir($real, 0700)) {
            throw new CliError("cannot create $real", 'check the parent directory and the quota (storage stats)');
        }
        return [$real, true];
    }

    /** This account's FUSE mounts (or every FUSE mount with $uid null): mountpoint, fstype, source, user_id. */
    public static function fuseMounts($mountinfo = '/proc/self/mountinfo', $uid = null)
    {
        $out = [];
        foreach (explode("\n", (string) @file_get_contents($mountinfo)) as $line) {
            $f = explode(' ', trim($line));
            $sep = array_search('-', $f, true);
            if ($sep === false || count($f) < $sep + 4 || strpos($f[$sep + 1], 'fuse') !== 0) {
                continue;
            }
            $owner = preg_match('/(^|,)user_id=(\d+)(,|$)/', $f[$sep + 3], $m) ? (int) $m[2] : null;
            if ($uid === null || $owner === (int) $uid) {
                $out[] = ['mountpoint' => self::unescape($f[4]), 'fstype' => $f[$sep + 1], 'source' => self::unescape($f[$sep + 2]),
                    'user_id' => $owner];
            }
        }
        return $out;
    }

    public static function inFuse($real, array $mounts)
    {
        foreach ($mounts as $m) {
            if (self::inside($real, $m['mountpoint'])) {
                return true;
            }
        }
        return false;
    }

    private static function isSystem($real)
    {
        foreach (self::SYSTEM as $s) {
            if ($real === $s || strpos($real, $s . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /** mountinfo writes space, tab, newline and backslash as octal escapes (\040...). */
    private static function unescape($s)
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $s);
    }
}
