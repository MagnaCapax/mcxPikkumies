<?php
/**
 * Routes of the fake (included by router.php with $s, $q, $rel, $method, $body, $send in scope).
 * REQUIRED: the boolean query parameters each spec declares with a default (OpenAPI files of the
 * commits in the unit READMEs); a request missing one is a test failure.
 */
$item = ['sonarr' => 'series', 'radarr' => 'movie', 'lidarr' => 'artist', 'readarr' => 'author', 'prowlarr' => 'indexer'][$s['app']];
$metaField = ['sonarr' => 'tvdbId', 'radarr' => 'tmdbId', 'lidarr' => 'foreignArtistId', 'readarr' => 'foreignAuthorId', 'prowlarr' => 'id'][$s['app']];
$REQUIRED = [
    'sonarr' => ['GET series' => ['includeSeasonImages'], 'DELETE series/#' => ['deleteFiles', 'addImportListExclusion'],
        'GET queue' => ['includeUnknownSeriesItems', 'includeSeries', 'includeEpisode'],
        'GET wanted/missing' => ['includeSeries', 'includeImages', 'monitored'],
        'GET wanted/cutoff' => ['includeSeries', 'includeEpisodeFile', 'includeImages', 'monitored'],
        'GET history/since' => ['includeSeries', 'includeEpisode']],
    'radarr' => ['GET movie' => ['excludeLocalCovers'], 'DELETE movie/#' => ['deleteFiles', 'addImportExclusion'],
        'GET queue' => ['includeUnknownMovieItems', 'includeMovie'], 'GET wanted/missing' => ['monitored'], 'GET wanted/cutoff' => ['monitored'],
        'GET history/since' => ['includeMovie']],
    'prowlarr' => ['POST indexer/test' => ['forceTest']],
    // Lidarr 0cfa30b9 and Readarr 0b79d300 (lidarr and readarr READMEs)
    'lidarr' => ['DELETE artist/#' => ['deleteFiles', 'addImportListExclusion'], 'GET queue' => ['includeUnknownArtistItems', 'includeArtist', 'includeAlbum'],
        'GET wanted/missing' => ['includeArtist', 'monitored'], 'GET wanted/cutoff' => ['includeArtist', 'monitored'],
        'GET history' => ['includeArtist', 'includeAlbum', 'includeTrack'], 'GET history/since' => ['includeArtist', 'includeAlbum', 'includeTrack']],
    'readarr' => ['GET queue' => ['includeUnknownAuthorItems', 'includeAuthor', 'includeBook'], 'GET wanted/missing' => ['includeAuthor', 'monitored'],
        'GET wanted/cutoff' => ['includeAuthor', 'monitored'], 'GET history' => ['includeAuthor', 'includeBook'],
        'GET history/since' => ['includeAuthor', 'includeBook']],
];
$route = $method . ' ' . preg_replace('#/[0-9]+$#', '/#', $rel);
foreach (isset($REQUIRED[$s['app']][$route]) ? $REQUIRED[$s['app']][$route] : [] as $b) {
    if (!isset($q[$b]) || !in_array($q[$b][0], ['true', 'false'], true)) {
        $s['violations'][] = "$route without $b";
        $send(400, [['propertyName' => $b, 'errorMessage' => "boolean $b not sent"]]);
    }
}
$one = function ($k) use ($q) {
    return isset($q[$k]) ? $q[$k][0] : null;
};
$paging = function (array $rows) use ($one) {
    $size = (int) ($one('pageSize') ?: 10);
    $page = max(1, (int) ($one('page') ?: 1));
    return ['page' => $page, 'pageSize' => $size, 'totalRecords' => count($rows),
        'records' => array_slice($rows, ($page - 1) * $size, $size)];
};
$started = 1760000000 + 60 * count(array_filter(explode("\n", (string) @file_get_contents($s['dir'] . '/restarts')), 'strlen'));
$byId = function ($id) use (&$s) {
    foreach ($s['items'] as $i => $it) {
        if ($it['id'] === (int) $id) {
            return $i;
        }
    }
    return null;
};
$rmTree = function ($p) use (&$rmTree) {
    foreach (is_link($p) || !is_dir($p) ? [] : array_diff(scandir($p), ['.', '..']) as $e) {
        $rmTree("$p/$e");
    }
    is_dir($p) && !is_link($p) ? rmdir($p) : unlink($p);
};
if ($method === 'GET' && $rel === 'system/status') {
    $send(200, ['appName' => ucfirst($s['app']), 'version' => $s['version'], 'startTime' => gmdate('Y-m-d\TH:i:s\Z', $started),
        'urlBase' => $s['base'], 'branch' => 'main', 'isDocker' => false]);
}
if ($method === 'GET' && $rel === 'health' && $s['mode'] === 'huge') { // more than Http::MAX (5 MiB)
    $send(200, [['id' => 1, 'source' => 'X', 'type' => 'notice', 'message' => str_repeat('x', 6 * 1048576), 'wikiUrl' => '']]);
}
if ($method === 'GET' && $rel === $item && $s['mode'] === 'empty') {
    $send(200, '', 'text/plain');
}
if ($method === 'GET' && in_array($rel, ['health', 'diskspace', 'qualityprofile', 'rootfolder', 'metadataprofile'], true)) {
    $send(200, $s[$rel]);
}
if ($method === 'GET' && $rel === 'config/mediamanagement') {
    $send(200, ['id' => 1, 'recycleBin' => $s['recycleBin'], 'recycleBinCleanupDays' => 7]);
}
if ($method === 'GET' && $rel === $item) {
    $f = $metaField;
    $rows = array_values(array_filter($s['items'], function ($i) use ($one, $f) {
        return $one($f) === null || (string) $i[$f] === $one($f);
    }));
    foreach ($s['mode'] === 'bigList' ? array_keys($rows) : [] as $n) { // a large library: 6 MiB, over Http::MAX
        $rows[$n]['overview'] = str_repeat('o', 3 * 1048576);
    }
    $send(200, $rows);
}
if (preg_match("#^$item/([0-9]+)$#", $rel, $m) && ($i = $byId($m[1])) !== null) {
    if ($method === 'GET') {
        $send(200, $s['items'][$i]);
    }
    if ($method === 'DELETE' && in_array((int) $m[1], isset($s['failDelete']) ? (array) $s['failDelete'] : [], true)) {
        $send(500, ['message' => 'Database is locked']); // nothing removed
    }
    if ($method === 'DELETE') {
        $it = $s['items'][$i];
        array_splice($s['items'], $i, 1);
        if ($one('deleteFiles') === 'true' && empty($s['refuse']) && is_dir($it['path'])) {
            $s['recycleBin'] !== '' ? rename($it['path'], $s['recycleBin'] . '/' . basename($it['path'])) : $rmTree($it['path']);
        }
        $send(200, null);
    }
}
if ($method === 'GET' && $rel === "$item/lookup") {
    $term = strtolower((string) $one('term'));
    foreach (isset($s['failLookup']) ? (array) $s['failLookup'] : [] as $bad) { // a metadata service that fails for this term
        if (strpos($term, $bad) !== false) {
            $send(503, ['message' => 'metadata service unavailable']);
        }
    }
    $send(200, array_values(array_filter($s['lookup'], function ($l) use ($term) {
        if (preg_match('/^(tvdb|tmdb|imdb|mbid|lidarr):(.+)$/', $term, $m)) { // Lidarr: SkyHookProxy.cs:433-436
            return strtolower((string) $l[in_array($m[1], ['mbid', 'lidarr'], true) ? 'foreignArtistId' : $m[1] . 'Id']) === $m[2];
        }
        return strpos(strtolower(isset($l['title']) ? $l['title'] : $l['artistName']), $term) !== false;
    })));
}
if ($method === 'POST' && $rel === $item && $s['app'] !== 'prowlarr') {
    $f = $metaField;
    if (empty($body['qualityProfileId']) || empty($body['rootFolderPath'])
        || ($s['app'] === 'lidarr' && empty($body['metadataProfileId']))) { // Lidarr ArtistController.cs:80
        $send(400, [['propertyName' => 'QualityProfileId', 'errorMessage' => 'Must be set']]);
    }
    foreach ($s['items'] as $it) {
        if ($it[$f] === $body[$f]) {
            $send(400, [['propertyName' => ucfirst($f), 'errorMessage' => 'This item has already been added']]);
        }
    }
    $new = $body + ['id' => 0];
    $new['id'] = 100 + count($s['items']);
    $new['path'] = rtrim($body['rootFolderPath'], '/') . '/' . (isset($body['title']) ? $body['title'] : $body['artistName']);
    $s['items'][] = $new;
    $s['added'][] = $body;
    $send(200, $new);
}
if ($method === 'GET' && in_array($rel, ['queue', 'wanted/missing', 'wanted/cutoff', 'history'], true)) {
    $send(200, $paging($s[$rel]));
}
if ($method === 'GET' && $rel === 'history/since') {
    $send(200, $s['history']);
}
if ($method === 'POST' && $rel === 'command') {
    $s['commandPosts'] = (isset($s['commandPosts']) ? $s['commandPosts'] : 0) + 1;
    if (isset($s['failCommandAt']) && $s['commandPosts'] === $s['failCommandAt']) { // the n-th POST command: nothing queued
        $send(500, ['message' => isset($s['appText']) ? $s['appText'] : 'Database is locked']);
    }
    $id = 1000 + count($s['commands']);
    $s['commands'][$id] = ['id' => $id, 'name' => $body['name'], 'body' => $body, 'status' => 'queued', 'polls' => 0];
    $send(200, $s['commands'][$id]);
}
if ($method === 'GET' && preg_match('#^command/([0-9]+)$#', $rel, $m) && isset($s['commands'][$m[1]])) {
    if (!empty($s['failPoll'])) { // the command was queued, but reading its state fails
        $send(500, ['message' => 'Database is locked']);
    }
    $c = &$s['commands'][$m[1]];
    $c['polls']++;
    $done = $s['commandMode'] === 'fail' ? 'failed' : 'completed';
    $c['status'] = $s['commandMode'] === 'stuck' || $c['polls'] < 2 ? 'started' : $done;
    if ($c['status'] === 'failed') {
        $c['exception'] = (isset($s['appText']) ? $s['appText'] : 'Indexer error') . "\n  at stack";
    }
    $send(200, $c);
}
// Sonarr/Radarr exact-release path (docs/adr/0046 §3): ReleaseController.cs GetReleases/DownloadRelease.
if ($method === 'GET' && $rel === 'release' && in_array($s['app'], ['sonarr', 'radarr'], true)) {
    if ($s['app'] === 'radarr') {
        if ($one('movieId') === null) {
            $send(400, ['message' => 'movieId is required (the fake never serves the RSS fallback)']);
        }
    } elseif ($one('episodeId') === null && ($one('seriesId') === null || $one('seasonNumber') === null)) {
        $send(400, ['message' => 'seriesId and seasonNumber must be provided together']);
    }
    $send(200, $s['releases']);
}
if ($method === 'POST' && $rel === 'release' && in_array($s['app'], ['sonarr', 'radarr'], true)) {
    $s['grabbed'][] = $body;
    $mode = isset($s['grabMode']) ? $s['grabMode'] : 'ok';
    if ($mode === '404') {
        $send(404, ['message' => "Couldn't find requested release in cache, try searching again"]);
    }
    if ($mode === '409') {
        $send(409, ['message' => 'Getting release from indexer failed']);
    }
    $send(200, $body);
}
if ($s['app'] === 'prowlarr') {
    require __DIR__ . '/prowlarr-routes.php';
}
