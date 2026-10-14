<?php
/*
 * A fake Plex Media Server for the media tests, run as `php -S 127.0.0.1:<port> plex.php` with MEDIA_FAKE_DIR
 * naming its state.json. Shapes follow the community OpenAPI spec "Plex Media Server" 1.1.1
 * (LukeHagar/plex-api-spec, read 2026-10-14; [UNVERIFIED-spec], the official spec was not fetched):
 * JSON wrapped in MediaContainer; /identity open; sections as Directory[] with key, title, type, refreshing,
 * Location[]; sessions as Metadata[] with Session, User, Player, TranscodeSession; terminate's 401
 * "Server does not have the feature enabled". The token is accepted only in the X-Plex-Token header; one in
 * the URL is recorded as a violation. Scan is refreshSectionPost (POST /library/sections/{id}/refresh, spec
 * lines 41951-42011 of the 1.1.1 file); its `force` and `path` query parameters are POST parameters there.
 * Test knobs: `deny` (path => 401 or 403 for a valid token); `open` (an allowed network: any token or none gets in, as
 * live 1.43.4 answered a caller in allowedNetworks); `recent` (the recently added Metadata list). As live 1.43.4 did,
 * /library/recentlyAdded takes X-Plex-Container-Start/-Size as query parameters and answers `size` (this page) and
 * `totalSize` (the whole list: 3 for a size-2 request on 2026-10-14).
 */
$dir = getenv('MEDIA_FAKE_DIR');
$s = json_decode((string) file_get_contents("$dir/state.json"), true);
$h = array_change_key_case(function_exists('getallheaders') ? (array) getallheaders() : [], CASE_LOWER);
list($path) = explode('?', $_SERVER['REQUEST_URI'], 2);
$query = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
parse_str($query, $q);
$m = $_SERVER['REQUEST_METHOD'];
$s['calls'][] = ['method' => $m, 'path' => $path, 'query' => $query, 'body' => ''];
if (stripos($query, 'token') !== false) {
    $s['violations'][] = "token in the URL: $path";
}
$out = function ($code, $mc = null) use (&$s, $dir) {
    file_put_contents("$dir/state.json", json_encode($s));
    http_response_code($code);
    if (is_string($mc)) {
        header('Content-Type: text/html');
        echo $mc;
    } elseif ($mc !== null) {
        header('Content-Type: application/json');
        echo json_encode(['MediaContainer' => $mc]);
    }
    exit;
};
if (trim((string) @file_get_contents("$dir/power")) !== 'up') {
    $out(503, 'starting');
}
if ($m === 'GET' && $path === '/identity') {
    $out(200, ['size' => 0, 'claimed' => true, 'machineIdentifier' => 'm1', 'version' => $s['version']]);
}
$token = isset($h['x-plex-token']) ? $h['x-plex-token'] : '';
if (empty($s['open']) && !in_array($token, $s['tokens'], true)) {
    $out(401, '<html><head><title>Unauthorized</title></head></html>');
}
if (isset($s['deny'][$path])) {
    $out((int) $s['deny'][$path], '<html><head><title>Denied</title></head></html>');
}
$route = "$m $path";
if ($route === 'GET /') {
    $out(200, ['size' => 0, 'friendlyName' => 'fakeplex', 'version' => $s['version'], 'transcoderActiveVideoSessions' => 1]);
}
if ($route === 'GET /:/prefs') {
    $out(200, ['size' => 3, 'Setting' => [['id' => 'HardwareAcceleratedCodecs', 'value' => true],
        ['id' => 'TranscoderTempDirectory', 'value' => '/transcode'], ['id' => 'allowedNetworks', 'value' => '']]]);
}
if ($route === 'GET /library/sections/all') {
    $out(200, ['size' => count($s['sections']), 'Directory' => array_map(function ($d) {
        return ['key' => $d[0], 'title' => $d[1], 'type' => $d[2], 'refreshing' => $d[3],
            'Location' => [['id' => 1, 'path' => "/media/{$d[1]}"]]];
    }, $s['sections'])]);
}
if ($m === 'GET' && preg_match('#^/library/sections/(\d+)/all$#', $path, $x)) {
    $out(200, ['size' => 0, 'totalSize' => (int) $x[1] * 10, 'offset' => 0]);
}
if ($m === 'POST' && preg_match('#^/library/sections/(\d+)/refresh$#', $path)) {
    $out(200);
}
if ($route === 'GET /status/sessions') {
    $out(200, ['size' => count($s['sessions']), 'Metadata' => array_values($s['sessions'])]);
}
if ($route === 'POST /status/sessions/terminate') {
    if (!empty($s['gate'])) {
        $out(401, 'Server does not have the feature enabled');
    }
    $id = isset($q['sessionId']) ? $q['sessionId'] : '';
    if (!isset($s['sessions'][$id])) {
        $out(404, 'Session not found');
    }
    unset($s['sessions'][$id]);
    $out(200);
}
if ($route === 'GET /library/recentlyAdded') {
    $all = isset($s['recent']) ? $s['recent'] : [
        ['title' => 'Pilot', 'grandparentTitle' => 'A Show', 'type' => 'episode', 'librarySectionTitle' => 'TV', 'addedAt' => 1760400000],
        ['title' => 'New Film', 'type' => 'movie', 'librarySectionTitle' => 'Movies', 'addedAt' => 1760300000]];
    $size = isset($q['X-Plex-Container-Size']) ? (int) $q['X-Plex-Container-Size'] : count($all);
    $page = array_slice($all, isset($q['X-Plex-Container-Start']) ? (int) $q['X-Plex-Container-Start'] : 0, $size);
    $out(200, ['size' => count($page), 'totalSize' => count($all), 'Metadata' => $page]);
}
$out(200, []); // anything else "succeeds", so a test sees it in the calls if it was ever sent
