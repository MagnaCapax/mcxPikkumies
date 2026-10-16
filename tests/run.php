<?php
/**
 * Minimal test runner (no dependencies): php tests/run.php
 * Each tests/*Test.php returns an array of name => callable; a callable throws on failure.
 */
$root = dirname(__DIR__);
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
foreach (glob(__DIR__ . '/*Test.php') as $file) {
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
