<?php
/**
 * tests/run.php puts a `crontab` that always fails first on PATH, so neither a test nor a pikkumies
 * process a test spawns can change the real crontab of whoever runs the suite.
 */
return [
    'suite guard: crontab on PATH is the failing guard, here and in spawned processes, and nothing is written' => function () {
        exec('command -v crontab 2>/dev/null', $path, $code);
        check($code === 0 && strpos($path[0], '/pikkumies-test-crontab-') !== false, 'crontab is not the suite guard: ' . json_encode($path));
        $guard = (string) getenv('PIKKUMIES_TEST_CRONTAB_GUARD');
        check(dirname($path[0]) === $guard && preg_match('#/pikkumies-test-crontab-[0-9a-f]{16}$#', $guard) === 1, 'guard dir name is not random: ' . $guard);
        $st = lstat($guard);
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        check(!is_link($guard) && $st['uid'] === $euid && ($st['mode'] & 0777) === 0700, 'guard dir is not a private dir of this user');
        list($rc, $out, $err) = Update::exec([Update::$crontab, '-l']);
        check($rc !== 0 && strpos($err . $out, 'test suite') !== false, 'Update reached a real crontab: ' . $rc . ' ' . $out . $err);
        $tmp = testTempDir('pk-cronguard');
        try {
            $script = "$tmp/child.php";
            file_put_contents($script, '<?php exec("command -v crontab", $p); echo $p[0] ?? "";');
            list($c, $child) = runPhp($script, []);
            check($c === 0 && strpos($child, '/pikkumies-test-crontab-') !== false, 'spawned process sees another crontab: ' . $child);
            $msgs = Update::ensureScheduled(Config::defaults(), 1061, false, "$tmp/update.log");
            check($msgs === [], 'ensureScheduled wrote through the guard: ' . json_encode($msgs));
        } finally {
            testRmTree($tmp);
        }
    },
];
