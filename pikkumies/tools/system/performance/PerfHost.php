<?php
/**
 * PerfHost: how busy the server is, from files every account can read (ADR-0035). Two samples CHECK_WINDOW_S apart:
 *  - /proc/stat: CPU busy and steal shares over the window, processes running and blocked now;
 *  - /proc/pressure (one parser, PerfProbe::psi): cpu some, io full, memory full, avg10 and avg60;
 *  - /proc/diskstats: wait per I/O (await) and busy share (util) of the device behind HOME, found in
 *    /proc/self/mountinfo; an md array keeps no time fields, so its members (from /proc/mdstat) are read instead and
 *    the busiest one is shown; no device found = unknown;
 *  - /proc/meminfo MemAvailable, /proc/vmstat swap in/out over the window;
 *  - /proc/mdstat: only whether an array check or resync is running (a keyword, no verdict on the array);
 *  - the default-route interface (/proc/net/route): its speed (/sys/class/net/<if>/speed) and its error and drop
 *    counts over the window (/proc/net/dev).
 * Load average is not read: it counts processes waiting for the disk too, so it is no measure of CPU use.
 * A value that cannot be read is null (unknown), never 0.
 */
class PerfHost
{
    /** Seconds between the two samples; a test sets it to 0 (the counters are then whatever the fixture holds). */
    public static $windowS = PerfLimits::CHECK_WINDOW_S;

    public static function wait()
    {
        if (self::$windowS > 0) {
            usleep((int) (self::$windowS * 1000000));
        }
    }

    /** One set of counters. */
    public static function sample(PerfProbe $probe)
    {
        $fs = $probe->fs;
        $stat = $fs->read('/proc/stat');
        $cpu = null;
        $running = null;
        $blocked = null;
        if ($stat !== null) {
            if (preg_match('/^cpu\s+([0-9 ]+)$/m', $stat, $m)) {
                $cpu = array_map('floatval', preg_split('/\s+/', trim($m[1])));
            }
            $running = preg_match('/^procs_running\s+(\d+)/m', $stat, $m) ? (int) $m[1] : null;
            $blocked = preg_match('/^procs_blocked\s+(\d+)/m', $stat, $m) ? (int) $m[1] : null;
        }
        $vm = PerfAccount::kv($fs->read('/proc/vmstat'));
        $iface = self::defaultIface($fs);
        return ['t' => microtime(true), 'cpu' => $cpu, 'running' => $running, 'blocked' => $blocked,
            'disk' => self::diskstats($fs->read('/proc/diskstats')), 'swap' => $vm === null ? null : [isset($vm['pswpin']) ? $vm['pswpin'] : null, isset($vm['pswpout']) ? $vm['pswpout'] : null],
            'iface' => $iface, 'net' => $iface === null ? null : self::netdev($fs->read('/proc/net/dev'), $iface)];
    }

    /**
     * Two samples -> the host record's figures, plus the PSI read now. 'device' says how the disk figures were found:
     * the device itself, the busiest md member, or null (unknown).
     */
    public static function summary(PerfProbe $probe, array $a, array $b)
    {
        $fs = $probe->fs;
        $dt = max(0.001, $b['t'] - $a['t']);
        $busy = null;
        $steal = null;
        if ($a['cpu'] !== null && $b['cpu'] !== null && count($a['cpu']) >= 4 && count($b['cpu']) === count($a['cpu'])) {
            $d = [];
            foreach ($b['cpu'] as $i => $v) {
                $d[$i] = max(0, $v - $a['cpu'][$i]);
            }
            $guest = array_slice($d, 8); // guest time is already counted in user/nice
            $total = array_sum($d) - array_sum($guest);
            if ($total > 0) {
                $idle = $d[3] + (isset($d[4]) ? $d[4] : 0);
                $busy = round(100 * ($total - $idle) / $total, 1);
                $steal = isset($d[7]) ? round(100 * $d[7] / $total, 1) : null;
            }
        }
        list($await, $util, $how) = self::diskFigures($fs, $probe->home, $a['disk'], $b['disk'], $dt);
        $mem = PerfAccount::kv(preg_replace('/ kB$/m', '', (string) $fs->read('/proc/meminfo')));
        $memAvail = isset($mem['MemAvailable:']) ? $mem['MemAvailable:'] * 1024 : null;
        $swapIn = $swapOut = null;
        if ($a['swap'] !== null && $b['swap'] !== null) {
            $swapIn = $a['swap'][0] === null || $b['swap'][0] === null ? null : round(max(0, $b['swap'][0] - $a['swap'][0]) / $dt, 1);
            $swapOut = $a['swap'][1] === null || $b['swap'][1] === null ? null : round(max(0, $b['swap'][1] - $a['swap'][1]) / $dt, 1);
        }
        $speed = null;
        if ($b['iface'] !== null) {
            $raw = PerfAccount::num($fs->read('/sys/class/net/' . $b['iface'] . '/speed'));
            $speed = $raw !== null && $raw > 0 ? (int) $raw : null; // -1 = the driver does not say
        }
        $errs = null;
        if ($a['net'] !== null && $b['net'] !== null) {
            $errs = [];
            foreach ($b['net'] as $k => $v) {
                $errs[$k] = max(0, $v - $a['net'][$k]);
            }
        }
        return ['pressure' => $probe->psi(), 'cpu_busy_pct' => $busy, 'cpu_steal_pct' => $steal, 'procs_running' => $b['running'], 'procs_blocked' => $b['blocked'],
            'disk_await_ms' => $await, 'disk_util_pct' => $util, 'disk_device' => $how, 'mem_available_bytes' => $memAvail,
            'swap_in_pages_s' => $swapIn, 'swap_out_pages_s' => $swapOut, 'array_maintenance' => self::maintenance($fs->read('/proc/mdstat')),
            'link_speed_mbit' => $speed, 'link_errors_during_check' => $errs];
    }

    /** "check" or "resync" when /proc/mdstat shows one running, else null (unknown when the file is missing is also null). */
    public static function maintenance($mdstat)
    {
        return $mdstat !== null && preg_match('/\b(check|resync)\s*=/', $mdstat, $m) ? $m[1] : null;
    }

    /** /proc/diskstats -> [name => [major, minor, ios, ticks, io_ticks]]. */
    public static function diskstats($text)
    {
        if ($text === null) {
            return null;
        }
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) >= 14 && ctype_digit($f[0]) && ctype_digit($f[1])) {
                $out[$f[2]] = ['major' => (int) $f[0], 'minor' => (int) $f[1], 'ios' => (float) $f[3] + (float) $f[7],
                    'ticks' => (float) $f[6] + (float) $f[10], 'io_ticks' => (float) $f[12]];
            }
        }
        return $out;
    }

    /** [await ms, util %, how found] of the device behind $home; [null, null, null] when unknown. */
    private static function diskFigures(PerfFs $fs, $home, $a, $b, $dt)
    {
        if ($a === null || $b === null) {
            return [null, null, null];
        }
        $devno = self::homeDevice($fs->read('/proc/self/mountinfo'), (string) $home);
        $name = null;
        foreach ($b as $n => $d) {
            if ($devno !== null && $d['major'] . ':' . $d['minor'] === $devno) {
                $name = $n;
            }
        }
        if ($name === null || !isset($a[$name])) {
            return [null, null, null];
        }
        $fig = function ($n) use ($a, $b, $dt) {
            if (!isset($a[$n], $b[$n])) {
                return null;
            }
            $ios = $b[$n]['ios'] - $a[$n]['ios'];
            $ticks = $b[$n]['ticks'] - $a[$n]['ticks'];
            $busy = $b[$n]['io_ticks'] - $a[$n]['io_ticks'];
            return [$ios > 0 ? round($ticks / $ios, 1) : null, round(min(100, max(0, 100 * $busy / ($dt * 1000))), 1), $b[$n]['ticks'] + $b[$n]['io_ticks']];
        };
        if (strpos($name, 'md') !== 0 || $b[$name]['ticks'] + $b[$name]['io_ticks'] > 0) {
            $f = $fig($name);
            return [$f[0], $f[1], 'device'];
        }
        $best = null;
        foreach (self::mdMembers($fs->read('/proc/mdstat'), $name) as $member) {
            $n = isset($b[$member]) ? $member : preg_replace('/(?<=\d)p\d+$|(?<=[a-z])\d+$/', '', $member);
            $f = isset($b[$n]) ? $fig($n) : null;
            if ($f !== null && $f[2] > 0 && ($best === null || $f[1] > $best[1])) {
                $best = $f;
            }
        }
        return $best === null ? [null, null, null] : [$best[0], $best[1], 'busiest array member'];
    }

    /** maj:min of the mount that holds $home (the longest mount point that is a prefix of it), or null. */
    public static function homeDevice($mountinfo, $home)
    {
        if ($mountinfo === null || $home === '') {
            return null;
        }
        $best = null;
        $bestLen = -1;
        foreach (explode("\n", $mountinfo) as $line) {
            $f = explode(' ', trim($line));
            if (count($f) < 5 || !preg_match('/^\d+:\d+$/', $f[2])) {
                continue;
            }
            $mp = str_replace('\\040', ' ', $f[4]);
            $len = strlen(rtrim($mp, '/'));
            if (($mp === '/' || $home === $mp || strpos($home, rtrim($mp, '/') . '/') === 0) && $len > $bestLen) {
                $best = $f[2];
                $bestLen = $len;
            }
        }
        return $best;
    }

    /** Member device names of array $md in /proc/mdstat ("md0 : active raid5 sda1[0] sdb1[1]" -> sda1, sdb1). */
    public static function mdMembers($mdstat, $md)
    {
        if ($mdstat === null || !preg_match('/^' . preg_quote($md, '/') . '\s*:\s*\S+\s+(.*)$/m', $mdstat, $m)) {
            return [];
        }
        preg_match_all('/\b([A-Za-z0-9_-]+)\[\d+\]/', $m[1], $x);
        return $x[1];
    }

    /** Interface of the default route in /proc/net/route, or null. */
    private static function defaultIface(PerfFs $fs)
    {
        foreach (explode("\n", (string) $fs->read('/proc/net/route')) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) >= 8 && $f[1] === '00000000' && $f[7] === '00000000' && preg_match('/^[A-Za-z0-9_.:-]{1,15}$/', $f[0])) {
                return $f[0];
            }
        }
        return null;
    }

    /** /proc/net/dev counters of $iface: rx_errs, rx_drop, tx_errs, tx_drop; null when absent. */
    private static function netdev($text, $iface)
    {
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^\s*' . preg_quote($iface, '/') . ':\s*(.*)$/', $line, $m)) {
                $f = preg_split('/\s+/', trim($m[1]));
                if (count($f) >= 12) {
                    return ['rx_errs' => (float) $f[2], 'rx_drop' => (float) $f[3], 'tx_errs' => (float) $f[10], 'tx_drop' => (float) $f[11]];
                }
            }
        }
        return null;
    }
}
