<?php
/**
 * Helpers for the check-stage tests (PerformanceCheckTest, PerformancePrivacyTest, PerformanceRouterTest): the
 * verb classes loaded in this process, a PerfProbe on a fixture tree, `check` run in-process with the two samples
 * taken back to back (PerfHost::$windowS = 0), fake processes, and a set of router facts in which no rule fires.
 */
require_once __DIR__ . '/harness.php';
$perfUnit = dirname(__DIR__, 3) . '/pikkumies/tools/system/performance';
require_once "$perfUnit/PerfProbe.php";
foreach (['PerfAccount', 'PerfHost', 'PerfProcs', 'PerfSessions', 'PerfRouter', 'PerfSamples'] as $perfClass) {
    require_once "$perfUnit/$perfClass.php";
}

const PERF_B_SSH = '198.51.100.7 50123 192.0.2.10 22';

function perfBNeedWrappers()
{
    foreach (['nice', 'ionice', 'timeout'] as $p) {
        if (ToolProc::which($p) === null) {
            skip("$p is not installed here");
        }
    }
}

function perfBProbe(array $fx, array $roots, array $extra = [])
{
    return new PerfProbe($extra + ['local_dir' => $fx['local'], 'home' => $fx['home'], 'uid' => PERF_FIXTURE_UID, 'roots' => $roots,
        'read_log' => $fx['dir'] . '/run/read.jsonl', 'fingerprint' => 'fixture-host|fixture-machine|' . PERF_FIXTURE_UID]);
}

/**
 * check in this process: returns [records, probe]. $env: SSH_CONNECTION etc. (null unsets); the fakes come first on
 * PATH and log to the fixture's fake log.
 */
function perfBCheck(array $fx, array $roots, array $params = [], array $env = [], array $extra = [])
{
    $env += ['SSH_CONNECTION' => PERF_B_SSH, 'PATH' => $fx['bin'] . ':' . getenv('PATH'), 'PK_PERF_FAKE_LOG' => $fx['log']];
    $old = PerfHost::$windowS;
    PerfHost::$windowS = 0;
    try {
        return testWithEnv($env, function () use ($fx, $roots, $params, $extra) {
            $probe = perfBProbe($fx, $roots, $extra);
            $recs = PerfRouter::check($probe, $params + ['symptom' => 'everything', 'share' => false, 'with_ip' => false]);
            return [$recs, $probe];
        });
    } finally {
        PerfHost::$windowS = $old;
    }
}

/** The records as the tool prints them (one JSON line each, cleaned as Cli does). */
function perfBJson(array $records)
{
    return implode("\n", array_map(function ($r) {
        return Cli::json(Cli::clean($r));
    }, $records));
}

/** The record of $area, or null. */
function perfBArea(array $records, $area)
{
    foreach ($records as $r) {
        if (isset($r['area']) && $r['area'] === $area) {
            return $r;
        }
    }
    return null;
}

/** Paths in the fixture's read log, by kind. */
function perfBReadLog(array $fx, $kind = 'read')
{
    $file = $fx['dir'] . '/run/read.jsonl';
    $out = [];
    foreach (is_file($file) ? cliLines((string) file_get_contents($file)) : [] as $l) {
        if ($l['kind'] === $kind) {
            $out[] = $l['path'];
        }
    }
    return $out;
}

/** A fake process in the tree: /proc/<pid>/status (and smaps_rollup, cmdline when given). */
function perfBProc(array $roots, $pid, $uid, $name, $state = 'S', $rssKb = 1024, $cmdline = null)
{
    $dir = $roots['/proc'] . "/$pid";
    perfWrite("$dir/status", "Name:\t$name\nUmask:\t0022\nState:\t$state (x)\nTgid:\t$pid\nPid:\t$pid\nPPid:\t1\nUid:\t$uid\t$uid\t$uid\t$uid\nGid:\t$uid\t$uid\t$uid\t$uid\nVmRSS:\t$rssKb kB\nVmSwap:\t0 kB\n");
    perfWrite("$dir/smaps_rollup", "Rss:  $rssKb kB\nPss:  " . intdiv($rssKb, 2) . " kB\n");
    if ($cmdline !== null) {
        perfWrite("$dir/cmdline", implode("\0", $cmdline) . "\0");
    }
}

/** Router facts in which no rule finds its evidence. */
function perfBFacts(array $over = [])
{
    $f = [
        'symptom' => 'everything',
        'memory' => ['limit_bytes' => 2147483648, 'anon_pct_of_limit' => 25.0, 'anon_pct_first_sample' => 25.0, 'oom_kills_during_check' => 0, 'platform_status' => 'OK'],
        'cpu' => ['quota_cores' => 2.0, 'own_use_cores' => 0.2, 'throttled_pct' => 0.0],
        'io' => ['caps' => [['device' => '8:0', 'rbps' => 104857600.0]], 'own_rate' => ['8:0' => ['read_bps' => 1048576, 'write_bps' => 0]]],
        'traffic' => [['metric' => 'upload past 30 days', 'used_bytes' => 1e12, 'limit_bytes' => 5e12, 'used_pct' => 20.0]],
        'host' => ['pressure' => ['cpu' => ['level' => 'some', 'avg10' => 5.0, 'avg60' => 4.0], 'io' => ['level' => 'full', 'avg10' => 2.0, 'avg60' => 1.0],
            'memory' => ['level' => 'full', 'avg10' => 0.0, 'avg60' => 0.0]], 'cpu_busy_pct' => 30.0, 'cpu_steal_pct' => 0.0, 'array_maintenance' => null, 'link_speed_mbit' => 10000],
        'procs' => ['processes' => 5, 'waiting_on_disk' => 0, 'groups' => ['rtorrent' => ['processes' => 1, 'rss_bytes' => 1, 'swap_bytes' => 0, 'pss_bytes' => null, 'waiting_on_disk' => 0]], 'stream_hints' => []],
        'sessions' => ['visible' => true, 'reason' => null, 'active' => 2, 'sessions' => [
            ['service' => 'ssh', 'rtt_ms' => 20.0, 'rwnd_limited_pct' => 1.0, 'busy_ms' => 4000, 'retrans' => 0, 'delivery_mbit' => 50.0, 'window_bound_mbit' => 400.0, 'direction' => 'from server'],
            ['service' => 'ssh', 'rtt_ms' => 20.0, 'rwnd_limited_pct' => 0.0, 'busy_ms' => 4000, 'retrans' => 0, 'delivery_mbit' => 40.0, 'window_bound_mbit' => 400.0, 'direction' => 'from server'],
        ]],
    ];
    return array_replace_recursive($f, $over);
}

function perfBClasses(array $facts)
{
    return array_column(PerfRouter::classify($facts), 'class');
}
