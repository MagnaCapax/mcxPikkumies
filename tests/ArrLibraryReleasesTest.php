<?php
/** Sonarr/Radarr's own `releases`/`grab` (docs/adr/0046 §3), against the fake API. */
require_once __DIR__ . '/fixtures/arr/grab-helpers.php';

return [
    'grab: sonarr releases needs episodeId alone or seriesId+seasonNumber together; radarr takes only movieId' => function () {
        $fx = arrFixture('sonarr');
        try {
            list($code, , $out) = arrRun($fx, ['releases', '1']);
            check($code === 1 && strpos($out, '--season') !== false, 'sonarr with neither: ' . $out);
            list($code, , $out) = arrRun($fx, ['releases', '1', '--season', 'abc']);
            check($code === 1 && strpos($out, 'needs a number') !== false, 'a non-number season must not become season 0 (specials): ' . $out);
            list($code, , $out) = arrRun($fx, ['releases', '1', '--season', '1', '--episode', '5']);
            check($code === 1 && strpos($out, 'not both') !== false, '--season with --episode must be refused: ' . $out);
            $row = ['id' => 0, 'guid' => 'sg1', 'title' => 'Show A S01', 'indexerId' => 1, 'indexer' => 'X', 'size' => 4000, 'seeders' => 3,
                'protocol' => 'torrent', 'quality' => ['quality' => ['name' => 'HD-1080p']], 'rejected' => false, 'rejections' => []];
            arrSet($fx, ['releases' => [$row]]);
            list($code, $l, $out) = arrRun($fx, ['releases', '1', '--season', '1']);
            check($code === 0 && $l[0]['quality'] === 'HD-1080p' && $l[0]['rejected'] === false, 'season: ' . $out);
            check(strpos(arrCalls($fx, 'GET', 'release')[0]['query'], 'seriesId=1') !== false && strpos(arrCalls($fx, 'GET', 'release')[0]['query'], 'seasonNumber=1') !== false, 'season query: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['releases', '1', '--episode', '55']);
            check($code === 0, 'episode: ' . $out);
            check(strpos(arrCalls($fx, 'GET', 'release')[1]['query'], 'episodeId=55') !== false, 'episode query: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['grab', $l[0]['handle']]);
            check($code === 0 && $l[0]['result'] === 'sent', 'sonarr grab: ' . $out);
            $body = arrState($fx)['grabbed'][0];
            check($body['guid'] === 'sg1' && $body['indexerId'] === 1, 'sonarr grab POST body: ' . json_encode($body));
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('radarr');
        try {
            list($code, , $out) = arrRun($fx, ['releases', '1', '--season', '1']);
            check($code === 1 && strpos($out, 'takes no --season') !== false, 'radarr with --season: ' . $out);
            $row = ['id' => 0, 'guid' => 'rg1', 'title' => 'Film B', 'indexerId' => 1, 'indexer' => 'X', 'size' => 2048, 'seeders' => 2,
                'protocol' => 'torrent', 'quality' => ['quality' => ['name' => 'WEB-1080p']], 'rejected' => true, 'rejections' => ['Not a preferred word']];
            arrSet($fx, ['releases' => [$row]]);
            list($code, $l, $out) = arrRun($fx, ['releases', '1']);
            check($code === 0 && $l[0]['rejected'] === true && $l[0]['rejection'] === 'Not a preferred word', 'radarr releases: ' . $out);
            check(strpos(arrCalls($fx, 'GET', 'release')[0]['query'], 'movieId=1') !== false, 'movieId query: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'grab: a sonarr handle grabbed on radarr is refused by name, not treated as unknown' => function () {
        $shared = sys_get_temp_dir() . '/pk-arr-grab-shared-' . getmypid() . '-' . mt_rand() . '.json';
        $fxS = arrFixture('sonarr');
        $fxR = arrFixture('radarr');
        try {
            $row = ['id' => 0, 'guid' => 'sg2', 'title' => 'Show A', 'indexerId' => 1, 'indexer' => 'X', 'size' => 1, 'seeders' => 1,
                'protocol' => 'torrent', 'quality' => [], 'rejected' => false, 'rejections' => []];
            arrSet($fxS, ['releases' => [$row]]);
            list(, $l) = arrRun($fxS, ['releases', '1', '--season', '1'], ['PIKKUMIES_ARR_RELEASES_FILE' => $shared]);
            list($code, , $out) = arrRun($fxR, ['grab', $l[0]['handle']], ['PIKKUMIES_ARR_RELEASES_FILE' => $shared]);
            check($code === 1 && strpos($out, 'is from sonarr, not radarr') !== false, 'cross-app handle: ' . $out);
        } finally {
            arrDone($fxS);
            arrDone($fxR);
            @unlink($shared);
        }
    },
];
