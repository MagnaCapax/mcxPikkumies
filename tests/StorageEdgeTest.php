<?php
/**
 * tools/storage/lib/HomePath.php and ToolText.php, exercised directly in-process: neither class has
 * ANY existing test (grep confirmed zero hits across tests/*.php) even though every storage, backup
 * and rclone verb's safety rests on HomePath's containment checks, and ToolText is the one place
 * backup/rclone error text is cleaned of credentials before it can reach a ticket or a log.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/storage/lib/HomePath.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/lib/ToolText.php';

if (!function_exists('heHome')) {
    function heHome()
    {
        $home = sys_get_temp_dir() . '/pk-homepath-' . getmypid() . '-' . mt_rand();
        mkdir($home, 0700, true);
        return realpath($home);
    }
}

/** Asserts $fn() throws CliError; any other outcome (return, or a different exception) fails the test. */
if (!function_exists('heMustRefuse')) {
    function heMustRefuse(callable $fn, $msg)
    {
        try {
            $fn();
        } catch (CliError $e) {
            return;
        }
        check(false, $msg);
    }
}

return [
    'HomePath::home(): an unset HOME, a nonexistent one, and "/" are all refused' => function () {
        testWithEnv(['HOME' => null], function () {
            heMustRefuse(function () {
                HomePath::home();
            }, 'an unset HOME must be refused');
        });
        testWithEnv(['HOME' => '/nonexistent-pk-home-xyz'], function () {
            heMustRefuse(function () {
                HomePath::home();
            }, 'a nonexistent HOME must be refused');
        });
        testWithEnv(['HOME' => '/'], function () {
            heMustRefuse(function () {
                HomePath::home();
            }, 'HOME=/ must be refused, never let a verb operate on the filesystem root');
        });
    },
    'HomePath::newTarget(): refuses a relative path, "..", and $home itself; a symlinked target is refused; a new directory is '
    . 'created 0700; an existing EMPTY directory is accepted; an existing NON-empty one is refused' => function () {
        $home = heHome();
        try {
            heMustRefuse(function () use ($home) {
                HomePath::newTarget('restore', $home, []);
            }, 'a relative --target must be refused');
            heMustRefuse(function () use ($home) {
                HomePath::newTarget("$home/../evil", $home, []);
            }, 'a --target containing .. must be refused');
            heMustRefuse(function () use ($home) {
                HomePath::newTarget($home, $home, []);
            }, '$home itself must never be an accepted restore target');
            $link = "$home/a-symlink";
            symlink($home, $link);
            heMustRefuse(function () use ($link, $home) {
                HomePath::newTarget($link, $home, []);
            }, 'a symlinked --target must be refused, never followed');
            list($real, $created) = HomePath::newTarget("$home/restore-new", $home, []);
            check($created === true && (fileperms($real) & 0777) === 0700, 'a brand-new target must be created 0700: '
                . json_encode([$real, $created, sprintf('%o', fileperms($real))]));
            mkdir("$home/restore-empty", 0700);
            list($real2, $created2) = HomePath::newTarget("$home/restore-empty", $home, []);
            check($created2 === false && $real2 === realpath("$home/restore-empty"),
                'an existing EMPTY directory must be accepted, not recreated: ' . json_encode([$real2, $created2]));
            mkdir("$home/restore-full", 0700);
            file_put_contents("$home/restore-full/x", 'y');
            heMustRefuse(function () use ($home) {
                HomePath::newTarget("$home/restore-full", $home, []);
            }, 'a non-empty existing directory must be refused (a restore never overwrites)');
        } finally {
            exec('rm -rf ' . escapeshellarg($home));
        }
    },
    'HomePath::newTarget(): a target inside a FUSE mount is refused even though it is otherwise a valid new directory' => function () {
        $home = heHome();
        try {
            mkdir("$home/cloud", 0700, true);
            mkdir("$home/not-cloud", 0700, true);
            $mounts = [['mountpoint' => realpath("$home/cloud")]];
            heMustRefuse(function () use ($home, $mounts) {
                HomePath::newTarget("$home/cloud/restore", $home, $mounts);
            }, 'a target inside a FUSE mount must be refused (rclone/sshfs...)');
            list($real, $created) = HomePath::newTarget("$home/not-cloud/restore", $home, $mounts);
            check($created === true, 'a target OUTSIDE the FUSE mount must still be accepted: ' . json_encode([$real, $created]));
        } finally {
            exec('rm -rf ' . escapeshellarg($home));
        }
    },
    'HomePath::fuseMounts(): mountinfo octal escapes (space, tab) in the mountpoint are decoded, and a plain non-FUSE line is never returned' => function () {
        $home = heHome();
        try {
            $mi = "$home/mountinfo";
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            file_put_contents($mi, "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n"
                . "90 22 0:50 / $home/my\\040cloud\\011dir rw,nosuid - fuse.rclone gdrive: rw,user_id=$uid,group_id=$uid\n");
            $mounts = HomePath::fuseMounts($mi, $uid);
            check(count($mounts) === 1, 'the plain ext4 line must never be returned as a FUSE mount: ' . json_encode($mounts));
            check($mounts[0]['mountpoint'] === "$home/my cloud\tdir", 'octal escapes (\\040 space, \\011 tab) must be decoded: '
                . json_encode($mounts));
            check($mounts[0]['fstype'] === 'fuse.rclone' && $mounts[0]['user_id'] === $uid, 'fstype/user_id wrong: ' . json_encode($mounts));
        } finally {
            exec('rm -rf ' . escapeshellarg($home));
        }
    },
    'HomePath::existing(): a symlink inside HOME pointing OUTSIDE HOME is refused; one pointing to another spot inside HOME is accepted' => function () {
        $home = heHome();
        $outside = sys_get_temp_dir() . '/pk-outside-' . getmypid() . '-' . mt_rand();
        try {
            file_put_contents($outside, 'secret');
            symlink($outside, "$home/escape");
            heMustRefuse(function () use ($home) {
                HomePath::existing("$home/escape", $home);
            }, 'a symlink pointing outside HOME must be refused (backup/rclone sources stay inside the account)');
            mkdir("$home/data", 0700, true);
            file_put_contents("$home/data/real.txt", 'x');
            symlink("$home/data/real.txt", "$home/inside-link");
            check(HomePath::existing("$home/inside-link", $home) === realpath("$home/data/real.txt"),
                'a symlink pointing to another spot inside HOME must be accepted');
        } finally {
            @unlink($outside);
            exec('rm -rf ' . escapeshellarg($home));
        }
    },
    "HomePath::inside(): a string-PREFIX of \$home's own name is never mistaken for containment (classic path-prefix bug)" => function () {
        check(HomePath::inside('/home/bobby', '/home/bob') === false, '/home/bobby must not be "inside" /home/bob');
        check(HomePath::inside('/home/bobby/x', '/home/bob') === false, '/home/bobby/x must not be "inside" /home/bob');
        check(HomePath::inside('/home/bob/x', '/home/bob') === true, '/home/bob/x must be inside /home/bob');
        check(HomePath::inside('/home/bob', '/home/bob') === true, '/home/bob must be inside itself');
    },
    'HomePath::dir(): a bare relative path is refused even with $explicit=true, never resolved against the process cwd' => function () {
        $home = heHome();
        try {
            try {
                HomePath::dir('relative/sub', $home, true);
                check(false, 'a relative path must be refused even when --path marks it explicit');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'absolute path') !== false, 'wrong refusal reason: ' . $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($home));
        }
    },
    'ToolText::tail(): an earlier error/exception/fatal line is prefixed as the cause even when it is outside the last 4 lines' => function () {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "line $i: normal output";
        }
        $lines[2] = 'FatalError: disk is full'; // line 3 of 10: well outside the last-4 tail
        $out = ToolText::tail(implode("\n", $lines));
        check(strpos($out, 'FatalError: disk is full') === 0, 'the earlier fatal line must be prefixed as the cause: ' . $out);
        check(strpos($out, 'line 10') !== false, 'the tail (last 4 lines) must still be kept: ' . $out);
    },
    'ToolText::tail(): a Platform:/PID:/sys.argv:/SSH_ORIGINAL_COMMAND: footer line is never chosen as the cause even though it also matches the error regex' => function () {
        $lines = array_merge(
            ['Traceback (most recent call last):', 'some context line', 'Platform: Linux somehost 6.1.0 error-build',
                'another context line', 'yet another line', 'final context before tail'],
            ['tail line 1', 'tail line 2', 'tail line 3', 'tail line 4']
        );
        $out = ToolText::tail(implode("\n", $lines));
        check(strpos($out, 'Platform:') === false, 'the Platform: footer line must never be chosen as the cause, even though it matches /error/i: ' . $out);
        check(strpos($out, 'tail line 4') !== false, 'the real tail must still be kept: ' . $out);
    },
    'ToolText::tail(): a long error is kept whole up to Cli::TEXT_MAX, so the agent reads the real message' => function () {
        $msg = 'Error: repository check failed: ' . str_repeat('segment 0123456789 ', 60) . 'END-OF-ERROR';
        check(strlen($msg) > 1000 && strlen($msg) < Cli::TEXT_MAX, 'fixture size');
        $out = ToolText::tail($msg);
        check(strpos($out, 'Error: repository check failed') === 0 && strpos($out, 'END-OF-ERROR') !== false, 'a ' . strlen($msg) . '-byte error must survive whole: ' . substr($out, 0, 80));
        $huge = ToolText::tail(str_repeat('x', Cli::TEXT_MAX * 2));
        check(strlen($huge) <= Cli::TEXT_MAX + 3, 'an oversized error is still bounded: ' . strlen($huge));
    },
    'ToolText::redact(): a restic-style URL whose password itself contains @ is redacted up to the LAST @; credential-free text and null pass through unchanged' => function () {
        $r = ToolText::redact('rest:https://user:pa@ss@host:8000/repo');
        check($r === 'rest:https://user:REDACTED@host:8000/repo', 'password containing @ not redacted to the last @: ' . $r);
        check(ToolText::redact('no credentials here') === 'no credentials here', 'credential-free text must pass through unchanged');
        check(ToolText::redact(null) === null, 'null must pass through unchanged, never become the string "null"');
    },
];
