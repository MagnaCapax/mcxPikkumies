<?php
/**
 * The deluge unit's recheck, reannounce, limit, show --trackers and new list fields against the fake deluge-web
 * (tests/fixtures/deluge/router.php); the exact JSON-RPC calls are checked. No real Deluge is contacted.
 */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

return [
    'deluge: recheck and reannounce send one list of exactly the named ids; nothing else changes' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['recheck', 'aaaaaaaa', 'cccccccc']);
            check($code === 0 && delugeCalls($fx, 'core.force_recheck')[0]['params'] === [[str_repeat('a', 40), str_repeat('c', 40)]],
                'recheck: ' . $out);
            check($l[0]['state'] === 'checking' && $l[0]['done_before'] === 4000 && delugeState($fx)['torrents'][str_repeat('b',
                40)]['state'] === 'Paused', 'read back: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['reannounce', str_repeat('b', 40)]);
            check($code === 0 && delugeCalls($fx, 'core.force_reannounce')[0]['params'] === [[str_repeat('b', 40)]] &&
                $l[0]['message'] === 'Error: unregistered torrent', 'reannounce: ' . $out);
            list($code) = delugeRun($fx, ['reannounce']);
            check($code === 1 && count(delugeCalls($fx, 'core.force_reannounce')) === 1, 'reannounce without ids reached Deluge');
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: limit converts bytes/s to KiB/s floats, -1.0 for no limit, and reads back in bytes/s' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['limit']);
            check($code === 0 && $l[0] === ['down_limit' => 0, 'up_limit' => 524288], 'read: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['limit', '--down', '10MiB/s', '--up', '0']);
            $set = delugeCalls($fx, 'core.set_config');
            check(count($set) === 1 && strpos($set[0]['raw'], '"max_download_speed":10240.0') !== false && strpos($set[0]['raw'],
                '"max_upload_speed":-1.0') !== false, 'set_config: ' . json_encode($set));
            check($code === 0 && $l[0]['down_limit'] === 10485760 && $l[0]['up_limit'] === 0 && $l[0]['was'] === ['down_limit' => 0,
                'up_limit' => 524288] && $l[1]['verified'] === true, 'read back: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['limit', '--up', '1536']);
            check($code === 0 && strpos(delugeCalls($fx, 'core.set_config')[1]['raw'], '"max_upload_speed":1.5') !== false &&
                $l[0]['up_limit'] === 1536, 'a fraction of a KiB: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: show --trackers lists host and tier only; list carries completion, seeding time, private, uploaded' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['show', 'aaaaaaaa', '--trackers']);
            check($code === 0 && count($l) === 3 && $l[1]['tracker'] === 'https://tracker.example' && $l[1]['tier'] === 0 &&
                $l[1]['status'] === null && $l[1]['seeds'] === null, 'trackers: ' . $out);
            check(strpos($out, 'SECRETDELUGEKEY99') === false && strpos($out, 'a1b2c3d4') === false, 'a passkey was printed: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['list', '--sort', 'seeding_time']);
            check($code === 1 && strpos($out, 'uploaded') !== false, 'seeding_time is not a sort key: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['list', '--sort', 'uploaded', '--limit', '1']);
            check($code === 0 && $l[0]['name'] === 'Show' && $l[0]['completed_at'] === 1700000500 && $l[0]['seeding_time'] === 86400 &&
                $l[0]['private'] === true && $l[0]['uploaded'] === 6000, 'fields: ' . $out);
            list(, $l) = delugeRun($fx, ['list', '--state', 'paused']);
            check($l[0]['completed_at'] === null && $l[0]['private'] === false, 'unfinished: ' . json_encode($l));
            list(, $l, $out) = delugeRun($fx, ['show', 'bbbbbbbb']);
            check($l[0]['completed_at'] === null && $l[0]['seeding_time'] === 0 && $l[0]['private'] === false,
                'show must keep the list fields: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
];
