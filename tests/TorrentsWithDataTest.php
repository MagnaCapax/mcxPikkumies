<?php
/**
 * The shared delete rules (tools/torrent-clients/lib: DeletePlan, DeleteFreed, DeleteRun) and add --dir, through the fake
 * client of TorrentsTest.php. Its helpers (torrentsFixture and the others) are defined there: tests/run.php loads the test
 * files in name order, and TorrentsTest.php sorts before this file (as CliTest.php, which defines cliLines, sorts first).
 */
return [
    'torrents: --expect is the exact byte total show printed; a bare rounded label or a changed total is refused' => function () {
        $dir = torrentsFixture();
        $b = str_repeat('b', 40);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '4.9KiB']);
        check($code === 1 && strpos($out, '4.9KiB:5000') !== false && torrentsCalls($dir) === [], 'a bare label was accepted: ' . $out);
        file_put_contents("$dir/data/B/b.mkv", str_repeat('b', 5010)); // still "4.9KiB" rounded, 10 bytes more
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '4.9KiB:5000']);
        check($code === 1 && strpos($out, 'now total') !== false && torrentsCalls($dir) === [], 'a changed byte total within the rounding was accepted: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '5010']);
        check($code === 0, 'bare exact bytes refused: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: files left behind make the delete partial, end with ok:false, and free only what is gone' => function () {
        $dir = torrentsFixture();
        $a = str_repeat('a', 40);
        $c = 'ab' . str_repeat('c', 38);
        file_put_contents("$dir/data/A/second.mkv", str_repeat('s', 700));
        torrentsEdit($dir, function (&$s) use ($dir) {
            $s['torrents'][0]['files'][] = "$dir/data/A/second.mkv";
            $s['keep'] = ["$dir/data/A/second.mkv"]; // the client "forgets" this one
        });
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $a, $c, '--with-data', '--expect', '3.6KiB:3700']);
        $last = cliLines($out)[count(cliLines($out)) - 1];
        check($code === 1 && $last['ok'] === false && strpos($last['error'], 'second.mkv') !== false && strpos($last['error'], 'freed 3000') !== false, 'partial delete: ' . $out);
        check(!is_file("$dir/data/A/a.mkv") && is_file("$dir/data/A/second.mkv"), 'fixture: the client deleted what it should');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: add --dir must be an existing folder inside the home, the same rule for every client' => function () {
        $dir = torrentsFixture();
        list($code, $out) = torrentsRun($dir, ['add', 'magnet:?xt=urn:btih:' . str_repeat('1', 40), '--dir', sys_get_temp_dir()]);
        check($code === 1 && torrentsCalls($dir) === [], '--dir outside home accepted: ' . $out);
        list($code, $out) = torrentsRun($dir, ['add', 'magnet:?xt=urn:btih:' . str_repeat('1', 40), '--dir', "$dir/data/missing"]);
        check($code === 1 && torrentsCalls($dir) === [], 'a missing --dir accepted: ' . $out);
        list($code, $out) = torrentsRun($dir, ['add', 'magnet:?xt=urn:btih:' . str_repeat('1', 40), '--dir', "$dir/data/A/"]);
        check($code === 0 && torrentsCalls($dir)[0][2]['dir'] === realpath("$dir/data/A"),
            'a folder in home refused or not resolved: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: a cross-seed elsewhere is found: symlinks to the files in another folder, a hard link beside them' => function () {
        $dir = torrentsFixture();
        $a = str_repeat('a', 40);
        mkdir("$dir/cross/A", 0700, true);
        symlink("$dir/data/A/a.mkv", "$dir/cross/A/a.mkv"); // a cross-seed tool in symlink mode, in its own folder
        torrentsEdit($dir, function (&$s) use ($dir) {
            array_pop($s['torrents']); // not the Alpha twin: it shares the very path
            $s['torrents'][] = ['id' => str_repeat('e', 40), 'native' => str_repeat('E', 40), 'name' => 'Alpha cross-seed',
                'path' => "$dir/cross/A",
                'files' => ["$dir/cross/A/a.mkv"]] + $s['torrents'][0];
        });
        list($code, $out) = runPhp("$dir/fake.php", ['show', $a, '--files']);
        $l = cliLines($out);
        check($code === 0 && $l[1]['shared_with'] === [str_repeat('e', 40)] && $l[count($l) - 1]['refused'] === [$a],
            'symlinked cross-seed missed: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $a, '--with-data', '--expect', '2.9KiB:3000']);
        check($code === 1 && strpos($out, 'Alpha cross-seed') !== false && is_file("$dir/data/A/a.mkv") && torrentsCalls($dir) === [],
            'deleted the only copy: ' . $out);
        unlink("$dir/cross/A/a.mkv");
        link("$dir/data/A/a.mkv", "$dir/data/a-hard.mkv"); // a single-file torrent beside the folder, hard-linked
        torrentsEdit($dir, function (&$s) use ($dir) {
            $s['torrents'][2]['path'] = $s['torrents'][2]['files'][0] = "$dir/data/a-hard.mkv";
        });
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $a, '--with-data', '--expect', '2.9KiB:3000']);
        check($code === 1 && strpos($out, 'Alpha cross-seed') !== false && is_file("$dir/data/A/a.mkv"),
            'hard-linked twin beside the folder missed: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: --with-data is refused while the client cannot list another torrent\'s files; show says which' => function () {
        $dir = torrentsFixture();
        $b = str_repeat('b', 40);
        torrentsEdit($dir, function (&$s) {
            $s['torrents'][0]['unlisted'] = true; // Alpha, in another folder than Beta
        });
        list($code, $out) = runPhp("$dir/fake.php", ['show', $b, '--files']);
        $s = cliLines($out)[count(cliLines($out)) - 1];
        check($code === 0 && $s['unchecked'] === [str_repeat('a', 40)] && strpos($s['hint'], 'refused') !== false, 'preview: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '4.9KiB:5000']);
        check($code === 1 && strpos($out, 'did not list the files') !== false && is_file("$dir/data/B/b.mkv") && torrentsCalls($dir) === [],
            'deleted unchecked: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: two hard links in one batch: the second is listed with same_as and the inode counts as freed, once' => function () {
        $dir = torrentsFixture();
        $b = str_repeat('b', 40);
        $y = str_repeat('f', 40);
        torrentsEdit($dir, function (&$s) use ($dir, $y) {
            $s['torrents'][] = ['id' => $y, 'native' => strtoupper($y), 'name' => 'Beta hard link', 'path' => "$dir/data/b-hardlink.mkv",
                'files' => ["$dir/data/b-hardlink.mkv"]] + $s['torrents'][1];
        });
        list($code, $out) = runPhp("$dir/fake.php", ['show', $b, $y, '--files']);
        $f = array_values(array_filter(cliLines($out), function ($r) {
            return isset($r['file']);
        }));
        $s = cliLines($out)[count(cliLines($out)) - 1];
        check($code === 0 && count($f) === 2 && $f[1]['same_as'] === "$dir/data/B/b.mkv" && $s['expect'] === '4.9KiB:5000'
            && $s['freed_bytes'] === 5000,
            'preview: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, $y, '--with-data', '--expect', '4.9KiB:5000']);
        $l = cliLines($out);
        check($code === 0 && $l[2]['freed_bytes'] === 5000 && $l[0]['freed_bytes'] + $l[1]['freed_bytes'] === 5000, 'freed: ' . $out);
        check(!file_exists("$dir/data/B/b.mkv") && !file_exists("$dir/data/b-hardlink.mkv"), 'fixture: both links deleted');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: a delete that frees nothing still says which files were left on purpose' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';
        $id = str_repeat('a', 40);
        $gone = sys_get_temp_dir() . '/pk-tor-gone-' . getmypid() . '/x.mkv';
        $plan = ['files' => [['torrent' => $id, 'file' => $gone, 'bytes' => 10, 'links' => 0, 'on_disk' => false, 'shared_with' => [],
            'skip' => 'symlinked directory not followed']], 'inodes' => [], 'bytes' => 0, 'freed' => 0, 'shared' => [],
                'unlisted' => [], 'expect' => '0B:0'];
        $r = DeleteRun::outcome($plan, [['id' => $id, 'name' => 'A', 'disposal' => 'partial', 'errors' => [], 'orphans' => [$gone]]]);
        $note = (string) $r['status']['note'];
        check(strpos($note, 'left on disk on purpose') !== false && strpos($note, 'no disk space was freed') !== false, 'note: ' . $note);
    },
];
