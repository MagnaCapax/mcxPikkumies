<?php
/**
 * The whisparr unit against the fake Servarr API (tests/fixtures/arr/): Whisparr v3 is Radarr-shaped and v2 Sonarr-shaped
 * (pikkumies/tools/arr/whisparr/README.md), so the fake runs as radarr or sonarr and only `version` says which Whisparr it is.
 */
require_once __DIR__ . '/fixtures/arr/phase2.php';

/** $major 2|3; config.xml in ~/.config/Whisparr, the Servarr default folder (AppFolderInfo.cs:36). */
function arrWhisparrFixture($major, array $over = [])
{
    $fx = arrFixture($major === 2 ? 'sonarr' : 'radarr', $over + ['where' => 'none',
        'version' => $major === 2 ? '2.2.0.404' : '3.1.0.2116']);
    $cfg = $fx['home'] . '/.config/Whisparr/config.xml';
    mkdir(dirname($cfg), 0700, true);
    copy($fx['dir'] . '/unused.xml', $cfg);
    unlink($fx['dir'] . '/unused.xml');
    return ['script' => dirname(__DIR__) . '/pikkumies/tools/arr/whisparr/whisparr.php', 'config' => $cfg] + $fx;
}

return [
    'whisparr v3 (Radarr-shaped): read verbs, remove and MoviesSearch; every boolean the controllers declare is sent' => function () {
        $fx = arrWhisparrFixture(3);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && count($l) === 3 && $l[0]['title'] === 'Film B' && $l[0]['has_file'] === true
                && $l[0]['meta_id'] === 'tmdb:603', 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 0 && $l[0]['app'] === 'Whisparr' && $l[0]['version'] === '3.1.0.2116' && $l[0]['found'] === 'servarr'
                && $l[0]['movie'] === 2 && $l[0]['free_bytes'] === 5000000000, 'stats: ' . $out);
            foreach ([['queue'], ['wanted'], ['wanted', '--cutoff'], ['history'], ['history', '--since', '2026-10-01'],
                ['health'], ['show', '2'],
                ['remove', '2']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args); // the fake answers 400 and logs a violation when a boolean is missing
                check($code === 0, implode(' ', $args) . ': ' . $out);
            }
            check(arrCalls($fx, 'DELETE', 'movie/2')[0]['query'] === 'deleteFiles=false&addImportExclusion=false', 'remove params');
            list($code, $l, $out) = arrRun($fx, ['search', '1']);
            $cmds = array_values(arrState($fx)['commands']);
            check($code === 0 && $cmds[0]['body'] === ['name' => 'MoviesSearch', 'movieIds' => [1]], 'search: ' . json_encode($cmds));
            foreach ([['lookup', 'x'], ['add', 'tmdb:603']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args);
                check($code === 2 && strpos($out, 'unknown verb') !== false, implode(' ', $args) . ' exists: ' . $out);
            }
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--no-search']);
            check($code === 2 && !arrCalls($fx, 'DELETE', 'movie/1'), 'an add option was accepted: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'whisparr v3: --with-data needs the exact --expect; the movie folder is deleted' => function () {
        $fx = arrWhisparrFixture(3);
        $b = $fx['home'] . '/media/Film B';
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data', '--expect', '2.0KiB:2047:356a19']);
            check($code === 1 && is_dir($b) && !arrCalls($fx, 'DELETE', 'movie/1'), 'a wrong --expect deleted: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', '1', '--files']);
            check($code === 0 && $l[count($l) - 1]['expect'] === '2.0KiB:2048:356a19', 'preview: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data', '--expect', '2.0KiB:2048:356a19']);
            clearstatcache();
            check($code === 0 && $l[0]['disposal'] === 'deleted' && !is_dir($b)
                && arrCalls($fx, 'DELETE', 'movie/1')[0]['query'] === 'deleteFiles=true&addImportExclusion=false', 'delete: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'whisparr v2 (Sonarr-shaped): sites with scene counts and tpdb: ids, wanted scenes, SeriesSearch per site' => function () {
        $fx = arrWhisparrFixture(2, ['wanted/missing' => [['id' => 501, 'seriesId' => 1, 'title' => 'Scene Two',
            'releaseDate' => '2008-01-27',
            'monitored' => true, 'seasonNumber' => 2008, 'episodeNumber' => 2]]]);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && $l[0]['title'] === 'Show A' && $l[0]['meta_id'] === 'tpdb:81189' && $l[0]['episodes'] === 1
                && $l[0]['episodes_total'] === 2 && !isset($l[0]['has_file']), 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['wanted']);
            check($code === 0 && $l[0] === ['id' => 501, 'item' => 1, 'title' => 'Scene Two', 'released_at' => 1201392000],
                'wanted: ' . $out);
            foreach ([['queue'], ['wanted', '--cutoff'], ['history', '--since', '2026-10-01'], ['remove', '2']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args);
                check($code === 0, implode(' ', $args) . ': ' . $out);
            }
            check(arrCalls($fx, 'DELETE', 'series/2')[0]['query'] === 'deleteFiles=false&addImportListExclusion=false', 'remove params');
            list($code, $l, $out) = arrRun($fx, ['search', '1']);
            $cmds = array_values(arrState($fx)['commands']);
            check($code === 0 && $cmds[0]['body'] === ['name' => 'SeriesSearch', 'seriesId' => 1], 'search: ' . json_encode($cmds));
            list($code, $l, $out) = arrRun($fx, ['remove', 'tpdb:81189']);
            check($code === 1 && strpos($out, 'not an id') !== false && !arrCalls($fx, 'DELETE', 'series/1'),
                'a tpdb id was a library id: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'whisparr: an unknown version changes nothing; restart of a stopped one still gets the provider answer' => function () {
        $fx = arrWhisparrFixture(3, ['version' => '10.0.0.1']);
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1']);
            check($code === 1 && strpos($out, 'version \\"10.0.0.1') !== false && !arrCalls($fx, 'DELETE', 'movie/1')
                && count(arrState($fx)['calls']) === 1, 'unknown version: ' . $out);
        } finally {
            arrDone($fx);
        }
        $dead = arrWhisparrFixture(3, ['start' => false]);
        try {
            list($code, $l, $out) = arrRun($dead, ['restart']);
            check($code === 1 && strpos($out, 'done by the provider') !== false, 'restart: ' . $out);
        } finally {
            arrDone($dead);
        }
    },
    'whisparr: the key never reaches the output (401, a 500 that echoes it, a dead port), never in the URL' => function () {
        $fx = arrWhisparrFixture(3, ['key' => 'another-key-the-app-has-now-000000']);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'HTTP 401') !== false && count(arrCalls($fx, 'GET', 'system/status')) === 2, '401: ' . $out);
            arrSet($fx, ['key' => ARR_KEY, 'mode' => 'http500']);
            list($code, $l, $out, $err) = arrRun($fx, ['stats', '--human']);
            check($code === 1 && strpos($err, 'HTTP 500') !== false && strpos($err, 'REDACTED') !== false, '500: ' . $out . $err);
            arrSet($fx, ['mode' => '']);
            list($code) = arrRun($fx, ['list']);
            check($code === 0, 'the header key works');
            foreach (arrState($fx)['calls'] as $c) {
                check(stripos($c['query'], 'apikey') === false, 'a key in the query: ' . $c['query']);
            }
        } finally {
            arrDone($fx);
        }
        $dead = arrWhisparrFixture(2, ['start' => false]);
        try {
            list($code, $l, $out) = arrRun($dead, ['history']);
            check($code === 1 && strpos($out, 'not running') !== false, 'dead port: ' . $out);
        } finally {
            arrDone($dead);
        }
    },
    'whisparr: no PMSS lowercase folder; a port another account owns is refused' => function () {
        $app = ArrApps::get('whisparr');
        check(array_keys(ArrLocate::candidates($app, '/h', 'pmss')) === ['servarr', 'docker']
            && ArrLocate::candidates($app, '/h', 'pmss')['servarr'] === '/h/.config/Whisparr/config.xml'
            && ArrApps::get('whisparr2')['id'] === 'whisparr',
            'whisparr folders: ' . json_encode(ArrLocate::candidates($app, '/h', 'pmss')));
        $fx = arrWhisparrFixture(3);
        try {
            arrPhase2OwnerRefusal(function ($tables) use ($app, $fx) {
                return ArrLocate::find($app, $fx['home'], 'generic', arrUid(), [], false, $tables);
            }, $fx['port']);
        } finally {
            arrDone($fx);
        }
    },
];
