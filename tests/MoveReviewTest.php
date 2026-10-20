<?php
/**
 * torrent-clients/move: regressions for the security review of 2026-10-20 (F3 overwrite and layout trust, F5 the real
 * ssh argv, F6 ids on the command line, the paged-read cap and the group kill). Each failed on the code before the fixes.
 */
require_once __DIR__ . '/moveSupport.php';

return [
    'move review: a .torrent naming a file or folder that starts with a dot is refused (F3)' => function () {
        $cases = [['name' => '.bashrc', 'length' => 1], ['name' => '.ssh', 'files' => [['length' => 1, 'path' => ['authorized_keys']]]],
            ['name' => 'x', 'files' => [['length' => 1, 'path' => ['.ssh', 'authorized_keys']]]], ['name' => 'x', 'files' => [['length' => 1, 'path' => ['..', 'y']]]]];
        foreach ($cases as $info) {
            $err = mvError(function () use ($info) {
                MoveMeta::parse(mvBenc(['info' => $info + ['piece length' => 1, 'pieces' => '']]));
            });
            check(strpos($err, 'unsafe path element') !== false, 'refused: ' . json_encode($info) . " -> $err");
        }
        check(MoveMeta::parse(mvBenc(['info' => ['name' => 'a.b', 'length' => 1, 'piece length' => 1, 'pieces' => '']]))['files'] === [['a.b', 1]], 'a dot inside a name is fine');
    },
    'move review: the source folder may not be the home itself or a dot-folder (F3)' => function () {
        $home = '/home/fixtureuserx';
        foreach ([[[['k', 3]], ["$home/.ssh/k"]], [[['x.mkv', 3]], ["$home/x.mkv"]], [[['x.mkv', 3]], ["$home/data/.hid/x.mkv"]]] as $c) {
            $err = mvError(function () use ($c, $home) {
                MoveMeta::root(['files' => $c[0]], [[$c[1][0], 3, false]], $home, 'x');
            });
            check(strpos($err, 'nothing planned') === 0, 'refused: ' . $c[1][0] . " -> $err");
        }
        check(MoveMeta::root(['files' => [['x.mkv', 3]]], [["$home/data/x.mkv", 3, false]], $home, 'x') === 'data', 'a data folder is fine');
    },
    'move review: the target folder lies inside a data root, never the home, a dot-folder or the Pikkumies tree (F3)' => function () {
        $w = mvWorld('pk-moverev-roots');
        try {
            $h = $w['home'];
            foreach (['.ssh', 'pikkumies/lib', 'files/x', 'other'] as $d) {
                mkdir("$h/$d", 0700, true);
            }
            list($r) = mvRig($w);
            $r->localFn = function ($argv) use ($h) {
                return in_array('list', $argv, true) ? mvOut([['path' => "$h/files/x"], ['path' => "$h/.ssh/k"], ['path' => "$h/pikkumies/lib"], ['path' => "$h/top.mkv"]], [], [])
                    : mvOut([['save_path' => "$h/data"]], [], []);
            };
            $roots = MoveRoots::roots($r, 'qbittorrent', $h);
            sort($roots);
            check($roots === ["$h/data", "$h/files"], 'roots: ' . json_encode($roots));
            symlink("$h/.ssh", "$h/data/link");
            foreach (['~/.ssh', $h, '~/pikkumies/lib', '~/other', '~/data/.hidden', "$h/data/../.ssh", '~/data/link', '~/data/link/sub'] as $d) {
                check(strpos(mvError(function () use ($d, $h, $roots) {
                    MoveRoots::targetDir($d, $h, $roots);
                }), 'nothing planned') === 0, "refused: $d");
            }
            check(MoveRoots::targetDir('~/data/linux', $h, $roots) === "$h/data/linux" && MoveRoots::targetDir('~/files', $h, $roots) === "$h/files", 'inside a root is fine');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move review: plan refuses a torrent whose file already exists here, and the file stays as it was (F3)' => function () {
        $w = mvWorld('pk-moverev-exists');
        try {
            $id = mvTorrent($w, 'Foo', ['Foo/movie.mkv' => str_repeat('s', 20), 'Foo/b.nfo' => 'n']);
            mkdir("{$w['home']}/data/Foo");
            file_put_contents("{$w['home']}/data/Foo/movie.mkv", "the user's own film!");
            list($r, $st) = mvRig($w);
            $err = mvError(function () use ($r, $st, $w, $id) {
                (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1']);
            });
            check(strpos($err, 'Foo/movie.mkv already exists') !== false && !$st->all(), "refused: $err");
            check(file_get_contents("{$w['home']}/data/Foo/movie.mkv") === "the user's own film!", 'untouched');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move review: a file that appears here after the plan is never overwritten by the real rsync run (F3)' => function () {
        if (ToolProc::which('rsync') === null) {
            skip('rsync is not installed');
        }
        $w = mvWorld('pk-moverev-race');
        try {
            $id = mvTorrent($w, 'Foo', ['Foo/movie.mkv' => str_repeat('s', 20), 'Foo/a.nfo' => 'n']);
            list($r, $st) = mvRig($w);
            $r->rsyncFn = mvRealRsync($w);
            $move = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1'])['status']['move'];
            mkdir("{$w['home']}/data/Foo");
            file_put_contents("{$w['home']}/data/Foo/movie.mkv", "the user's own film!");
            $err = mvError(function () use ($r, $st, $w, $move) {
                (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, []);
            });
            check(strpos($err, 'already exists') !== false, "refused: $err");
            check(file_get_contents("{$w['home']}/data/Foo/movie.mkv") === "the user's own film!" && !isset($w['target']['qbittorrent'][$id]), 'not overwritten, not added');
            unlink("{$w['home']}/data/Foo/movie.mkv");
            (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, []);
            check(file_get_contents("{$w['home']}/data/Foo/movie.mkv") === str_repeat('s', 20) && !glob("{$w['home']}/data/.pikkumies-move-*"), 'placed once free; staging gone');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move review: the copy list comes from the saved .torrent, never from the move state; rsync gets -rt, never -a (F3)' => function () {
        $w = mvWorld('pk-moverev-list');
        try {
            $id = mvTorrent($w, 'Pack', ['Pack/a.bin' => 'aaa', 'Pack/b.bin' => 'bb']);
            list($r, $st) = mvRig($w);
            $move = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1'])['status']['move'];
            $m = $st->load($move);
            $m['items'][0]['files'] = [['../../.ssh/authorized_keys', 30]];
            $st->save($m);
            (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, []);
            check(mvLists($w) === ["Pack/a.bin\0Pack/b.bin\0"], 'list: ' . json_encode($w['lists']));
            $argv = $w['rsync'][0];
            check(in_array('-rt', $argv, true) && !preg_grep('/^-[a-zA-Z]*[alpDs]/', array_diff($argv, ['-rt', '-e'])), 'flags: ' . json_encode($argv));
        } finally {
            testRmTree($w['t']);
        }
    },
    'move review: freeing the source needs --ids naming exactly the move\'s torrents, so the delete review sees them (F6)' => function () {
        $w = mvWorld('pk-moverev-ids');
        try {
            $id = mvTorrent($w, 'One', ['One/a' => 'xyz']);
            list($r, $st) = mvRig($w);
            $move = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1'])['status']['move'];
            $done = (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, []);
            check(strpos($done['status']['next'], "--ids $id --expect 3B:3") !== false, 'the next command carries the ids: ' . $done['status']['next']);
            foreach ([[], ['ids' => str_repeat('f', 40)], ['ids' => "$id,$id"]] as $extra) {
                $err = mvError(function () use ($r, $st, $w, $move, $extra) {
                    (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, ['free-source' => true, 'expect' => '3B:3', 'intent' => 'delete it'] + $extra);
                });
                check(strpos($err, 'nothing done') === 0, 'refused: ' . json_encode($extra) . " -> $err");
            }
            check(!mvVerbs($w, 'remove') && isset($w['source'][$id]), 'nothing removed');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move review: a paged read stops at 32 MiB from a source that never ends its answer' => function () {
        $r = new MoveRunner('/nonexistent');
        $n = 0;
        $r->remoteFn = function ($nick, $argv) use (&$n) {
            $n++;
            $i = array_search('--offset', $argv, true);
            $pad = '{"a":1}' . str_repeat(' ', 20000); // a small record in a large answer
            return ['rc' => 0, 'stdout' => "$pad\n" . json_encode(['ok' => true, 'truncated' => true, 'next_offset' => (int) $argv[$i + 1] + 1]) . "\n", 'stderr' => ''];
        };
        $err = mvError(function () use ($r) {
            $r->pages('hdd1', 'rtorrent', ['list']);
        });
        check(strpos($err, 'grew past 33554432 bytes') !== false && $n < 1700, "stopped after $n pages: $err");
    },
    'move review: a local unit that does not answer is killed together with the child it started' => function () {
        if (ToolProc::which('setsid') === null || !function_exists('posix_kill')) {
            skip('setsid or posix is not available');
        }
        $t = testTempDir('pk-moverev-kill');
        try {
            file_put_contents("$t/hang.php", '<?php exec("sleep 60 > /dev/null 2>&1 & echo \$! > ' . $t . '/child"); sleep(60);');
            $r = MoveProc::proc([PHP_BINARY, "$t/hang.php"], '', 2);
            check($r['rc'] === 124, 'timed out: ' . json_encode($r));
            $pid = (int) trim((string) @file_get_contents("$t/child"));
            $gone = false;
            for ($i = 0; $i < 40 && !$gone; $i++) {
                $gone = $pid > 1 && !posix_kill($pid, 0);
                usleep(50000);
            }
            check($pid > 1 && $gone, "the child $pid is gone too");
        } finally {
            testRmTree($t);
        }
    },
    'move review: the pull the source sees passes the real ServeGate::rsync for a data folder; `.` and other folders are refused' => function () {
        $gate = dirname(__DIR__) . '/pikkumies/tools/seedbox/services/ServeGate.php';
        if (!is_file($gate) || ToolProc::which('rsync') === null) {
            skip('ServeGate.php (builder A) or rsync is not in this tree');
        }
        require_once $gate;
        $t = testTempDir('pk-moverev-gate');
        try {
            mkdir("$t/acct/data/Foo", 0700, true);
            mkdir("$t/acct/other", 0700, true);
            mkdir("$t/dest", 0700, true);
            file_put_contents("$t/fakessh.php", '<?php file_put_contents(' . var_export("$t/seen.json", true) . ', json_encode(array_slice($argv, 1))); exit(255);');
            file_put_contents("$t/list", "a.bin\0");
            $argv = MoveCopy::argv([[PHP_BINARY, "$t/fakessh.php"], 'fixturehostx'], 'data/Foo', "$t/dest", false);
            (new MoveRunner('/nonexistent'))->rsync($argv, "$t/list"); // real rsync builds the remote command; the fake ssh keeps it
            $seen = json_decode((string) @file_get_contents("$t/seen.json"), true);
            $at = is_array($seen) ? array_search('fixturehostx', $seen, true) : false;
            check($at !== false, 'rsync called the ssh command: ' . json_encode($seen));
            $words = ServeGate::tokenize(implode(' ', array_slice($seen, $at + 1))); // what serve reads from SSH_ORIGINAL_COMMAND
            $roots = [realpath("$t/acct/data")];
            $ok = ServeGate::decide($words, false, "$t/acct", $roots);
            check($ok['kind'] === 'rsync' && $ok['rel'] === ['Foo/'] && end($words) === 'data/Foo/', 'allowed: ' . json_encode($ok));
            foreach (['.', 'other/', '.ssh/'] as $bad) {
                $w = $words;
                $w[count($w) - 1] = $bad;
                check(mvError(function () use ($w, $t, $roots) {
                    ServeGate::decide($w, false, "$t/acct", $roots);
                }) !== '', "refused: $bad");
            }
            check(strpos(mvError(function () use ($t) {
                MoveCopy::argv([['ssh'], 'fixturehostx'], '', "$t/dest", false);
            }), 'nothing planned: the source folder') === 0, 'move never asks for the home itself');
        } finally {
            testRmTree($t);
        }
    },
    'move review: plan asks the source first and refuses a folder its serve will not send, naming services.serveRoots' => function () {
        $w = mvWorld('pk-moverev-probe');
        try {
            $id = mvTorrent($w, 'Far', ['Far/a' => 'x'], 'torrents');
            list($r, $st) = mvRig($w);
            $r->rsyncFn = function ($argv, $list) use (&$w) {
                $w['rsync'][] = $argv;
                return ['code' => 12, 'err' => 'pikkumies serve: path torrents/ is not inside a data root of this box'];
            };
            $err = $hint = '';
            try {
                (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1', 'dir' => "{$w['home']}/data"]);
            } catch (CliError $e) {
                list($err, $hint) = [$e->getMessage(), $e->hint];
            }
            check(strpos($err, 'nothing planned: hdd1 refuses to send torrents') === 0 && strpos($hint, 'services.serveRoots') !== false && !$st->all(), "refused: $err / $hint");
            check(count($w['rsync']) === 1 && end($w['rsync'][0]) !== '' && !glob("{$w['t']}/tmp/move-probe-*"), 'one probe, cleaned up');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move review: the rsync argv builds from the real Services::sshArgv, not only from a stub (F5)' => function () {
        if (!class_exists('Services')) {
            skip('lib/Services.php is not in this tree (builder A)');
        }
        $t = testTempDir('pk-moverev-ssh');
        $oldDir = Services::$localDir;
        try {
            Services::$localDir = "$t/local";
            Services::$configFile = "$t/local/config.php";
            Services::put(['nick' => 'src1', 'host' => 'box.example.com', 'user' => 'fixtureuserx', 'transport' => 'pikkumies']);
            mkdir(Services::secretDir('src1'), 0700, true);
            file_put_contents(Services::secretDir('src1') . '/key', 'k');
            file_put_contents(Services::secretDir('src1') . '/known_hosts', 'box.example.com ssh-ed25519 AAAA');
            $argv = MoveCopy::argv((new MoveRunner('/nonexistent'))->ssh('src1'), 'data/x', "$t/dest/data/x", false);
            check(in_array('box.example.com:data/x/', $argv, true) && strpos($argv[array_search('-e', $argv, true) + 1], 'UserKnownHostsFile=') !== false, json_encode($argv));
        } finally {
            Services::$localDir = $oldDir;
            Services::$configFile = null;
            testRmTree($t);
        }
    },
];
