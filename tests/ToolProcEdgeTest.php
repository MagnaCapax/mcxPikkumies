<?php
/**
 * pikkumies/lib/ToolProc.php and tools/storage/lib/ToolList.php (neither has ANY existing test; grep confirmed
 * zero hits), plus RcloneCmd/RclonePlan's version-parsing fallback. ToolProc is the one place every
 * storage/backup/rclone verb runs an external program; its escaping and output caps are the safety
 * boundary the whole tools/storage tree depends on.
 */
require_once __DIR__ . '/fixtures/storage/harness.php';
require_once dirname(__DIR__) . '/pikkumies/lib/ToolProc.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/lib/ToolList.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/lib/ToolText.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/rclone/RclonePlace.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/rclone/RcloneCmd.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/rclone/RclonePlan.php';

return [
    'ToolProc::run(): stdout caps at MAX_OUT with cut=true when no line-reader is given; stderr independently caps at MAX_ERR' => function () {
        $code = 'echo str_repeat("a", 9 * 1024 * 1024); fwrite(STDERR, str_repeat("b", 2 * 1024 * 1024));';
        $r = ToolProc::run([PHP_BINARY, '-r', $code]);
        check($r['cut'] === true, 'cut must be true once stdout crosses MAX_OUT');
        check(strlen($r['out']) >= ToolProc::MAX_OUT && strlen($r['out']) < ToolProc::MAX_OUT + 200000,
            'stdout must be capped at roughly MAX_OUT (8 MiB), not the full 9 MiB written: got ' . strlen($r['out']));
        check(strlen($r['err']) >= ToolProc::MAX_ERR && strlen($r['err']) < ToolProc::MAX_ERR + 200000,
            'stderr must be capped at roughly MAX_ERR (1 MiB), not the full 2 MiB written: got ' . strlen($r['err']));
    },
    "ToolProc::run(): a null \$env entry REMOVES that variable from the child, never passes the literal string 'null'" => function () {
        putenv('PK_TOOLPROC_FOO=bar');
        try {
            $code = 'echo getenv("PK_TOOLPROC_FOO") === false ? "REMOVED" : getenv("PK_TOOLPROC_FOO");';
            $r = ToolProc::run([PHP_BINARY, '-r', $code], ['PK_TOOLPROC_FOO' => null]);
            check($r['out'] === 'REMOVED', 'a null $env entry must unset the variable, not pass the literal string "null": got '
                . var_export($r['out'], true));
        } finally {
            putenv('PK_TOOLPROC_FOO');
        }
    },
    'ToolProc::run(): $onLine returning false stops reading AND proc_terminate()s a still-running child promptly' => function () {
        $code = 'for ($i = 1; $i <= 5; $i++) { echo "line$i\n"; } fwrite(STDOUT, ""); flush(); sleep(30);';
        $seen = [];
        $t0 = microtime(true);
        $r = ToolProc::run([PHP_BINARY, '-r', $code], [], function ($line) use (&$seen) {
            $seen[] = $line;
            return count($seen) < 2; // stop reading after the 2nd line
        });
        $elapsed = microtime(true) - $t0;
        check($r['stopped'] === true && $seen === ['line1', 'line2'], 'must stop exactly after the 2nd line: ' . json_encode($seen));
        check($elapsed < 5, "proc_terminate() did not stop the lingering sleep 30 child promptly: took {$elapsed}s");
    },
    'ToolProc::which()/need(): PATH order matters, a non-executable same-named file is skipped for a later real one, and need()\'s hint passes through verbatim' => function () {
        $dir1 = sys_get_temp_dir() . '/pk-which1-' . getmypid() . '-' . mt_rand();
        $dir2 = sys_get_temp_dir() . '/pk-which2-' . getmypid() . '-' . mt_rand();
        mkdir($dir1, 0700, true);
        mkdir($dir2, 0700, true);
        file_put_contents("$dir1/pktool", 'not executable, just a stale leftover file');
        chmod("$dir1/pktool", 0644);
        file_put_contents("$dir2/pktool", "#!/bin/sh\necho real\n");
        chmod("$dir2/pktool", 0755);
        testWithEnv(['PATH' => "$dir1:$dir2"], function () use ($dir2) {
            check(ToolProc::which('pktool') === "$dir2/pktool", 'a non-executable same-named file earlier in PATH must be skipped: got '
                . var_export(ToolProc::which('pktool'), true));
            check(ToolProc::which('pk-does-not-exist-anywhere') === null, 'a missing binary must return null');
            try {
                ToolProc::need('pk-does-not-exist-anywhere', 'install it from the package manager');
                check(false, 'need() must throw CliError when the binary is missing');
            } catch (CliError $e) {
                check($e->hint === 'install it from the package manager', 'need() must pass its hint through verbatim: ' . $e->hint);
            }
        });
        exec('rm -rf ' . escapeshellarg($dir1) . ' ' . escapeshellarg($dir2));
    },
    'ToolProc::line()/run(): every argv element is independently escaped and round-trips through a real shell as one literal argument '
    . '(the shell-injection primitive every storage/rclone verb depends on)' => function () {
        $weird = ['a b', '$(id)', '; rm -rf /', "it's a test", "two\nlines", '-leading-dash', '`backtick`', '&& echo pwned'];
        $code = 'echo json_encode(array_slice($argv, 1));';
        $r = ToolProc::run(array_merge([PHP_BINARY, '-r', $code, '--'], $weird));
        check($r['code'] === 0, 'the child must run cleanly: ' . $r['err']);
        check(json_decode($r['out'], true) === $weird, 'argv must round-trip byte-for-byte, each element its own shell word, none '
            . 'interpreted by the shell: ' . $r['out']);
    },
    'ToolList::want(): --limit 0 asks the CAP; --limit N asks N+1; a negative --limit clamps to 0 (falls to the CAP branch); the default uses Cli::LIMIT' => function () {
        check(ToolList::want(['limit' => 0]) === ToolList::CAP, '--limit 0 must ask the CAP');
        check(ToolList::want(['limit' => 5]) === 6, '--limit N must ask N+1 (the extra one marks truncated)');
        check(ToolList::want(['limit' => -5]) === ToolList::CAP, 'a negative --limit must clamp to 0, which falls to the CAP branch, '
            . 'not a negative/zero read count');
        check(ToolList::want([]) === Cli::LIMIT + 1, 'with no --limit, want() must use Cli::LIMIT, not a hardcoded default of its own');
        check(ToolList::want(['limit' => 100, 'offset' => 50]) === 151, '--offset must add into the want count');
    },
    'ToolList::listed(): listed_all is exactly count < want; the CAP note appears only exactly at CAP' => function () {
        check(ToolList::listed(5, 10) === ['listed_all' => true], 'count<want must be listed_all=true with no CAP note');
        check(ToolList::listed(10, 10) === ['listed_all' => false], 'count===want must be listed_all=false (truncated), no CAP note yet');
        check(ToolList::listed(ToolList::CAP, ToolList::CAP) === ['listed_all' => false,
            'note' => 'read the first ' . ToolList::CAP . ' entries only; list a narrower path for the rest'], 'exactly at CAP must carry the note');
        check(ToolList::listed(ToolList::CAP - 1, ToolList::CAP) === ['listed_all' => true], 'one under CAP must not carry the note '
            . '(listed_all is already true since count<want)');
    },
    "RcloneCmd::version()/atLeast(): an unparseable `rclone version` banner reads as version()===null, so RclonePlan falls back to the "
    . "pre-1.66 check-only path instead of crashing or wrongly claiming sync/move support" => function () {
        $fx = stFixture(['version' => 'nightly-build-2026.10.16'], ['rclone']); // "rclone vnightly-..." has no digits after v: unparseable
        try {
            testWithEnv(['PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir']], function () use ($fx) {
                $c = new RcloneCmd($fx['home'], function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
                check($c->version() === null, 'an unparseable version banner must read as null, never a wrong partial match: got '
                    . var_export($c->version(), true));
                check($c->atLeast('1.66') === false, 'atLeast() must be false when the version is unknown, never true by default');
                check(RclonePlan::exact($c) === false, 'RclonePlan must fall back to the pre-1.66 check-only path when the version cannot be parsed');
            });
        } finally {
            stDone($fx);
        }
    },
];
