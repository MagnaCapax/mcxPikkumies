<?php
/**
 * lib/CronTick.php edges not covered by CronTickTest.php (ADR-0025): logIfChanged() resilience
 * (CRLF output, a corrupted state file) and setprivPresent()'s real PATH scan, which the existing
 * tests only ever exercise through $setprivPresentOverride.
 */
return [
    'cron-tick: logIfChanged() strips CRLF from the logged first line' => function () {
        $tmp = testTempDir('pk-tick-crlf');
        $log = "$tmp/update.log";
        $state = "$tmp/cron-tick.state";
        CronTick::logIfChanged($log, $state, 0, "line1\r\nline2\r\n", '2026-10-16T00:00:00Z');
        $logged = (string) file_get_contents($log);
        check(strpos($logged, 'line1') !== false, "the first line must be logged: $logged");
        check(strpos($logged, 'line2') === false, "only the FIRST line must be logged, not line2: $logged");
        check(strpos($logged, "\r") === false, "no carriage return must reach the log file: " . json_encode($logged));
        testRmTree($tmp);
    },
    'cron-tick: logIfChanged() with a corrupted (non-JSON) state file never crashes and still logs' => function () {
        $tmp = testTempDir('pk-tick-badstate');
        $log = "$tmp/update.log";
        $state = "$tmp/cron-tick.state";
        file_put_contents($state, 'not json at all {{{');
        // A half-written state.json from a crashed prior tick must be treated as "state unknown" (a
        // forced log line), not thrown on json_decode() returning null.
        CronTick::logIfChanged($log, $state, 0, '', '2026-10-16T00:00:00Z');
        check(strpos((string) file_get_contents($log), '(no jobs due)') !== false, 'a corrupted state file must still force a log line: ' . file_get_contents($log));
        check(json_decode((string) file_get_contents($state), true) !== null, 'the state file must be a valid JSON object after the tick');
        testRmTree($tmp);
    },
    "cron-tick: setprivPresent() real PATH scan finds an executable 'setpriv', rejects a non-executable one" => function () {
        $tmp = testTempDir('pk-setpriv');
        mkdir("$tmp/bin", 0700, true);
        file_put_contents("$tmp/bin/setpriv", "#!/bin/sh\nexit 0\n");
        chmod("$tmp/bin/setpriv", 0755);
        testWithEnv(['PATH' => "$tmp/bin"], function () {
            check(CronTick::setprivPresent() === true, 'an executable setpriv on PATH must be found');
        });
        chmod("$tmp/bin/setpriv", 0644);
        testWithEnv(['PATH' => "$tmp/bin"], function () {
            check(CronTick::setprivPresent() === false, 'a non-executable setpriv must not count as present');
        });
        testRmTree($tmp);
    },
    "cron-tick: setprivPresent() finds setpriv in the 2nd PATH directory after a miss in the 1st" => function () {
        $tmp = testTempDir('pk-setpriv2');
        mkdir("$tmp/empty", 0700, true);
        mkdir("$tmp/bin", 0700, true);
        file_put_contents("$tmp/bin/setpriv", "#!/bin/sh\nexit 0\n");
        chmod("$tmp/bin/setpriv", 0755);
        testWithEnv(['PATH' => "$tmp/empty:$tmp/bin"], function () {
            check(CronTick::setprivPresent() === true, 'setpriv in the second PATH directory must still be found');
        });
        testRmTree($tmp);
    },
];
