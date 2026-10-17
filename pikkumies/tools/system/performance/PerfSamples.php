<?php
/**
 * PerfSamples: the samples a scheduled `check --tick` writes, and the `samples` verb that reads them (ADR-0035).
 *  - tick: the server's pressure, CPU busy and steal, the disk wait behind HOME (two readings CHECK_WINDOW_S
 *    apart) and the account's CPU throttling, appended as one line to local/performance/samples.jsonl (0600) with
 *    the time and the machine fingerprint hash. It prints nothing (a no_agent job sends whatever it prints) and
 *    does not run userShowLimits. Samples older than SAMPLES_HOURS_MAX hours are dropped when the file grows.
 *  - samples: per-hour medians of those figures over the last N hours, split into segments where the fingerprint
 *    changes (another server), with the time of the last sample and the gaps where the job did not run.
 * Nothing in a sample names the account, the server or another account. A figure that could not be read is null,
 * and a median of no figures is null, never 0.
 */
class PerfSamples
{
    const FILE = 'samples.jsonl';
    const FIELDS = ['io_full_avg10', 'io_full_avg60', 'cpu_some_avg10', 'mem_full_avg10', 'cpu_busy_pct', 'cpu_steal_pct', 'disk_await_ms', 'throttled_pct'];
    const READ_MAX = 4194304;     // bytes of samples.jsonl read; the file is trimmed well below this
    const TRIM_AT = 2097152;      // the writer drops old samples once the file is this large
    const GAPS_SHOWN = 10;

    /** check --tick: one sample, no output. */
    public static function tick(PerfProbe $probe, array $params)
    {
        $pmss = is_file($probe->home . '/bin/userShowLimits'); // PMSS is always shared; userShowLimits itself is not run by the tick
        $layout = PerfAccount::layout($probe, $pmss);
        $a1 = PerfAccount::sample($probe, $layout);
        $h1 = PerfHost::sample($probe);
        PerfHost::wait();
        $a2 = PerfAccount::sample($probe, $layout);
        $h2 = PerfHost::sample($probe);
        $host = PerfHost::summary($probe, $h1, $h2);
        $acc = PerfAccount::summary($layout, $a1, $a2, null);
        $psi = $host['pressure'];
        $sample = ['ts' => gmdate('c'), 'fingerprint' => $probe->fingerprint(),
            'io_full_avg10' => $psi['io']['avg10'], 'io_full_avg60' => $psi['io']['avg60'], 'cpu_some_avg10' => $psi['cpu']['avg10'], 'mem_full_avg10' => $psi['memory']['avg10'],
            'cpu_busy_pct' => $host['cpu_busy_pct'], 'cpu_steal_pct' => $host['cpu_steal_pct'], 'disk_await_ms' => $host['disk_await_ms'], 'throttled_pct' => $acc['cpu']['throttled_pct']];
        $file = $probe->stateDir() . '/' . self::FILE;
        $old = umask(077);
        @file_put_contents($file, json_encode($sample, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
        @chmod($file, 0600);
        clearstatcache(true, $file);
        if (@filesize($file) > self::TRIM_AT) {
            self::trim($probe, $file);
        }
        return [];
    }

    /** Keeps the samples of the last SAMPLES_HOURS_MAX hours (rewritten through a 0600 file, then renamed). */
    private static function trim(PerfProbe $probe, $file)
    {
        $keep = '';
        $cut = time() - PerfLimits::SAMPLES_HOURS_MAX * 3600;
        foreach (self::parse($probe->fs->read($file, self::READ_MAX)) as $s) {
            if ($s['t'] >= $cut) {
                $keep .= json_encode($s['raw'], JSON_UNESCAPED_SLASHES) . "\n";
            }
        }
        $tmp = $file . '.' . getmypid();
        $old = umask(077);
        if (@file_put_contents($tmp, $keep) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
        umask($old);
    }

    /** samples [--hours N]: a summary record, then one record per segment and day with per-hour medians. */
    public static function samples(PerfProbe $probe, array $params)
    {
        $hours = isset($params['hours']) ? (int) $params['hours'] : PerfLimits::SAMPLES_HOURS_DEFAULT;
        $now = time();
        $all = self::parse($probe->fs->read($probe->stateDir() . '/' . self::FILE, self::READ_MAX));
        $rows = array_values(array_filter($all, function ($s) use ($now, $hours) {
            return $s['t'] >= $now - $hours * 3600 && $s['t'] <= $now + 300;
        }));
        usort($rows, function ($x, $y) {
            return $x['t'] - $y['t'];
        });
        $current = $probe->fingerprint();
        $segments = [];
        foreach ($rows as $s) {
            $n = count($segments);
            if (!$n || $segments[$n - 1]['fingerprint'] !== $s['raw']['fingerprint']) {
                $segments[] = ['fingerprint' => $s['raw']['fingerprint'], 'rows' => []];
                $n++;
            }
            $segments[$n - 1]['rows'][] = $s;
        }
        $gaps = [];
        $interval = PerfLimits::SAMPLE_INTERVAL_MIN * 60;
        for ($i = 1; $i < count($rows); $i++) {
            $d = $rows[$i]['t'] - $rows[$i - 1]['t'];
            if ($d > 2 * $interval) {
                $gaps[] = ['from' => gmdate('c', $rows[$i - 1]['t']), 'to' => gmdate('c', $rows[$i]['t']), 'minutes' => (int) round($d / 60)];
            }
        }
        usort($gaps, function ($x, $y) {
            return $y['minutes'] - $x['minutes'];
        });
        $summary = ['area' => 'samples', 'hours' => $hours, 'samples' => count($rows),
            'last_sample' => $rows ? gmdate('c', $rows[count($rows) - 1]['t']) : null,
            'segments' => array_map(function ($seg, $i) use ($current) {
                $r = $seg['rows'];
                return ['segment' => $i + 1, 'server' => $seg['fingerprint'] === $current ? 'this server' : 'another server',
                    'from' => gmdate('c', $r[0]['t']), 'to' => gmdate('c', $r[count($r) - 1]['t']), 'samples' => count($r)];
            }, $segments, array_keys($segments)),
            'gaps' => array_slice($gaps, 0, self::GAPS_SHOWN), 'gaps_total' => count($gaps)];
        if (!$rows) {
            $summary['notice'] = 'no samples in this period: the scheduled job writes one every ' . PerfLimits::SAMPLE_INTERVAL_MIN . ' minutes once the user asked for it';
        } elseif ($rows[count($rows) - 1]['t'] < $now - 2 * $interval) {
            $summary['notice'] = 'the last sample is older than two intervals: the job has ended or is not running';
        }
        if (is_file($probe->home . '/bin/userShowLimits')) {
            $summary['platform'] = 'userShowLimits keeps its own 30-day figures; check shows them in the account record';
        }
        $records = [$summary];
        foreach ($segments as $i => $seg) {
            $days = [];
            foreach ($seg['rows'] as $s) {
                $days[gmdate('Y-m-d', $s['t'])][gmdate('H', $s['t'])][] = $s['raw'];
            }
            foreach ($days as $day => $byHour) {
                $list = [];
                foreach ($byHour as $h => $raws) {
                    $row = ['hour' => $h . ':00Z', 'samples' => count($raws)];
                    foreach (self::FIELDS as $field) {
                        $row[$field] = self::median(array_column($raws, $field));
                    }
                    $list[] = $row;
                }
                $records[] = ['area' => 'hours', 'segment' => $i + 1, 'day' => $day, 'hours' => $list];
            }
        }
        return $records;
    }

    /** samples.jsonl text -> [['t' => unix time, 'raw' => sample]] for the lines that parse; null text -> []. */
    public static function parse($text)
    {
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            $s = $line === '' ? null : json_decode($line, true);
            if (!is_array($s) || !isset($s['ts'], $s['fingerprint']) || !is_string($s['fingerprint']) || ($t = strtotime((string) $s['ts'])) === false) {
                continue;
            }
            $raw = ['ts' => (string) $s['ts'], 'fingerprint' => substr($s['fingerprint'], 0, 32)];
            foreach (self::FIELDS as $f) {
                $raw[$f] = isset($s[$f]) && is_numeric($s[$f]) ? (float) $s[$f] : null;
            }
            $out[] = ['t' => $t, 'raw' => $raw];
        }
        return $out;
    }

    /** Median of the numbers in $values (nulls left out); null when there are none. */
    public static function median(array $values)
    {
        $v = array_values(array_filter($values, function ($x) {
            return $x !== null;
        }));
        if (!$v) {
            return null;
        }
        sort($v);
        $n = count($v);
        return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 2);
    }
}
