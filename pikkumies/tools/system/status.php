<?php
/**
 * status.php — read-only snapshot of this machine as the agent sees it (JSON).
 * Combines the detection profile with disk usage of $HOME. No writes, no network.
 */
require dirname(__DIR__, 2) . '/lib/Config.php';
require dirname(__DIR__, 2) . '/lib/Detect.php';

$profile = Detect::run();
$home = (string) getenv('HOME');
$profile['disk'] = [
    'home'    => $home,
    'freeGb'  => $home !== '' && is_dir($home) ? round(disk_free_space($home) / 1073741824, 1) : null,
    'totalGb' => $home !== '' && is_dir($home) ? round(disk_total_space($home) / 1073741824, 1) : null,
];
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
$profile['load'] = $load ? array_map(function ($v) {
    return round($v, 2);
}, $load) : null;

echo json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
