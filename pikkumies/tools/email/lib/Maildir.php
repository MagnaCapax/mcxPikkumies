<?php
/**
 * Maildir: the one Maildir writer of the email units (imap archive, pop fetch; ADR-0053). A message is streamed into
 * tmp/ through the transport's byte-capped sink (never held whole in memory), then linked into new/ without replacing
 * a file that is already there, so a second run of the same message is a no-op. Directories 0700, files 0600, created
 * with fopen 'x'. roomProblem() is the unattended stop: a run nobody watches keeps HermesInstall::UNATTENDED_RESERVE
 * (10%) of the disk and file quota free, the same rule as an unattended Hermes build.
 */
class Maildir
{
    public static function ensure($dir)
    {
        foreach (['', '/cur', '/new', '/tmp'] as $sub) {
            $d = $dir . $sub;
            if (is_link($d) || (file_exists($d) && !is_dir($d))) {
                throw new CliError("$d is a symlink or a file; nothing written", 'name a plain directory');
            }
            if (!is_dir($d) && !@mkdir($d, 0700, true)) {
                throw new CliError("cannot create $d", 'check the parent directory and the quota (storage stats)');
            }
        }
        return $dir;
    }

    public static function has($dir, $name)
    {
        return file_exists("$dir/new/$name") || (bool) glob("$dir/cur/" . str_replace(['*', '?', '['], '_', $name) . '*');
    }

    /**
     * Writes one message: $fill(callable $sink) streams the bytes into tmp/$name and returns how many. Returns the
     * bytes stored, or 0 when the message was already in new/ or cur/. A failure removes the partial file.
     */
    public static function deliver($dir, $name, callable $fill)
    {
        if (!preg_match('/^[A-Za-z0-9._,=-]+\z/', $name) || self::has($dir, $name)) {
            return 0;
        }
        $tmp = "$dir/tmp/$name";
        $old = umask(0077);
        $fh = @fopen($tmp, 'x');
        umask($old);
        if ($fh === false) {
            throw new CliError("cannot create $tmp", 'check the quota (storage stats), or remove a stale file left in tmp/');
        }
        try {
            $bytes = (int) call_user_func($fill, function ($chunk) use ($fh) {
                return fwrite($fh, $chunk) === strlen($chunk);
            });
        } catch (Exception $e) {
            fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        fclose($fh);
        @chmod($tmp, 0600);
        $new = "$dir/new/$name";
        $placed = @link($tmp, $new) || (!file_exists($new) && @rename($tmp, $new));
        @unlink($tmp);
        if (!$placed && !file_exists($new)) {
            throw new CliError("cannot move $name into $dir/new", 'check the folder permissions');
        }
        return $placed ? $bytes : 0;
    }

    /**
     * Why one more message of $next bytes would leave less than $reserve of the quota free, or ''. $q: Quota::fields();
     * $files: Quota::files(); $bytes, $count: what this run already wrote; $fsTotal: the filesystem size, used only
     * when no quota is known.
     */
    public static function roomProblem(array $q, $files, $bytes, $count, $next, $reserve, $fsTotal = null)
    {
        if ($q['quota_free_bytes'] !== null && $q['quota_limit_bytes'] > 0) {
            if ($q['quota_free_bytes'] - $bytes - $next < $q['quota_limit_bytes'] * $reserve) {
                return sprintf('the disk quota would fall under %d%% free', $reserve * 100);
            }
        } elseif ($q['fs_free_bytes'] !== null && $fsTotal > 0 && $q['fs_free_bytes'] - $bytes - $next < $fsTotal * $reserve) {
            return sprintf('the disk would fall under %d%% free', $reserve * 100);
        }
        if (is_array($files) && $files['soft'] > 0 && $files['used'] + $count + 1 > $files['soft'] * (1 - $reserve)) {
            return sprintf('the file quota would fall under %d%% free (one file per message)', $reserve * 100);
        }
        return '';
    }
}
