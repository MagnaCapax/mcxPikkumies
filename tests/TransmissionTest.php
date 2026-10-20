<?php
/** The transmission unit (tools/torrent-clients/transmission) against a fake RPC server (tests/fixtures/transmission). */
if (!function_exists('transmissionFixture')) {
    require __DIR__ . '/fixtures/transmission/helpers.php';
}

return [
    'transmission: list maps status/error/isStalled to the shared state words, done, labels and message' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['list']);
            check($code === 0, "list failed: $out");
            $byName = [];
            foreach ($l as $r) {
                if (isset($r['name'])) {
                    $byName[$r['name']] = $r;
                }
            }
            check($byName['Show']['state'] === 'seeding' && $byName['Show']['label'] === 'tv,hd' && $byName['Show']['done'] === 4000
                && $byName['Show']['private'] === true && $byName['Show']['completed_at'] === 1700000500 && $byName['Show']['seeding_time'] === 86400
                && $byName['Show']['uploaded'] === 6000, 'Show record: ' . $out);
            check(!isset($byName['Show']['native']), 'the client id string leaked into the record: ' . $out);
            check($byName['Other']['state'] === 'paused', 'status 0 must map to paused: ' . $out);
            check($byName['Grabbing']['state'] === 'downloading' && abs($byName['Grabbing']['progress'] - 0.125) < 0.0001, 'status 4: ' . $out);
            check($byName['Errored']['state'] === 'error' && $byName['Errored']['message'] === 'No data found! Ensure your drives are connected.',
                'a nonzero error must override the status mapping: ' . $out);
            check($byName['StalledOne']['state'] === 'stalled', 'isStalled must override downloading when error is 0: ' . $out);
            check($byName['Show']['id'] === str_repeat('a', 40), 'id must be the lower-case hashString: ' . $out);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: show adds seeds/peers (split by connected-peer progress) and swarm totals from trackerStats' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['show', str_repeat('a', 40)]);
            check($code === 0 && count($l) === 2, "show failed: $out");
            $r = $l[0];
            check($r['seeds'] === 2 && $r['peers'] === 1, 'connected seeds (progress 1.0) vs peers: ' . $out);
            check($r['swarm_seeds'] === 5 && $r['swarm_peers'] === 2, 'trackerStats seederCount/leecherCount: ' . $out);
            check($r['uploaded_bytes'] === 6000 && $r['total_size'] === 4000 && $r['file_count'] === 2, 'extra fields: ' . $out);
            list(, $l2, $out2) = transmissionRun($fx, ['show', str_repeat('b', 40)]);
            check($l2[0]['eta'] === null, 'eta -1 (unknown) must become null: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: show --trackers approximates status from isBackup/hasAnnounced/lastAnnounceSucceeded and never prints the passkey' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['show', str_repeat('a', 40), '--trackers']);
            check($code === 0, "show --trackers failed: $out");
            $tr = null;
            foreach ($l as $r) {
                if (isset($r['tracker'])) {
                    $tr = $r;
                }
            }
            check($tr !== null && $tr['tracker'] === 'https://tracker.example' && $tr['status'] === 'working'
                && $tr['seeds'] === 5 && $tr['leechers'] === 2, 'tracker record: ' . $out);
            check(stripos($out, 'SECRETPASSKEY') === false, 'the passkey leaked into the output: ' . $out);
            list(, $l2, $out2) = transmissionRun($fx, ['show', str_repeat('e', 40), '--trackers']);
            $tr2 = null;
            foreach ($l2 as $r) {
                if (isset($r['tracker'])) {
                    $tr2 = $r;
                }
            }
            check($tr2 !== null && $tr2['status'] === 'not contacted' && $tr2['seeds'] === null && $tr2['leechers'] === null,
                'no hasAnnounced and negative counts: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: add detects a magnet duplicate as torrent-duplicate (still result success) and reports a new one as added' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['add', 'magnet:?xt=urn:btih:' . str_repeat('a', 40) . '&dn=Show']);
            check($code === 0 && $l[0]['id'] === str_repeat('a', 40) && $l[0]['result'] === 'already in the client', 'duplicate: ' . $out);
            $newHash = str_repeat('f', 40);
            list($code2, $l2, $out2) = transmissionRun($fx, ['add', 'magnet:?xt=urn:btih:' . $newHash . '&dn=NewOne', '--paused']);
            check($code2 === 0 && $l2[0]['id'] === $newHash && $l2[0]['result'] === 'added' && $l2[0]['paused'] === true
                && $l2[1]['verified'] === true, 'new paused torrent: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: add sends a local .torrent file as base64 metainfo, never the magnet/url path' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['add', $fx['home'] . '/torrents/a.torrent']);
            check($code === 0, "file add failed: $out");
            $calls = transmissionCalls($fx, 'torrent-add');
            $last = $calls[count($calls) - 1];
            check(isset($last['arguments']['metainfo']) && !isset($last['arguments']['filename']), 'file add did not send metainfo: ' . json_encode($last));
            check(base64_decode($last['arguments']['metainfo']) === file_get_contents($fx['home'] . '/torrents/a.torrent'), 'metainfo was not the raw file bytes');
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: remove keeps files by default; --with-data deletes them for good (no trash) and frees the bytes' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['remove', str_repeat('c', 40)]);
            check($code === 0 && $l[0]['disposal'] === 'kept', 'plain remove: ' . $out);
            check(is_file($fx['home'] . '/data/other/o.iso'), 'plain remove on a different torrent deleted an unrelated file');
            list($code2, $l2, $out2) = transmissionRun($fx, ['remove', str_repeat('b', 40), '--with-data', '--expect', '1.9KiB:2000']);
            check($code2 === 0 && $l2[0]['disposal'] === 'deleted' && $l2[1]['freed_bytes'] === 2000, 'delete with data: ' . $out2);
            check(!is_file($fx['home'] . '/data/other/o.iso'), 'the file is still on disk after --with-data');
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: pause and resume send torrent-stop/torrent-start with the exact ids and read the state back' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['pause', str_repeat('a', 40)]);
            check($code === 0 && $l[0]['state'] === 'paused' && $l[0]['verified'] === true, 'pause: ' . $out);
            check(transmissionCalls($fx, 'torrent-stop')[0]['arguments']['ids'] === [str_repeat('a', 40)], 'pause did not send the exact id: ' . $out);
            list($code2, $l2, $out2) = transmissionRun($fx, ['resume', str_repeat('a', 40)]);
            check($code2 === 0 && $l2[0]['state'] === 'seeding' && $l2[0]['verified'] === true, 'resume: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: recheck and reannounce go through the shared TorrentChange verbs onto torrent-verify/torrent-reannounce' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['recheck', str_repeat('b', 40)]);
            check($code === 0 && $l[0]['result'] === 'recheck requested' && count(transmissionCalls($fx, 'torrent-verify')) === 1, 'recheck: ' . $out);
            list($code2, $l2, $out2) = transmissionRun($fx, ['reannounce', str_repeat('b', 40)]);
            check($code2 === 0 && $l2[0]['result'] === 'announce requested' && count(transmissionCalls($fx, 'torrent-reannounce')) === 1, 'reannounce: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: limit reads session-get, sets with the *-enabled gate (0 disables, not a sentinel), and reads back' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['limit']);
            check($code === 0 && $l[0] === ['down_limit' => 0, 'up_limit' => 0], "read: $out");
            list($code2, $l2, $out2) = transmissionRun($fx, ['limit', '--down', '10MiB/s', '--up=1000']);
            check($code2 === 0 && $l2[0]['down_limit'] === 10485760 && $l2[0]['up_limit'] === 1024
                && $l2[0]['was'] === ['down_limit' => 0, 'up_limit' => 0] && $l2[1]['verified'] === true, "set: $out2");
            $set = transmissionCalls($fx, 'session-set');
            check($set[0]['arguments']['speed-limit-down-enabled'] === true && $set[0]['arguments']['speed-limit-down'] === 10240,
                'KBps conversion and enabled flag: ' . json_encode($set[0]));
            list($code3, $l3, $out3) = transmissionRun($fx, ['limit', '--up', '0']);
            check($code3 === 0 && $l3[0]['up_limit'] === 0 && $l3[0]['down_limit'] === 10485760, '0 disables, down left alone: ' . $out3);
            $set2 = transmissionCalls($fx, 'session-set');
            check($set2[count($set2) - 1]['arguments']['speed-limit-up-enabled'] === false
                && !array_key_exists('speed-limit-up', $set2[count($set2) - 1]['arguments']), '0 must turn the flag off without sending a value: ' . json_encode($set2));
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: stats and traffic read session-get/session-stats, by_state counts, and the quota fields' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['stats']);
            check($code === 0 && $l[0]['client'] === 'transmission' && $l[0]['version'] === '4.1.3 (fake)' && $l[0]['torrents'] === 5
                && $l[0]['by_state']['seeding'] === 1 && $l[0]['by_state']['paused'] === 1 && $l[0]['by_state']['error'] === 1
                && $l[0]['by_state']['stalled'] === 1 && array_key_exists('quota_free_bytes', $l[0]), 'stats: ' . $out);
            list($code2, $l2, $out2) = transmissionRun($fx, ['traffic']);
            check($code2 === 0 && $l2[0]['down_rate'] === 1000 && $l2[0]['up_rate'] === 2000
                && $l2[0]['down_total'] === 30000 && $l2[0]['up_total'] === 40000, 'traffic: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: repath refuses with no change unless every file already exists at --to with the right size, then calls torrent-set-location+torrent-verify' => function () {
        $fx = transmissionFixture();
        try {
            @mkdir($fx['home'] . '/moved', 0700, true);
            list($code, $l, $out) = transmissionRun($fx, ['repath', str_repeat('b', 40), '--to', $fx['home'] . '/moved']);
            check($code === 1 && strpos($out, 'nothing repathed') !== false, 'repath ran without the data being there: ' . $out);
            check(transmissionCalls($fx, 'torrent-set-location') === [], 'repath mutated the client despite missing data');
            @mkdir($fx['home'] . '/moved/other', 0700, true);
            copy($fx['home'] . '/data/other/o.iso', $fx['home'] . '/moved/other/o.iso'); // repath keeps the path relative to the old downloadDir ("other/o.iso")
            list($code2, $l2, $out2) = transmissionRun($fx, ['repath', str_repeat('b', 40), '--to', $fx['home'] . '/moved']);
            check($code2 === 0 && $l2[0]['verified'] === true && rtrim($l2[0]['path'], '/') === rtrim($fx['home'] . '/moved', '/'), 'repath: ' . $out2);
            $loc = transmissionCalls($fx, 'torrent-set-location');
            check(count($loc) === 1 && $loc[0]['arguments']['move'] === false, 'repath must ask for move:false: ' . json_encode($loc));
            check(count(transmissionCalls($fx, 'torrent-verify')) === 1, 'repath did not request a hash-check');
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: export prints the saved .torrent as base64 from torrentFile, and refuses when there is none' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['export', str_repeat('a', 40)]);
            check($code === 0 && $l[0]['torrent_base64'] === base64_encode(file_get_contents($fx['home'] . '/torrents/a.torrent')),
                "export: $out");
            list($code2, $l2, $out2) = transmissionRun($fx, ['export', str_repeat('b', 40)]);
            check($code2 === 1 && strpos($out2, 'no .torrent file on disk') !== false, 'export of a torrent with no torrentFile should refuse: ' . $out2);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: a 409 session-id challenge is handled once per call, with the id from the server\'s own response header' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $l, $out) = transmissionRun($fx, ['list']);
            check($code === 0, "first call with no session id must still succeed after the 409: $out");
            check(strpos($out, TRANSMISSION_SESSION) === false, 'the session id leaked into stdout');
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission: --verbs lists exactly the USAGE.md verbs, including repath and export' => function () {
        $fx = transmissionFixture();
        try {
            list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/tools/torrent-clients/transmission/transmission.php', ['--verbs']);
            $have = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
            sort($have);
            $want = ['add', 'export', 'limit', 'list', 'login', 'pause', 'reannounce', 'recheck', 'remove', 'repath', 'resume', 'restart', 'show', 'stats', 'traffic'];
            sort($want);
            check($have === $want, '--verbs: ' . json_encode($have));
        } finally {
            transmissionDone($fx);
        }
    },
];
