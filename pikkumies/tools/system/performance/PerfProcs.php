<?php
/**
 * PerfProcs: what this account's own processes use (ADR-0035, privacy rule P3). A process is the account's when the
 * effective uid in its /proc/<pid>/status is this account's, whatever /proc shows of other processes (hidepid or
 * not). A process directory the kernel shows as owned by another user is skipped before anything in it is read; a
 * status whose uid is not this account's is dropped at once. The /proc directory itself is the only listing.
 * Processes are grouped by app (rtorrent, lighttpd, docker, arr, media, rclone, other) from the process name; no
 * name, command line or pid reaches the output. Per group: count, resident and swapped memory; the proportional
 * share (PSS) of the PSS_TOP largest processes; processes waiting on the disk (state D).
 * Transfer settings that decide how many streams a client opens are read from the command line of own rclone and
 * lftp processes, and only the whitelisted flags (--transfers, --multi-thread-streams, pget -n) are kept.
 */
class PerfProcs
{
    const STATUS_BYTES = 4096; // the fields used are near the top of status
    const GROUPS = [
        'rtorrent' => '/^rtorrent/i',
        'lighttpd' => '/^(lighttpd|php-cgi|php-fpm)/i',
        'docker' => '/^(dockerd|containerd|containerd-shim|rootlesskit|slirp4netns|docker-proxy|runc)/i',
        'arr' => '/^(sonarr|radarr|lidarr|readarr|prowlarr|bazarr|whisparr|jackett|autobrr)/i',
        'media' => '/^(plex|jellyfin|emby)/i',
        'rclone' => '/^rclone/i',
        'torrent' => '/^(qbittorrent|deluge)/i',
    ];

    /**
     * ['groups' => [group => [processes, rss_bytes, swap_bytes, pss_bytes|null, waiting_on_disk]], 'processes', 'waiting_on_disk',
     *  'stream_hints' => [[app, flag, value]]]; null when /proc cannot be listed.
     */
    public static function walk(PerfProbe $probe)
    {
        $fs = $probe->fs;
        $procDir = $fs->path('/proc');
        $names = $procDir === null ? false : @scandir($procDir);
        if ($names === false) {
            return null;
        }
        // The kernel shows a process directory as owned by the process's user: one owned by someone else is skipped
        // unread. The owner of /proc/self is this process's own user (it is the seam's tree in tests).
        $selfOwner = @fileowner($procDir . '/self');
        $own = [];
        foreach ($names as $pid) {
            if (!ctype_digit((string) $pid) || ($selfOwner !== false && @fileowner($procDir . '/' . $pid) !== $selfOwner)) {
                continue;
            }
            $st = self::status($fs->read("/proc/$pid/status", self::STATUS_BYTES));
            if ($st === null || $st['uid'] !== (int) $probe->uid) {
                continue;
            }
            $st['pid'] = (int) $pid;
            $st['group'] = self::group($st['name']);
            $own[] = $st;
        }
        usort($own, function ($x, $y) {
            return $y['rss'] - $x['rss'];
        });
        $groups = [];
        $waiting = 0;
        $hints = [];
        foreach ($own as $i => $p) {
            $g = $p['group'];
            if (!isset($groups[$g])) {
                $groups[$g] = ['processes' => 0, 'rss_bytes' => 0, 'swap_bytes' => 0, 'pss_bytes' => null, 'waiting_on_disk' => 0];
            }
            $groups[$g]['processes']++;
            $groups[$g]['rss_bytes'] += $p['rss'];
            $groups[$g]['swap_bytes'] += $p['swap'];
            if ($p['state'] === 'D') {
                $groups[$g]['waiting_on_disk']++;
                $waiting++;
            }
            if ($i < PerfLimits::PSS_TOP) {
                $pss = self::pss($fs->read('/proc/' . $p['pid'] . '/smaps_rollup'));
                if ($pss !== null) {
                    $groups[$g]['pss_bytes'] = (int) $groups[$g]['pss_bytes'] + $pss;
                }
            }
            if (preg_match('/^(rclone|lftp)$/i', $p['name'])) {
                foreach (self::streamFlags($fs->read('/proc/' . $p['pid'] . '/cmdline', 65536)) as $h) {
                    $hints[] = ['app' => strtolower($p['name'])] + $h;
                }
            }
        }
        uasort($groups, function ($x, $y) {
            return $y['rss_bytes'] - $x['rss_bytes'];
        });
        return ['processes' => count($own), 'waiting_on_disk' => $waiting, 'groups' => $groups, 'stream_hints' => array_slice($hints, 0, 10)];
    }

    /** status text -> [name, state, uid (effective), rss bytes, swap bytes]; null when the Uid line is missing. */
    public static function status($text)
    {
        if ($text === null || !preg_match('/^Uid:\s+\d+\s+(\d+)/m', $text, $u)) {
            return null;
        }
        $kb = function ($field) use ($text) {
            return preg_match('/^' . $field . ':\s+(\d+)\s+kB/m', $text, $m) ? 1024 * (int) $m[1] : 0;
        };
        return ['name' => preg_match('/^Name:\s*(.*)$/m', $text, $m) ? trim($m[1]) : '', 'state' => preg_match('/^State:\s+(\S)/m', $text, $m) ? $m[1] : '',
            'uid' => (int) $u[1], 'rss' => $kb('VmRSS'), 'swap' => $kb('VmSwap')];
    }

    public static function group($name)
    {
        foreach (self::GROUPS as $g => $re) {
            if (preg_match($re, $name)) {
                return $g;
            }
        }
        return 'other';
    }

    private static function pss($text)
    {
        return $text !== null && preg_match('/^Pss:\s+(\d+)\s+kB/m', $text, $m) ? 1024 * (int) $m[1] : null;
    }

    /** Only the whitelisted stream flags of a NUL-separated command line, as [flag, value]; everything else is dropped. */
    public static function streamFlags($cmdline)
    {
        $args = explode("\0", rtrim((string) $cmdline, "\0"));
        $out = [];
        foreach ($args as $i => $a) {
            foreach (['--transfers', '--multi-thread-streams'] as $flag) {
                if (preg_match('/^' . preg_quote($flag, '/') . '=(\d{1,4})$/', $a, $m)) {
                    $out[] = ['flag' => $flag, 'value' => (int) $m[1]];
                } elseif ($a === $flag && isset($args[$i + 1]) && preg_match('/^\d{1,4}$/', $args[$i + 1])) {
                    $out[] = ['flag' => $flag, 'value' => (int) $args[$i + 1]];
                }
            }
            if (preg_match('/(?:^|[\s;])pget\s+(?:-\S+\s+)*-n\s*(\d{1,4})\b/', $a, $m)) {
                $out[] = ['flag' => 'pget -n', 'value' => (int) $m[1]];
            }
        }
        return $out;
    }
}
