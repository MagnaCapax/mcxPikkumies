<?php
/**
 * system-status: read-only snapshot of this machine as the agent sees it (ADR-0015 tool unit).
 * The cached classification (host family, stack, root access, own running apps, tier, ADR-0023)
 * plus this moment's own limits, read live through the same sources the cache recorded, with the
 * limit closest to its own cap listed first (round 28 of the vplan: answer "can we increase
 * performance" with the real bottleneck, not whichever key happens to be declared first). No
 * writes, no network.
 */
require dirname(__DIR__, 3) . '/lib/Detect.php';
require_once dirname(__DIR__, 3) . '/lib/Cgroup.php'; // Detect.php already require_once's this; once here too, so a direct `php system-status.php` still finds the class
require dirname(__DIR__, 3) . '/lib/Cli.php';
require dirname(__DIR__, 3) . '/lib/CliHuman.php';

Cli::run($argv, [
    'show' => function () {
        $profile = Detect::cached();
        // ADR-0023: hostnames and reverse DNS stay in local/host.json; this record goes to the model.
        unset($profile['identity'], $profile['fingerprint']);
        $home = (string) getenv('HOME');
        $limits = Detect::limits($profile['family'], $home, !empty($profile['tenancy']['shared']));
        $profile['limits'] = Detect::leadingLimit($limits, Cgroup::memoryCurrent());
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
        $profile['load'] = $load ? array_map(function ($v) {
            return round($v, 2);
        }, $load) : null;
        return [$profile];
    },
], "usage: system-status.php show [--human]\n  show  one record: cached classification (host family, stack, tier, root access), own running apps, live limits (CPU, memory, disk), load");
