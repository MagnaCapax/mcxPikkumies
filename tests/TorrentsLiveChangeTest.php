<?php
/**
 * Defects a live run of the changing verbs found on 2026-10-14 (rTorrent 0.16.22, qBittorrent 5.1.4/5.2.4, Deluge 2.2.0;
 * the outputs under the run's out/ folder), each against the unit's fake: rTorrent pause read back from d.state and
 * d.is_active, Deluge add on the shared contract, the qBittorrent trash preview, and the small qBittorrent fields.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';
require_once __DIR__ . '/fixtures/deluge/helpers.php';
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';

return [
    'rtorrent: pause of a torrent with a tracker message reads paused and verified from d.state/d.is_active, message kept' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            // Live rt-pause.txt: {"state":"error"} and no verified for a torrent whose raw d.state and d.is_active were 0.
            list($code, $out, $l) = rtorrentRun($h, ['pause', 'dddddddd']);
            check($code === 0 && $l[0]['state'] === 'paused' && $l[0]['result'] === 'paused' && $l[1]['verified'] === true
                && $l[0]['message'] === 'Tracker: [Failure reason "unregistered torrent"]', 'pause: ' . $out);
            list(, , $l) = rtorrentRun($h, ['list', '--state', 'paused']);
            check(in_array('Broken', array_column($l, 'name'), true), 'a stopped torrent with a message must list as paused');
            list($code, $out, $l) = rtorrentRun($h, ['resume', 'dddddddd']);
            check($code === 0 && $l[0]['state'] === 'error' && $l[0]['result'] === 'resumed' && $l[1]['verified'] === true,
                'a started torrent with a message is error again: ' . $out);
            $s = rtorrentState($h);
            $s['torrents'][0]['ignore_toggle'] = 1;
            file_put_contents("$h/state.json", json_encode($s));
            list($code, $out, $l) = rtorrentRun($h, ['pause', 'aaaaaaaa']);
            check($code === 0 && $l[0]['result'] === 'not changed yet' && $l[1]['verified'] === false, 'an ignored stop: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'deluge: add names the torrent, says added with a paused flag, reads it back, and a duplicate is "already in the client"' => function () {
        $fx = delugeFixture();
        try {
            $info = 'd6:lengthi5e4:name8:pkA-real12:piece lengthi16384e6:pieces20:' . str_repeat('p', 20) . 'e';
            file_put_contents($fx['dir'] . '/pkA-dl.torrent', 'd8:announce17:http://t.example/a4:info' . $info . 'e');
            $id = sha1($info);
            // Live de-add-A.txt: name "pkA-dl.torrent" (the file); de-add-B.txt: "added paused" and no verified.
            list($code, $l, $out) = delugeRun($fx, ['add', $fx['dir'] . '/pkA-dl.torrent', '--paused']);
            check($code === 0 && $l[0]['id'] === $id && $l[0]['name'] === 'pkA-real' && $l[0]['result'] === 'added'
                && $l[0]['paused'] === true && $l[1]['verified'] === true, 'add: ' . $out);
            // Live de-add-A-again.txt: ok:false with Deluge's traceback text.
            list($code, $l, $out) = delugeRun($fx, ['add', $fx['dir'] . '/pkA-dl.torrent']);
            check($code === 0 && $l[0]['result'] === 'already in the client' && $l[0]['name'] === 'pkA-real' && $l[0]['id'] === $id
                && $l[1]['verified'] === true && strpos($out, 'Traceback') === false, 'duplicate: ' . $out);
            // A URL's id is known only to Deluge: its refusal "Torrent already in session (<id>)" is read, not printed raw.
            $urlId = sha1('http://t.example/x.torrent'); // the fake's id for a URL item
            delugeEdit($fx, function (array &$s) use ($urlId) {
                $s['torrents'][$urlId] = ['hash' => $urlId, 'name' => 'From URL'] + array_values($s['torrents'])[0];
            });
            list($code, $l, $out) = delugeRun($fx, ['add', 'http://t.example/x.torrent']);
            check($code === 0 && $l[0]['result'] === 'already in the client' && $l[0]['id'] === $urlId && $l[0]['name'] === 'From URL'
                && strpos($out, 'Traceback') === false && strpos($out, 'AddTorrentError') === false, 'a duplicate Deluge refuses: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'qbittorrent: the trash-mode preview says freed 0 (moved to trash) as the delete does; delete mode keeps the real total' => function () {
        $B = str_repeat('b', 40);
        qbitWith([], function ($fx) use ($B) { // MoveToTrash, the fixture's (and 5.x's) default
            // Live qb51-showfiles-B.txt: freed_bytes 524288 in the preview; qb51-remove-B-data.txt: freed_bytes 0.
            list($code, $out) = qbitRun($fx, ['show', $B, '--files']);
            $s = cliLines($out)[count(cliLines($out)) - 1];
            check($code === 0 && $s['freed_bytes'] === 0 && $s['bytes'] === 6000 && $s['disposal'] === 'trashed'
                && strpos($s['note'], 'trash') !== false && $s['expect'] === '5.9KiB:6000', 'preview: ' . $out);
            list($code, $out) = qbitRun($fx, ['remove', $B, '--with-data', '--expect', '5.9KiB:6000']);
            check($code === 0 && cliLines($out)[1]['freed_bytes'] === 0, 'the delete says the same: ' . $out);
        });
        $prefs = ['torrent_content_remove_option' => 'Delete', 'listen_port' => 1, 'save_path' => '/tmp'];
        qbitWith(['prefs' => $prefs], function ($fx) use ($B) {
            list($code, $out) = qbitRun($fx, ['show', $B, '--files']);
            $s = cliLines($out)[count(cliLines($out)) - 1];
            check($code === 0 && $s['freed_bytes'] === 6000 && !isset($s['disposal']), 'delete-mode preview: ' . $out);
        });
    },
    'qbittorrent: show gives null for creation_date -1; reannounce carries the tracker message; read-backs say requested' => function () {
        qbitWith([], function ($fx) {
            $s = qbitState($fx);
            $s['torrents'][0]['props'] += ['creation_date' => -1, 'completion_date' => 1700000900]; // live qb51-showfiles-B.txt: -1
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $out) = qbitRun($fx, ['show', 'aaaaaaaa']);
            $l = cliLines($out);
            check($code === 0 && array_key_exists('creation_date', $l[0]) && $l[0]['creation_date'] === null
                && $l[0]['completion_date'] === 1700000900, 'show: ' . $out);
            // Live qb52-reannounce.txt: message "" while the tracker refused the connection.
            list($code, $out) = qbitRun($fx, ['reannounce', 'aaaaaaaa']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['message'] === 'http://tracker2.example:2710: unregistered torrent'
                && strpos($l[1]['read_back'], 'requested; state right after request') === 0, 'reannounce: ' . $out);
            list($code, $out) = qbitRun($fx, ['recheck', 'aaaaaaaa']);
            check($code === 0 && strpos(cliLines($out)[1]['read_back'], 'requested; state right after request') === 0, 'recheck: ' . $out);
        });
    },
];
