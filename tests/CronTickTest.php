<?php
/** lib/CronTick.php: the setpriv-wrapped per-minute tick (ADR-0025). */
return [
    'cron-tick: builds the setpriv-wrapped argv when setpriv exists' => function () {
        CronTick::$setprivPresentOverride = true;
        $argv = CronTick::argv('/h/bin/hermes', false);
        check($argv === ['setpriv', '--no-new-privs', '/h/bin/hermes', 'cron', 'tick'], 'setpriv wrapper missing: ' . json_encode($argv));
        check(CronTick::argv('/h/bin/hermes', true) === $argv, 'root-available must not change the wrapped argv when setpriv exists');
        CronTick::$setprivPresentOverride = null;
    },
    'cron-tick: runs bare when setpriv is missing and there is no root/sudo to drop' => function () {
        CronTick::$setprivPresentOverride = false;
        CronTick::$mightEscalateOverride = false; // not root, in no sudo group (the test host may be either)
        $argv = CronTick::argv('/h/bin/hermes', false);
        check($argv === ['/h/bin/hermes', 'cron', 'tick'], 'bare argv expected: ' . json_encode($argv));
        CronTick::$setprivPresentOverride = null;
        CronTick::$mightEscalateOverride = null;
    },
    'cron-tick: refuses on a root-available host with no setpriv to run it under' => function () {
        CronTick::$setprivPresentOverride = false;
        check(CronTick::refuses(true), 'must refuse: root available, no setpriv');
        check(CronTick::argv('/h/bin/hermes', true) === [], 'argv must be empty ([]) on refusal, never a bare command');
        CronTick::$mightEscalateOverride = false;
        check(!CronTick::refuses(false), 'must not refuse when there is no root to protect against');
        // A stale or edited cache that says "no root" must not win over the OS (ADR-0004 addendum).
        CronTick::$mightEscalateOverride = true;
        check(CronTick::refuses(false), 'must refuse when the OS says this account can escalate, whatever the cache says');
        check(CronTick::argv('/h/bin/hermes', false) === [], 'argv must be empty when the live check refuses');
        CronTick::$setprivPresentOverride = true;
        check(!CronTick::refuses(true) && CronTick::argv('/h/bin/hermes', true)[0] === 'setpriv', 'with setpriv present it always runs, wrapped');
        CronTick::$setprivPresentOverride = null;
        CronTick::$mightEscalateOverride = null;
    },
    'cron-tick: logs a refusal once, not every call' => function () {
        $log = sys_get_temp_dir() . '/pk-tick-refuse-' . getmypid() . '.log';
        @unlink($log);
        CronTick::logRefusalOnce($log);
        $first = file_get_contents($log);
        check(strpos($first, 'refused: root available, no setpriv') !== false, 'refusal line missing: ' . $first);
        CronTick::logRefusalOnce($log);
        check(file_get_contents($log) === $first, 'a second refusal must not append another line');
        unlink($log);
    },
    'cron-tick: logs only on a changed outcome, never a repeated idle tick' => function () {
        $tmp = sys_get_temp_dir() . '/pk-tick-' . getmypid();
        @mkdir($tmp, 0700, true);
        $log = "$tmp/update.log";
        $state = "$tmp/cron-tick.state";
        CronTick::logIfChanged($log, $state, 0, '', '2026-10-15T00:00:00Z');
        check(strpos(file_get_contents($log), '(no jobs due)') !== false, 'first idle tick must log once: ' . file_get_contents($log));
        $afterFirst = file_get_contents($log);
        CronTick::logIfChanged($log, $state, 0, '', '2026-10-15T00:01:00Z');
        check(file_get_contents($log) === $afterFirst, 'a repeated idle tick must not log again: ' . file_get_contents($log));
        CronTick::logIfChanged($log, $state, 0, "job x: blocked_config\n", '2026-10-15T00:02:00Z');
        check(strpos(file_get_contents($log), 'job x: blocked_config') !== false, 'a tick with output must log: ' . file_get_contents($log));
        CronTick::logIfChanged($log, $state, 1, '', '2026-10-15T00:03:00Z');
        check(strpos(file_get_contents($log), '[cron-tick] exit 1 (no jobs due)') !== false, 'an exit-code change must log even with no output: ' . file_get_contents($log));
        exec('rm -rf ' . escapeshellarg($tmp));
    },
];
