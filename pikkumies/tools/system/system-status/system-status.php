<?php
/**
 * system-status: read-only snapshot of this machine as the agent sees it (ADR-0015 tool unit).
 * The detection profile (host family, root access, own running apps, resources) plus the load
 * average. No writes, no network.
 */
require dirname(__DIR__, 3) . '/lib/Detect.php';
require dirname(__DIR__, 3) . '/lib/Cli.php';
require dirname(__DIR__, 3) . '/lib/CliHuman.php';

Cli::run($argv, [
    'show' => function () {
        $profile = Detect::run();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
        $profile['load'] = $load ? array_map(function ($v) {
            return round($v, 2);
        }, $load) : null;
        return [$profile];
    },
], "usage: system-status.php show [--human]\n  show  one record: OS, user, host, root access, own running apps, CPUs, memory, home disk, load");
