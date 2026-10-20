<?php
/**
 * LocalTmp: Pikkumies' own temporary files in local/tmp (ADR-0099), never the shared /tmp. The folder counts against
 * the account's quota, so a runaway temporary file fills this account and not the server's root filesystem, and no other
 * account can see, squat or fill it. Every folder on the way is a real folder of this uid with no access for group or
 * others (made 0700 when missing, closed when this uid left it open, refused when it is a symlink or someone else's).
 */
class LocalTmp
{
    /** "$localDir/tmp" (and "/$sub" below it) as a private folder of $uid, or null when one on the way cannot be trusted. */
    public static function dir($localDir, $sub = '', $uid = null)
    {
        $uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $dir = rtrim($localDir, '/') . '/tmp';
        foreach (array_merge([''], $sub === '' ? [] : explode('/', trim($sub, '/'))) as $part) {
            $dir .= $part === '' ? '' : "/$part";
            if ($part === '.' || $part === '..' || !self::privateDir($dir, $uid)) {
                return null;
            }
        }
        return $dir;
    }

    /** A new empty 0600 file in "$localDir/tmp"; a CliError when the folder cannot be trusted (tempnam's /tmp fallback is refused). */
    public static function file($localDir, $prefix)
    {
        $dir = self::dir($localDir);
        $old = umask(0077);
        $f = $dir !== null ? @tempnam($dir, $prefix) : false;
        umask($old);
        if ($f === false || dirname($f) !== $dir) {
            if ($f !== false) {
                @unlink($f);
            }
            throw new CliError('cannot make a temporary file in ' . rtrim($localDir, '/') . '/tmp', 'that folder must be this account\'s own, mode 0700');
        }
        return $f;
    }

    /** $dir exists as a folder of $uid that no one else can open (made 0700 when missing); never through a symlink. */
    public static function privateDir($dir, $uid)
    {
        clearstatcache(true, $dir);
        if (@lstat($dir) === false) {
            @mkdir($dir, 0700);
            @chmod($dir, 0700);
            clearstatcache(true, $dir);
        }
        $st = @lstat($dir);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000 || (int) $st['uid'] !== (int) $uid) {
            return false;
        }
        if (($st['mode'] & 077) === 0) {
            return true;
        }
        $ok = @chmod($dir, 0700);
        clearstatcache(true, $dir); // PHP 7.3's chmod leaves the old mode in the stat cache
        $st = @lstat($dir);
        return $ok && $st !== false && ($st['mode'] & 0170000) === 0040000 && ($st['mode'] & 077) === 0;
    }
}
