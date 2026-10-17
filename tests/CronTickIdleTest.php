<?php
/**
 * The per-minute tick starts no Python process when Hermes has no job (security review 2026-10-17, F7: an idle
 * `hermes cron tick` measured ~67 MB RSS, ADR-0024 addendum, every minute for every account), and runs at the lowest
 * CPU and I/O priority when it does. The jobs.json shapes are those Hermes' cron/jobs.py load_jobs accepts.
 */
return [
    'cron-tick idle: no jobs.json or an empty job list starts nothing; any job or an unreadable file is left to Hermes' => function () {
        $tmp = testTempDir('pk-tick-idle');
        check(CronTick::hasJobs($tmp) === false, 'no cron dir: no jobs');
        mkdir("$tmp/cron");
        foreach (['{"jobs": [], "updated_at": "x"}', '[]', '{"jobs": {}}'] as $empty) {
            file_put_contents("$tmp/cron/jobs.json", $empty);
            check(CronTick::hasJobs($tmp) === false, "empty list must skip: $empty");
        }
        foreach (['{"jobs": [{"id": "a1", "schedule": "0 3 * * *"}]}', '[{"id": "a1"}]', '{"jobs": {"a1": {"prompt": "x"}}}', 'not json {'] as $some) {
            file_put_contents("$tmp/cron/jobs.json", $some);
            check(CronTick::hasJobs($tmp) === true, "must run Hermes for: $some");
        }
        testRmTree($tmp);
    },
    'cron-tick idle: nice and ionice wrap the tick when they are on PATH, and only from absolute PATH entries' => function () {
        $tmp = testTempDir('pk-tick-nice');
        mkdir("$tmp/bin");
        foreach (['nice', 'ionice'] as $b) {
            file_put_contents("$tmp/bin/$b", "#!/bin/sh\n");
            chmod("$tmp/bin/$b", 0755);
        }
        testWithEnv(['PATH' => "$tmp/bin"], function () {
            check(CronTick::lowPriority(['setpriv', '--no-new-privs', 'h', 'cron', 'tick']) === ['nice', '-n', '19', 'ionice', '-c', '3', 'setpriv', '--no-new-privs', 'h', 'cron', 'tick'], 'both prefixes');
        });
        testWithEnv(['PATH' => "$tmp/empty"], function () {
            check(CronTick::lowPriority(['h', 'cron', 'tick']) === ['h', 'cron', 'tick'], 'neither on PATH: unchanged');
        });
        testRmTree($tmp);
    },
    'cron-tick idle: the cron-tick command checks for jobs before it takes the Hermes lock or starts anything' => function () {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $start = strpos($src, "case 'cron-tick':");
        $tick = substr($src, $start, strpos($src, "case 'tmux':") - $start);
        $skip = strpos($tick, 'CronTick::hasJobs(HermesConfig::home())');
        check($skip !== false && $skip < strpos($tick, 'HermesInstall::lock(') && $skip < strpos($tick, 'exec('), 'hasJobs must come first');
        check(strpos($tick, 'CronTick::lowPriority($tickArgv)') !== false, 'the tick runs through lowPriority');
    },
];
