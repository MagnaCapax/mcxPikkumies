<?php
/** The library verbs of sonarr and radarr against the fake API (tests/fixtures/arr/). */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'arr library: list, show, stats, queue, wanted and history print the shared records' => function () {
        $fx = arrFixture('radarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['list', '--search', 'film c']);
            check($code === 0 && count($l) === 2 && $l[0]['id'] === 2 && $l[0]['meta_id'] === 'tmdb:604' && $l[0]['size'] === 500 && $l[0]['has_file'] === true, 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 0 && $l[0]['version'] === '4.0.9.2244' && $l[0]['found'] === 'pmss' && $l[0]['movie'] === 2 && $l[0]['health_warnings'] === 1
                && $l[0]['free_bytes'] === 5000000000 && $l[0]['process'] === 'tmux stopped', 'stats: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['queue']);
            check($code === 0 && $l[0]['id'] === 77 && $l[0]['left_bytes'] === 1000 && $l[0]['message'] === 'Stalled: no peers' && $l[1]['in_queue'] === 1, 'queue: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['wanted', '--limit', '5']);
            check($code === 0 && $l[0]['meta_id'] === 'tmdb:605' && strpos(arrCalls($fx, 'GET', 'wanted/missing')[0]['query'], 'pageSize=6') !== false, 'wanted: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['history', '--since', '2026-10-01']);
            check($code === 0 && $l[0]['event'] === 'grabbed' && strpos(arrCalls($fx, 'GET', 'history/since')[0]['query'], 'date=2026-10-01T00%3A00%3A00Z') !== false, 'history: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', '1', 'tmdb:603']);
            check($code === 1 && strpos($out, 'tmdb:603: not an id') !== false, 'a prefixed id is never a library id: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr library: remove keeps files; --with-data needs the exact --expect and frees only single-link files' => function () {
        $fx = arrFixture('radarr');
        $b = $fx['home'] . '/media/Film B';
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '99', '--with-data', '--expect', '2.0KiB:2048:356a19']);
            check($code === 1 && strpos($out, '99: no such movie') !== false && is_dir($b) && !arrCalls($fx, 'DELETE', 'movie/1'), 'one bad id must stop the batch: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data']);
            check($code === 1 && strpos($out, '--expect 2.0KiB') !== false && is_dir($b), 'missing --expect: ' . $out);
            // the label alone, a change the rounding hides, no id check (the old format), another id set's check: all refuse
            foreach (['1GiB', '2.0KiB', '2.0KiB:2047:356a19', '2.0KiB:2048', '2.0KiB:2048:2219fe', '2.0KiB:2048:356A19'] as $wrong) {
                list($code) = arrRun($fx, ['remove', '1', '--with-data', '--expect', $wrong]);
                check($code === 1 && is_dir($b) && !arrCalls($fx, 'DELETE', 'movie/1'), "--expect $wrong deleted something");
            }
            list($code, $l, $out) = arrRun($fx, ['show', '1', '2', '--files']);
            check($code === 0 && $l[count($l) - 1]['expect'] === '2.5KiB:2548:2219fe' && $l[count($l) - 1]['freed_bytes'] === 2048, 'preview: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '2', '--with-data', '--expect', '2.5KiB:2548:2219fe']);
            check($code === 0 && $l[0]['disposal'] === 'deleted' && $l[0]['freed_bytes'] === 2048 && $l[1]['disposal'] === 'deleted' && $l[1]['freed_bytes'] === 0
                && $l[2]['freed_bytes'] === 2048 && $l[0]['verified'] === true, 'delete: ' . $out);
            clearstatcache(); // the fake app deleted it in another process; this process stat'ed $b above (lines 31-36)
            check(!is_dir($b), 'folder Film B not deleted: ' . json_encode(@scandir($b)) . ' out: ' . $out);
            check(is_file($fx['home'] . '/media/c-hardlink.mkv'), 'the other hard link was touched: ' . json_encode(@scandir($fx['home'] . '/media')) . ' out: ' . $out);
            check(arrCalls($fx, 'DELETE', 'movie/1')[0]['query'] === 'deleteFiles=true&addImportExclusion=false', 'delete params');
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('radarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '2']);
            check($code === 0 && $l[0]['disposal'] === 'kept' && $l[0]['verified'] === true && is_dir($fx['home'] . '/media/Film C'), 'plain remove: ' . $out);
            check(arrCalls($fx, 'DELETE', 'movie/2')[0]['query'] === 'deleteFiles=false&addImportExclusion=false', 'plain remove params');
        } finally {
            arrDone($fx);
        }
    },
    'arr library: the recycle bin reads as recycled, a silent refusal as refused, an overlapping folder is refused up front' => function () {
        $fx = arrFixture('radarr');
        arrSet($fx, ['recycleBin' => $fx['dir'] . '/recycle']);
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data', '--expect', '2.0KiB:2048:356a19']);
            check($code === 0 && $l[0]['disposal'] === 'recycled' && $l[0]['freed_bytes'] === 0 && is_dir($fx['dir'] . '/recycle/Film B'), 'recycled: ' . $out);
            arrSet($fx, ['recycleBin' => '', 'refuse' => true]);
            list($code, $l, $out) = arrRun($fx, ['remove', '2', '--with-data', '--expect', '500B:500:da4b92']);
            check($code === 0 && $l[0]['disposal'] === 'refused' && $l[0]['verified'] === false && is_dir($fx['home'] . '/media/Film C'), 'refused: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('sonarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '--with-data', '--expect', '3.9KiB:4000:356a19']);
            check($code === 1 && strpos($out, 'contains the folder of 2') !== false && !arrCalls($fx, 'DELETE', 'series/1'), 'overlap: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr library: add takes prefixed ids only, needs one profile and root, reports exists, and sends the search choice' => function () {
        $fx = arrFixture('radarr');
        try {
            foreach ([['add', '603'], ['add', 'tvdb:5'], ['add', 'imdb:603']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args);
                check($code === 1 && strpos($out, 'nothing added') !== false, implode(' ', $args) . ': ' . $out);
            }
            list($code, $l, $out) = arrRun($fx, ['add', 'tmdb:700']);
            check($code === 1 && strpos($out, '--profile') !== false && strpos($out, '4 HD-1080p') !== false && empty(arrState($fx)['added']), 'two profiles: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['add', 'tmdb:700', 'imdb:tt0133093', '--profile', 'hd-1080p', '--no-search']);
            check($code === 0 && $l[0]['result'] === 'added' && $l[0]['search'] === false && $l[1]['result'] === 'exists' && $l[1]['id'] === 1, 'add: ' . $out);
            $body = arrState($fx)['added'][0];
            check($body['qualityProfileId'] === 4 && $body['rootFolderPath'] === $fx['home'] . '/media' && $body['monitored'] === true
                && $body['addOptions']['searchForMovie'] === false && $body['addOptions']['monitor'] === 'movieOnly' && !isset($body['id']), 'body: ' . json_encode($body));
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('sonarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['add', 'tvdb:999', '--profile', '1']);
            $body = arrState($fx)['added'][0];
            check($code === 0 && $l[0]['result'] === 'added' && $body['addOptions']['searchForMissingEpisodes'] === true && $body['seasonFolder'] === true, 'sonarr add: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr library: search sends the allowlisted command per app and reports a slow one as still running' => function () {
        $fx = arrFixture('sonarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['search', '1', '2']);
            $cmds = array_values(arrState($fx)['commands']);
            check($code === 0 && count($l) === 3 && $l[0]['state'] === 'completed' && $cmds[0]['body'] === ['name' => 'SeriesSearch', 'seriesId' => 1], 'sonarr search: ' . $out);
            arrSet($fx, ['commandMode' => 'stuck']);
            list($code, $l, $out) = arrRun($fx, ['search', '1'], ['PIKKUMIES_ARR_WAIT' => '1']);
            check($code === 0 && $l[0]['state'] === 'started' && isset($l[0]['note']) && $l[0]['command_id'] > 0, 'expiry is ok:true with the state: ' . $out);
            arrSet($fx, ['commandMode' => 'fail']);
            list($code, $l, $out) = arrRun($fx, ['search', '2']);
            check($code === 1 && strpos($out, 'failed') !== false && strpos($out, 'Indexer error') !== false && strpos($out, 'at stack') === false, 'failed command: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('radarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['search', '2', '1']);
            $cmds = array_values(arrState($fx)['commands']);
            check($code === 0 && count($cmds) === 1 && $cmds[0]['body'] === ['name' => 'MoviesSearch', 'movieIds' => [2, 1]], 'radarr search: ' . json_encode($cmds));
        } finally {
            arrDone($fx);
        }
    },
    'arr library: history, queue and wanted read page by page; --limit 0 is "as many as fit", truncation is marked' => function () {
        $fx = arrFixture('radarr');
        $history = [];
        for ($i = 1; $i <= 1200; $i++) {
            $history[] = ['id' => $i, 'movieId' => 1, 'sourceTitle' => "Release.$i", 'date' => '2026-10-13T10:00:00Z', 'eventType' => 'grabbed'];
        }
        $pages = function ($fx, $from) {
            return array_map(function ($c) {
                return $c['query'];
            }, array_slice(arrCalls($fx, 'GET', 'history'), $from));
        };
        try {
            arrSet($fx, ['history' => array_slice($history, 0, 450), 'queue' => []]);
            list($code, $l, $out) = arrRun($fx, ['history', '--limit', '0']);
            $st = end($l);
            $q = $pages($fx, 0);
            check($code === 0 && count($q) === 3 && strpos($q[2], 'page=3&pageSize=200&') === 0 && $l[0]['id'] === 1
                && $st['truncated'] === true && $st['total'] === 450 && $st['next_offset'] === count($l) - 1,
                '--limit 0: ' . json_encode($q) . $out);
            list($code, $l, $out) = arrRun($fx, ['history', '--limit', '5', '--offset', '300']);
            $st = end($l);
            $q = $pages($fx, 3);
            check($code === 0 && count($q) === 2 && strpos($q[1], 'page=2&pageSize=200&') === 0 && $l[0]['id'] === 301 && count($l) === 6
                && $st['truncated'] === true && $st['next_offset'] === 305, '--offset 300: ' . json_encode($q) . $out);
            arrSet($fx, ['history' => $history]);
            list($code, $l, $out) = arrRun($fx, ['history', '--limit', '0', '--offset', '995']);
            $st = end($l);
            check($code === 0 && count($l) === 6 && $l[0]['id'] === 996 && strpos($st['note'], 'first 1000 of 1200') !== false,
                'the cap: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['queue', '--limit', '500']);
            check($code === 0 && strpos(arrCalls($fx, 'GET', 'queue')[0]['query'], 'page=1&pageSize=200&') === 0, 'queue page size: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
];
