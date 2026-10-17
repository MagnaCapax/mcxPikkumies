<?php
/**
 * Update::ensureScheduled(): the two tagged crontab lines come back after an account move (PMSS
 * userTransfer does not carry the crontab), nothing is written when they are already right, and a
 * failing `crontab -l` writes nothing. The crontab command is a fake under the test temp dir.
 */
function ensureFakeCrontab($tmp)
{
    file_put_contents("$tmp/crontab", "#!/bin/sh\nf=\"$tmp/tab\"\nif [ \"\$1\" = -l ]; then\n  [ -f \"$tmp/fail\" ] && { echo 'crontab: permission denied' >&2; exit 1; }\n  [ -f \"\$f\" ] && exec cat \"\$f\"\n  echo 'no crontab for u' >&2; exit 1\nfi\ncp \"\$1\" \"\$f\"\n");
    chmod("$tmp/crontab", 0700);
    Update::$crontab = "$tmp/crontab";
}

return [
    'update: ensureScheduled() restores both lines on an empty crontab, then is a no-op' => function () {
        $tmp = testTempDir('pk-ensure');
        ensureFakeCrontab($tmp);
        CronTick::$setprivPresentOverride = true;
        try {
            $msgs = Update::ensureScheduled(Config::defaults(), 1061, false, "$tmp/update.log");
            $tab = file_get_contents("$tmp/tab");
            check(count($msgs) === 2, 'expected two restored lines: ' . json_encode($msgs));
            check(substr_count($tab, Update::CRON_TAG) === 1 && substr_count($tab, Update::CRON_TICK_TAG) === 1, 'both lines once: ' . $tab);
            check(Update::ensureScheduled(Config::defaults(), 1061, false, "$tmp/update.log") === [], 'second call must change nothing');
            check(file_get_contents("$tmp/tab") === $tab, 'second call rewrote the crontab');
        } finally {
            CronTick::$setprivPresentOverride = null;
            Update::$crontab = 'crontab';
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    },
    'update: ensureScheduled() restores only the missing line and keeps foreign lines' => function () {
        $tmp = testTempDir('pk-ensure-one');
        ensureFakeCrontab($tmp);
        CronTick::$setprivPresentOverride = true;
        try {
            file_put_contents("$tmp/tab", "@reboot ~/start-rtorrent\n" . Update::cronLine(1061) . "\n");
            $msgs = Update::ensureScheduled(Config::defaults(), 1061, false, "$tmp/update.log");
            $tab = file_get_contents("$tmp/tab");
            check(count($msgs) === 1 && strpos($msgs[0], 'cron-tick') === 0, 'only the tick line was missing: ' . json_encode($msgs));
            check(strpos($tab, "@reboot ~/start-rtorrent\n") === 0, 'foreign line lost: ' . $tab);
            check(substr_count($tab, Update::CRON_TAG) === 1 && substr_count($tab, Update::CRON_TICK_TAG) === 1, 'lines: ' . $tab);
        } finally {
            CronTick::$setprivPresentOverride = null;
            Update::$crontab = 'crontab';
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    },
    'update: ensureScheduled() honours the off switches and writes nothing when crontab -l fails' => function () {
        $tmp = testTempDir('pk-ensure-off');
        ensureFakeCrontab($tmp);
        CronTick::$setprivPresentOverride = true;
        try {
            $off = Config::merge(Config::defaults(), ['autoUpdate' => ['disabled' => true], 'cron' => ['disabled' => true]]);
            check(Update::ensureScheduled($off, 1061, false, "$tmp/update.log") === [], 'both off on an empty crontab: nothing to do');
            check(!is_file("$tmp/tab"), 'off must not create a crontab');
            file_put_contents("$tmp/tab", Update::cronLine(1061) . "\n" . Update::cronTickLine() . "\n");
            Update::ensureScheduled($off, 1061, false, "$tmp/update.log");
            $tab = file_get_contents("$tmp/tab");
            check(strpos($tab, Update::CRON_TAG) === false && strpos($tab, Update::CRON_TICK_TAG) === false, 'off must remove both lines: ' . $tab);
            touch("$tmp/fail");
            check(Update::ensureScheduled(Config::defaults(), 1061, false, "$tmp/update.log") === [], 'a failing crontab -l must report nothing');
            check(file_get_contents("$tmp/tab") === $tab, 'a failing crontab -l must write nothing');
        } finally {
            CronTick::$setprivPresentOverride = null;
            Update::$crontab = 'crontab';
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    },
];
