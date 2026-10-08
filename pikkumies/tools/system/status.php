<?php
/**
 * status.php — read-only snapshot of this machine as the agent sees it (JSON).
 * The detection profile (resources include free and total disk of $HOME) plus the load average.
 * No writes, no network.
 */
require dirname(__DIR__, 2) . '/lib/Detect.php';

$profile = Detect::run();
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
$profile['load'] = $load ? array_map(function ($v) {
    return round($v, 2);
}, $load) : null;

echo json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
