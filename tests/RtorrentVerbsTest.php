<?php
/**
 * tools/torrent-clients/rtorrent: recheck, reannounce, limit, show --trackers and the new list fields against the fake
 * rTorrent (tests/fixtures/rtorrent/server.php); the exact XML-RPC calls are checked. Nothing real is contacted.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';

return [
    'rtorrent: recheck is d.check_hash and reannounce d.tracker_announce (not .force), once per hash as d.hash prints it' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['recheck', 'aaaaaaaa', str_repeat('e', 40)]);
            check($code === 0 && array_column(rtorrentCalls($h, 'd.check_hash'), 1) === [str_repeat('A', 40), str_repeat('E', 40)],
                'd.check_hash: ' . $out);
            check($l[0]['state'] === 'checking' && $l[0]['done_before'] === 3000 && $l[1]['done_before'] === 1300, 'state read back: ' .
                $out);
            list($code, $out, $l) = rtorrentRun($h, ['reannounce', 'dddddddd']);
            check($code === 0 && array_column(rtorrentCalls($h, 'd.tracker_announce'), 1) === [str_repeat('D', 40)] && !rtorrentCalls($h,
                'd.tracker_announce.force'), 'announce: ' . $out);
            check($l[0]['message'] === 'Tracker: [Failure reason "unregistered torrent"]', 'message read back: ' . $out);
            list($code) = rtorrentRun($h, ['recheck', 'Multi']);
            check($code === 1 && count(rtorrentCalls($h, 'd.check_hash')) === 2, 'a name reached rtorrent');
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: limit reads the throttle, sets it with "" as target in bytes/s, and reads it back with persists false' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['limit']);
            check($code === 0 && $l[0] === ['down_limit' => 0, 'up_limit' => 512000], 'read: ' . $out);
            list($code, $out, $l) = rtorrentRun($h, ['limit', '--down', '1.5MiB/s', '--up', '0']);
            check(rtorrentCalls($h, 'throttle.global_down.max_rate.set') === [['throttle.global_down.max_rate.set', '', 1572864]]
                && rtorrentCalls($h, 'throttle.global_up.max_rate.set') === [['throttle.global_up.max_rate.set', '', 0]], 'setter calls: ' .
                    json_encode(rtorrentState($h)['calls']));
            check($code === 0 && $l[0]['down_limit'] === 1572864 && $l[0]['up_limit'] === 0 && $l[0]['was']['up_limit'] === 512000 &&
                $l[0]['persists'] === false && $l[1]['verified'] === true, 'read back: ' . $out);
            list($code, $out) = rtorrentRun($h, ['limit', '--up', '10 MB/s']);
            check($code === 1 && count(rtorrentCalls($h, 'throttle.global_up.max_rate.set')) === 1, 'an ambiguous unit reached rtorrent: ' .
                $out);
            // rTorrent keeps whole KiB/s (bytes >> 10, root.h:88-89): 1..1023 B/s would become 0, no limit.
            list($code, $out) = rtorrentRun($h, ['limit', '--down', '2MiB/s', '--up', '512']);
            check($code === 1 && strpos($out, '1024') !== false && count(rtorrentCalls($h, 'throttle.global_down.max_rate.set')) === 1,
                'under 1 KiB/s: ' . $out);
            list($code, $out, $l) = rtorrentRun($h, ['limit', '--down', '1500']);
            check($code === 0 && $l[0]['down_limit'] === 1024 && $l[1]['verified'] === true, 'cut to whole KiB/s: ' . $out);
            check(strpos($l[0]['note'], 'PMSS') === false, 'a PMSS note on a generic host: ' . $out);
            list($code, $out, $l) = rtorrentRun($h, ['limit', '--up', '1MiB/s'], "$h/run-pmss.php");
            check($code === 0 && strpos($l[0]['note'], 'watchdog') !== false && strpos($l[0]['note'], 'cap') !== false,
                'PMSS cap not mentioned: ' . $out);
            $doc = dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/';
            check(strpos((string) file_get_contents($doc . 'USAGE.md'), 'PMSS watchdog') !== false
                && strpos((string) file_get_contents($doc . 'README.md'), 'root.h:88-89') !== false
                && strpos((string) file_get_contents($doc . 'README.md'), 'sets no global rate') === false,
                'the docs miss the PMSS cap or the KiB rule');
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: show --trackers gives host, tier, status and scrape counts, never a passkey; list has the new fields' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['show', 'aaaaaaaa', '--trackers']);
            check($code === 0 && count($l) === 5, 'records: ' . $out);
            check(array_column(array_slice($l, 1, 3), 'tracker') === ['https://tracker.example:8443', 'udp://backup.example:6969',
                'http://old.example'], 'hosts: ' . $out);
            check(array_column(array_slice($l, 1, 3), 'status') === ['working', 'failing', 'disabled'] && $l[1]['seeds'] === 7 &&
                $l[1]['leechers'] === 3 && $l[2]['tier'] === 1 && $l[1]['message'] === null, 'fields: ' . $out);
            check($l[2]['seeds'] === null && $l[2]['leechers'] === null, 'a tracker that never reported counts must give null: ' . $out);
            check(strpos($out, 'SECRETPASSKEY123') === false && strpos($out, 'a1b2c3d4') === false && strpos($out, 'announce') === false,
                'a passkey or path was printed: ' . $out);
            check(rtorrentCalls($h, 't.multicall')[0][1] === str_repeat('A', 40), 't.multicall target');
            list($code, $out, $l) = rtorrentRun($h, ['list', '--sort', 'uploaded', '--limit', '1']);
            check($code === 0 && $l[0]['name'] === 'Multi' && $l[0]['completed_at'] === 1700100000 && $l[0]['private'] === true &&
                $l[0]['uploaded'] === 4500 && $l[0]['seeding_time'] === null, 'list fields: ' . $out);
            list(, , $l) = rtorrentRun($h, ['list', '--state', 'paused']);
            check($l[0]['completed_at'] === null && $l[0]['private'] === false, 'unfinished: completed_at null');
        } finally {
            rtorrentDone($h, $p);
        }
    },
];
