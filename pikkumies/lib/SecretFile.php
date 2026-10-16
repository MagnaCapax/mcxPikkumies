<?php
/**
 * SecretFile: one tool secret in local/secrets/ (directory 0700, file 0600, ADR-0019), read and written the same
 * way by AutobrrSecret and QbitSecret. A read trusts only a regular file (never a symlink) owned by this account;
 * any other owner refuses with a hint to run login again, which replaces the file. A file this account owns that
 * others could read (mode & 077) is set back to 0600 with a note on stderr, then used: login writes 0600, so a
 * looser mode is drift (a copy, a restore, another umask) and the secret is the user's own; refusing it would only
 * make the user type it again, and a chmod that fails refuses. MediaSecret and BackupStore keep their own copy.
 */
class SecretFile
{
    /** The contents (at most 64 KiB), or false when nothing is stored. $login: the hint; $uid: a test seam. */
    public static function read($file, $login, $uid = null)
    {
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st === false) {
            return false;
        }
        $type = $st['mode'] & 0170000;
        if ($type !== 0100000) {
            throw new CliError("$file is " . ($type === 0120000 ? 'a symlink' : 'not a regular file') . '; not reading a secret from it',
                "$login (that replaces it)");
        }
        $uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        if ((int) $st['uid'] !== $uid) {
            throw new CliError("$file belongs to uid {$st['uid']}, not to this account (uid $uid); not trusting it",
                "$login (that replaces it)");
        }
        if ($st['mode'] & 077) {
            if (!@chmod($file, 0600)) {
                throw new CliError(sprintf('%s is mode %04o (others can read it) and could not be set to 0600', $file, $st['mode'] & 0777),
                    "$login (that writes it 0600)");
            }
            fwrite(STDERR, sprintf("note: %s was mode %04o (others could read it); set to 0600\n", $file, $st['mode'] & 0777));
        }
        return @file_get_contents($file, false, null, 0, 65536);
    }

    /** Stores $fields as JSON, atomically: a temporary file written 0600 in the 0700 directory, then renamed. */
    public static function write($file, array $fields, $what)
    {
        $dir = dirname($file);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        $json = json_encode($fields + ['saved_at' => time()]);
        if ($json === false) {
            throw new CliError("cannot store the $what: the data could not be encoded as JSON (not valid UTF-8?)", 're-check the value just entered and try again');
        }
        $tmp = $file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $json) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new CliError("cannot store the $what", "check that $dir is writable");
        }
    }
}
