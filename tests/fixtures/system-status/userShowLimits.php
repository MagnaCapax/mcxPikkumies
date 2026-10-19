<?php
/**
 * A PMSS `~/bin/userShowLimits --json` answer for the system-status account tests, in the shape of PMSS
 * etc/skel/bin/userShowLimits at 9d84b74c: pmssUserShowLimitsCollect returns user, host, generated_at and sections
 * (:272); each metric is metric, value, limit, unit, percent, source (pmssUserShowLimitsMetric, :20-28). Every
 * metric name below is the one PMSS writes (account :129-136, disk :140-151, memory :157-164, processes :173-177,
 * cpu :182-202, traffic :231-234, server :238-257), including the account and server names that
 * Detect::userShowLimitsData must drop. Values are made up.
 */
$m = function ($metric, $value, $limit, $unit, $source) {
    return ['metric' => $metric, 'value' => $value, 'limit' => $limit, 'unit' => $unit,
        'percent' => $limit !== null && is_numeric($value) ? round($value * 100 / $limit, 1) : null, 'source' => $source];
};
$gib = 1073741824;
return ['user' => 'fixture-acct-name', 'host' => 'fixture-srv-name.example', 'generated_at' => 1792224000, 'sections' => [
    'account' => ['available' => true, 'metrics' => [
        $m('username', 'fixture-acct-name', null, '', 'proc'),
        $m('hostname', 'fixture-srv-name.example', null, '', 'proc'),
        $m('PMSS version', '2026-10-01', null, '', 'proc'),
        $m('Debian version', '12.7', null, '', 'proc'),
        $m('bonus', 10.0, null, 'percent', 'account-config'),
        $m('Docker enabled', true, null, 'bool', 'account-config'),
    ]],
    'disk' => ['available' => true, 'metrics' => [
        $m('quota configured', 2000.0 * $gib, null, 'bytes', 'account-config'),
        $m('quotaBurst configured', 2500.0 * $gib, null, 'bytes', 'account-config'),
        $m('blocks used', 1200.0 * $gib, 2000.0 * $gib, 'bytes', 'quota-snapshot'),
        $m('blocks soft', 2000.0 * $gib, null, 'bytes', 'quota-snapshot'),
        $m('blocks hard', 2500.0 * $gib, null, 'bytes', 'quota-snapshot'),
        $m('files used', 120000.0, 2000000.0, 'count', 'quota-snapshot'),
        $m('files soft', 2000000.0, null, 'count', 'quota-snapshot'),
        $m('files hard', 2500000.0, null, 'count', 'quota-snapshot'),
        $m('blocks against hard', 1200.0 * $gib, 2500.0 * $gib, 'bytes', 'quota-snapshot'),
        $m('files against hard', 120000.0, 2500000.0, 'count', 'quota-snapshot'),
        $m('snapshot age', 240.0, null, 'seconds', 'quota-snapshot'),
    ]],
    'memory' => ['available' => true, 'metrics' => [
        $m('ramMiB configured', 4096.0 * 1048576, null, 'bytes', 'account-config'),
        $m('memory', 1610612736.0, 4294967296.0, 'bytes', 'cgroup'),
        $m('memory pressure', 3.5, 100, 'percent', 'cgroup'),
        $m('memory status', 'OK', null, '', 'cgroup'),
        $m('OOM kills', 0.0, null, 'count', 'cgroup'),
        $m('RAM GB-hours past 30 days', 1500.0, null, 'gib-hours', 'resource-data'),
    ]],
    'processes' => ['available' => true, 'metrics' => [
        $m('processes', 120.0, 2000.0, 'count', 'cgroup'),
        $m('soft open files', 1024.0, null, 'count', 'rlimit'),
        $m('hard open files', 4096.0, null, 'count', 'rlimit'),
    ]],
    'cpu' => ['available' => true, 'metrics' => [
        $m('CPUWeight configured', 100.0, null, 'count', 'account-config'),
        $m('cpuQuotaPercent configured', 200.0, null, 'percent', 'account-config'),
        $m('CPU weight enforced', '100', null, 'count', 'cgroup'),
        $m('CPU quota enforced', 200.0, null, 'percent', 'cgroup'),
        $m('CPU hours past 30 days', 300.5, null, 'hours', 'resource-data'),
    ]],
    'disk I/O' => ['available' => true, 'metrics' => [
        $m('I/O weight enforced', '100', null, 'count', 'cgroup'),
        $m('read bps enforced', '104857600', null, 'bytes_per_second', 'cgroup'),
    ]],
    'traffic' => ['available' => true, 'metrics' => [
        $m('upload past 30 days', 1024.0 * $gib, 5120.0 * $gib, 'bytes', 'traffic-data'),
        $m('inbound past 30 days', 512.0 * $gib, null, 'bytes', 'traffic-data'),
        $m('bonus traffic', 100.0 * $gib, null, 'bytes', 'traffic-data'),
        $m('trafficCapMbit configured', 1000.0, null, 'mbit', 'account-config'),
    ]],
    'server (shared by all accounts)' => ['available' => true, 'metrics' => [
        $m('load average (run queue) 1m', '3.10', null, 'count', 'proc'),
        $m('memory total', 68719476736.0, null, 'bytes', 'proc'),
        $m('server /home (shared by all accounts) free', 40000.0 * $gib, null, 'bytes', 'filesystem'),
    ]],
]];
