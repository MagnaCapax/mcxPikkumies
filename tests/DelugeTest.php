<?php
/** The deluge unit: list, show, add, pause and remove against a fake deluge-web (tests/fixtures/deluge/router.php; helpers in tests/fixtures/deluge/helpers.php), HOME in a temp dir. */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

return [
    'deluge: list logs in, connects to the localclient daemon and maps the states' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['list']);
            check($code === 0 && count($l) === 6, 'list: ' . $out);
            $by = array_column($l, null, 'name');
            check($l[0]['id'] === str_repeat('a', 40) && !isset($l[0]['native']), 'id first and lower case, native hidden: ' . $out);
            check($by['Show']['state'] === 'seeding' && $by['Other']['state'] === 'paused' && $by['Grabbing']['state'] === 'downloading'
                && $by['Moving one']['state'] === 'unknown' && $by['Allocating one']['state'] === 'checking', 'state mapping: ' . $out);
            check($by['Grabbing']['progress'] === 0.125 && $by['Show']['size'] === 4000 && (float) $by['Allocating one']['ratio'] === 0.0 && $by['Show']['ratio'] === 1.5, 'units: ' . $out);
            check($by['Other']['message'] === 'Error: unregistered torrent' && $by['Show']['message'] === '' && $by['Moving one']['message'] === 'Moving storage', 'message: ' . $out);
            check($by['Show']['path'] === $fx['home'] . '/data' && $by['Show']['added_at'] === 1700000000, 'path/added_at: ' . $out);
            $connect = delugeCalls($fx, 'web.connect');
            check(count($connect) === 1 && $connect[0]['params'] === ['h1'], 'connected to the wrong host: ' . json_encode($connect));
            $st = delugeCalls($fx, 'core.get_torrents_status');
            check(strpos($st[0]['raw'], '"params":[{}') !== false, 'the filter must be an empty object: ' . $st[0]['raw']);
            list($code, $l) = delugeRun($fx, ['list', '--state', 'paused']);
            check($code === 0 && count($l) === 2 && $l[0]['name'] === 'Other', 'state filter');
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: show takes a prefix in any case; --files gives the delete preview' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['show', 'AAAAAAAA']);
            check($code === 0 && $l[0]['id'] === str_repeat('a', 40) && $l[0]['tracker'] === 'tracker.example' && $l[0]['client_state'] === 'Seeding', 'show: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['show', str_repeat('a', 40), '--files']);
            $files = array_column(array_slice($l, 1, 2), 'file');
            check($code === 0 && $files === [$fx['home'] . '/data/Show/e1.mkv', $fx['home'] . '/data/Show/sub/e2.srt'], 'files: ' . $out);
            check($l[count($l) - 1]['expect'] === '3.9KiB:4000' && $l[count($l) - 1]['freed_bytes'] === 4000, 'preview: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: add sends magnets, URLs and files with the options and reports each id' => function () {
        $fx = delugeFixture();
        try {
            $hash = '0123456789abcdef0123456789abcdef01234567';
            file_put_contents($fx['dir'] . '/x.torrent', 'd4:infod4:name1:xee');
            mkdir($fx['home'] . '/data/new');
            list($code, $l, $out) = delugeRun($fx, ['add', "magnet:?xt=urn:btih:$hash&dn=Some+Name", $fx['dir'] . '/x.torrent', '/nope', '--paused', '--dir', $fx['home'] . '/data/new/']);
            check($code === 0 && $l[0]['id'] === $hash && $l[0]['name'] === 'Some Name' && $l[0]['result'] === 'added'
                && $l[0]['paused'] === true, 'magnet: ' . $out);
            check($l[1]['result'] === 'added' && $l[1]['name'] === 'x' && strlen($l[1]['id']) === 40 && $l[2]['result'] === 'failed'
                && $l[3]['verified'] === false, 'file and bad item: ' . $out);
            $m = delugeCalls($fx, 'core.add_torrent_magnet')[0]['params'];
            check($m[1] === ['add_paused' => true, 'download_location' => $fx['home'] . '/data/new'], 'options: ' . json_encode($m));
            $f = delugeCalls($fx, 'core.add_torrent_file')[0]['params'];
            check($f[0] === 'x.torrent' && base64_decode($f[1]) === 'd4:infod4:name1:xee', 'file contents: ' . json_encode($f));
            list($code, $l, $out) = delugeRun($fx, ['add', "magnet:?xt=urn:btih:$hash"]);
            check($code === 0 && $l[0]['result'] === 'already in the client' && $l[0]['id'] === $hash, 'a duplicate: ' . $out);
            list($code) = delugeRun($fx, ['add', 'magnet:?xt=urn:btih:' . $hash, '--dir', 'relative']);
            check($code === 1, 'a relative --dir was accepted');
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: pause and resume send exactly the named ids and read the state back' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['pause', 'aaaaaaaa', 'cccccccc']);
            check($code === 0 && count($l) === 3 && $l[0]['state'] === 'paused' && $l[0]['verified'] === true, 'pause: ' . $out);
            check(delugeCalls($fx, 'core.pause_torrents')[0]['params'] === [[str_repeat('a', 40), str_repeat('c', 40)]], 'pause ids');
            list($code, $l, $out) = delugeRun($fx, ['resume', str_repeat('b', 40)]);
            check($code === 0 && $l[0]['state'] === 'seeding' && $l[0]['verified'] === true, 'resume: ' . $out);
            check(delugeState($fx)['torrents'][str_repeat('e', 40)]['state'] === 'Allocating', 'another torrent changed');
            list($code) = delugeRun($fx, ['pause']);
            check($code === 1 && count(delugeCalls($fx, 'core.pause_torrents')) === 1, 'pause without ids reached Deluge');
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: remove keeps the files; --with-data deletes them and frees the space' => function () {
        $fx = delugeFixture();
        try {
            $b = str_repeat('b', 40);
            list($code, $l, $out) = delugeRun($fx, ['remove', $b]);
            check($code === 0 && $l[0]['disposal'] === 'kept' && $l[0]['verified'] === true && is_file($fx['home'] . '/data/other/o.iso'), 'plain remove: ' . $out);
            check(delugeCalls($fx, 'core.remove_torrent')[0]['params'] === [$b, false], 'remove params');
            $a = str_repeat('a', 40);
            list($code) = delugeRun($fx, ['remove', $a, '--with-data', '--expect', '1GiB:1073741824']);
            check($code === 1 && is_file($fx['home'] . '/data/Show/e1.mkv'), 'a wrong --expect deleted something');
            list($code, $l, $out) = delugeRun($fx, ['remove', $a, '--with-data', '--expect', '3.9KiB:4000']);
            check($code === 0 && $l[0]['disposal'] === 'deleted' && $l[0]['verified'] === true && $l[1]['freed_bytes'] === 4000, 'delete: ' . $out);
            check(!file_exists($fx['home'] . '/data/Show/e1.mkv') && !file_exists($fx['home'] . '/data/Show/sub/e2.srt'), 'files not deleted');
            check(delugeCalls($fx, 'core.remove_torrent')[1]['params'] === [$a, true], 'remove_data flag');
        } finally {
            delugeDone($fx);
        }
    },
];
