<?php
/** The deluge unit: what remove --with-data checks and reports, against a fake deluge-web (tests/fixtures/deluge/router.php; helpers in tests/fixtures/deluge/helpers.php), HOME in a temp dir. */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

return [
    'deluge: files still on disk after the delete make it partial, free nothing for them, and end with ok:false' => function () {
        $fx = delugeFixture();
        try {
            $a = str_repeat('a', 40);
            $s = delugeState($fx);
            $s['keep_files'] = ['Show/e1.mkv']; // libtorrent failed to delete this one
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $l, $out) = delugeRun($fx, ['remove', $a, '--with-data', '--expect', '3.9KiB:4000']);
            check($code === 1 && $l[0]['ok'] === false && strpos($l[0]['error'], 'partial') !== false && strpos($l[0]['error'], 'freed 1000 bytes') !== false, 'partial delete: ' . $out);
            check(is_file($fx['home'] . '/data/Show/e1.mkv') && !is_file($fx['home'] . '/data/Show/sub/e2.srt'), 'fixture did not delete as set up');
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: the shared-file check reads all files in one call and finds a symlinked cross-seed in another folder' => function () {
        $fx = delugeFixture();
        try {
            $a = str_repeat('a', 40);
            $x = str_repeat('f', 40);
            mkdir($fx['home'] . '/cross/Show', 0700, true);
            symlink($fx['home'] . '/data/Show/e1.mkv', $fx['home'] . '/cross/Show/e1.mkv'); // a cross-seed tool in symlink mode
            $s = delugeState($fx);
            $s['torrents'][$x] = ['hash' => $x, 'name' => 'Show cross-seed', 'state' => 'Seeding', 'download_location' => $fx['home']
                . '/cross',
                'files' => [['index' => 0, 'path' => 'Show/e1.mkv', 'size' => 3000, 'offset' => 0]]] + $s['torrents'][$a];
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $l, $out) = delugeRun($fx, ['show', $a, '--files']);
            check($code === 0 && $l[count($l) - 1]['refused'] === [$a] && $l[1]['shared_with'] === [$x],
                'preview missed the cross-seed: ' . $out);
            $withFiles = function ($c) {
                return in_array('files', (array) $c['params'][1], true);
            };
            $bulk = array_values(array_filter(delugeCalls($fx, 'core.get_torrents_status'), $withFiles));
            $one = array_values(array_filter(delugeCalls($fx, 'core.get_torrent_status'), $withFiles));
            check(count($bulk) === 1 && count($one) === 1
                && $one[0]['params'][0] === $a, 'not one bulk call plus the target: ' . json_encode(array_column(delugeState($fx)['calls'],
                'method')));
            list($code, $l, $out) = delugeRun($fx, ['remove', $a, '--with-data', '--expect', '3.9KiB:4000']);
            check($code === 1 && strpos($out, 'Show cross-seed') !== false && is_file($fx['home'] . '/data/Show/e1.mkv')
                && !delugeCalls($fx, 'core.remove_torrent'),
                'deleted: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
];
