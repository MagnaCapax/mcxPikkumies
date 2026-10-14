<?php
/** The lidarr unit against the fake Servarr API (tests/fixtures/arr/, seed-v1.php): shared lib, Lidarr's table entry. */
require_once __DIR__ . '/fixtures/arr/harness.php';
require_once __DIR__ . '/fixtures/arr/phase2.php';

return [
    'lidarr: list, show, stats, queue, wanted and history; every boolean the spec declares is sent' => function () {
        $fx = arrFixture('lidarr', ['where' => 'servarr']);
        try {
            list($code, $l, $out) = arrRun($fx, ['list', '--search', 'artist c']);
            check($code === 0 && count($l) === 2 && $l[0]['id'] === 2 && $l[0]['title'] === 'Artist C'
                && $l[0]['meta_id'] === 'mbid:0c0c0c0c-1111-2222-3333-444444444444' && $l[0]['tracks'] === 12
                && $l[0]['tracks_total'] === 30
                && $l[0]['albums'] === 3 && $l[0]['size'] === 500 && !isset($l[0]['has_file']), 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', '1']);
            check($code === 0 && $l[0]['title'] === 'Artist B' && !array_key_exists('imdb', $l[0])
                && $l[0]['overview'] === 'A band.', 'show: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 0 && $l[0]['found'] === 'servarr' && $l[0]['artist'] === 2
                && $l[0]['free_bytes'] === 5000000000, 'stats: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['wanted']);
            check($code === 0 && $l[0] === ['id' => 31, 'item' => 1, 'title' => 'Album X', 'released_at' => 1577923200], 'wanted: ' . $out);
            foreach ([['queue'], ['wanted', '--cutoff'], ['history'], ['history', '--since', '2026-10-01'], ['remove', '2']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args); // the fake answers 400 and logs a violation when a boolean is missing
                check($code === 0, implode(' ', $args) . ': ' . $out);
            }
            check(arrCalls($fx, 'DELETE', 'artist/2')[0]['query'] === 'deleteFiles=false&addImportListExclusion=false', 'remove params');
            check(arrCalls($fx, 'GET', 'history')[0]['query'] === 'page=1&pageSize=20&sortKey=date&sortDirection=descending'
                . '&includeArtist=false&includeAlbum=false&includeTrack=false', 'history params');
        } finally {
            arrDone($fx);
        }
    },
    'lidarr: lookup and add by mbid:, the metadata profile from the root folder; search is ArtistSearch; plain numbers are never mbids' => function () {
        $fx = arrFixture('lidarr', ['where' => 'servarr']);
        try {
            list($code, $l, $out) = arrRun($fx, ['lookup', 'new artist']);
            check($code === 0 && $l[0]['meta_id'] === 'mbid:abcdef01-2345-6789-abcd-ef0123456789' && $l[0]['in_library'] === false
                && $l[0]['title'] === 'New Artist', 'lookup: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['add', 'mbid:ABCDEF01-2345-6789-abcd-ef0123456789', 'mbid:0b0b0b0b-1111-2222-3333-444444444444',
                '--no-search', '--profile', 'Any']);
            $added = arrState($fx)['added'];
            check($code === 0 && $l[0]['result'] === 'added' && $l[0]['title'] === 'New Artist'
                && $l[1]['result'] === 'exists' && $l[1]['id'] === 1
                && $added[0]['metadataProfileId'] === 2
                && $added[0]['addOptions'] === ['monitor' => 'all', 'searchForMissingAlbums' => false]
                && $added[0]['monitored'] === true, 'add: ' . $out . json_encode($added));
            list($code, $l, $out) = arrRun($fx, ['add', '603']);
            check($code === 1 && strpos($out, 'not an id Lidarr adds by (mbid:<n>)') !== false, 'a plain number was taken as an mbid: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['remove', 'mbid:0b0b0b0b-1111-2222-3333-444444444444']);
            check($code === 1 && strpos($out, 'mbid: ids are for `lookup` and `add`') !== false && !arrCalls($fx, 'DELETE', 'artist/1'),
                'an mbid was taken as a library id: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['search', '1', '2']);
            $cmds = array_values(arrState($fx)['commands']);
            check($code === 0 && count($cmds) === 2
                && $cmds[0]['body'] === ['name' => 'ArtistSearch', 'artistId' => 1], 'search: ' . json_encode($cmds));
        } finally {
            arrDone($fx);
        }
    },
    'lidarr: one id\'s failed lookup or one failed search POST never hides what happened to the others' => function () {
        $fx = arrFixture('lidarr', ['where' => 'servarr', 'failLookup' => ['0b0b']]);
        try {
            $a = 'mbid:abcdef01-2345-6789-abcd-ef0123456789';
            list($code, $l, $out) = arrRun($fx, ['add', $a, 'mbid:0b0b0b0b-1111-2222-3333-444444444444', '--no-search', '--profile', 'Any']);
            check($code === 0 && $l[0]['result'] === 'added' && $l[0]['title'] === 'New Artist' && $l[1]['result'] === 'failed'
                && strpos($l[1]['error'], 'HTTP 503') !== false && count(arrState($fx)['added']) === 1, 'second lookup 503: ' . $out);
            arrSet($fx, ['failCommandAt' => 2]);
            list($code, $l, $out) = arrRun($fx, ['search', '1', '2']);
            $err = $l[count($l) - 1]['error'];
            check($code === 1 && strpos($err, 'ArtistSearch for 1 (Artist B) (command 1000): completed') !== false
                && strpos($err, 'ArtistSearch for 2 (Artist C): failed, sending failed: the app failed (HTTP 500)') !== false
                && count(arrState($fx)['commands']) === 1, 'second POST 500: ' . $out);
            arrSet($fx, ['failCommandAt' => 1, 'commandPosts' => 0, 'commands' => []]);
            list($code, $l, $out) = arrRun($fx, ['search', '1', '2']);
            $err = $l[count($l) - 1]['error'];
            check($code === 1 && strpos($err, 'for 1 (Artist B): failed') !== false && strpos($err, 'for 2 (Artist C): not_sent') !== false
                && count(arrState($fx)['commands']) === 0, 'first POST 500: ' . $out);
            arrSet($fx, ['failCommandAt' => 0, 'commandPosts' => 0, 'commands' => [], 'failPoll' => true]);
            list($code, $l, $out) = arrRun($fx, ['search', '1', '2']);
            $err = $l[count($l) - 1]['error'];
            check($code === 1 && substr_count($err, ': unknown, sent, but its state could not be read') === 2
                && strpos($err, '(command 1001)') !== false && count(arrState($fx)['commands']) === 2, 'poll 500 after both POSTs: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'lidarr: --with-data needs the exact --expect; Lidarr deletes the artist folder' => function () {
        $fx = arrFixture('lidarr', ['where' => 'servarr']);
        $b = $fx['home'] . '/media/Film B';
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data', '--expect', '2.0KiB:2047']);
            check($code === 1 && is_dir($b) && !arrCalls($fx, 'DELETE', 'artist/1'), 'a wrong --expect deleted: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', '1', '2', '--files']);
            check($code === 0 && $l[count($l) - 1]['expect'] === '2.5KiB:2548', 'preview: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data', '--expect', '2.0KiB:2048']);
            clearstatcache();
            check($code === 0 && $l[0]['disposal'] === 'deleted' && !is_dir($b)
                && arrCalls($fx, 'DELETE', 'artist/1')[0]['query'] === 'deleteFiles=true&addImportListExclusion=false', 'delete: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'lidarr: the key never reaches the output (401, a 500 that echoes it, a dead port), never in the URL' => function () {
        arrPhase2KeyOracle('lidarr', ['where' => 'servarr'], 'artist');
    },
    'lidarr: no PMSS lowercase folder; restart is refused outside Docker; a port another account owns is refused' => function () {
        $app = ArrApps::get('lidarr');
        check(array_keys(ArrLocate::candidates($app, '/h', 'pmss')) === ['servarr', 'docker'], 'the installer folder was looked at for lidarr');
        $fx = arrFixture('lidarr', ['where' => 'pmss']); // a lowercase folder the PMSS installer never makes for Lidarr
        try {
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'not installed') !== false
                && strpos($out, 'install-media-stack') === false, 'pmss folder: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('lidarr', ['where' => 'servarr']);
        try {
            file_put_contents($fx['home'] . '/install-media-stack.sh', "#!/bin/bash\necho \"\$*\" >> \"\$HOME/../installer.log\"\n");
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'done by the provider') !== false
                && !file_exists($fx['dir'] . '/installer.log'), 'restart: ' . $out);
            arrPhase2OwnerRefusal(function ($tables) use ($app, $fx) {
                return ArrLocate::find($app, $fx['home'], 'generic', arrUid(), [], false, $tables);
            }, $fx['port']);
        } finally {
            arrDone($fx);
        }
    },
];
