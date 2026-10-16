<?php
/**
 * Helpers shared by tests (loaded by tests/run.php). Keep this file small: a helper belongs here
 * only when several test files need it.
 */

/** A throwaway directory under the system temp dir; remove it with testRmTree() in a finally. */
function testTempDir($prefix = 'pk-test')
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

/** Remove a directory tree made by a test. Refuses anything outside the system temp dir. */
function testRmTree($dir)
{
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/';
    if (!is_string($dir) || strpos($dir, $tmp) !== 0 || strpos($dir, '..') !== false || $dir === $tmp) {
        throw new Exception("testRmTree refused: $dir");
    }
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** A TCP port on 127.0.0.1 that was free a moment ago. */
function testFreePort()
{
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($srv, false);
    fclose($srv);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/** Run $fn with environment variables set (null unsets), restoring the old values even when it throws. */
function testWithEnv(array $vars, callable $fn)
{
    $old = [];
    foreach ($vars as $k => $v) {
        $old[$k] = getenv($k);
        putenv($v === null ? $k : "$k=$v");
    }
    try {
        return $fn();
    } finally {
        foreach ($old as $k => $v) {
            putenv($v === false ? $k : "$k=$v");
        }
    }
}
