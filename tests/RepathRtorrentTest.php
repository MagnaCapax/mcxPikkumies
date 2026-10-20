<?php
/**
 * tools/torrent-clients/rtorrent: `repath` against the fake rTorrent (tests/fixtures/rtorrent/server.php).
 * Nothing real is contacted.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';

return [
    'rtorrent: repath refuses unless every file already exists at --to with the exact size' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            mkdir("$h/data/Elsewhere", 0700, true);
            // Only one of the two files is there, and it is the wrong size.
            file_put_contents("$h/data/Elsewhere/a.mkv", 'short');
            list($code, $out) = rtorrentRun($h, ['repath', 'aaaaaaaa', '--to', "$h/data/Elsewhere"]);
            check($code === 1 && strpos($out, 'nothing repathed') !== false && strpos($out, 'a.mkv') !== false
                && strpos($out, 'sub/b.nfo') !== false, "missing-data refusal: $out");
            check(!rtorrentCalls($h, 'd.directory_base.set'), 'nothing was set when the data was not verified: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: repath stops, sets d.directory_base.set (never d.directory.set), checks the hash, and restarts a started torrent' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            mkdir("$h/data/MultiMoved/sub", 0700, true);
            file_put_contents("$h/data/MultiMoved/a.mkv", str_repeat('a', 2000));
            file_put_contents("$h/data/MultiMoved/sub/b.nfo", str_repeat('b', 1000));
            list($code, $out, $l) = rtorrentRun($h, ['repath', 'aaaaaaaa', '--to', "$h/data/MultiMoved"]);
            check($code === 0, "repath failed: $out");
            check($l[0]['id'] === str_repeat('a', 40) && $l[0]['to'] === "$h/data/MultiMoved" && $l[0]['directory'] === "$h/data/MultiMoved"
                && $l[0]['was_started'] === true && $l[0]['verified'] === true, 'record: ' . $out);
            check(array_column(rtorrentCalls($h, 'd.directory_base.set'), 1) === [str_repeat('A', 40)], 'd.directory_base.set target: ' . $out);
            check(array_column(rtorrentCalls($h, 'd.directory_base.set'), 2) === ["$h/data/MultiMoved"], 'd.directory_base.set value: ' . $out);
            check(!rtorrentCalls($h, 'd.directory.set'), 'repath must never use d.directory.set (it would nest a multi-file torrent one level deeper): ' . $out);
            check(count(rtorrentCalls($h, 'd.stop')) === 1 && count(rtorrentCalls($h, 'd.start')) === 1, 'stop then restart (it was started): ' . $out);
            check(count(rtorrentCalls($h, 'd.check_hash')) === 1, 'check_hash: ' . $out);
            check(rtorrentState($h)['torrents'][0]['dir'] === "$h/data/MultiMoved", 'the fake server actually moved the pointer: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: repath on an already-stopped torrent does not restart it, and --to must be a real folder inside $HOME' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            mkdir("$h/moved", 0700, true);
            file_put_contents("$h/moved/single.mkv", str_repeat('s', 500));
            list($code, $out, $l) = rtorrentRun($h, ['repath', 'bbbbbbbb', '--to', "$h/moved"]);
            check($code === 0 && $l[0]['was_started'] === false, "stopped torrent repath: $out");
            check(!rtorrentCalls($h, 'd.start'), 'a torrent that was not started must not be started: ' . $out);
            list($code, $out) = rtorrentRun($h, ['repath', 'bbbbbbbb', '--to', "$h/does-not-exist"]);
            check($code === 1 && strpos($out, 'not an existing folder inside your home') !== false, '--to must already exist: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
];
