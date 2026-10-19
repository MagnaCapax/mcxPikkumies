<?php
/**
 * CronTick::logIfChanged (lib/CronTick.php, ADR-0024 addendum): local/update.log gets a new line
 * only when a tick's outcome (exit code, or whether it printed anything at all) differs from the
 * last recorded one; an idle tick that keeps repeating the same outcome never appends again, and
 * the state file that remembers "the last outcome" is overwritten, never grown, each tick (D168).
 * tests/CronTickIdleTest.php, CronTickTest.php and CronTickEdgeTest.php belong to another builder's
 * batch and do not call logIfChanged() at all, so this lives in its own file.
 */
return [
    'CronTick::logIfChanged: a repeated idle outcome appends no new log line; the state file holds one object, not a growing list' => function () {
        $dir = testTempDir('pk-crontick-log');
        $logFile = "$dir/update.log";
        $stateFile = "$dir/state/tick.json";
        CronTick::logIfChanged($logFile, $stateFile, 0, '', '2026-01-01T00:00:00Z');
        check(is_file($logFile), 'the first tick (nothing recorded yet) must always log');
        $firstLog = file_get_contents($logFile);
        check(strpos($firstLog, '(no jobs due)') !== false, 'an idle tick with no output must say so: ' . $firstLog);
        check(substr_count($firstLog, "\n") === 1, 'the first tick must write exactly one line: ' . $firstLog);
        // Two more idle ticks, same code (0) and same "no output": nothing new must be appended.
        CronTick::logIfChanged($logFile, $stateFile, 0, '', '2026-01-01T00:01:00Z');
        CronTick::logIfChanged($logFile, $stateFile, 0, '', '2026-01-01T00:02:00Z');
        check(file_get_contents($logFile) === $firstLog, 'a repeated idle outcome must never append another line: ' . file_get_contents($logFile));
        $state = json_decode((string) file_get_contents($stateFile), true);
        check($state === ['code' => 0, 'hadOutput' => false], 'the state file must hold exactly one current-outcome object: ' . json_encode($state));
    },
    'CronTick::logIfChanged: a changed exit code appends a new line; output always appends even when the code repeats' => function () {
        $dir = testTempDir('pk-crontick-log-change');
        $logFile = "$dir/update.log";
        $stateFile = "$dir/state/tick.json";
        CronTick::logIfChanged($logFile, $stateFile, 0, '', '2026-02-01T00:00:00Z');
        CronTick::logIfChanged($logFile, $stateFile, 1, '', '2026-02-01T00:01:00Z');
        $lines = explode("\n", trim((string) file_get_contents($logFile)));
        check(count($lines) === 2, 'a changed exit code must append a second line: ' . json_encode($lines));
        check(strpos($lines[1], 'exit 1') !== false, 'the new line must name the new exit code: ' . $lines[1]);
        // Same code (1) again, but this time with output: hadOutput changed false->true, so it logs again,
        // and the printed line carries the first line of that output.
        CronTick::logIfChanged($logFile, $stateFile, 1, "something ran\nmore detail", '2026-02-01T00:02:00Z');
        $lines = explode("\n", trim((string) file_get_contents($logFile)));
        check(count($lines) === 3 && strpos($lines[2], 'something ran') !== false, 'output on an otherwise-unchanged code must still append, using its first line: ' . json_encode($lines));
        // Same code AND output present again: hadOutput stays true, this is logged every time it has output
        // (not deduplicated the way a silent idle tick is) per the function's own "if ($changed || $hadOutput)" rule.
        CronTick::logIfChanged($logFile, $stateFile, 1, 'something ran again', '2026-02-01T00:03:00Z');
        $lines = explode("\n", trim((string) file_get_contents($logFile)));
        check(count($lines) === 4, 'a tick that has output logs every time, even with an unchanged code: ' . json_encode($lines));
        $state = json_decode((string) file_get_contents($stateFile), true);
        check($state === ['code' => 1, 'hadOutput' => true], 'the state file must reflect only the latest tick, not every one seen: ' . json_encode($state));
    },
];
