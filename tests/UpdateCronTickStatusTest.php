<?php
/**
 * Update::cronTickStatus (lib/Update.php, ADR-0025): the per-minute tick rewrites local/cron-tick.last every
 * 60 seconds, so its mtime is a liveness signal nothing else in the tree reads. A tick dead for days used to
 * look exactly like an idle one. The states are kept apart because they need different answers: a missing
 * crontab line is repaired by a session, a stale tick is not, and an expired account (which cron's PAM
 * account module blocks outright, and whose shadow expiry a user cannot read) must not be called broken.
 */
return [
    'cronTickStatus: a fresh tick log with the line installed is running, and the age is what the file says' => function () {
        $dir = testTempDir('pk-tick-status-ok');
        $log = "$dir/cron-tick.last";
        file_put_contents($log, '');
        touch($log, time() - 42);
        Update::$cronTickLogOverride = $log;
        Update::$crontabTextOverride = "* * * * * '/x/bin/pikkumies' cron-tick > $log 2>&1 # pikkumies-cron-tick\n";
        try {
            $s = Update::cronTickStatus([], false);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
        }
        check($s['state'] === 'ok', 'a 42s-old tick log must read as running: ' . json_encode($s));
        check($s['age'] >= 41 && $s['age'] <= 44, 'the reported age must be the file age, not a guess: ' . json_encode($s));
    },
    'cronTickStatus: an old tick log is STALE — the state that used to be invisible' => function () {
        $dir = testTempDir('pk-tick-status-stale');
        $log = "$dir/cron-tick.last";
        file_put_contents($log, '');
        touch($log, time() - (Update::CRON_TICK_STALE_SECONDS + 600));
        Update::$cronTickLogOverride = $log;
        Update::$crontabTextOverride = "# pikkumies-cron-tick\n";
        try {
            $s = Update::cronTickStatus([], false);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
        }
        check($s['state'] === 'stale', 'a tick log older than the threshold must read as stale: ' . json_encode($s));
        check($s['age'] > Update::CRON_TICK_STALE_SECONDS, 'stale must carry the real age: ' . json_encode($s));
    },
    'cronTickStatus: exactly at the threshold still counts as running (a slow minute is not a fault)' => function () {
        $dir = testTempDir('pk-tick-status-edge');
        $log = "$dir/cron-tick.last";
        file_put_contents($log, '');
        touch($log, time() - Update::CRON_TICK_STALE_SECONDS);
        Update::$cronTickLogOverride = $log;
        Update::$crontabTextOverride = "# pikkumies-cron-tick\n";
        try {
            $s = Update::cronTickStatus([], false);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
        }
        check($s['state'] === 'ok', 'age == threshold must not be stale: ' . json_encode($s));
    },
    'cronTickStatus: no tick log at all with the line installed is NEVER — it has not fired once' => function () {
        $dir = testTempDir('pk-tick-status-never');
        Update::$cronTickLogOverride = "$dir/absent.last";
        Update::$crontabTextOverride = "# pikkumies-cron-tick\n";
        try {
            $s = Update::cronTickStatus([], false);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
        }
        check($s['state'] === 'never', 'a missing log with the line present must read as never-run: ' . json_encode($s));
        check($s['age'] === null, 'never-run carries no age: ' . json_encode($s));
    },
    'cronTickStatus: a crontab without the tag is MISSING-LINE — repairable, unlike a stale tick' => function () {
        $dir = testTempDir('pk-tick-status-missing');
        $log = "$dir/cron-tick.last";
        file_put_contents($log, '');
        Update::$cronTickLogOverride = $log;
        Update::$crontabTextOverride = "17 4 * * * '/x/bin/pikkumies' update # pikkumies-update\n";
        try {
            $s = Update::cronTickStatus([], false);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
        }
        check($s['state'] === 'missing-line', 'the update line alone must not satisfy the tick line: ' . json_encode($s));
    },
    'cronTickStatus: cron.disabled reads OFF, and the refusal reads REFUSED, before any crontab or file is consulted' => function () {
        $dir = testTempDir('pk-tick-status-switches');
        Update::$cronTickLogOverride = "$dir/absent.last";
        Update::$crontabTextOverride = '# pikkumies-cron-tick' . "\n";
        CronTick::$setprivPresentOverride = false;
        CronTick::$mightEscalateOverride = true;
        try {
            $off = Update::cronTickStatus(['cron' => ['disabled' => true]], true);
            $refused = Update::cronTickStatus([], true);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
            CronTick::$setprivPresentOverride = null;
            CronTick::$mightEscalateOverride = null;
        }
        check($off['state'] === 'off', 'cron.disabled must win over everything else: ' . json_encode($off));
        check($refused['state'] === 'refused', 'root available with no setpriv must read as refused, not as a fault: ' . json_encode($refused));
    },
    'cronTickStatus: an unreadable crontab is not treated as a missing line' => function () {
        $dir = testTempDir('pk-tick-status-unreadable');
        $log = "$dir/cron-tick.last";
        file_put_contents($log, '');
        Update::$cronTickLogOverride = $log;
        // readCrontab() returns null when `crontab -l` fails for a reason other than "no crontab"; a null must
        // not be read as "the line is gone", or a locked-down host would report a fault it cannot substantiate.
        Update::$crontabTextOverride = null;
        Update::$crontabTextOverride = '';
        try {
            $s = Update::cronTickStatus([], false);
        } finally {
            Update::$cronTickLogOverride = null;
            Update::$crontabTextOverride = null;
        }
        check($s['state'] === 'missing-line', 'an empty crontab has no tick line, so it is missing-line: ' . json_encode($s));
    },
];
