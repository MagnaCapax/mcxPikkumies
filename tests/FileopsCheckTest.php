<?php
/**
 * hooks/guard/fileops-check/ with lib/FileopsClassify.php and lib/FileopsList.php (ADR-0048). Each test builds a
 * throwaway tree (a home, and pikkumies/{lib,hooks/guard/fileops-check,local}) and sets HOME to it, so nothing here
 * touches this checkout's real local/. Most tests call fileopsCheckAnswer() in this process with the tree's local/;
 * the marker and config tests run the hook script itself.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/fileops-check/fileops-check.php';

if (!function_exists('fileopsTree')) {
    /** A tree with home/data/{a,b} and a copy of lib/ and the unit; returns its directory. */
    function fileopsTree()
    {
        $tmp = testTempDir('pk-fileops');
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tmp/pikkumies/lib", 0700, true);
        @mkdir("$tmp/pikkumies/hooks/guard/fileops-check", 0700, true);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        foreach ((array) glob("$src/lib/*.php") as $f) {
            copy($f, "$tmp/pikkumies/lib/" . basename($f));
        }
        foreach ((array) glob("$src/hooks/guard/fileops-check/*.*") as $f) {
            copy($f, "$tmp/pikkumies/hooks/guard/fileops-check/" . basename($f));
        }
        @mkdir("$tmp/home/data", 0700, true);
        file_put_contents("$tmp/home/data/a", 'a');
        file_put_contents("$tmp/home/data/b", 'b');
        return $tmp;
    }

    function fileopsPayload($cmd, $session = 's1', $callId = 'call-1', array $input = [])
    {
        return ['tool_name' => 'terminal', 'tool_input' => array_merge(['command' => $cmd], $input), 'session_id' => $session, 'extra' => ['tool_call_id' => $callId]];
    }

    /** fileopsCheckAnswer() in this process, HOME = the tree's home. */
    function fileopsAnswer($tree, array $payload, array $limits = [])
    {
        return testWithEnv(['HOME' => "$tree/home"], function () use ($tree, $payload, $limits) {
            return fileopsCheckAnswer($payload, ['sessions' => ['retentionDays' => 0], 'fileopsCheck' => ['disabled' => false]], "$tree/pikkumies/local", $limits);
        });
    }

    /** The hook script itself; [decoded answer or null, raw stdout]. */
    function fileopsRun($tree, array $payload)
    {
        list(, $out) = testWithEnv(['HOME' => "$tree/home"], function () use ($tree, $payload) {
            return runPhp("$tree/pikkumies/hooks/guard/fileops-check/fileops-check.php", [], json_encode($payload));
        });
        return [json_decode($out, true), $out];
    }

    function fileopsListFile($tree, $callId)
    {
        return "$tree/pikkumies/local/logs/fileops/" . sha1($callId) . '.jsonl.gz';
    }

    /** The list's lines, decoded: [header, entry, ...]. */
    function fileopsListLines($file)
    {
        return array_map(function ($l) {
            return json_decode($l, true);
        }, array_values(array_filter(explode("\n", (string) gzdecode((string) file_get_contents($file))), 'strlen')));
    }
}

return [
    'fileops: a recursive chmod blocks once, the identical command then runs, a different or stale one blocks again' => function () {
        $tree = fileopsTree();
        try {
            $cmd = "chmod -R 700 $tree/home/data";
            list($d, $raw) = fileopsRun($tree, fileopsPayload($cmd, 's1', 'c1'));
            check(is_array($d) && $d['action'] === 'block', 'first run must block: ' . $raw);
            check(strpos($d['message'], 'hold 3 entries') !== false && strpos($d['message'], 'within 10 minutes proceeds') !== false
                && strpos($d['message'], 'Tell the user in one line') !== false, 'block message: ' . $d['message']);
            check(is_file(fileopsListFile($tree, 'c1')), 'the list must be written before the block');
            list($d, $raw) = fileopsRun($tree, fileopsPayload($cmd, 's1', 'c2'));
            check($d === null && trim($raw) === '', 'the identical command within 10 minutes must run unasked, got ' . $raw);
            check(is_file(fileopsListFile($tree, 'c2')), 'the re-run still writes the list');
            list($d) = fileopsRun($tree, fileopsPayload("chmod -R 750 $tree/home/data", 's1', 'c3'));
            check(is_array($d) && $d['action'] === 'block', 'a different command must block again');
            list($d) = fileopsRun($tree, fileopsPayload($cmd, 's2', 'c4'));
            check(is_array($d) && $d['action'] === 'block', 'the same command in another session must block again');
            touch("$tree/pikkumies/local/state/fileops/" . sha1("s1\0$cmd"), time() - 601);
            list($d) = fileopsRun($tree, fileopsPayload($cmd, 's1', 'c5'));
            check(is_array($d) && $d['action'] === 'block', 'a marker older than 10 minutes must not let it through');
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: mv onto an existing file and a recursive chmod of the home ask the user, even with a marker for the command' => function () {
        $tree = fileopsTree();
        try {
            $home = "$tree/home";
            foreach (["mv $home/data/a $home/data/b", "chmod -R 700 $home", "mv -t $home/data $tree/a $home/x/a"] as $cmd) {
                @mkdir("$tree/pikkumies/local/state/fileops", 0700, true);
                touch("$tree/pikkumies/local/state/fileops/" . sha1("s1\0$cmd")); // a fresh marker must not matter
                file_put_contents("$tree/a", 'x');
                @mkdir("$home/x", 0700, true);
                file_put_contents("$home/x/a", 'y');
                $d = fileopsAnswer($tree, fileopsPayload($cmd));
                check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === 'pikkumies-fileops:' . substr(sha1($cmd), 0, 16), "$cmd: expected approve: " . json_encode($d));
                check(strpos($d['message'], "Command [" . substr(sha1($cmd), 0, 16) . "]: $cmd") !== false, 'the whole command must be shown: ' . $d['message']);
            }
            $d = fileopsAnswer($tree, fileopsPayload("chmod -R 700 $home"));
            check(strpos($d['message'], 'the home itself') !== false, 'the reason must name the home: ' . $d['message']);
            $d = fileopsAnswer($tree, fileopsPayload("mv $home/data/a $home/data/b"));
            check(strpos($d['message'], 'mv replaces ~/data/b') !== false, 'the reason must name the replaced file with ~: ' . $d['message']);
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: mv to a new name or into a directory, mv -n, and a plain chmod of a file are silent' => function () {
        $tree = fileopsTree();
        try {
            $home = "$tree/home";
            foreach (["mv $home/data/a $home/data/c", "mv $home/data/a $home", "mv -n $home/data/a $home/data/b", "chmod 600 $home/data/a",
                "chown me $home/data/a", 'ls -la', "mv $home/data/a /dev/null"] as $cmd) {
                $d = fileopsAnswer($tree, fileopsPayload($cmd));
                check($d === null, "$cmd: expected silent, got " . json_encode($d));
            }
            check(!is_dir("$tree/pikkumies/local/logs/fileops"), 'a silent command writes no list');
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: the list is named by sha1 of the tool call id (even one with ../), inside local/logs/fileops, 0700/0600' => function () {
        $tree = fileopsTree();
        try {
            $id = '../../../escape/x';
            fileopsAnswer($tree, fileopsPayload("chmod -R 700 $tree/home/data", 's1', $id));
            $file = fileopsListFile($tree, $id);
            check(is_file($file), 'list not at local/logs/fileops/<sha1>.jsonl.gz');
            check(!file_exists("$tree/escape") && !file_exists("$tree/pikkumies/escape") && count((array) glob(dirname($file) . '/*')) === 1, 'something was written outside the list file');
            check((fileperms(dirname($file)) & 0777) === 0700 && (fileperms($file) & 0777) === 0600, 'list dir 0700, file 0600');
            $lines = fileopsListLines($file);
            check(array_keys($lines[0]) === ['ts', 'session_id', 'tool_call_id', 'command', 'targets', 'truncated'] && $lines[0]['tool_call_id'] === $id
                && $lines[0]['truncated'] === false, 'header: ' . json_encode($lines[0]));
            check(count($lines) === 4 && $lines[1]['t'] === 'd' && $lines[1]['m'] === '0700' && isset($lines[2]['u'], $lines[2]['g'], $lines[2]['s'], $lines[2]['mt']), 'entries: ' . json_encode($lines));
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: entries are lstat-ed (a symlink out of $HOME is type l, not followed) and a target outside $HOME is not listed' => function () {
        $tree = fileopsTree();
        try {
            @mkdir("$tree/outside", 0700, true);
            file_put_contents("$tree/outside/kept", 'k');
            symlink("$tree/outside", "$tree/home/data/link");
            fileopsAnswer($tree, fileopsPayload("chmod -R 700 $tree/home/data $tree/outside", 's1', 'c1'));
            $lines = fileopsListLines(fileopsListFile($tree, 'c1'));
            $types = [];
            foreach (array_slice($lines, 1) as $e) {
                $types[$e['p']] = $e['t'];
                check(strpos($e['p'], "$tree/outside") !== 0, 'a path outside $HOME was listed: ' . $e['p']);
            }
            check(isset($types["$tree/home/data/link"]) && $types["$tree/home/data/link"] === 'l', 'symlink not listed as l: ' . json_encode($types));
            check(count($types) === 4, 'expected data, a, b and the link only: ' . json_encode($types));
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: the walk stops at the cap with truncated=true, and hitting the cap asks the user' => function () {
        $tree = fileopsTree();
        try {
            for ($i = 0; $i < 20; $i++) {
                file_put_contents("$tree/home/data/f$i", 'x');
            }
            $d = fileopsAnswer($tree, fileopsPayload("chmod -R 700 $tree/home/data", 's1', 'c1'), ['cap' => 5]);
            check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], 'over 5 entries') !== false, 'cap not asked: ' . json_encode($d));
            $lines = fileopsListLines(fileopsListFile($tree, 'c1'));
            check($lines[0]['truncated'] === true && count($lines) - 1 <= 5 && count($lines) > 1, 'truncated list: ' . count($lines));
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: glob expansion under a large tree shares the cap' => function () {
        $tree = fileopsTree();
        try {
            @mkdir("$tree/home/big", 0700, true);
            for ($i = 0; $i < 60; $i++) {
                file_put_contents("$tree/home/big/f$i.txt", 'x');
            }
            $word = ['w' => "$tree/home/big/*.txt", 'p' => "$tree/home/big/*.txt", 'g' => true, 't' => false];
            $ctx = FileopsList::context(10);
            $r = FileopsList::expand($word, '', "$tree/home", $ctx);
            check($ctx['truncated'] === true && count($r['paths']) <= 10 && $ctx['count'] <= 11, 'glob ignored the cap: ' . count($r['paths']) . ' paths, count ' . $ctx['count']);
            $all = FileopsList::context();
            $r = FileopsList::expand($word, '', "$tree/home", $all);
            check(count($r['paths']) === 60 && !$all['truncated'], 'glob without a cap: ' . count($r['paths']));
            $d = fileopsAnswer($tree, fileopsPayload("chmod -R 700 $tree/home/big/*.txt", 's1', 'c1'), ['cap' => 10]);
            check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], 'over 10 entries') !== false, 'a capped glob must ask: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: an unwritable marker directory, or no valid session id, asks the user instead of blocking' => function () {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            skip('root can write a 0500 directory');
        }
        $tree = fileopsTree();
        try {
            $cmd = "chmod -R 700 $tree/home/data";
            $d = fileopsAnswer($tree, fileopsPayload($cmd, 'bad id/..'));
            check(is_array($d) && $d['action'] === 'approve', 'an invalid session id must ask: ' . json_encode($d));
            @mkdir("$tree/pikkumies/local/state/fileops", 0700, true);
            chmod("$tree/pikkumies/local/state/fileops", 0500);
            $d = fileopsAnswer($tree, fileopsPayload($cmd));
            chmod("$tree/pikkumies/local/state/fileops", 0700);
            check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], 'could not be recorded') !== false, 'an unwritable marker must ask: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: fileopsCheck.disabled makes the hook silent' => function () {
        $tree = fileopsTree();
        try {
            file_put_contents("$tree/pikkumies/local/config.php", "<?php\nreturn ['fileopsCheck' => ['disabled' => true]];\n");
            list($d, $raw) = fileopsRun($tree, fileopsPayload("chmod -R 700 $tree/home/data"));
            check($d === null && trim($raw) === '', 'disabled must be silent, got ' . $raw);
            check(!is_dir("$tree/pikkumies/local/logs/fileops"), 'disabled must write no list');
        } finally {
            testRmTree($tree);
        }
    },
    'fileops: a relative target with no workdir lists nothing and asks for absolute paths; with a workdir it is listed' => function () {
        $tree = fileopsTree();
        try {
            $d = fileopsAnswer($tree, fileopsPayload('chmod -R 700 data', 's1', 'c1'));
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'use absolute paths') !== false, 'relative: ' . json_encode($d));
            check(!is_file(fileopsListFile($tree, 'c1')), 'no list without a workdir');
            fileopsAnswer($tree, fileopsPayload('chmod -R 700 data', 's1', 'c2', ['workdir' => "$tree/home"]));
            check(count(fileopsListLines(fileopsListFile($tree, 'c2'))) === 4, 'workdir: data, a and b must be listed');
        } finally {
            testRmTree($tree);
        }
    },
];
