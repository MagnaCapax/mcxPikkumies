<?php
/**
 * tools/torrent-clients/rtorrent: list, show, add, pause and remove against a fake rTorrent
 * (tests/fixtures/rtorrent/server.php, helpers in tests/fixtures/rtorrent/helpers.php). Nothing real is contacted.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';

return [
    'rtorrent: list maps the client states and fields to the shared record' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['list']);
            check($code === 0 && count($l) === 7, 'list: ' . $out);
            $by = array_column(array_slice($l, 0, -1), null, 'name');
            check($l[0]['id'] === str_repeat('a', 40) && array_keys($l[0])[0] === 'id' && !isset($l[0]['native']), 'id must be lower case, first, and native hidden: ' . $out);
            $states = array_map(function ($r) {
                return $r['state'];
            }, $by);
            check($states === ['Multi' => 'seeding', 'single.mkv' => 'paused', 'Checking' => 'checking', 'Broken' => 'error', 'Linked' => 'seeding', 'Fetching' => 'downloading'], 'states: ' . json_encode($states));
            check($by['Multi']['ratio'] === 1.5 && $by['Multi']['label'] === 'TV Shows' && $by['Multi']['added_at'] === 1700000000 && $by['Multi']['path'] === "$h/data/Multi", 'fields: ' . json_encode($by['Multi']));
            check($by['single.mkv']['path'] === "$h/data/single.mkv" && $by['Fetching']['progress'] === 0.25 && $by['Fetching']['down_rate'] === 4096, 'fields: ' . $out);
            list(, , $l) = rtorrentRun($h, ['list', '--state', 'paused']);
            check(count($l) === 2 && $l[0]['name'] === 'single.mkv', 'state filter');
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: show gives details (trackers only with --trackers); the hash goes to rtorrent as d.hash prints it' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['show', 'aaaaaaaa']);
            check($code === 0 && $l[0]['id'] === str_repeat('a', 40) && array_keys($l[0])[0] === 'id' && $l[0]['seeds'] === 4 && $l[0]['private'] === true && $l[0]['file_count'] === 2, 'show: ' . $out);
            check(!isset($l[0]['trackers']) && !rtorrentCalls($h, 't.multicall') && strpos($out, 'SECRETPASSKEY123') === false,
                'show without --trackers read the trackers: ' . $out);
            check($l[0]['completed_at'] === 1700100000 && $l[0]['uploaded'] === 4500 && $l[0]['seeding_time'] === null,
                'list fields in show: ' . $out);
            check(rtorrentCalls($h, 'd.creation_date')[0][1] === str_repeat('A', 40), 'the target must be the upper-case hash');
            list($code, $out, $l) = rtorrentRun($h, ['show', str_repeat('a', 40), '--files']);
            $s = $l[count($l) - 1];
            check($code === 0 && count($l) === 4 && $l[1]['file'] === "$h/data/Multi/a.mkv" && $s['expect'] === '2.9KiB:3000' && $s['freed_bytes'] === 3000, 'files: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: add sends magnets and .torrent contents, reports the ids, and checks every item first' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            $hash = rtorrentTorrentFile("$h/x.torrent");
            $hex = '0123456789abcdef0123456789abcdef01234567';
            list($code, $out, $l) = rtorrentRun($h, ['add', "magnet:?xt=urn:btih:$hex&dn=Some+Show", "$h/x.torrent", '--dir', "$h/data/Multi"]);
            check($code === 0 && $l[0]['id'] === $hex && $l[0]['name'] === 'Some Show' && $l[0]['result'] === 'added' && $l[1]['id'] === strtolower($hash) && $l[1]['result'] === 'added', 'add: ' . $out);
            $start = rtorrentCalls($h, 'load.start')[0];
            check($start[1] === '' && $start[3] === 'd.directory.set=' . realpath("$h/data/Multi"), 'load.start params: ' . json_encode($start));
            check(rtorrentCalls($h, 'load.raw_start')[0][2] === 'base64:' . filesize("$h/x.torrent"), 'the .torrent contents were not sent as base64');
            list(, $out, $l) = rtorrentRun($h, ['add', "magnet:?xt=urn:btih:$hex"]);
            check($l[0]['result'] === 'already in rtorrent', 'duplicate: ' . $out);
            list(, , $l) = rtorrentRun($h, ['add', 'magnet:?xt=urn:btih:AEBAGBAFAYDQQCIKBMGA2DQPCAIREEYU', '--paused']);
            check($l[0]['id'] === '0102030405060708090a0b0c0d0e0f1011121314' && count(rtorrentCalls($h, 'load.normal')) === 1, 'base32 magnet or --paused');
            $before = count(rtorrentState($h)['calls']);
            list($code) = rtorrentRun($h, ['add', "magnet:?xt=urn:btih:$hex", "$h/nonexistent.torrent"]);
            check($code === 1 && count(rtorrentState($h)['calls']) === $before, 'a bad item still sent something');
            list($code) = rtorrentRun($h, ['add', "magnet:?xt=urn:btih:$hex", '--dir', '/tmp']);
            check($code === 1 && count(rtorrentState($h)['calls']) === $before, '--dir outside home accepted');
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: pause is d.stop, resume is d.start, each reads the state back' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['pause', 'aaaaaaaa', str_repeat('f', 40)]);
            check($code === 0 && $l[0]['state'] === 'paused' && $l[1]['state'] === 'paused', 'pause: ' . $out);
            check(array_column(rtorrentCalls($h, 'd.stop'), 1) === [str_repeat('A', 40), str_repeat('F', 40)], 'd.stop not sent per hash');
            list($code, $out, $l) = rtorrentRun($h, ['resume', 'bbbbbbbb']);
            check($code === 0 && $l[0]['state'] === 'seeding' && rtorrentCalls($h, 'd.start')[0][1] === str_repeat('B', 40), 'resume: ' . $out);
            list($code) = rtorrentRun($h, ['pause']);
            check($code === 1 && count(rtorrentCalls($h, 'd.stop')) === 2, 'pause without an id reached rtorrent');
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: remove keeps the files and never calls d.delete_tied; --with-data deletes only inside the torrent folder after d.erase' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['remove', str_repeat('b', 40)]);
            check($code === 0 && $l[0]['disposal'] === 'kept' && is_file("$h/data/single.mkv") && !rtorrentCalls($h, 'd.delete_tied'), 'remove: ' . $out);
            list($code, $out) = rtorrentRun($h, ['remove', str_repeat('a', 40), '--with-data', '--expect', '9GiB:9663676416']);
            check($code === 1 && is_file("$h/data/Multi/a.mkv"), 'a wrong --expect deleted files');
            list($code, $out, $l) = rtorrentRun($h, ['remove', str_repeat('a', 40), '--with-data', '--expect', '2.9KiB:3000']);
            check($code === 0 && $l[0]['disposal'] === 'deleted' && $l[1]['freed_bytes'] === 3000, 'delete: ' . $out);
            check(!file_exists("$h/data/Multi") && is_dir("$h/data"), 'the torrent folder must go, its parent must stay');
            $erased = rtorrentState($h)['erased'];
            check(count($erased[1]['erased_while_files_existed']) === 2, 'files were unlinked before d.erase (rtorrent would keep them open)');
            list($code, $out, $l) = rtorrentRun($h, ['remove', str_repeat('e', 40), '--with-data', '--expect', '1000B:1000']);
            check($code === 0 && !file_exists("$h/data/Linked/real.mkv") && is_file("$h/outside/victim.mkv"), 'a symlinked directory was followed: ' . $out);
            check($l[0]['disposal'] === 'partial' && $l[0]['orphans'] === ["$h/data/Linked/lnk/victim.mkv"] && $l[0]['errors'] === [] && $l[1]['freed_bytes'] === 1000 && $l[1]['skipped_files'] === 1, 'orphans not reported, or the freed file not counted: ' . $out);
            check(is_link("$h/data/Linked/lnk"), 'the symlink itself is not a torrent file and stays');
        } finally {
            rtorrentDone($h, $p);
        }
    },
];
