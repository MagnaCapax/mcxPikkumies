<?php
/**
 * Quota: how much space this user has left. On shared servers the free space of the filesystem is
 * the whole array (tens of TB on a 1 TB plan), so a tool that reports it tells a full user the disk
 * is fine. The user's quota is the answer; the filesystem figure stays as a second fact, because a
 * full filesystem (ENOSPC) looks the same to the user as a full quota (EDQUOT).
 *
 * Sources, first that works: the live `quota -s` the user may run for themselves, then PMSS's
 * ~/.quota (root cron writes `quota -u <user> -v -s` there every minute; scripts/cron/updateQuotas.php,
 * etc/seedbox/config/root.cron). Parsing follows the PMSS panel (etc/skel/www/index.php:34-108):
 * the first /dev line, or the line after a /dev line that wrapped. Free = soft limit - used, as the
 * panel shows it; the hard limit (PMSS: soft x 1.25) is where writes stop.
 */
class Quota
{
    /** @var callable|null test seam: fn() => raw `quota -s` output or null */
    public static $live = null;

    /**
     * Fields for a stats record: quota_used_bytes, quota_limit_bytes, quota_hard_bytes,
     * quota_free_bytes, over_quota, quota_source (null when no quota was found), fs_free_bytes.
     */
    public static function fields($home)
    {
        $q = self::read($home);
        $fs = ($home !== '' && is_dir($home)) ? @disk_free_space($home) : false;
        return [
            'quota_used_bytes'  => $q ? $q['used'] : null,
            'quota_limit_bytes' => $q ? $q['soft'] : null,
            'quota_hard_bytes'  => $q ? $q['hard'] : null,
            'quota_free_bytes'  => $q ? max(0, $q['soft'] - $q['used']) : null,
            'over_quota'        => $q ? ($q['soft'] > 0 && $q['used'] >= $q['soft']) : null,
            'quota_source'      => $q ? $q['source'] : null,
            'fs_free_bytes'     => $fs === false ? null : (int) $fs, // the whole filesystem: on shared servers far more than the user may use
        ];
    }

    /** ['used','soft','hard' (bytes),'source'] or null. A limit of 0 means no quota is set. */
    public static function read($home)
    {
        $raw = self::$live !== null ? call_user_func(self::$live) : self::runQuota();
        if (is_string($raw) && ($q = self::parse($raw)) !== null) {
            return $q + ['source' => 'quota -s'];
        }
        $file = $home !== '' ? "$home/.quota" : '';
        if ($file !== '' && is_file($file) && !is_link($file) && filesize($file) < 65536) {
            $q = self::parse((string) @file_get_contents($file));
            if ($q !== null) {
                return $q + ['source' => '~/.quota'];
            }
        }
        return null;
    }

    /** Output of `quota -s` / `quota -v -s` -> ['used','soft','hard'] or null (none, placeholder, unparseable). */
    public static function parse($raw)
    {
        if (preg_match('/\(uid 0\)/', $raw)) {
            return null; // PMSS writes "/dev/null 0K 0K 0K" for uid 0 when quota failed (updateQuotas.php:116-124)
        }
        $wrapped = false;
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $m = [];
            if (preg_match('#^(/dev/\S+)\s+(\S+)\s+(\S+)\s+(\S+)#', $line, $m) === 1) {
                return $m[1] === '/dev/null' ? null : self::build($m[2], $m[3], $m[4]);
            }
            if ($wrapped && preg_match('/^(\S+)\s+(\S+)\s+(\S+)/', $line, $m) === 1) {
                return self::build($m[1], $m[2], $m[3]);
            }
            $wrapped = preg_match('#^/dev/\S+$#', $line) === 1 && $line !== '/dev/null';
        }
        return null;
    }

    /** "1024G", "977M", "1100G*" (over), "4K", a plain number (KiB blocks) -> bytes, or null. */
    public static function bytes($v)
    {
        $m = [];
        if (preg_match('/^(\d+(?:\.\d+)?)([KMGTPE]?)(?:i?B)?\*?$/i', trim($v), $m) !== 1) {
            return null;
        }
        $pow = ['' => 1, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6]; // no suffix = 1 KiB blocks
        return (int) round((float) $m[1] * pow(1024, $pow[strtoupper($m[2])]));
    }

    private static function build($used, $soft, $hard)
    {
        $u = self::bytes($used);
        $s = self::bytes($soft);
        $h = self::bytes($hard);
        if ($u === null || $s === null || $h === null || ($s === 0 && $h === 0)) {
            return null; // unparseable, or no quota set for this user
        }
        return ['used' => $u, 'soft' => $s > 0 ? $s : $h, 'hard' => $h];
    }

    private static function runQuota()
    {
        if (!is_executable('/usr/bin/quota') && !is_executable('/bin/quota') && !is_executable('/usr/sbin/quota')) {
            return null;
        }
        $out = [];
        exec('timeout 5 quota -s 2>/dev/null', $out); // the caller's own quota; can be slow on a busy disk
        return $out ? implode("\n", $out) : null; // quota exits non-zero when over quota, so the code says nothing
    }
}
