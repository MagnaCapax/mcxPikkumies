<?php
/**
 * check and samples (ADR-0035): the whole verb through the dispatcher (at most 10 records, each within the byte
 * budget, verdict first), the account figures on cgroup v1/v2/hybrid trees, unknown values as null (never 0) with
 * the check listed under skipped, rates over the two samples, the disk wait of an md array taken from its members,
 * the previous check and a server change, the silent tick, and samples split where the server changed.
 */
require_once __DIR__ . '/fixtures/performance/check-helpers.php';

/** A userShowLimits answer with $n long metrics in each of several sections (to press on the record budget). */
function perfCkBigUsl($n)
{
    $m = [];
    for ($i = 0; $i < $n; $i++) {
        $m[] = ['metric' => "metric $i " . str_repeat('x', 300), 'value' => str_repeat('v', 500), 'limit' => $i, 'unit' => 'bytes', 'percent' => null, 'source' => 'cgroup'];
    }
    return json_encode(['user' => 'fake-user-name', 'host' => 'fake-host.example', 'generated_at' => 1, 'sections' => [
        'memory' => ['available' => true, 'metrics' => $m], 'cpu' => ['available' => true, 'metrics' => $m], 'traffic' => ['available' => true, 'metrics' => $m],
        'disk I/O' => ['available' => true, 'metrics' => $m]]]);
}

return [
    'perf check: through the dispatcher, at most 10 records each within 16 KiB, verdict first, even with a huge userShowLimits answer and many sessions' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            file_put_contents($fx['dir'] . '/run/usl.json', perfCkBigUsl(200));
            $ss = '';
            for ($i = 0; $i < 60; $i++) {
                $ss .= "ESTAB 0 0 192.0.2.10:22 198.51.100.7:" . (40000 + $i) . "\n\t cubic rtt:30/2 mss:1448 cwnd:10 bytes_acked:1000 busy:10ms rwnd_limited:1ms(10.0%) delivery_rate 1Mbps\n";
            }
            file_put_contents($fx['dir'] . '/run/ss.out', $ss);
            for ($p = 6000; $p < 6300; $p++) {
                perfBProc($roots, $p, PERF_FIXTURE_UID, ['rtorrent', 'lighttpd', 'Sonarr', 'jellyfin', 'rclone', 'php', 'bash'][$p % 7], $p % 13 ? 'S' : 'D');
            }
            list($code, $recs, $out) = perfRun($fx, ['check', '--symptom', 'torrents'], ['roots' => $roots],
                ['SSH_CONNECTION' => PERF_B_SSH, 'PK_PERF_FAKE_USERSHOWLIMITS_OUT' => $fx['dir'] . '/run/usl.json', 'PK_PERF_FAKE_SS_OUT' => $fx['dir'] . '/run/ss.out']);
            check($code === 0, "check exited $code: $out");
            $status = array_pop($recs);
            check($status['ok'] === true && empty($status['truncated']), 'status: ' . json_encode($status));
            check(count($recs) <= PerfLimits::CHECK_MAX_RECORDS && count($recs) === 10, 'records: ' . count($recs));
            foreach (explode("\n", trim($out)) as $line) {
                check(strlen($line) <= Cli::BUDGET && strpos($line, '"error":"record larger') === false, 'a record over the budget: ' . substr($line, 0, 200));
            }
            check(array_column($recs, 'area') === ['verdict', 'account', 'cpu', 'memory', 'io', 'host', 'sessions', 'next_checks', 'skipped', 'cost'], 'areas: ' . json_encode(array_column($recs, 'area')));
            check($recs[0]['symptom'] === 'torrents' && isset($recs[0]['class'], $recs[0]['evidence'], $recs[0]['negation'], $recs[0]['disproved_by'], $recs[0]['next_step']), 'verdict: ' . json_encode($recs[0]));
            check(count($recs[1]['platform_limits']) === PerfAccount::USL_METRICS_MAX && count($recs[6]['sessions']) === PerfSessions::SHOWN, 'lists not capped');
            check(count($recs[3]['by_app']) <= PerfLimits::MEM_TOP_GROUPS, 'memory by app over MEM_TOP_GROUPS');
            check(strpos($out, 'fake-user-name') === false && strpos($out, 'fake-host.example') === false, 'account or server name printed');
            $runs = cliLines((string) file_get_contents($fx['local'] . '/performance/runs.jsonl'));
            check($runs[0]['verb'] === 'check' && $runs[0]['classes'] && $runs[0]['skipped'] !== null, 'run log: ' . json_encode($runs));
        } finally {
            perfDone($fx);
        }
    },
    'perf check: account figures on v1, v2 and hybrid trees (limit, memory in use, cache, CPU quota, I/O cap)' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            foreach (['v1', 'v2', 'hybrid'] as $mode) {
                $roots = perfFakeTree($fx['dir'] . "/tree-$mode", $mode);
                unlink($fx['home'] . '/bin/userShowLimits');
                list($recs) = perfBCheck($fx, $roots);
                $acc = perfBArea($recs, 'account');
                check($acc['source'] === 'cgroup' && $acc['cgroup'] === $mode && $acc['scope'] === 'account', "$mode account: " . json_encode($acc));
                $mem = perfBArea($recs, 'memory');
                check($mem['limit_bytes'] === 2147483648.0 && $mem['anon_bytes'] === 536870912.0 && $mem['cache_bytes'] === 268435456.0 && $mem['anon_pct_of_limit'] === 25.0, "$mode memory: " . json_encode($mem));
                check(perfBArea($recs, 'cpu')['quota_cores'] === 2.0, "$mode cpu: " . json_encode(perfBArea($recs, 'cpu')));
                check(perfBArea($recs, 'io')['caps'] === [['device' => '8:0', 'rbps' => 104857600.0]], "$mode caps: " . json_encode(perfBArea($recs, 'io')['caps']));
                check(($acc['own_pressure'] === null) === ($mode === 'v1'), "$mode own pressure: " . json_encode($acc['own_pressure']));
                copy(__DIR__ . '/fixtures/performance/bin/userShowLimits', $fx['home'] . '/bin/userShowLimits');
                file_put_contents($fx['home'] . '/bin/userShowLimits', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/performance/bin/userShowLimits'));
                chmod($fx['home'] . '/bin/userShowLimits', 0700);
            }
            list($recs) = perfBCheck($fx, perfFakeTree($fx['dir'] . '/tree-pmss', 'v2'));
            $acc = perfBArea($recs, 'account');
            check($acc['source'] === 'userShowLimits' && perfBArea($recs, 'memory')['platform_status'] === 'OK' && $acc['traffic'][0]['used_pct'] === 20.0, 'PMSS account: ' . json_encode($acc));
            check(!array_filter($acc['platform_limits'], function ($m) {
                return in_array($m['section'], ['account', 'server (shared by all accounts)'], true);
            }), 'the account/server sections of userShowLimits must not be in the account record');
        } finally {
            perfDone($fx);
        }
    },
    'perf check: what cannot be read is null (never 0) and listed under skipped' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            foreach (['pressure/cpu', 'pressure/io', 'pressure/memory', 'stat', 'diskstats'] as $f) {
                unlink($roots['/proc'] . "/$f");
            }
            $slice = $roots['/sys'] . '/fs/cgroup/user.slice/user-' . PERF_FIXTURE_UID . '.slice';
            foreach (['cpu.stat', 'io.stat', 'memory.max', 'memory.stat', 'memory.events', 'cpu.pressure', 'io.pressure', 'memory.pressure'] as $f) {
                unlink("$slice/$f");
            }
            unlink($fx['home'] . '/bin/userShowLimits');
            list($recs) = perfBCheck($fx, $roots, [], ['SSH_CONNECTION' => null]);
            $host = perfBArea($recs, 'host');
            foreach (['cpu', 'io', 'memory'] as $r) {
                check($host['pressure'][$r]['avg10'] === null && $host['pressure'][$r]['avg60'] === null, "server pressure $r must be null: " . json_encode($host['pressure'][$r]));
            }
            foreach (['cpu_busy_pct', 'cpu_steal_pct', 'procs_running', 'disk_await_ms', 'disk_util_pct', 'swap_in_pages_s', 'link_speed_mbit'] as $k) {
                check($host[$k] === null, "host $k must be null, got " . json_encode($host[$k]));
            }
            $mem = perfBArea($recs, 'memory');
            foreach (['limit_bytes', 'anon_bytes', 'anon_pct_of_limit', 'oom_kills_during_check', 'platform_status'] as $k) {
                check($mem[$k] === null, "memory $k must be null, got " . json_encode($mem[$k]));
            }
            check(perfBArea($recs, 'cpu')['throttled_pct'] === null && perfBArea($recs, 'cpu')['own_use_cores'] === null, 'cpu unknowns: ' . json_encode(perfBArea($recs, 'cpu')));
            check(perfBArea($recs, 'io')['own_rate'] === null, 'own disk rate must be null');
            $skipped = array_column(perfBArea($recs, 'skipped')['skipped'], 'reason', 'check');
            foreach (['server pressure', 'server CPU', 'disk wait', 'memory limit', 'CPU throttling', 'own disk rate', 'swap activity', 'link speed', 'sessions'] as $c) {
                check(isset($skipped[$c]) && $skipped[$c] !== '', "skipped lacks $c: " . json_encode($skipped));
            }
            check(perfBArea($recs, 'verdict')['class'] === 'nothing_found', 'unknowns must not make a verdict: ' . perfBArea($recs, 'verdict')['class']);
        } finally {
            perfDone($fx);
        }
    },
    'perf check: rates over the two samples (throttling, own disk rate, CPU busy and steal, OOM kills during the check)' => function () {
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            $probe = perfBProbe($fx, $roots);
            $slice = $roots['/sys'] . '/fs/cgroup/user.slice/user-' . PERF_FIXTURE_UID . '.slice';
            $layout = PerfAccount::layout($probe, false);
            $a1 = PerfAccount::sample($probe, $layout);
            $h1 = PerfHost::sample($probe);
            perfWrite("$slice/cpu.stat", "usage_usec 102000000\nnr_periods 1020\nnr_throttled 60\nthrottled_usec 6000000\n");
            perfWrite("$slice/io.stat", "8:0 rbytes=21000000 wbytes=2000000 rios=100 wios=200\n");
            perfWrite("$slice/memory.events", "low 0\nhigh 0\nmax 0\noom 1\noom_kill 1\n");
            perfWrite($roots['/proc'] . '/stat', "cpu  100600 500 40200 900100 20000 0 1000 3100 0 0\nprocs_running 3\nprocs_blocked 1\n");
            $a2 = PerfAccount::sample($probe, $layout);
            $a2['t'] = $a1['t'] + 2.0;
            $h2 = PerfHost::sample($probe);
            $h2['t'] = $h1['t'] + 2.0;
            $sum = PerfAccount::summary($layout, $a1, $a2, null);
            check($sum['cpu']['throttled_pct'] === 50.0 && $sum['cpu']['own_use_cores'] === 1.0 && $sum['cpu']['periods_during_check'] === 20.0, 'cpu: ' . json_encode($sum['cpu']));
            check($sum['io']['own_rate']['8:0'] === ['read_bps' => 10000000, 'write_bps' => 0], 'io rate: ' . json_encode($sum['io']['own_rate']));
            check($sum['memory']['oom_kills_during_check'] === 1.0, 'oom delta: ' . json_encode($sum['memory']));
            $host = PerfHost::summary($probe, $h1, $h2);
            check($host['cpu_busy_pct'] === 90.0 && $host['cpu_steal_pct'] === 10.0, 'busy/steal: ' . json_encode([$host['cpu_busy_pct'], $host['cpu_steal_pct']]));
            $facts = ['memory' => $sum['memory'], 'cpu' => $sum['cpu'], 'io' => $sum['io'], 'host' => $host];
            $classes = perfBClasses($facts);
            check(in_array('account_cpu_bound', $classes, true) && in_array('account_memory_bound', $classes, true) && in_array('host_steal', $classes, true), 'classes from rates: ' . json_encode($classes));
        } finally {
            perfDone($fx);
        }
    },
    'perf check: the disk wait of an md array comes from its busiest member; a plain device from itself; unknown when HOME is not found' => function () {
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            perfWrite($roots['/proc'] . '/self/mountinfo', "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n30 22 9:0 / " . $fx['home'] . " rw - ext4 /dev/md0 rw\n");
            perfWrite($roots['/proc'] . '/mdstat', "Personalities : [raid5]\nmd0 : active raid5 sdb1[1] sda1[0]\n      1000 blocks [2/2] [UU]\n      [=>...]  check =  8.0% (1/2) finish=10min\n");
            $stats = function ($sdaTicks, $sdaIo, $sdbTicks, $sdbIo, $ios) {
                return "   9       0 md0 1000 0 80000 0 2000 0 160000 0 0 0 0\n"
                    . "   8       0 sda $ios 0 400000 $sdaTicks 0 0 640000 0 0 $sdaIo 0\n"
                    . "   8      16 sdb $ios 0 400000 $sdbTicks 0 0 640000 0 0 $sdbIo 0\n"
                    . "   8       1 sda1 10 0 80 5 0 0 0 0 0 5 0\n";
            };
            perfWrite($roots['/proc'] . '/diskstats', $stats(1000, 1000, 1000, 1000, 100));
            $probe = perfBProbe($fx, $roots);
            $h1 = PerfHost::sample($probe);
            perfWrite($roots['/proc'] . '/diskstats', $stats(1500, 1400, 3000, 1800, 200));
            $h2 = PerfHost::sample($probe);
            $h2['t'] = $h1['t'] + 2.0;
            $host = PerfHost::summary($probe, $h1, $h2);
            check($host['disk_device'] === 'busiest array member' && $host['disk_await_ms'] === 20.0 && $host['disk_util_pct'] === 40.0, 'md member: ' . json_encode([$host['disk_device'], $host['disk_await_ms'], $host['disk_util_pct']]));
            check($host['array_maintenance'] === 'check', 'mdstat check not seen');
            perfWrite($roots['/proc'] . '/self/mountinfo', "22 1 8:0 / / rw - ext4 /dev/sda rw\n");
            $host = PerfHost::summary($probe, $h1, $h2);
            check($host['disk_device'] === 'device' && $host['disk_await_ms'] === 5.0, 'plain device: ' . json_encode([$host['disk_device'], $host['disk_await_ms']]));
            perfWrite($roots['/proc'] . '/self/mountinfo', "22 1 7:0 / /srv rw - ext4 /dev/loop0 rw\n");
            $host = PerfHost::summary($probe, $h1, $h2);
            check($host['disk_device'] === null && $host['disk_await_ms'] === null && $host['disk_util_pct'] === null, 'unknown device must be null: ' . json_encode($host));
        } finally {
            perfDone($fx);
        }
    },
    'perf check: the previous check is shown, and a different fingerprint says it ran on another server' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            list($first) = perfBCheck($fx, $roots);
            check(perfBArea($first, 'host')['previous'] === null, 'first check has no previous');
            $file = $fx['local'] . '/performance/last-check.json';
            check(substr(sprintf('%o', fileperms($file)), -4) === '0600', 'last-check.json mode ' . sprintf('%o', fileperms($file)));
            check(strpos((string) file_get_contents($file), 'fixture-host') === false, 'last-check.json holds the raw fingerprint');
            list($second) = perfBCheck($fx, $roots);
            $prev = perfBArea($second, 'host')['previous'];
            check($prev['same_server'] === true && $prev['classes'] === [perfBArea($first, 'verdict')['class']], 'same server: ' . json_encode($prev));
            list($moved) = perfBCheck($fx, $roots, [], [], ['fingerprint' => 'new-host|machine|' . PERF_FIXTURE_UID]);
            $prev = perfBArea($moved, 'host')['previous'];
            check($prev['same_server'] === false && strpos($prev['note'], 'another server') !== false, 'moved: ' . json_encode($prev));
        } finally {
            perfDone($fx);
        }
    },
    'perf check: --tick prints nothing and appends one 0600 sample without names; samples splits by server and gives hourly medians and gaps' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            list($code, , $out, $err) = perfRun($fx, ['check', '--tick'], ['roots' => $roots]);
            check($code === 0 && $out === '' && $err === '', "tick: $code '$out' '$err'");
            $file = $fx['local'] . '/performance/samples.jsonl';
            check(substr(sprintf('%o', fileperms($file)), -4) === '0600', 'samples.jsonl mode');
            $lines = cliLines((string) file_get_contents($file));
            check(count($lines) === 1 && array_keys($lines[0]) === array_merge(['ts', 'fingerprint'], PerfSamples::FIELDS), 'sample: ' . json_encode($lines));
            check($lines[0]['io_full_avg10'] === 3.2 && preg_match('/^[0-9a-f]{16}$/', $lines[0]['fingerprint']), 'sample values: ' . json_encode($lines[0]));
            check(!in_array('userShowLimits', array_column(perfFakeCalls($fx), 'prog'), true), 'the tick ran userShowLimits');
            // samples: an older server's segment, then this one; a gap; medians per hour, nulls left out
            $probe = perfBProbe($fx, $roots);
            $here = $probe->fingerprint();
            $t0 = (int) (floor(time() / 3600) * 3600) - 5 * 3600;
            $rows = [];
            foreach ([[0, 'aaaaaaaaaaaaaaaa', 10], [900, 'aaaaaaaaaaaaaaaa', 30], [1800, 'aaaaaaaaaaaaaaaa', null], [3 * 3600, $here, 1], [3 * 3600 + 900, $here, 3], [3 * 3600 + 1800, $here, 2]] as $r) {
                $rows[] = json_encode(['ts' => gmdate('c', $t0 + $r[0]), 'fingerprint' => $r[1], 'io_full_avg10' => $r[2], 'cpu_busy_pct' => 50]);
            }
            file_put_contents($file, implode("\n", $rows) . "\nnot json\n");
            $recs = PerfSamples::samples($probe, ['hours' => 24]);
            $sum = $recs[0];
            check($sum['samples'] === 6 && count($sum['segments']) === 2, 'summary: ' . json_encode($sum));
            check($sum['segments'][0]['server'] === 'another server' && $sum['segments'][1]['server'] === 'this server' && $sum['segments'][0]['samples'] === 3, 'segments: ' . json_encode($sum['segments']));
            check($sum['gaps_total'] === 1 && $sum['gaps'][0]['minutes'] === 150, 'gaps: ' . json_encode($sum['gaps']));
            $hours = array_values(array_filter($recs, function ($r) {
                return $r['area'] === 'hours';
            }));
            check(count($hours) >= 2 && $hours[0]['segment'] === 1 && $hours[count($hours) - 1]['segment'] === 2, 'hour records: ' . json_encode($hours));
            check($hours[0]['hours'][0]['io_full_avg10'] === 20.0 && $hours[0]['hours'][0]['samples'] === 3, 'segment 1 median (null left out): ' . json_encode($hours[0]['hours'][0]));
            check($hours[count($hours) - 1]['hours'][0]['io_full_avg10'] === 2.0 && $hours[count($hours) - 1]['hours'][0]['disk_await_ms'] === null, 'segment 2 median: ' . json_encode($hours[count($hours) - 1]));
            $short = PerfSamples::samples($probe, ['hours' => 1]);
            check($short[0]['samples'] === 0 && isset($short[0]['notice']), 'nothing in the last hour must say so: ' . json_encode($short[0]));
        } finally {
            perfDone($fx);
        }
    },
];
