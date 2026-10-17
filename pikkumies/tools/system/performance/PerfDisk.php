<?php
/**
 * PerfDisk: `disk`, the light disk probe (README.md, ADR-0035). In one 20 s budget:
 *  - refuses before writing anything when the free quota is under twice the bytes it writes, or the hard limit is
 *    reached (Quota::fields): a full account gets an answer, not a write error,
 *  - writes 16 x 4 KiB with `dd oflag=dsync` into this run's scratch file (each write waits for the disk to commit
 *    it): the mean commit latency,
 *  - looks for one existing file of the account's own of 64 MiB or more (`find` in scan mode, 5 s, this filesystem
 *    only, so a network mount in the home is never read; never a sparse file) and reads 64 x 4 KiB at random with
 *    `fio --readonly --direct=1`: read latency p50/p95/max. A filesystem without direct reads (EINVAL) skips the
 *    reads: a cached read would measure memory,
 *  - shows the server's disk pressure at the same moment and this account's previous run.
 * Probes run in measure mode (best-effort level 7), the search in scan mode (idle). No absolute verdict, and nothing
 * about what kind of disk it is: the numbers are what this account got now, to compare with the pressure beside them
 * and with the previous run. The file's name and path never reach the records.
 */
class PerfDisk
{
    const LAST_FILE = 'disk-last.json';

    public static function disk(PerfProbe $probe, array $params)
    {
        $end = microtime(true) + PerfLimits::DISK_DEADLINE_S;
        $writeBytes = PerfLimits::DISK_WRITES * PerfLimits::DISK_WRITE_BS;
        $quota = Quota::fields($probe->home);
        $why = self::quotaRefusal($quota, $writeBytes);
        if ($why !== null) {
            throw new CliError("$why; the disk probe was not run", 'free some space first (the probe needs ' . (PerfLimits::DISK_QUOTA_FACTOR * $writeBytes) . ' bytes of room for its ' . $writeBytes . ' bytes)');
        }

        $out = ['kind' => 'disk', 'write_commit_ms_mean' => null, 'writes' => 0, 'read_p50_ms' => null, 'read_p95_ms' => null, 'read_max_ms' => null, 'reads' => 0];
        $file = $probe->scratchFile('dsync.bin');
        $r = $probe->measure(['dd', 'if=/dev/urandom', 'of=' . $file, 'bs=' . PerfLimits::DISK_WRITE_BS, 'count=' . PerfLimits::DISK_WRITES, 'iflag=fullblock', 'oflag=dsync'],
            self::left($end, PerfLimits::DD_MAX_S));
        $out['io_class'] = $r['io_class'];
        $w = $r['code'] === 0 ? self::parseDd($r['err']) : null;
        if ($w !== null) {
            $out['writes'] = PerfLimits::DISK_WRITES;
            $out['write_commit_ms_mean'] = round($w['seconds'] * 1000 / PerfLimits::DISK_WRITES, 3);
            $probe->addCost('bytes_written', $w['bytes']);
        } else {
            $probe->skipped[] = ['check' => 'disk write', 'reason' => $r['timed_out'] ? 'the synced writes did not finish in time (the disk is very busy, or stuck)' : 'dd could not run here (exit ' . $r['code'] . ')'];
        }
        @unlink($file);

        if (isset($params['read']) && $params['read'] === false) {
            $probe->skipped[] = ['check' => 'disk read', 'reason' => 'not asked (--no-read)'];
        } else {
            self::readProbe($probe, $end, $out);
        }

        $psi = $probe->psi();
        $out['server_io_pressure'] = ['level' => 'full', 'avg10' => $psi['io']['avg10'], 'avg60' => $psi['io']['avg60']];
        $out['quota_free_bytes'] = $quota['quota_free_bytes'];
        $prev = self::previous($probe);
        $out['previous'] = $prev;
        $out['note'] = 'no absolute verdict: these are what this account got now; compare them with the server\'s disk pressure beside them and with the previous run. A light probe is not the disk\'s ceiling and says nothing about what kind of disk it is';
        self::remember($probe, $out);
        return [$out];
    }

    /** Why the probe must not write, or null. Unknown quota does not refuse (no quota on this machine). */
    public static function quotaRefusal(array $q, $writeBytes)
    {
        $need = PerfLimits::DISK_QUOTA_FACTOR * $writeBytes;
        if (isset($q['quota_hard_bytes'], $q['quota_used_bytes']) && $q['quota_hard_bytes'] > 0 && $q['quota_used_bytes'] >= $q['quota_hard_bytes']) {
            return 'the account is at its hard quota limit';
        }
        if (isset($q['quota_free_bytes']) && $q['quota_free_bytes'] < $need) {
            return 'the account\'s quota is nearly full (' . $q['quota_free_bytes'] . ' bytes free)';
        }
        if (isset($q['fs_free_bytes']) && $q['fs_free_bytes'] < $need) {
            return 'the filesystem is nearly full';
        }
        return null;
    }

    /** dd's summary (LC_ALL=C, on stderr) -> [bytes, seconds] or null. */
    public static function parseDd($text)
    {
        if (!preg_match('/^(\d+) bytes .*copied, ([0-9.]+(?:e-?\d+)?) s/m', (string) $text, $m)) {
            return null;
        }
        return ['bytes' => (int) $m[1], 'seconds' => (float) $m[2]];
    }

    /**
     * fio --output-format=json -> [reads, bytes, p50_ms, p95_ms, max_ms], or ['error' => 'einval'|'other'], or null.
     * fio may print warning lines before the JSON.
     */
    public static function parseFio($text)
    {
        $text = (string) $text;
        $start = strpos($text, '{');
        $j = $start === false ? null : json_decode(substr($text, $start), true);
        if (!is_array($j) || !isset($j['jobs'][0]['read'])) {
            // no result: fio stopped at open(). EINVAL there means the filesystem has no O_DIRECT (tmpfs, some FUSE)
            return preg_match('/Invalid argument|does not support direct|err=\s*22\b/i', $text) ? ['error' => 'einval'] : null;
        }
        $job = $j['jobs'][0];
        if (!empty($job['error'])) {
            return ['error' => (int) $job['error'] === 22 ? 'einval' : 'other'];
        }
        $rd = $job['read'];
        $lat = isset($rd['clat_ns']) ? $rd['clat_ns'] : (isset($rd['lat_ns']) ? $rd['lat_ns'] : null);
        if (!is_array($lat) || !isset($rd['total_ios']) || (int) $rd['total_ios'] < 1) {
            return null;
        }
        $pct = isset($lat['percentile']) && is_array($lat['percentile']) ? $lat['percentile'] : [];
        $ms = function ($ns) {
            return $ns === null ? null : round($ns / 1e6, 3);
        };
        $p = function ($want) use ($pct) {
            foreach ($pct as $k => $v) {
                if (abs((float) $k - $want) < 0.001) {
                    return (float) $v;
                }
            }
            return null;
        };
        return ['reads' => (int) $rd['total_ios'], 'bytes' => isset($rd['io_bytes']) ? (int) $rd['io_bytes'] : 0,
            'p50_ms' => $ms($p(50.0)), 'p95_ms' => $ms($p(95.0)), 'max_ms' => $ms(isset($lat['max']) ? (float) $lat['max'] : null)];
    }

    private static function readProbe(PerfProbe $probe, $end, array &$out)
    {
        if (ToolProc::which('fio') === null) {
            $probe->skipped[] = ['check' => 'disk read', 'reason' => 'fio is not installed'];
            return;
        }
        $file = self::findFile($probe, $end);
        if ($file === null) {
            $probe->skipped[] = ['check' => 'disk read', 'reason' => 'no fully written (not sparse) file of your own of ' . (PerfLimits::DISK_READ_FILE_MIN >> 20) . ' MiB or more found within 3 levels of your home in ' . PerfLimits::DISK_FIND_S . ' s'];
            return;
        }
        $ioBytes = PerfLimits::DISK_READS * PerfLimits::DISK_READ_BS;
        $r = $probe->measure(['fio', '--name=probe', '--filename=' . str_replace(':', '\\:', $file), '--readonly', '--direct=1', '--rw=randread',
            '--bs=' . PerfLimits::DISK_READ_BS, '--io_size=' . $ioBytes, '--ioengine=psync', '--iodepth=1', '--numjobs=1', '--invalidate=0',
            '--output-format=json'], self::left($end, PerfLimits::DISK_DEADLINE_S));
        $f = self::parseFio($r['out'] . "\n" . $r['err']);
        if ($f !== null && isset($f['error']) && $f['error'] === 'einval') {
            $probe->skipped[] = ['check' => 'disk read', 'reason' => 'this filesystem does not take direct reads (O_DIRECT); a cached read would measure memory, not the disk'];
            return;
        }
        if ($r['code'] !== 0 || $f === null || isset($f['error'])) {
            $probe->skipped[] = ['check' => 'disk read', 'reason' => $r['timed_out'] ? 'the reads did not finish in time (the disk is very busy, or stuck)' : 'fio could not run here (exit ' . $r['code'] . ')'];
            return;
        }
        $out['reads'] = $f['reads'];
        $out['read_p50_ms'] = $f['p50_ms'];
        $out['read_p95_ms'] = $f['p95_ms'];
        $out['read_max_ms'] = $f['max_ms'];
        $out['read_file_mib'] = (int) (filesize($file) >> 20);
        $probe->addCost('bytes_read_direct', $f['bytes'] > 0 ? min($f['bytes'], $ioBytes) : $ioBytes);
    }

    /**
     * One regular file of this process's own uid, readable, at least DISK_READ_FILE_MIN, within 3 levels of home and on
     * home's filesystem (-xdev: a FUSE or network mount in the home is never read), and not sparse: a torrent client's
     * unfinished file is mostly holes, and reading a hole returns zeros without touching the disk. The first candidate
     * that passes stops the search. Each line is checked again on its own (owner, size, blocks), so a name holding a
     * newline only yields fragments that fail the check.
     */
    private static function findFile(PerfProbe $probe, $end)
    {
        $home = (string) $probe->home;
        if ($home === '' || $home[0] !== '/' || !is_dir($home)) {
            return null;
        }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(); // the owner of the files this process may read as its own
        $found = null;
        $probe->scan(['find', $home, '-xdev', '-maxdepth', '3', '-type', 'f', '-uid', (string) $euid, '-readable',
            '-size', '+' . (PerfLimits::DISK_READ_FILE_MIN - 1) . 'c', '-print'], self::left($end, PerfLimits::DISK_FIND_S),
            function ($path) use ($euid, &$found) {
                clearstatcache();
                $st = $path !== '' && $path[0] === '/' && !is_link($path) && is_file($path) ? @stat($path) : false;
                if ($st === false || $st['uid'] !== $euid || $st['size'] < PerfLimits::DISK_READ_FILE_MIN
                    || $st['blocks'] * 512 < PerfLimits::ALLOCATED_SHARE * $st['size']) {
                    return true; // next candidate
                }
                $found = $path;
                return false; // stop the search
            });
        return $found;
    }

    /** Seconds left in the budget, at most $cap, at least 1 (a probe always gets a deadline). */
    private static function left($end, $cap)
    {
        return max(1, min((int) $cap, (int) floor($end - microtime(true))));
    }

    /** The previous run's figures from local/performance/disk-last.json, with same_server from the fingerprint hash. */
    private static function previous(PerfProbe $probe)
    {
        $file = $probe->stateDir() . '/' . self::LAST_FILE;
        $j = is_file($file) && !is_link($file) && filesize($file) < 65536 ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($j) || !isset($j['ts'])) {
            return null;
        }
        $prev = ['ts' => (string) $j['ts'], 'same_server' => isset($j['fingerprint']) && $j['fingerprint'] === $probe->fingerprint()];
        foreach (['write_commit_ms_mean', 'read_p50_ms', 'read_p95_ms', 'read_max_ms'] as $k) {
            $prev[$k] = isset($j[$k]) && is_numeric($j[$k]) ? (float) $j[$k] : null;
        }
        return $prev;
    }

    private static function remember(PerfProbe $probe, array $out)
    {
        $file = $probe->stateDir() . '/' . self::LAST_FILE;
        if (is_link($file)) {
            return;
        }
        $keep = ['ts' => gmdate('c'), 'fingerprint' => $probe->fingerprint()];
        foreach (['write_commit_ms_mean', 'read_p50_ms', 'read_p95_ms', 'read_max_ms'] as $k) {
            $keep[$k] = $out[$k];
        }
        $old = umask(077);
        @file_put_contents($file, json_encode($keep, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        umask($old);
        @chmod($file, 0600);
    }
}
