<?php
/**
 * Shared by the fake programs of the performance tests (copied next to them by tests/fixtures/performance/harness.php).
 * Each fake appends its argv to $PK_PERF_FAKE_LOG (JSON Lines), prints the file named by
 * $PK_PERF_FAKE_<NAME>_OUT when set (so a test supplies its own output without editing the fake), otherwise its
 * default output, and exits with $PK_PERF_FAKE_<NAME>_RC (default 0). <NAME> is the program name upper-cased with
 * non-alphanumerics as _ (userShowLimits -> USERSHOWLIMITS).
 */
function perfFakeKey($name)
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $name));
}

function perfFakeLog($name, array $argv)
{
    $log = getenv('PK_PERF_FAKE_LOG');
    if (is_string($log) && $log !== '') {
        file_put_contents($log, json_encode(['prog' => $name, 'argv' => array_slice($argv, 1)], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}

/** Log, print the output (stdout, or stderr when $toStderr), exit. */
function perfFake($name, array $argv, $default, $toStderr = false)
{
    perfFakeLog($name, $argv);
    $key = perfFakeKey($name);
    $file = getenv("PK_PERF_FAKE_{$key}_OUT");
    $out = is_string($file) && $file !== '' && is_file($file) ? (string) file_get_contents($file) : $default;
    fwrite($toStderr ? STDERR : STDOUT, $out);
    $rc = getenv("PK_PERF_FAKE_{$key}_RC");
    exit(is_string($rc) && ctype_digit($rc) ? (int) $rc : 0);
}

/**
 * $PK_PERF_FAKE_MODE=hang: start a grandchild whose command line carries $PK_PERF_FAKE_MARK (so a test can look for
 * it after a deadline), then sleep, as a program that never finishes would. Used to prove a deadline kills children.
 */
function perfFakeHang()
{
    if (getenv('PK_PERF_FAKE_MODE') !== 'hang') {
        return;
    }
    $mark = (string) getenv('PK_PERF_FAKE_MARK');
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('sleep(60);') . ' ' . escapeshellarg('pk-perf-mark-' . $mark) . ' >/dev/null 2>&1 &');
    sleep(60);
    exit(0);
}
