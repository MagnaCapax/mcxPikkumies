<?php
/**
 * PerfAccount: the account's own limits and use (ADR-0035, README.md "How it finds things", privacy rule P1).
 *  - The account's own cgroup directory is the `user-<uid>.slice` segment of this process's own path in
 *    /proc/self/cgroup, for each controller. Usage and stat files are read only there; limit files may be read at
 *    its ancestors (the tightest limit binds). No glob, no listing: every path is built from /proc/self/cgroup and
 *    read through PerfFs, which refuses any other account's slice anyway.
 *  - No such segment: on a shared server the account part is `unavailable`; on a server of its own the leaf of the
 *    path is read, labelled `scope: machine` when that leaf is the root.
 *  - On PMSS, `~/bin/userShowLimits --json` (through Detect::userShowLimitsData, which drops the account and
 *    server names) gives the platform's own view; its metrics are shown as they are, uninterpreted.
 * sample() reads one set of counters; summary() turns two samples (CHECK_WINDOW_S apart) into rates and shares.
 * A value that cannot be read is null (unknown), never 0.
 */
class PerfAccount
{
    const CONTROLLERS = ['memory', 'cpu', 'cpuacct', 'blkio'];
    const V1_UNLIMITED_FLOOR = 4611686018427387904; // v1 writes a huge number for "no limit" (Cgroup::V1_UNLIMITED_FLOOR)
    const USL_METRICS_MAX = 40;                     // metrics of userShowLimits shown (keeps the record well under the budget)
    const USL_SKIP_SECTIONS = '/^(account|server\b)/i'; // the account section names the account; the server one is the host record's

    /**
     * Where this account's cgroup files are: ['format' => v1|v2|hybrid|null, 'scope' => account|cgroup|machine|null,
     * 'dirs' => controller => [own dir, [ancestor dirs, root first]], 'v2' => [own dir, ancestors] or null,
     * 'reason' => why unavailable, or null]. Paths are logical (/sys/fs/cgroup/...), read through PerfFs.
     * $pmss: userShowLimits answered (PMSS is always shared).
     */
    public static function layout(PerfProbe $probe, $pmss)
    {
        $text = $probe->fs->read('/proc/self/cgroup');
        $out = ['format' => null, 'scope' => null, 'dirs' => [], 'v2' => null, 'reason' => null];
        if ($text === null || trim($text) === '') {
            $out['reason'] = '/proc/self/cgroup could not be read';
            return $out;
        }
        $v2Path = null;
        $v1 = []; // controller => [mount dir name, path]
        foreach (explode("\n", trim($text)) as $line) {
            $parts = explode(':', trim($line), 3);
            if (count($parts) !== 3) {
                continue;
            }
            if ($parts[0] === '0' && $parts[1] === '') {
                $v2Path = $parts[2];
                continue;
            }
            foreach (explode(',', $parts[1]) as $ctrl) {
                if (in_array($ctrl, self::CONTROLLERS, true)) {
                    $v1[$ctrl] = [$parts[1], $parts[2]];
                }
            }
        }
        $out['format'] = $v1 ? ($v2Path !== null ? 'hybrid' : 'v1') : ($v2Path !== null ? 'v2' : null);
        if ($out['format'] === null) {
            $out['reason'] = 'no cgroup line this tool knows in /proc/self/cgroup';
            return $out;
        }
        $paths = [];
        foreach ($v1 as $ctrl => $mp) {
            $paths[] = $mp[1];
        }
        if ($v2Path !== null) {
            $paths[] = $v2Path;
        }
        $hasSegment = false;
        foreach ($paths as $p) {
            if (self::ownCut($p, $probe->uid) !== null) {
                $hasSegment = true;
            }
        }
        if ($hasSegment) {
            $out['scope'] = 'account';
        } elseif (self::shared($probe, $pmss)) {
            $out['reason'] = 'this process is not in a cgroup of the account, and the server is shared';
            return $out;
        } else {
            $out['scope'] = 'cgroup';
        }
        $resolve = function ($base, $path) use ($probe, &$out) {
            $cut = self::ownCut($path, $probe->uid);
            if ($cut === null && $out['scope'] === 'account') {
                return null; // this controller is not under the account slice: not read (it would be someone else's or the machine's)
            }
            if ($cut === null) {
                $cut = self::segments($path); // a server of its own: the leaf
                if (!$cut) {
                    $out['scope'] = 'machine';
                }
            }
            $dirs = [$base];
            $cur = $base;
            foreach ($cut as $seg) {
                $cur .= '/' . $seg;
                $dirs[] = $cur;
            }
            $own = array_pop($dirs);
            return [$own, $dirs];
        };
        foreach ($v1 as $ctrl => $mp) {
            $d = $resolve('/sys/fs/cgroup/' . $mp[0], $mp[1]);
            if ($d !== null) {
                $out['dirs'][$ctrl] = $d;
            }
        }
        if ($v2Path !== null) {
            $out['v2'] = $resolve($out['format'] === 'hybrid' ? '/sys/fs/cgroup/unified' : '/sys/fs/cgroup', $v2Path);
        }
        return $out;
    }

    /** The segments of $path up to and including `user-<uid>.slice`, or null when it has none. */
    public static function ownCut($path, $uid)
    {
        $segs = self::segments($path);
        foreach ($segs as $i => $seg) {
            if ($seg === "user-$uid.slice") {
                return array_slice($segs, 0, $i + 1);
            }
        }
        return null;
    }

    private static function segments($path)
    {
        return array_values(array_filter(explode('/', (string) $path), function ($s) {
            return $s !== '' && $s !== '.' && $s !== '..';
        }));
    }

    /**
     * Whether other accounts share the machine: PMSS always; elsewhere more than one human account in /etc/passwd
     * (uid >= 1000, a home that exists), as Detect::sharedTenancy decides. Only the yes/no is kept. Unreadable:
     * shared (the cautious answer: the account part is then unavailable, not the machine's figures).
     */
    private static function shared(PerfProbe $probe, $pmss)
    {
        if ($pmss) {
            return true;
        }
        $text = $probe->fs->read('/etc/passwd');
        if ($text === null) {
            return true;
        }
        $humans = 0;
        foreach (explode("\n", $text) as $line) {
            $f = explode(':', $line);
            if (count($f) >= 7 && ctype_digit($f[2]) && (int) $f[2] >= 1000 && (int) $f[2] !== 65534 && $f[5] !== '' && is_dir($f[5])) {
                $humans++;
            }
        }
        return $humans > 1;
    }

    /** One set of the account's own counters and limits (see the class comment); t is when it was read. */
    public static function sample(PerfProbe $probe, array $layout)
    {
        $s = ['t' => microtime(true), 'mem' => ['limit' => null, 'current' => null, 'anon' => null, 'file' => null, 'oom_kill' => null],
            'cpu' => ['quota_cores' => null, 'usage_s' => null, 'nr_periods' => null, 'nr_throttled' => null],
            'io' => ['caps' => [], 'bytes' => null], 'psi' => null];
        if ($layout['scope'] === null) {
            return $s;
        }
        $fs = $probe->fs;
        if ($layout['v2'] !== null && ($layout['format'] === 'v2' || !isset($layout['dirs']['memory']))) {
            list($own, $anc) = $layout['v2'];
            $stat = self::kv($fs->read("$own/memory.stat"));
            $events = self::kv($fs->read("$own/memory.events"));
            $s['mem'] = ['limit' => self::minLimit($fs, array_merge($anc, [$own]), 'memory.max'), 'current' => self::num($fs->read("$own/memory.current")),
                'anon' => self::pick($stat, ['anon']), 'file' => self::pick($stat, ['file']), 'oom_kill' => self::pick($events, ['oom_kill'])];
        } elseif (isset($layout['dirs']['memory'])) {
            list($own, $anc) = $layout['dirs']['memory'];
            $stat = self::kv($fs->read("$own/memory.stat"));
            $oom = self::kv($fs->read("$own/memory.oom_control"));
            $s['mem'] = ['limit' => self::minLimit($fs, array_merge($anc, [$own]), 'memory.limit_in_bytes'), 'current' => self::num($fs->read("$own/memory.usage_in_bytes")),
                'anon' => self::pick($stat, ['total_rss', 'rss']), 'file' => self::pick($stat, ['total_cache', 'cache']), 'oom_kill' => self::pick($oom, ['oom_kill'])];
        }
        if ($layout['v2'] !== null && ($layout['format'] === 'v2' || !isset($layout['dirs']['cpu']))) {
            list($own, $anc) = $layout['v2'];
            $stat = self::kv($fs->read("$own/cpu.stat"));
            $usage = self::pick($stat, ['usage_usec']);
            $s['cpu'] = ['quota_cores' => self::minCores($fs, array_merge($anc, [$own]), true), 'usage_s' => $usage === null ? null : $usage / 1e6,
                'nr_periods' => self::pick($stat, ['nr_periods']), 'nr_throttled' => self::pick($stat, ['nr_throttled'])];
        } elseif (isset($layout['dirs']['cpu'])) {
            list($own, $anc) = $layout['dirs']['cpu'];
            $stat = self::kv($fs->read("$own/cpu.stat"));
            $acct = isset($layout['dirs']['cpuacct']) ? $layout['dirs']['cpuacct'][0] : $own;
            $ns = self::num($fs->read("$acct/cpuacct.usage"));
            $s['cpu'] = ['quota_cores' => self::minCores($fs, array_merge($anc, [$own]), false), 'usage_s' => $ns === null ? null : $ns / 1e9,
                'nr_periods' => self::pick($stat, ['nr_periods']), 'nr_throttled' => self::pick($stat, ['nr_throttled'])];
        }
        if ($layout['v2'] !== null && ($layout['format'] === 'v2' || !isset($layout['dirs']['blkio']))) {
            list($own, $anc) = $layout['v2'];
            foreach (array_merge($anc, [$own]) as $dir) {
                self::mergeCaps($s['io']['caps'], self::parseIoMax($fs->read("$dir/io.max")));
            }
            $s['io']['bytes'] = self::parseIoStat($fs->read("$own/io.stat"));
        } elseif (isset($layout['dirs']['blkio'])) {
            list($own, $anc) = $layout['dirs']['blkio'];
            foreach (array_merge($anc, [$own]) as $dir) {
                foreach (['rbps' => 'read_bps_device', 'wbps' => 'write_bps_device', 'riops' => 'read_iops_device', 'wiops' => 'write_iops_device'] as $k => $file) {
                    self::mergeCaps($s['io']['caps'], self::parseThrottle($fs->read("$dir/blkio.throttle.$file"), $k));
                }
            }
            $s['io']['bytes'] = self::parseServiceBytes($fs->read("$own/blkio.throttle.io_service_bytes"));
        }
        if ($layout['v2'] !== null) {
            list($own) = $layout['v2'];
            $psi = [];
            foreach (['cpu' => 'some', 'io' => 'full', 'memory' => 'full'] as $res => $level) {
                $p = PerfProbe::parsePsi((string) $fs->read("$own/$res.pressure"));
                $psi[$res] = ['level' => $level, 'avg10' => isset($p[$level]) ? $p[$level]['avg10'] : null, 'avg60' => isset($p[$level]) ? $p[$level]['avg60'] : null];
            }
            $s['psi'] = $psi;
        }
        return $s;
    }

    /**
     * Two samples (and userShowLimits' data, or null) -> the account, cpu, memory and io parts of check, plus the
     * facts the router reads. Rates are over the time between the samples; a counter missing in either is null.
     */
    public static function summary(array $layout, array $a, array $b, $usl)
    {
        $dt = max(0.001, $b['t'] - $a['t']);
        $delta = function ($x, $y) {
            return $x === null || $y === null || $y < $x ? null : $y - $x;
        };
        $mem = $b['mem'];
        $uslMem = self::uslMetric($usl, 'memory', 'memory');
        $limit = $mem['limit'] !== null ? $mem['limit'] : ($uslMem !== null && is_numeric($uslMem['limit']) ? (float) $uslMem['limit'] : null);
        $pct = function ($v, $l) {
            return $v === null || $l === null || $l <= 0 ? null : round(100 * $v / $l, 1);
        };
        $status = self::uslMetric($usl, 'memory', 'memory status');
        $oomLife = self::uslMetric($usl, 'memory', 'OOM kills');
        $memory = ['limit_bytes' => $limit, 'in_use_bytes' => $mem['current'], 'anon_bytes' => $mem['anon'], 'cache_bytes' => $mem['file'],
            'anon_pct_of_limit' => $pct($mem['anon'], $limit), 'anon_pct_first_sample' => $pct($a['mem']['anon'], $limit),
            'oom_kills_during_check' => $delta($a['mem']['oom_kill'], $b['mem']['oom_kill']), 'oom_kills_counter' => $b['mem']['oom_kill'],
            'platform_status' => $status !== null && is_scalar($status['value']) ? (string) $status['value'] : null,
            'platform_oom_kills_lifetime' => $oomLife !== null && is_numeric($oomLife['value']) ? (int) $oomLife['value'] : null];

        $uslCpu = self::uslMetric($usl, 'cpu', 'CPU quota enforced');
        $quota = $b['cpu']['quota_cores'] !== null ? $b['cpu']['quota_cores'] : ($uslCpu !== null && is_numeric($uslCpu['value']) ? round((float) $uslCpu['value'] / 100, 2) : null);
        $periods = $delta($a['cpu']['nr_periods'], $b['cpu']['nr_periods']);
        $throttled = $delta($a['cpu']['nr_throttled'], $b['cpu']['nr_throttled']);
        $usage = $delta($a['cpu']['usage_s'], $b['cpu']['usage_s']);
        $cpu = ['quota_cores' => $quota === null ? null : round($quota, 2), 'own_use_cores' => $usage === null ? null : round($usage / $dt, 2),
            'throttled_pct' => $periods === null || $throttled === null || $periods <= 0 ? null : round(100 * $throttled / $periods, 1),
            'periods_during_check' => $periods];

        $rates = null;
        if ($a['io']['bytes'] !== null && $b['io']['bytes'] !== null) {
            $rates = [];
            foreach ($b['io']['bytes'] as $dev => $rw) {
                if (isset($a['io']['bytes'][$dev])) {
                    $r = $delta($a['io']['bytes'][$dev]['r'], $rw['r']);
                    $w = $delta($a['io']['bytes'][$dev]['w'], $rw['w']);
                    $rates[$dev] = ['read_bps' => $r === null ? null : (int) round($r / $dt), 'write_bps' => $w === null ? null : (int) round($w / $dt)];
                }
            }
        }
        $caps = [];
        foreach ($b['io']['caps'] as $dev => $c) {
            $caps[] = ['device' => $dev] + $c;
        }
        $io = ['caps' => $caps, 'own_rate' => $rates]; // userShowLimits' disk I/O metrics are in the account record (platform_limits)

        $account = ['source' => $usl !== null ? 'userShowLimits' : ($layout['scope'] !== null ? 'cgroup' : 'unavailable'),
            'cgroup' => $layout['format'], 'scope' => $layout['scope'], 'own_pressure' => $b['psi'],
            'traffic' => self::traffic($usl), 'platform_limits' => self::uslFlat($usl)];
        if ($layout['reason'] !== null) {
            $account['unavailable_reason'] = $layout['reason'];
        }
        return ['account' => $account, 'cpu' => $cpu, 'memory' => $memory, 'io' => $io];
    }

    /** userShowLimits traffic metrics that have a limit: [metric, used_bytes, limit_bytes, used_pct]. */
    public static function traffic($usl)
    {
        $out = [];
        foreach (self::uslSectionRaw($usl, 'traffic') as $m) {
            if (!isset($m['metric']) || !is_numeric(isset($m['value']) ? $m['value'] : null)) {
                continue;
            }
            $limit = isset($m['limit']) && is_numeric($m['limit']) && (float) $m['limit'] > 0 ? (float) $m['limit'] : null;
            $out[] = ['metric' => (string) $m['metric'], 'used_bytes' => (float) $m['value'], 'limit_bytes' => $limit,
                'used_pct' => $limit === null ? null : round(100 * (float) $m['value'] / $limit, 1)];
        }
        return $out;
    }

    private static function uslSectionRaw($usl, $name)
    {
        if (!is_array($usl) || !isset($usl['sections'][$name]) || empty($usl['sections'][$name]['available']) || !isset($usl['sections'][$name]['metrics']) || !is_array($usl['sections'][$name]['metrics'])) {
            return [];
        }
        return array_values(array_filter($usl['sections'][$name]['metrics'], 'is_array'));
    }

    private static function uslMetric($usl, $section, $metric)
    {
        foreach (self::uslSectionRaw($usl, $section) as $m) {
            if (isset($m['metric']) && $m['metric'] === $metric) {
                return $m + ['value' => null, 'limit' => null];
            }
        }
        return null;
    }

    private static function uslRow(array $m)
    {
        $s = function ($v) {
            return is_scalar($v) || $v === null ? (is_string($v) ? substr($v, 0, 80) : $v) : null;
        };
        return ['metric' => $s(isset($m['metric']) ? $m['metric'] : null), 'value' => $s(isset($m['value']) ? $m['value'] : null),
            'limit' => $s(isset($m['limit']) ? $m['limit'] : null), 'unit' => $s(isset($m['unit']) ? $m['unit'] : null)];
    }

    /** Every userShowLimits metric but the account and server sections, as the platform reports it (configured and enforced alike). */
    private static function uslFlat($usl)
    {
        if (!is_array($usl) || !isset($usl['sections']) || !is_array($usl['sections'])) {
            return null;
        }
        $out = [];
        foreach ($usl['sections'] as $name => $section) {
            if (preg_match(self::USL_SKIP_SECTIONS, (string) $name)) {
                continue;
            }
            foreach (self::uslSectionRaw($usl, $name) as $m) {
                if (count($out) >= self::USL_METRICS_MAX) {
                    break 2;
                }
                $out[] = ['section' => substr((string) $name, 0, 40)] + self::uslRow($m);
            }
        }
        return $out;
    }

    /** "key value" lines -> [key => float]; lines that do not parse are left out. null text -> null. */
    public static function kv($text)
    {
        if ($text === null) {
            return null;
        }
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(\S+)\s+(-?[0-9]+(?:\.[0-9]+)?)\s*$/', trim($line), $m)) {
                $out[$m[1]] = (float) $m[2];
            }
        }
        return $out;
    }

    private static function pick($kv, array $keys)
    {
        if (!is_array($kv)) {
            return null;
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $kv)) {
                return $kv[$k];
            }
        }
        return null;
    }

    public static function num($text)
    {
        $t = trim((string) $text);
        return $text !== null && is_numeric($t) ? (float) $t : null;
    }

    /** Lowest memory limit over $dirs (memory.max "max" or v1's huge value = none). */
    private static function minLimit(PerfFs $fs, array $dirs, $file)
    {
        $min = null;
        foreach ($dirs as $dir) {
            $v = self::num($fs->read("$dir/$file"));
            if ($v !== null && $v > 0 && $v < self::V1_UNLIMITED_FLOOR && ($min === null || $v < $min)) {
                $min = $v;
            }
        }
        return $min;
    }

    /** Lowest CPU quota in cores over $dirs (v2 cpu.max, v1 cfs_quota_us / cfs_period_us; -1 or max = none). */
    private static function minCores(PerfFs $fs, array $dirs, $v2)
    {
        $min = null;
        foreach ($dirs as $dir) {
            $cores = null;
            if ($v2) {
                $raw = preg_split('/\s+/', trim((string) $fs->read("$dir/cpu.max")));
                if (count($raw) === 2 && is_numeric($raw[0]) && is_numeric($raw[1]) && (float) $raw[1] > 0) {
                    $cores = (float) $raw[0] / (float) $raw[1];
                }
            } else {
                $q = self::num($fs->read("$dir/cpu.cfs_quota_us"));
                $p = $q !== null && $q > 0 ? self::num($fs->read("$dir/cpu.cfs_period_us")) : null;
                if ($p !== null && $p > 0) {
                    $cores = $q / $p;
                }
            }
            if ($cores !== null && ($min === null || $cores < $min)) {
                $min = $cores;
            }
        }
        return $min;
    }

    /** io.max lines -> [maj:min => [rbps, wbps, riops, wiops]] with only the set ones ("max" = none). */
    public static function parseIoMax($text)
    {
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (!preg_match('/^(\d+:\d+)\s+(.*)$/', trim($line), $m)) {
                continue;
            }
            foreach (preg_split('/\s+/', $m[2]) as $kv) {
                if (preg_match('/^(rbps|wbps|riops|wiops)=(\d+)$/', $kv, $x)) {
                    $out[$m[1]][$x[1]] = (float) $x[2];
                }
            }
        }
        return $out;
    }

    /** blkio.throttle.<file> lines "maj:min value" -> [maj:min => [$key => value]]. */
    private static function parseThrottle($text, $key)
    {
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^(\d+:\d+)\s+(\d+)$/', trim($line), $m) && (float) $m[2] > 0) {
                $out[$m[1]][$key] = (float) $m[2];
            }
        }
        return $out;
    }

    /** Keeps the tightest value per device and kind. */
    private static function mergeCaps(array &$caps, array $more)
    {
        foreach ($more as $dev => $kinds) {
            foreach ($kinds as $k => $v) {
                if (!isset($caps[$dev][$k]) || $v < $caps[$dev][$k]) {
                    $caps[$dev][$k] = $v;
                }
            }
        }
    }

    /** io.stat -> [maj:min => [r, w]] bytes; null text -> null. */
    public static function parseIoStat($text)
    {
        if ($text === null) {
            return null;
        }
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(\d+:\d+)\s.*\brbytes=(\d+).*\bwbytes=(\d+)/', trim($line), $m)) {
                $out[$m[1]] = ['r' => (float) $m[2], 'w' => (float) $m[3]];
            }
        }
        return $out;
    }

    /** blkio.throttle.io_service_bytes ("maj:min Read N" lines) -> [maj:min => [r, w]]; null text -> null. */
    private static function parseServiceBytes($text)
    {
        if ($text === null) {
            return null;
        }
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(\d+:\d+)\s+(Read|Write)\s+(\d+)$/', trim($line), $m)) {
                $out[$m[1]][$m[2] === 'Read' ? 'r' : 'w'] = (float) $m[3];
            }
        }
        foreach ($out as $dev => $rw) {
            $out[$dev] += ['r' => null, 'w' => null];
        }
        return $out;
    }
}
