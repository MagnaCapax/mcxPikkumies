<?php
/**
 * lib/Update.php edges not covered by ToolsTest.php's crontab/clone tests: cronLine()/cronTickLine()/
 * cronMerge() called directly (ToolsTest only ever exercises them through schedule()/scheduleCronTick()),
 * exec()'s own contract (non-shell argv, failure shape), git() on a non-git directory, and run()'s
 * three failure branches ToolsTest never reaches (non-git checkout, a failed fetch, genuinely
 * diverged committed history) plus its already-up-to-date success branch.
 *
 * git is the real binary, run only against throwaway repositories this file creates under the
 * system temp dir and a local file:// remote (no network, nothing outside the temp tree). crontab
 * is never invoked here. Update::$root is always reset to null in a finally.
 */
return [
    'update: cronLine() direct, literal minute/hour for two picked uids' => function () {
        // 1061 % 60 = 41, intdiv(1061, 60) % 24 = 17 (matches the existing schedule() test's uid).
        $line = Update::cronLine(1061);
        check(strpos($line, '41 17 * * * ') === 0, "unexpected cron fields for uid 1061: $line");
        // 125 % 60 = 5, intdiv(125, 60) % 24 = 2 -- an independent uid, never called through schedule().
        $line = Update::cronLine(125);
        check(strpos($line, '5 2 * * * ') === 0, "unexpected cron fields for uid 125: $line");
        check(strpos($line, 'update > ') !== false && substr($line, -strlen(Update::CRON_TAG)) === Update::CRON_TAG, "line shape: $line");
    },
    'update: cronTickLine() direct, fixed per-minute schedule and tag' => function () {
        $line = Update::cronTickLine();
        check(strpos($line, '* * * * * ') === 0, "cron-tick must run every minute: $line");
        // Its output goes to a log of the last run, never to /dev/null (ADR-0099): a failing tick must leave its error somewhere.
        check(strpos($line, 'cron-tick > ' . escapeshellarg(Update::cronTickLog()) . ' 2>&1') !== false && strpos($line, '/dev/null') === false,
            "output must go to the tick log: $line");
        check(substr($line, -strlen(Update::CRON_TICK_TAG)) === Update::CRON_TICK_TAG, "missing tag: $line");
    },
    'update: cronMerge() collapses 3 duplicate stale tagged lines down to 1' => function () {
        $current = "0 0 * * * old-one " . Update::CRON_TAG . "\n0 1 * * * old-two " . Update::CRON_TAG . "\n0 2 * * * old-three " . Update::CRON_TAG . "\n@reboot keep-me\n";
        $merged = Update::cronMerge($current, '0 3 * * * fresh ' . Update::CRON_TAG, Update::CRON_TAG);
        check(substr_count($merged, Update::CRON_TAG) === 1, "3 stale tagged lines must collapse to exactly 1: $merged");
        check(strpos($merged, '@reboot keep-me') !== false, "an untagged foreign line must survive: $merged");
        check(strpos($merged, '0 3 * * * fresh') !== false, "the fresh line must be present: $merged");
    },
    "update: cronMerge('', '', tag) is the empty string" => function () {
        check(Update::cronMerge('', '', Update::CRON_TAG) === '', 'nothing in, nothing out, no crash on an empty crontab');
    },
    'update: cronMerge() with no trailing newline on the kept content inserts exactly one' => function () {
        $merged = Update::cronMerge('foo', 'bar ' . Update::CRON_TAG, Update::CRON_TAG);
        check($merged === "foo\nbar " . Update::CRON_TAG . "\n", 'expected exactly one inserted newline: ' . json_encode($merged));
    },
    "update: exec() on a nonexistent binary returns a non-zero code, empty stdout, and the shell's own \"not found\"" => function () {
        list($code, $out, $err) = Update::exec(['/definitely/not/a/real/pikkumies-test-binary-xyz']);
        check($code !== 0, "a missing binary must not report success: code=$code");
        check($out === '', "stdout must be empty: " . json_encode($out));
        check(stripos($err, 'not found') !== false, "the shell's own diagnostic must mention the binary is missing: " . json_encode($err));
    },
    'update: exec() never shell-evaluates its argv; a semicolon and a $() stay literal text' => function () {
        list($code, $out, $err) = Update::exec(['echo', 'a;b $(whoami)']);
        check($code === 0 && $out === "a;b \$(whoami)\n", "exec() must pass argv through escapeshellarg per element, never a raw shell string: " . json_encode([$code, $out, $err]));
    },
    "update: git() direct on a non-git directory fails with git's own \"not a git repository\"" => function () {
        $tmp = testTempDir('pk-git-nongit');
        $saved = Update::$root;
        Update::$root = $tmp;
        try {
            $out = Update::git(['status'], $code);
            check($code !== 0, "git on a non-git dir must not report success: code=$code");
            check(stripos(implode("\n", $out), 'not a git repository') !== false, 'expected "not a git repository": ' . implode("\n", $out));
        } finally {
            Update::$root = $saved;
            testRmTree($tmp);
        }
    },
    'update: run() on a non-git directory returns [false, "not a git checkout:..."]' => function () {
        $tmp = testTempDir('pk-run-nongit');
        $saved = Update::$root;
        Update::$root = $tmp;
        try {
            list($ok, $msg) = Update::run();
            check($ok === false, 'run() on a non-git directory must not report success');
            check(strpos($msg, "not a git checkout:\n") === 0, "expected the not-a-git-checkout message literally: $msg");
        } finally {
            Update::$root = $saved;
            testRmTree($tmp);
        }
    },
    'update: run() when fetch fails (origin rewritten to a deleted path) returns [false, "fetch failed:..."]' => function () {
        $tmp = testTempDir('pk-run-fetchfail');
        $g = function ($dir, $args) {
            exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c);
            check($c === 0, "git $args: " . implode(' ', $o));
            return $o;
        };
        $saved = Update::$root;
        try {
            mkdir("$tmp/src", 0700, true);
            $g("$tmp/src", 'init -q -b main');
            file_put_contents("$tmp/src/a.txt", "1\n");
            $g("$tmp/src", 'add a.txt');
            $g("$tmp/src", 'commit -q -m one');
            $g($tmp, 'clone -q file://' . "$tmp/src" . ' co');
            exec('rm -rf ' . escapeshellarg("$tmp/src"));
            $g("$tmp/co", 'remote set-url origin file:///this/path/does/not/exist-pk-test');
            Update::$root = "$tmp/co";
            list($ok, $msg) = Update::run();
            check($ok === false, 'run() must not succeed when the remote is unreachable');
            check(strpos($msg, "fetch failed:\n") === 0, "expected the fetch-failed message literally: $msg");
        } finally {
            Update::$root = $saved;
            testRmTree($tmp);
        }
    },
    'update: run() refuses on genuinely diverged COMMITTED history, leaving local HEAD unchanged' => function () {
        $tmp = testTempDir('pk-run-diverged');
        $g = function ($dir, $args) {
            exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c);
            check($c === 0, "git $args: " . implode(' ', $o));
            return trim(implode("\n", $o));
        };
        $saved = Update::$root;
        try {
            mkdir("$tmp/src", 0700, true);
            $g("$tmp/src", 'init -q -b main');
            $g("$tmp/src", 'commit -q -m init --allow-empty');
            $g($tmp, 'clone -q file://' . "$tmp/src" . ' co');
            // Diverge: a new commit on each side, so fast-forward is genuinely impossible (not merely
            // an uncommitted local edit, which ToolsTest.php already covers).
            $g("$tmp/src", 'commit -q -m origin-side --allow-empty');
            $g("$tmp/co", 'commit -q -m local-side --allow-empty');
            $before = $g("$tmp/co", 'rev-parse --short HEAD');
            Update::$root = "$tmp/co";
            list($ok, $msg) = Update::run();
            check($ok === false, 'a diverged history must never be force-merged');
            check(strpos($msg, 'cannot fast-forward (history diverged); nothing changed:') === 0, "expected the diverged-history message literally: $msg");
            $after = $g("$tmp/co", 'rev-parse --short HEAD');
            check($before === $after, "local HEAD must be untouched by a refused merge: $before -> $after");
        } finally {
            Update::$root = $saved;
            testRmTree($tmp);
        }
    },
    'update: run() when already up to date reports it with the real short HEAD hash' => function () {
        $tmp = testTempDir('pk-run-uptodate');
        $g = function ($dir, $args) {
            exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c);
            check($c === 0, "git $args: " . implode(' ', $o));
            return trim(implode("\n", $o));
        };
        $saved = Update::$root;
        try {
            mkdir("$tmp/src", 0700, true);
            $g("$tmp/src", 'init -q -b main');
            $g("$tmp/src", 'commit -q -m one --allow-empty');
            $g($tmp, 'clone -q file://' . "$tmp/src" . ' co');
            $hash = $g("$tmp/co", 'rev-parse --short HEAD');
            Update::$root = "$tmp/co";
            list($ok, $msg) = Update::run();
            check($ok === true && $msg === "already up to date ($hash)", "expected the literal already-up-to-date message with the real hash: $msg");
        } finally {
            Update::$root = $saved;
            testRmTree($tmp);
        }
    },
];
