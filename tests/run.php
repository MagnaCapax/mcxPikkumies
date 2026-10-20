<?php
/**
 * Minimal test runner (no dependencies): php tests/run.php [Name ...] (only files whose name contains a word)
 * Each tests/*Test.php returns an array of name => callable; a callable throws on failure.
 */
$root = dirname(__DIR__);
// No test, and no pikkumies process a test spawns, may change the real crontab of whoever runs the suite:
// a `crontab` that always fails goes first on PATH. A test that puts its own fake earlier on PATH, or sets
// Update::$crontab, still gets its fake.
// The directory has an unguessable name and must be created fresh by this process, owned by it, and not a
// link; otherwise the suite stops, so a directory someone else made can neither go on PATH nor leave the
// real crontab reachable.
$cronGuard = sys_get_temp_dir() . '/pikkumies-test-crontab-' . bin2hex(random_bytes(8));
$guardStat = @mkdir($cronGuard, 0700) ? @lstat($cronGuard) : false;
if ($guardStat === false || is_link($cronGuard) || $guardStat['uid'] !== (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) || ($guardStat['mode'] & 0777) !== 0700
    || file_put_contents($cronGuard . '/crontab', "#!/bin/sh\necho 'crontab: not available inside the pikkumies test suite' >&2\nexit 1\n") === false
    || !chmod($cronGuard . '/crontab', 0700)) {
    fwrite(STDERR, "tests/run.php: could not create the private crontab guard in " . sys_get_temp_dir() . "; not running tests\n");
    exit(2);
}
putenv('PATH=' . $cronGuard . ':' . getenv('PATH'));
putenv('PIKKUMIES_TEST_CRONTAB_GUARD=' . $cronGuard);
register_shutdown_function(function () use ($cronGuard) {
    @unlink($cronGuard . '/crontab');
    @rmdir($cronGuard);
});
foreach (glob($root . '/pikkumies/lib/*.php') as $lib) {
    require_once $lib;
}

function check($cond, $msg)
{
    if (!$cond) {
        throw new Exception($msg);
    }
}

/** Thrown by skip(): the test could not run here; reported as "skip", never as "ok". */
class TestSkipped extends Exception
{
}

function skip($why)
{
    throw new TestSkipped($why);
}

require __DIR__ . '/support.php';

/** Run a PHP script with arguments and stdin; returns [exit code, stdout]. */
function runPhp($script, array $args, $stdin = '')
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache(); // the script may have changed files; a stale stat cache made a CI test see a deleted folder (991bd61)
    return [$code, $out];
}

$pass = 0;
$fail = 0;
$skipped = 0;
// php tests/run.php [Name ...]: run only the test files whose base name contains one of the words.
$only = array_slice($argv, 1);
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if ($only && !array_filter($only, function ($w) use ($file) {
        return stripos(basename($file, '.php'), $w) !== false;
    })) {
        continue;
    }
    foreach (require $file as $name => $fn) {
        try {
            $fn();
            $pass++;
            echo "ok   " . basename($file, '.php') . " :: $name\n";
        } catch (TestSkipped $e) {
            $skipped++;
            echo "skip " . basename($file, '.php') . " :: $name: " . $e->getMessage() . "\n";
        } catch (Throwable $e) {
            $fail++;
            echo "FAIL " . basename($file, '.php') . " :: $name: " . $e->getMessage() . "\n";
        }
    }
}
echo "\n$pass passed, $fail failed, $skipped skipped\n";
exit($fail === 0 ? 0 : 1);
