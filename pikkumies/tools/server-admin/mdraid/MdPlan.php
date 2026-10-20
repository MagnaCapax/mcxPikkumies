<?php
/**
 * MdPlan: profiles, geometry and the kernel line (ADR-0095). Data, with where each value comes from:
 * - stripe_cache_size: raid4/5/6 only, writable 17..32768, default 256 (Linux Documentation/admin-guide/md.rst;
 *   raid5.c raid5_set_cache_size). It is a floor the kernel pre-allocates, one page per member per stripe
 *   (raid5.c grow_buffers, pool_size = devices), so it pins value x members x 4 KiB, not a cap.
 * - chunk: mdadm's default is 512K (mdadm(8) --chunk); 2048K is Pulsed Media's big-file fleet standard.
 * - mke2fs: -E stride (blocks per chunk) and stripe_width (stride x data disks), -m reserved percent (a float),
 *   -T news (inode_ratio 4096 in mke2fs.conf) (e2fsprogs mke2fs.8, mke2fs.c, mke2fs.conf).
 * Values without a measurement behind them are labelled defaults, never optima.
 */
class MdPlan
{
    const BLOCK = 4096;
    const PAGE = 4096;
    const PINNED_SHARE = 0.05; // big-file: 32768 while every parity array's pinned stripe cache stays under 5% of RAM

    const PROFILES = [
        'big-file' => ['for' => 'media, backups, large sequential files', 'levels' => ['raid5', 'raid10'], 'chunk_kib' => 2048,
            'stripe_cache' => 32768, 'stripe_cache_low' => 4096, 'read_ahead_kb' => 4096, 'mkfs' => ['-m', '0.1'], 'mount' => 'noatime,commit=60',
            'label' => 'Pulsed Media\'s fleet standard for big-file RAID5 (chunk 2048K, stripe cache 32768, read-ahead 4096); the 5% memory rule is a default'],
        'typical' => ['for' => 'mixed use, "just make it work"', 'levels' => ['raid1', 'raid10', 'raid5'], 'chunk_kib' => 512,
            'stripe_cache' => 4096, 'stripe_cache_low' => 4096, 'read_ahead_kb' => null, 'mkfs' => [], 'mount' => 'noatime',
            'label' => 'default, not measured (chunk is mdadm\'s own default)'],
        'small-file' => ['for' => 'many small files, databases, VMs, random writes', 'levels' => ['raid10', 'raid1'], 'chunk_kib' => 512,
            'stripe_cache' => 4096, 'stripe_cache_low' => 4096, 'read_ahead_kb' => null, 'mkfs' => ['-T', 'news'], 'mount' => 'noatime',
            'label' => 'default, not measured; parity RAID is advised against for random writes (common mistakes, zfs-or-double-parity)'],
    ];
    const MIN_DISKS = ['raid0' => 2, 'raid1' => 2, 'raid5' => 3, 'raid10' => 4];
    const SIZE_LOSS = 0.01; // more than 1% of the raw capacity lost to the smallest member needs --accept-size-loss

    public static function profile($name)
    {
        if (!isset(self::PROFILES[$name])) {
            throw new CliError("unknown profile '$name'; nothing was done", 'profiles: ' . implode(', ', array_keys(self::PROFILES)));
        }
        return self::PROFILES[$name];
    }

    /** Normalised level (raid0|raid1|raid5|raid10), or a CliError: RAID6 and the rest are not offered. */
    public static function level($level)
    {
        $l = 'raid' . preg_replace('/^raid/i', '', (string) $level);
        if (!isset(self::MIN_DISKS[$l])) {
            throw new CliError("level '$level' is not offered; nothing was done", 'levels: 0, 1, 5, 10 (RAID6 is left out for its write cost; see the common mistakes list)');
        }
        return $l;
    }

    public static function dataDisks($level, $n)
    {
        $map = ['raid0' => $n, 'raid1' => 1, 'raid5' => $n - 1, 'raid10' => intdiv($n, 2)];
        return $map[$level];
    }

    /** Geometry and the mkfs.ext4 options for a level, member count and profile. */
    public static function geometry($level, $n, array $profile)
    {
        $chunk = $level === 'raid1' ? null : $profile['chunk_kib'];
        $data = self::dataDisks($level, $n);
        $stride = $chunk === null ? null : intdiv($chunk * 1024, self::BLOCK);
        $mkfs = $profile['mkfs'];
        if ($stride !== null) {
            $mkfs = array_merge($mkfs, ['-E', "stride=$stride,stripe_width=" . ($stride * $data)]);
        }
        return ['level' => $level, 'members' => $n, 'data_disks' => $data, 'chunk_kib' => $chunk, 'stride' => $stride,
            'stripe_width' => $stride === null ? null : $stride * $data, 'bitmap' => $level !== 'raid0', 'mkfs_options' => $mkfs];
    }

    /** Pinned stripe-cache memory in bytes. */
    public static function pinned($stripes, $members)
    {
        return (int) $stripes * (int) $members * self::PAGE;
    }

    /** The stripe cache a profile gives a parity array, after the memory rule; [value, why]. $othersPinned: bytes other arrays pin. */
    public static function stripeCache(array $profile, $members, $memTotal, $othersPinned)
    {
        $want = $profile['stripe_cache'];
        if ($want > $profile['stripe_cache_low'] && $memTotal <= 0) {
            return [$profile['stripe_cache_low'], 'RAM size could not be read, so the low value'];
        }
        if ($want > $profile['stripe_cache_low'] && $memTotal > 0 && $othersPinned + self::pinned($want, $members) >= $memTotal * self::PINNED_SHARE) {
            return [$profile['stripe_cache_low'], sprintf('%d would pin %.0f MiB with the other arrays, over 5%% of RAM', $want, ($othersPinned + self::pinned($want, $members)) / 1048576)];
        }
        return [$want, 'profile value'];
    }

    /** Capacity check: [usable bytes per member, bytes lost, share lost]. */
    public static function sizes(array $bytes)
    {
        $min = min($bytes);
        $lost = array_sum($bytes) - $min * count($bytes);
        return [$min, $lost, array_sum($bytes) > 0 ? (float) $lost / array_sum($bytes) : 0.0];
    }

    /** MemTotal in bytes from /proc/meminfo. */
    public static function memTotal()
    {
        return preg_match('/^MemTotal:\s+([0-9]+)\s*kB/m', (string) MdRun::read('/proc/meminfo'), $m) ? (int) $m[1] * 1024 : 0;
    }

    /**
     * Linux 6.12.13+ (and backports) moved md bitmap accounting to raid5_bitmap_sector(), which rounded with
     * round_down()/round_up(): those need a power of two, so with a non-power-of-two data-disk count the marked range
     * misses the write and a re-add or crash resync can skip it. Fixed by "md/raid5: round bitmap stripes with sector
     * division" (upstream 17ea021ae749). Ranges checked against kernel.org stable tags on 2026-10-20:
     * affected 6.6.79-6.6.156, 6.12.13-6.12.109, 6.13.2+, 6.14-6.17, 6.18.0-6.18.51, 6.19, 7.0, 7.1, 7.2.0-7.2.5;
     * fixed from 6.6.157, 6.12.110, 6.18.52, 7.2.6 and 7.3; earlier kernels never had the change.
     */
    const KERNEL_AFFECTED = [['6.6', 79, 156], ['6.12', 13, 109], ['6.13', 2, null], ['6.14', 0, null], ['6.15', 0, null], ['6.16', 0, null],
        ['6.17', 0, null], ['6.18', 0, 51], ['6.19', 0, null], ['7.0', 0, null], ['7.1', 0, null], ['7.2', 0, 5]];

    /** 'affected', 'fixed' or 'unknown' for a kernel; $procVersion may carry the distribution's upstream number. */
    public static function kernelBitmap($release, $procVersion = '')
    {
        $v = null;
        // /proc/version on Debian: "Debian 6.12.95-1~bpo12+1"; /proc/version_signature on Ubuntu: "Ubuntu 6.8.0-45.45-generic 6.8.12".
        if (preg_match('/\bDebian ([0-9]+\.[0-9]+\.[0-9]+)/', (string) $procVersion, $m)
            || preg_match('/\bUbuntu [0-9]\S* ([0-9]+\.[0-9]+\.[0-9]+)/', (string) $procVersion, $m)) {
            $v = $m[1];
        }
        $abiOnly = $v === null && preg_match('/^[0-9]+\.[0-9]+\.0-[0-9]/', (string) $release);
        if ($v === null && preg_match('/^([0-9]+\.[0-9]+)\.([0-9]+)/', (string) $release, $m)) {
            $v = $m[1] . '.' . $m[2];
        }
        if ($v === null || $abiOnly || !preg_match('/^([0-9]+\.[0-9]+)\.([0-9]+)/', $v, $m)) {
            return 'unknown'; // "5.14.0-503.el9", "6.12.0-1": an ABI name hides the point release, and a distribution may have backported the change
        }
        foreach (self::KERNEL_AFFECTED as $r) {
            if ($r[0] === $m[1]) {
                return (int) $m[2] >= $r[1] && ($r[2] === null || (int) $m[2] <= $r[2]) ? 'affected' : 'fixed';
            }
        }
        return 'fixed';
    }

    /** True when a re-add or crash resync on this geometry depends on that kernel fix: parity, a bitmap, a non-power-of-two data-disk count. */
    public static function bitmapRisk($level, $devices, $bitmap)
    {
        $data = in_array($level, ['raid4', 'raid5'], true) ? $devices - 1 : ($level === 'raid6' ? $devices - 2 : 0);
        return $bitmap && $data > 0 && ($data & ($data - 1)) !== 0;
    }
}
