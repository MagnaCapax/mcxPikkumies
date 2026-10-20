<?php
/**
 * drives: health of the disks in this machine (tools/hardware/README.md, README.md here, ADR-0094). Needs root.
 * `smart` reads every disk (a sleeping one is not woken) and shows a screen, never a verdict; `selftest` starts the
 * drive's own test; `verify` starts a write pass on a standalone spinning disk, the only thing that gives a verdict.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Detect', 'ToolProc', 'OwnProcs'] as $drivesClass) {
    require_once "$lib/$drivesClass.php";
}
foreach (['Smart', 'Selftest', 'Rules', 'Store', 'Host', 'Pass', 'Next', 'Lesson', 'Run', 'Verify', 'Actions'] as $drivesClass) {
    require_once __DIR__ . "/Drives$drivesClass.php";
}

$usage = <<<'TXT'
usage: php drives.php <verb> [arguments] [--human]
  smart [--serial S]                          every disk: screen, counters, self-test, pass state, verdict, grade
  selftest <serial> short|long                starts the drive's own self-test (results: the next smart)
  verify <serial> [--with-data] [--fix-unreadable]
                                              a write pass (badblocks -n) on a standalone spinning disk
Needs root. Counters are a screen, not a verdict; only a completed write pass gives one. See USAGE.md.
TXT;

// Test seam: a test may set this before including the file (proc, sys, root, virt, local, telemetry). Nothing is read
// from the environment.
$drivesSeam = isset($drivesSeam) ? $drivesSeam : [];
$drivesHost = new DrivesHost($drivesSeam);
$drivesStore = new DrivesStore(isset($drivesSeam['local']) ? $drivesSeam['local'] : Config::localDir(),
    isset($drivesSeam['telemetry']) && $drivesSeam['telemetry'] === 'off' ? [] : null);

Cli::run($argv, [
    'smart' => function ($args, $opt) use ($drivesHost, $drivesStore) {
        return (new DrivesRun($drivesHost, $drivesStore))->smart($opt);
    },
    'selftest' => function ($args, $opt) use ($drivesHost, $drivesStore) {
        return (new DrivesActions($drivesHost, $drivesStore))->selftest($args);
    },
    'verify' => function ($args, $opt) use ($drivesHost, $drivesStore) {
        return (new DrivesActions($drivesHost, $drivesStore))->verify($args, $opt);
    },
], $usage, ['with-data', 'fix-unreadable'], ['serial']);
