<?php
/**
 * Helpers shared by tests (loaded by tests/run.php). Keep this file small: a helper belongs here
 * only when several test files need it.
 */

/**
 * A throwaway directory under the system temp dir; remove it with testRmTree() in a finally. The name is short and
 * fixed-width ('p' and 8 hex digits) whatever $prefix says: hook messages quote paths in these trees and must fit
 * HookUnits::APPROVE_SHOWN, and a long name (prefix, pid, random) under a testing account's TMPDIR in its home pushed
 * some past it there while CI's /tmp kept them under. $prefix names the caller's purpose at the call site only.
 */
function testTempDir($prefix = 'pk-test')
{
    do {
        $dir = sys_get_temp_dir() . '/p' . bin2hex(random_bytes(4));
    } while (!@mkdir($dir, 0700, true));
    return $dir;
}

// The scratch folders the hooks make (lib/Scratch.php, ADR-0093) go to a folder of this run, never the real /tmp.
$testScratchBase = testTempDir('pk-test-scratch');
putenv("PIKKUMIES_SCRATCH_BASE=$testScratchBase");
register_shutdown_function(function () use ($testScratchBase) {
    testRmTree($testScratchBase);
});

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

/** The JSON Lines a tool printed, decoded; blank lines dropped. */
function cliLines($out)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", $out), 'strlen')));
}

/* Release-channel fixtures (tests/UpdateChannel*Test.php, ADR-0057): a bare origin, a working repository "dev" that pushes to it, clones as installs. */

/** git in $dir with a fixed identity; throws on failure; trimmed output. */
function chanGit($dir, $args)
{
    exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c);
    check($c === 0, "git $args: " . implode(' ', $o));
    return trim(implode("\n", $o));
}

/** A bare origin and a working repository "dev" with $commits commits on main, pushed. Returns the temp dir. */
function chanFixture($prefix, $commits)
{
    $tmp = testTempDir($prefix);
    chanGit($tmp, 'init -q --bare -b main origin.git');
    chanGit($tmp, 'clone -q file://' . "$tmp/origin.git dev");
    chanGit("$tmp/dev", 'symbolic-ref HEAD refs/heads/main');
    for ($i = 1; $i <= $commits; $i++) {
        chanCommit("$tmp/dev", "c$i");
    }
    return $tmp;
}

function chanCommit($dir, $msg)
{
    file_put_contents("$dir/f.txt", "$msg\n");
    chanGit($dir, 'add f.txt');
    chanGit($dir, "commit -q -m $msg");
    chanGit($dir, 'push -q origin main --tags');
    return chanGit($dir, 'rev-parse HEAD');
}

/** Run $fn with Update::$root pointing at $co, always restored; the temp tree is removed afterwards. */
function chanIn($tmp, $co, callable $fn)
{
    $saved = Update::$root;
    Update::$root = $co;
    try {
        return $fn();
    } finally {
        Update::$root = $saved;
        testRmTree($tmp);
    }
}
