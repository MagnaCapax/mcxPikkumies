<?php
/**
 * MediaStackCredentials: the user's own ~/.media-stack-credentials.txt, read and written the way the PMSS media-stack
 * installer does (etc/skel/install-media-stack.sh: `KEY = value` lines, first match read, a value starting with "[" is a
 * placeholder, every matching line replaced, mode 600, written to a temporary file and renamed). The one parser of the
 * file: jellyfin's login and the *ARR login verbs use it. A write takes an exclusive lock on <file>.lock, reads the
 * file again inside the lock, keeps every other line as it was, and refuses a file that is a link, not a regular file,
 * or not this account's own. The values are never printed by this class or its callers.
 */
class MediaStackCredentials
{
    const FILE = '.media-stack-credentials.txt';
    const MAX = 65536;

    public static function path($home)
    {
        return rtrim($home, '/') . '/' . self::FILE;
    }

    /** KEY (upper case, `-` as `_`, as the installer's media_stack_app_key) of an app: sonarr -> SONARR. */
    public static function appKey($app)
    {
        return strtoupper(str_replace('-', '_', $app));
    }

    /** KEY => value (the first line of each key), or null when the file is missing, a link or not a regular file. */
    public static function read($home)
    {
        $file = self::path($home);
        clearstatcache(true, $file);
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        return self::parse((string) @file_get_contents($file, false, null, 0, self::MAX));
    }

    public static function parse($text)
    {
        $v = [];
        foreach (explode("\n", $text) as $line) {
            $p = strpos($line, ' = ');
            if ($p !== false && !isset($v[substr($line, 0, $p)])) {
                $v[substr($line, 0, $p)] = rtrim(substr($line, $p + 3), "\r");
            }
        }
        return $v;
    }

    /** 'present', 'placeholder' (installer's "[...]" text), 'missing' (no such line) or 'no-file'. */
    public static function state($vals, $key)
    {
        if ($vals === null) {
            return 'no-file';
        }
        $s = isset($vals[$key]) ? $vals[$key] : '';
        return $s === '' ? 'missing' : ($s[0] === '[' ? 'placeholder' : 'present');
    }

    /** The usable value of $key: null when missing, empty or a placeholder. */
    public static function value($vals, $key)
    {
        return self::state($vals, $key) === 'present' ? $vals[$key] : null;
    }

    /**
     * Sets KEY => value for each pair (a null value removes that key's lines) and returns the values they had before
     * (null: no such line), which set() takes back to undo the change. Throws CliError, nothing written, on any doubt.
     */
    public static function set($home, array $pairs)
    {
        foreach ($pairs as $k => $v) {
            if (!preg_match('/^[A-Z0-9_]+$/', $k) || ($v !== null && (!is_string($v) || $v === '' || preg_match('/[\r\n]/', $v)))) {
                throw new CliError("refusing to write $k to ~/" . self::FILE . ': not a plain one-line value', 'nothing was written');
            }
        }
        $file = self::path($home);
        $lock = self::lock("$file.lock");
        try {
            $text = self::current($file);
            $old = [];
            $vals = $text === null ? [] : self::parse($text);
            foreach ($pairs as $k => $v) {
                $old[$k] = isset($vals[$k]) ? $vals[$k] : null;
            }
            self::write($file, self::apply($text, $pairs));
            return $old;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The lock file, opened and locked. A link, a non-regular file or another account's file is refused before it is
     * opened ('c' would follow a link and could create its target), and again after: the opened handle (fstat) must be
     * the regular file this account owns at that path (lstat), so a link put in its place between the two is refused.
     * PHP has no fchmod, so the mode is set by path only after that check (a remaining window of a few instructions).
     */
    private static function lock($path)
    {
        $refuse = new CliError('refusing ~/' . self::FILE . '.lock: it is a link, not a regular file, or not this account\'s own', 'nothing was written');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before !== false && (($before['mode'] & 0170000) !== 0100000 || (int) $before['uid'] !== (int) $uid)) {
            throw $refuse;
        }
        $lock = @fopen($path, 'c');
        if ($lock === false) {
            throw new CliError('cannot open ~/' . self::FILE . '.lock', 'check that the home folder is writable; nothing was written');
        }
        clearstatcache(true, $path);
        $open = fstat($lock);
        $now = @lstat($path);
        if ($open === false || $now === false || ($now['mode'] & 0170000) !== 0100000 || (int) $now['uid'] !== (int) $uid
            || $now['ino'] !== $open['ino'] || $now['dev'] !== $open['dev']) {
            fclose($lock);
            throw $refuse;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new CliError('cannot lock ~/' . self::FILE . '.lock', 'nothing was written');
        }
        @chmod($path, 0600);
        return $lock;
    }

    /** The file's text (null: there is none), refusing a link, a non-regular file or another account's file. */
    private static function current($file)
    {
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st === false) {
            return null;
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== (int) $uid) {
            throw new CliError('refusing ~/' . self::FILE . ': it is a link, not a regular file, or not this account\'s own', 'nothing was written');
        }
        $text = @file_get_contents($file, false, null, 0, self::MAX + 1);
        if (!is_string($text) || strlen($text) > self::MAX) {
            throw new CliError('cannot read ~/' . self::FILE . ' whole', 'nothing was written');
        }
        return $text;
    }

    /** $text with each pair set: every line of the key replaced (null: dropped), a missing key appended. */
    public static function apply($text, array $pairs)
    {
        $lines = $text === null ? ['# PMSS media stack credentials', '# This file is owner-readable only; app auth is separate from the public proxy.']
            : explode("\n", rtrim($text, "\n"));
        foreach ($pairs as $k => $v) {
            $found = false;
            foreach ($lines as $n => $line) {
                if (strpos($line, "$k = ") === 0) {
                    $found = true;
                    if ($v === null) {
                        unset($lines[$n]);
                    } else {
                        $lines[$n] = "$k = $v";
                    }
                }
            }
            if (!$found && $v !== null) {
                $lines[] = "$k = $v";
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private static function write($file, $text)
    {
        $tmp = $file . '.pk' . bin2hex(random_bytes(6));
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $text) === strlen($text) && @chmod($tmp, 0600) && @rename($tmp, $file);
        umask($old);
        if (!$ok) {
            @unlink($tmp);
            throw new CliError('cannot write ~/' . self::FILE, 'check that the home folder is writable; nothing was written');
        }
    }
}
