<?php
/**
 * lib/CliHuman.php with mixed kinds of record, as `show <id> --files --trackers --human` prints them: one block per kind,
 * each with its own columns, so file and tracker rows are not pushed past the line width (live smoke run 2026-10-14).
 */
return [
    'cli --human: a torrent, its tracker and its files print as separate blocks; file paths stay readable' => function () {
        $id = str_repeat('f', 40);
        $torrent = ['id' => $id, 'name' => 'pksmk2-test', 'state' => 'seeding', 'progress' => 1, 'size' => 1048576, 'done' => 1048576,
            'ratio' => 0, 'down_rate' => 0, 'up_rate' => 0, 'added_at' => 1791979149, 'label' => '', 'path' => '/home/u/data/pksmk2-test',
            'message' => 'Tracker: [Could not connect]', 'completed_at' => 1791979149, 'seeding_time' => null, 'private' => true];
        $records = [$torrent,
            ['torrent' => $id, 'tracker' => 'http://127.0.0.1:9', 'tier' => 0, 'status' => 'failing', 'message' => null, 'seeds' => null],
            ['torrent' => $id, 'file' => '/home/u/data/pksmk2-test/a.bin', 'bytes' => 786432, 'links' => 1, 'on_disk' => true],
            ['torrent' => $id, 'file' => '/home/u/data/pksmk2-test/sub/b.txt', 'bytes' => 262144, 'links' => 1, 'on_disk' => true]];
        $cols = getenv('COLUMNS');
        putenv('COLUMNS=120'); // the width this test measures against, whatever the terminal running it says
        try {
            $out = CliHuman::render($records);
        } finally {
            putenv($cols === false ? 'COLUMNS' : "COLUMNS=$cols");
        }
        $wants = ['/home/u/data/pksmk2-test/a.bin', '/home/u/data/pksmk2-test/sub/b.txt', 'http://127.0.0.1:9', 'failing', '768.0 KiB'];
        foreach ($wants as $want) {
            check(strpos($out, $want) !== false, "missing $want:\n$out");
        }
        foreach (explode("\n", $out) as $line) {
            check(trim($line) !== '…' && preg_match_all('/./us', $line) <= 120, "a line was lost or too long:\n$out");
        }
        check(strpos($out, "name: pksmk2-test\n") !== false && preg_match('/^torrent\s+file\s+bytes\s+links\s+on_disk$/m', $out),
            "one key: value block for the torrent, one table for the files:\n$out");
    },
    'cli --human: records of one kind still make one table, optional fields included' => function () {
        $out = CliHuman::render([['id' => 'a', 'name' => 'x', 'size' => 1], ['id' => 'b', 'name' => 'y', 'size' => 2048, 'note' => 'n']]);
        $lines = explode("\n", rtrim($out));
        check(count($lines) === 3 && preg_match('/^id\s+name\s+size\s+note$/', $lines[0]) && strpos($lines[2], '2.0 KiB') !== false, $out);
    },
];
