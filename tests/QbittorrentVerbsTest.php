<?php
/**
 * tools/torrent-clients/qbittorrent: recheck, reannounce, limit, show --trackers and the new list fields against the fake
 * WebUI (tests/fixtures/qbittorrent/router.php); the exact HTTP calls are checked. No real client is contacted.
 */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';

/** The POST calls the fake received for $api, as their form fields. */
function qbitPosts(array $fx, $api)
{
    return array_values(array_map(function ($c) {
        return $c[2];
    }, array_filter(qbitState($fx)['calls'], function ($c) use ($api) {
        return $c[0] === 'POST' && $c[1] === $api;
    })));
}

return [
    'qbittorrent: recheck and reannounce POST exactly the named hashes, as the client listed them, never all' => function () {
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['recheck', 'aaaaaaaa', str_repeat('b', 40)]);
            $l = cliLines($out);
            check($code === 0 && qbitPosts($fx, 'torrents/recheck') === [['hashes' => str_repeat('a', 40) . '|' . str_repeat('B', 40)]],
                'recheck: ' . $out);
            check($l[0]['state'] === 'checking' && $l[0]['done_before'] === 3000 && $l[1]['done_before'] === 6000, 'read back: ' . $out);
            list($code, $out) = qbitRun($fx, ['reannounce', 'cccccccc']);
            check($code === 0 && qbitPosts($fx, 'torrents/reannounce') === [['hashes' => str_repeat('c', 40)]] &&
                cliLines($out)[0]['result'] === 'announce requested', 'reannounce: ' . $out);
            list($code) = qbitRun($fx, ['recheck', 'all']);
            check($code === 1 && count(qbitPosts($fx, 'torrents/recheck')) === 1 && empty(qbitState($fx)['sent_all']),
                '"all" reached the client');
        });
    },
    'qbittorrent: limit sends bytes/s, reads back the KiB-rounded value, and says when the alternative limits changed' => function () {
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['limit']);
            check($code === 0 && cliLines($out)[0] === ['down_limit' => 0, 'up_limit' => 1048576], 'read: ' . $out);
            list($code, $out) = qbitRun($fx, ['limit', '--down', '1000', '--up', '0']);
            $l = cliLines($out);
            check(qbitPosts($fx, 'transfer/setDownloadLimit') === [['limit' => '1000']] && qbitPosts($fx,
                'transfer/setUploadLimit') === [['limit' => '0']], 'calls: ' . json_encode(qbitState($fx)['calls']));
            check($code === 0 && $l[0]['down_limit'] === 1024 && $l[0]['up_limit'] === 0 && $l[0]['alt_speed'] === false &&
                $l[1]['verified'] === true, 'read back: ' . $out);
        });
        qbitWith(['alt' => 1], function ($fx) {
            list($code, $out) = qbitRun($fx, ['limit', '--up', '2MiB/s']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['alt_speed'] === true && strpos($l[0]['note'], 'alternative') !== false && qbitPosts($fx,
                'transfer/setDownloadLimit') === [], 'alt mode: ' . $out);
        });
    },
    'qbittorrent: show --trackers skips DHT rows, maps status words, and prints the host only' => function () {
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['show', 'aaaaaaaa', '--trackers']);
            $l = cliLines($out);
            check($code === 0 && count($l) === 4 && $l[1]['tracker'] === 'https://tracker.example' &&
                $l[2]['tracker'] === 'http://tracker2.example:2710', 'records: ' . $out);
            check($l[1]['status'] === 'working' && $l[1]['seeds'] === 9 && $l[2]['status'] === 'failing' &&
                $l[2]['message'] === 'unregistered torrent' && $l[2]['seeds'] === null && $l[2]['tier'] === 1, 'fields: ' . $out);
            check(strpos($out, 'SECRETKEY123') === false && strpos($out, 'SECRETPATHKEY') === false && strpos($out, 'DHT') === false,
                'leak or DHT row: ' . $out);
        });
    },
    'qbittorrent: list carries completion, seeding time, private and uploaded; null where the client gives none' => function () {
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['list', '--sort', 'completed_at', '--limit', '2']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['name'] === 'Alpha' && $l[0]['completed_at'] === 1700000900 && $l[0]['seeding_time'] === 7200 &&
                $l[0]['private'] === true && $l[0]['uploaded'] === 9000, 'fields: ' . $out);
            check($l[1]['completed_at'] === null && $l[1]['seeding_time'] === null && $l[1]['private'] === null &&
                $l[1]['uploaded'] === null,
                'missing fields must be null: ' . $out);
            list(, $out) = qbitRun($fx, ['show', 'cccccccc']);
            check(cliLines($out)[0]['completed_at'] === null, 'a completion time far in the future (4294967295) is not a time: ' . $out);
        });
    },
];
