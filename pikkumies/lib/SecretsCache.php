<?php
/**
 * SecretsCache: where the unlocked key of the secret store waits for its TTL (ADR-0085). Never under the home, so a
 * backup or a copy of the home never holds it. First choice $XDG_RUNTIME_DIR/pikkumies/ (or /run/user/<uid>/ when
 * the variable is unset, so an agent started without it finds a key unlocked in an SSH terminal), used only when
 * that base is a directory this uid owns with no group or other access. Else /dev/shm/pikkumies-<uid>/, created 0700
 * when absent and then used only after lstat proves it is a real directory (not a symlink), owned by this uid, mode
 * 0700: /dev/shm is shared by every account, so anything else there is refused. The key file is written to a fresh
 * name opened with fopen 'x' (O_EXCL) at 0600 and renamed into place; a read trusts only a regular 0600 file of this
 * uid. Both places are tmpfs on systemd hosts, cleared at reboot. The TTL is enforced by whoever looks: an expired key
 * file stays until the next read of the cache or the next secrets command (sweep()), `lock`, or a reboot.
 */
class SecretsCache
{
    /** Test seams: the runtime base (null: $XDG_RUNTIME_DIR, else /run/user/<uid>), the shared tmpfs, the uid. */
    public static $runtimeBase = null;
    public static $shmDir = null;
    public static $uid = null;

    public static function uid()
    {
        return self::$uid !== null ? (int) self::$uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
    }

    /** The cache directories to look in, best first. */
    public static function candidates()
    {
        $uid = self::uid();
        $base = self::$runtimeBase !== null ? self::$runtimeBase : (string) getenv('XDG_RUNTIME_DIR');
        $base = $base !== '' ? $base : "/run/user/$uid";
        $out = [];
        $st = substr($base, 0, 1) === '/' ? @lstat($base) : false;
        if ($st !== false && ($st['mode'] & 0170000) === 0040000 && (int) $st['uid'] === $uid && ($st['mode'] & 077) === 0) {
            $out[] = rtrim($base, '/') . '/pikkumies';
        }
        $out[] = (self::$shmDir !== null ? rtrim(self::$shmDir, '/') : '/dev/shm') . "/pikkumies-$uid";
        return $out;
    }

    /** Throws unless $dir is a real directory of this uid, mode 0700, outside the home. */
    public static function check($dir)
    {
        clearstatcache(true, $dir);
        $st = @lstat($dir);
        $uid = self::uid();
        $why = $st === false ? 'missing' : (($st['mode'] & 0170000) === 0120000 ? 'a symlink' : (($st['mode'] & 0170000) !== 0040000 ? 'not a directory'
            : ((int) $st['uid'] !== $uid ? "owned by uid {$st['uid']}" : (($st['mode'] & 0777) !== 0700 ? sprintf('mode %04o', $st['mode'] & 0777) : ''))));
        $home = realpath((string) getenv('HOME'));
        $real = realpath($dir);
        if ($why === '' && $home !== false && $home !== '/' && $real !== false && strpos($real . '/', rtrim($home, '/') . '/') === 0) {
            $why = 'inside the home directory';
        }
        if ($why !== '') {
            throw new CliError("not keeping the unlocked key in $dir: it is $why (it must be a 0700 directory this account owns, outside the home)",
                'remove or fix it if it is yours; if another account made it, log in with a session that has XDG_RUNTIME_DIR (/run/user/' . $uid . ') and unlock there');
        }
    }

    /** The directory to write to: created 0700 when absent, then checked. */
    public static function dir()
    {
        $dir = self::candidates()[0];
        if (!file_exists($dir) && !is_link($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700);
            umask($old);
        }
        self::check($dir);
        return $dir;
    }

    public static function file($dir, $vault)
    {
        return "$dir/key-" . substr(sha1($vault), 0, 16);
    }

    /** Caches $key for $ttl seconds; returns [file, expires]. */
    public static function put($vault, $key, $salt, $ttl)
    {
        $dir = self::dir();
        $expires = Secrets::now() + (int) $ttl;
        $json = json_encode(['key' => base64_encode($key), 'salt' => $salt, 'expires' => $expires]);
        $tmp = "$dir/.tmp-" . bin2hex(random_bytes(8));
        $old = umask(0077);
        $fh = @fopen($tmp, 'x');
        umask($old);
        $ok = $fh !== false && @chmod($tmp, 0600) && @fwrite($fh, $json) === strlen($json);
        if ($fh !== false) {
            fclose($fh);
        }
        sodium_memzero($json);
        $file = self::file($dir, $vault);
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new CliError("cannot write the unlocked key to $dir", 'check that the directory is yours and has space; the store stays locked');
        }
        return [$file, $expires];
    }

    /** ['key', 'salt', 'expires', 'file'] of the first live cached key, or null. An expired one is removed. */
    public static function read($vault)
    {
        foreach (self::candidates() as $dir) {
            if (!file_exists($dir) && !is_link($dir)) {
                continue;
            }
            self::check($dir);
            $file = self::file($dir, $vault);
            clearstatcache(true, $file);
            $st = @lstat($file);
            if ($st === false) {
                continue;
            }
            if (($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== self::uid() || ($st['mode'] & 077) !== 0) {
                throw new CliError("$file is not a 0600 file of this account; not trusting it as the unlocked key", 'run `secrets lock`, then unlock again');
            }
            $c = json_decode((string) @file_get_contents($file, false, null, 0, 4096), true);
            $key = is_array($c) && isset($c['key'], $c['salt'], $c['expires']) ? base64_decode((string) $c['key'], true) : false;
            if ($key === false || strlen($key) !== 32 || (int) $c['expires'] <= Secrets::now()) {
                self::wipe($file);
                continue;
            }
            return ['key' => $key, 'salt' => (string) $c['salt'], 'expires' => (int) $c['expires'], 'file' => $file];
        }
        return null;
    }

    /** Removes every cached key of $vault; returns how many there were. A directory failing check() never held ours. */
    public static function forget($vault)
    {
        $n = 0;
        foreach (self::candidates() as $dir) {
            try {
                self::check($dir);
            } catch (CliError $e) {
                continue;
            }
            $file = self::file($dir, $vault);
            if (is_file($file) && !is_link($file)) {
                $n += self::wipe($file) ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * Wipes every expired or unreadable key file of this account in the cache directories, for any store. Run at the
     * start of every secrets command, so an expired key does not wait for the next read of its own store. Never throws.
     */
    public static function sweep()
    {
        $n = 0;
        foreach (self::candidates() as $dir) {
            try {
                self::check($dir);
            } catch (CliError $e) {
                continue;
            }
            foreach ((array) glob("$dir/key-*") as $file) {
                $st = @lstat($file);
                if ($st === false || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== self::uid()) {
                    continue;
                }
                $c = json_decode((string) @file_get_contents($file, false, null, 0, 4096), true);
                if (!is_array($c) || !isset($c['expires']) || (int) $c['expires'] <= Secrets::now()) {
                    $n += self::wipe($file) ? 1 : 0;
                }
            }
        }
        return $n;
    }

    private static function wipe($file)
    {
        $size = (int) @filesize($file);
        if ($size > 0) {
            @file_put_contents($file, str_repeat("\0", $size));
        }
        return @unlink($file);
    }
}
