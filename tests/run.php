<?php
/**
 * Minimal test runner (no dependencies): php tests/run.php
 * Each tests/*Test.php returns an array of name => callable; a callable throws on failure.
 */
$root = dirname(__DIR__);
foreach (['Config', 'Detect', 'Provider', 'Packs', 'HermesConfig', 'Update'] as $class) {
    require_once $root . '/pikkumies/lib/' . $class . '.php';
}

function check($cond, $msg)
{
    if (!$cond) {
        throw new Exception($msg);
    }
}

$pass = 0;
$fail = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    foreach (require $file as $name => $fn) {
        try {
            $fn();
            $pass++;
            echo "ok   " . basename($file, '.php') . " :: $name\n";
        } catch (Throwable $e) {
            $fail++;
            echo "FAIL " . basename($file, '.php') . " :: $name: " . $e->getMessage() . "\n";
        }
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
