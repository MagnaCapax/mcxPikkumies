<?php
/** The shared torrent-client verbs (tools/torrent-clients/lib) through a fake client kept in a JSON file. */
require_once __DIR__ . '/torrentsSupport.php';

return [
    'torrents: list filters by state and name; the client id string stays internal' => function () {
        $dir = torrentsFixture();
        list($code, $out) = runPhp("$dir/fake.php", ['list', '--search', 'alpha']);
        $l = cliLines($out);
        check($code === 0 && count($l) === 3 && $l[0]['name'] === 'Alpha' && !isset($l[0]['native']), 'list: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['list', '--state', 'sleeping']);
        check($code === 1, 'unknown state accepted');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: ids are full or 8+ prefixes, never names; one bad id changes nothing; no verb reaches "all"' => function () {
        $dir = torrentsFixture();
        $a = str_repeat('a', 40);
        list($code) = runPhp("$dir/fake.php", ['pause', 'Alpha']);
        check($code === 1, 'a name was accepted as an id');
        list($code) = runPhp("$dir/fake.php", ['pause', 'aaaa']);
        check($code === 1, 'a 4-character prefix was accepted');
        list($code, $out) = runPhp("$dir/fake.php", ['pause', 'ab', str_repeat('b', 8)]);
        check($code === 1 && torrentsCalls($dir) === [], 'a bad id in a batch still changed something: ' . $out);
        list($code) = runPhp("$dir/fake.php", ['pause']);
        check($code === 1 && torrentsCalls($dir) === [], 'pause with no id reached the client');
        list($code) = runPhp("$dir/fake.php", ['pause', 'aaaaaaaa', str_repeat('b', 10)]);
        check($code === 0 && torrentsCalls($dir) === [['pause', [strtoupper($a), str_repeat('B', 40)]]], 'pause did not send the client its own ids');
        list($code) = runPhp("$dir/fake.php", ['remove', 'aaaaaaaa']);
        check($code === 1, 'remove accepted a prefix');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: remove keeps files; --with-data needs the matching --expect and refuses shared files' => function () {
        $dir = torrentsFixture();
        $a = str_repeat('a', 40);
        $b = str_repeat('b', 40);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b]);
        $l = cliLines($out);
        check($code === 0 && $l[0]['disposal'] === 'kept' && $l[1]['files'] === 'kept, disk not freed', 'plain remove: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['show', $b, '--files']);
        $s = cliLines($out);
        $s = $s[count($s) - 1];
        check($s['expect'] === '4.9KiB:5000' && $s['freed_bytes'] === 0, 'preview: the hard-linked file frees nothing: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', '--with-data', $b]);
        check($code === 1 && strpos($out, '--expect is missing') !== false, 'delete without --expect (flag before the id): ' . $out);
        list($code) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '9GiB:9663676416']);
        check($code === 1 && is_file("$dir/data/B/b.mkv"), 'delete with a wrong --expect');
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '4.9KiB:5000']);
        $l = cliLines($out);
        check($code === 0 && $l[0]['disposal'] === 'deleted' && $l[1]['freed_bytes'] === 0 && strpos($l[1]['note'], 'hard links') !== false, 'delete: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $a, '--with-data', '--expect', '2.9KiB:3000']);
        check($code === 1 && strpos($out, 'shares files') !== false && strpos($out, 'in this client') !== false, 'a file shared with another torrent was deleted: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $a, 'ab' . str_repeat('c', 38), '--with-data', '--expect', '2.9KiB:3000']);
        check($code === 0 && cliLines($out)[2]['freed_bytes'] === 3000, 'both sharers in one call must be allowed: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: a file reached through a symlinked folder is the same file (shared check by inode, overlap by real path)' => function () {
        $dir = torrentsFixture();
        $b = str_repeat('b', 40);
        symlink("$dir/data/B", "$dir/via-link");
        torrentsEdit($dir, function (&$s) use ($dir) {
            $s['torrents'][] = ['id' => str_repeat('d', 40), 'native' => str_repeat('D', 40), 'name' => 'Beta cross-seed', 'state' => 'seeding', 'path' => "$dir/via-link",
                'files' => ["$dir/via-link/b.mkv"]] + $s['torrents'][0];
        });
        list($code, $out) = runPhp("$dir/fake.php", ['show', $b, '--files']);
        $l = cliLines($out);
        check($l[count($l) - 1]['refused'] === [$b] && $l[1]['shared_with'] === [str_repeat('d', 40)] && strpos($l[count($l) - 1]['hint'], 'in this client') !== false, 'preview missed the twin: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['remove', $b, '--with-data', '--expect', '4.9KiB:5000']);
        check($code === 1 && strpos($out, 'Beta cross-seed') !== false && is_file("$dir/data/B/b.mkv") && torrentsCalls($dir) === [], 'deleted a file another torrent uses through a symlinked folder: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
];
