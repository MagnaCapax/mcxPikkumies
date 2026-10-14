<?php
/**
 * Edge cases of the shared extra verbs found in review, through the fake client of TorrentsTest.php (loaded first: name
 * order), plus the size rules for the torrent-clients code (AGENTS.md: small files, short lines).
 */
return [
    'torrents: a non-zero rate that rounds to 0 B/s is refused (0 means no limit); limit takes no positional argument' => function () {
        $dir = torrentsFixture();
        foreach ([['--down', '0.4'], ['--up', '0.0001KiB'], ['10MiB/s'], ['--down', '1MiB/s', 'extra']] as $bad) {
            list($code, $out) = runPhp("$dir/fake.php", array_merge(['limit'], $bad));
            check($code === 1 && torrentsCalls($dir) === [], implode(' ', $bad) . ' was accepted: ' . $out);
        }
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: the fake stores limits as qBittorrent does: up to 1 KiB/s is 1 KiB/s, more is cut down to whole KiB/s' => function () {
        $dir = torrentsFixture();
        list($code, $out) = runPhp("$dir/fake.php", ['limit', '--down', '1500', '--up', '1']);
        $l = cliLines($out);
        check($code === 0 && $l[0]['down_limit'] === 1024 && $l[0]['up_limit'] === 1024 && $l[1]['verified'] === true, 'floor: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: a bare key-like token in a tracker message is removed (a tracker may echo the passkey in prose)' => function () {
        $dir = torrentsFixture();
        list($code, $out) = runPhp("$dir/fake.php", ['show', 'aaaaaaaa', '--trackers']);
        $l = cliLines($out);
        check($code === 0 && strpos($out, '0123abcd4567ef89aa') === false && $l[1]['message'] === 'invalid passkey REDACTED, contact staff',
            'message: ' . $out);
        check(TorrentTrackers::message('Announce OK; 2 seeds') === 'Announce OK; 2 seeds', 'plain text changed');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrent-clients: files stay near 150 lines; the extra-verb code and tests keep lines within 140 columns' => function () {
        $root = dirname(__DIR__);
        foreach ((array) glob("$root/pikkumies/tools/torrent-clients/*/*.php") as $f) {
            check(count(file($f)) <= 155, basename($f) . ' has ' . count(file($f)) . ' lines');
        }
        $mine = array_merge((array) glob("$root/pikkumies/tools/torrent-clients/lib/Torrent[CLT]*.php"),
            (array) glob("$root/pikkumies/tools/torrent-clients/*/*Extras.php"), (array) glob("$root/tests/*VerbsTest.php"), [__FILE__]);
        foreach ($mine as $f) {
            foreach (file($f) as $n => $line) {
                check(strlen(rtrim($line, "\n")) <= 140, basename($f) . ':' . ($n + 1) . ' is longer than 140 columns');
            }
        }
    },
];
