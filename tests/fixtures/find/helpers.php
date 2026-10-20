<?php
/**
 * Helpers for tests/FindTest.php: a fixture root of fake local units, and a wrapper that runs find.php
 * with the test seams set (the same pattern as tests/fixtures/qbittorrent/harness.php's qbitRun()).
 */

/** A root directory with one unit per key of $units (name => its `list` answer as PHP source). */
function findFixtureRoot(array $units)
{
    $root = testTempDir('pk-find');
    foreach ($units as $name => $src) {
        mkdir("$root/$name", 0700, true);
        file_put_contents("$root/$name/$name.php", $src);
    }
    return $root;
}

/**
 * Runs find.php against $root with the test seams set. $extraPhp is raw PHP (function definitions and
 * $findGet/$findFanout/$findJsonl/$findAll assignments) inserted before find.php is required, so a test
 * can inject fake remote-service callables without lib/Services.php existing anywhere.
 */
function findRun($root, array $args, $extraPhp = '', $timeout = 5)
{
    $dir = testTempDir('pk-find-run');
    $wrapper = "$dir/run.php";
    file_put_contents($wrapper, '<?php
$findRoot = ' . var_export($root, true) . ';
$findTimeout = ' . var_export($timeout, true) . ';
' . $extraPhp . '
require ' . var_export(dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients/find/find.php', true) . ';
');
    try {
        list($code, $out) = runPhp($wrapper, $args);
        return [$code, cliLines($out), $out];
    } finally {
        testRmTree($dir);
    }
}
