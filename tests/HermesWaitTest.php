<?php
/**
 * lib/HermesWait.php (ADR-0033 addendum 2026-10-19): a session where the runtime is not built yet waits for the build in
 * the foreground with a progress line and then starts; it never says "come back later". Fakes stand in for the build,
 * the clock and sleep, so no test waits.
 */
if (!function_exists('hermesWaitRun')) {
    /** Runs HermesWait::run with scripted ready/building answers (one per check); returns [result, printed, sleeps]. */
    function hermesWaitRun($status, array $ready, array $building, $log, $tty)
    {
        $printed = '';
        $sleeps = 0;
        $clock = 1000;
        $next = function (array &$list) {
            return count($list) > 1 ? array_shift($list) : $list[0];
        };
        $result = HermesWait::run($status, function () use (&$ready, $next) {
            return $next($ready);
        }, function () use (&$building, $next) {
            return $next($building);
        }, function ($t) use (&$printed) {
            $printed .= $t;
        }, $log, $tty, function ($s) use (&$sleeps, &$clock) {
            $sleeps++;
            $clock += $s;
            check($sleeps < 2000, 'the wait never ended');
        }, function () use (&$clock) {
            return $clock;
        });
        return [$result, $printed, $sleeps];
    }
}

return [
    'hermes wait: a build in the background is waited for with a progress line, then the session goes on' => function () {
        $dir = testTempDir('pk-hwait');
        try {
            file_put_contents("$dir/install.log", "hermes v0.22.2: build started\nInstalling packages 40%\r");
            list($r, $out, $sleeps) = hermesWaitRun('building v0.22.2 in the background (log: ~/pikkumies/local/install.log)', [false, false, false, false, true], [true], "$dir/install.log", true);
            check($r === '', 'ready must start the session: ' . $r);
            check($sleeps >= 2 && strpos($out, 'Setting up the agent runtime') !== false && strpos($out, 'Installing packages 40%') !== false, 'progress line with the log: ' . json_encode($out));
            check(strpos($out, "\r\033[K") !== false && strpos($out, 'The agent runtime is ready') !== false, 'a terminal redraws one line, then says ready: ' . json_encode($out));
            check(stripos($out, 'again') === false && stripos($out, 'come back') === false, 'never "come back later": ' . $out);
            list($r, $out) = hermesWaitRun('building in the background (log: x)', [false, false, false, true], [true], "$dir/install.log", false);
            check($r === '' && strpos($out, "\r") === false && substr_count($out, 'Setting up the agent runtime') === 1, 'without a terminal: plain lines, not one per check: ' . json_encode($out));
        } finally {
            testRmTree($dir);
        }
    },
    'hermes wait: nothing building (no room, skipped, a left-over tree) is not waited for; the reason is returned' => function () {
        foreach (['skipped (PIKKUMIES_SKIP_HERMES=1)', 'not installed: Hermes needs about 2.5 GB and your quota has 0.4 GB free.'] as $status) {
            list($r, $out, $sleeps) = hermesWaitRun($status, [false], [false], '/nonexistent/install.log', true);
            check($r === $status && $sleeps === 0 && $out === '', "no wait for: $status -> $r / $out");
        }
        list($r, , $sleeps) = hermesWaitRun('installed (pikkumies/hermes)', [true], [false], '/nonexistent/install.log', true);
        check($r === '' && $sleeps === 0, 'already ready: nothing to wait for');
    },
    'hermes wait: a build that ends without a runtime, never takes its lock, or hangs past MAX_WAIT stops the wait with the log\'s last line' => function () {
        $dir = testTempDir('pk-hwait');
        try {
            file_put_contents("$dir/install.log", "hermes v0.22.2: build started\nhermes v0.22.2: failed: \x1b[31mpm install\x1b[0m\x07\n\n");
            list($r, $out) = hermesWaitRun('building v0.22.2 in the background (log: x)', [false], [true, true, false], "$dir/install.log", true);
            check(strpos($r, 'the build stopped without finishing; `pikkumies install` builds again: hermes v0.22.2: failed: pm install (log: ') === 0, 'stopped: ' . json_encode($r));
            check(strpos($r . $out, "\x07") === false && strpos($r, "\x1b") === false, 'control characters from the log never reach the terminal');
            list($r, , $sleeps) = hermesWaitRun('building in the background (log: x)', [false], [false], "$dir/install.log", false);
            check(strpos($r, 'the build stopped without finishing') === 0 && $sleeps * HermesWait::POLL >= HermesWait::START_GRACE, 'never took its lock: waits the grace, then stops: ' . $r);
            list($r) = hermesWaitRun('building in the background (log: x)', [false, false, false, true], [true, false], "$dir/install.log", true);
            check($r === '', 'marker written as the lock was let go: ready, not stopped');
            list($r, , $sleeps) = hermesWaitRun('building in the background (log: x)', [false], [true], "$dir/install.log", true);
            check(strpos($r, 'the build is still running after 30m0s') === 0 && $sleeps * HermesWait::POLL === HermesWait::MAX_WAIT, 'a hung build is not waited for forever: ' . $r);
        } finally {
            testRmTree($dir);
        }
    },
    'hermes wait: bin/pikkumies waits for the runtime instead of telling the person to run it again later' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'run':(.*?)\n    case /s", $bin, $m) === 1, 'run case missing');
        check(strpos($m[1], 'HermesWait::run(HermesInstall::ensure(false,') !== false && strpos($m[1], "['HermesInstall', 'building']") !== false, 'run must start the build and wait for it');
        check(strpos($bin, 'again when it is ready') === false && strpos($bin, 'a few minutes); `pikkumies doctor` shows') === false, 'no "come back later" left');
        check(strpos($m[1], 'HermesWait::run(') < strpos($m[1], 'HermesInstall::lock('), 'the wait comes before the Hermes lock');
        // Both "build started or running" answers of ensure() carry the words the wait keys on.
        $hi = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/lib/HermesInstall.php');
        check(substr_count($hi, "in the background (log: ' . Config::shown(self::localDir() . '/install.log') . ')'") === 2, 'ensure() building answers changed: HermesWait keys on "in the background"');
    },
];
