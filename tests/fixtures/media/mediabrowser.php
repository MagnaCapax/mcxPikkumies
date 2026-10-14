<?php
/*
 * A fake Jellyfin 12 / Emby for the media tests, run as `php -S 127.0.0.1:<port> mediabrowser.php` with
 * MEDIA_FAKE_DIR naming its state.json. Shapes follow the Jellyfin 12.2.0 and Emby 4.1.1.0 OpenAPI specs
 * (paths, parameter names, SystemInfo, VirtualFolderInfo, SessionInfoDto, TranscodingInfo, LogFile,
 * AuthenticationResult) and what live 12.2.0 / 4.10.1.0 servers answered on 2026-10-14:
 * - Jellyfin: `Authorization: MediaBrowser ..., Token="..."`; X-Emby-Token is refused (401); with a BaseUrl
 *   set, paths outside it answer 302 (PMSS install-media-stack.sh:1816-1818);
 * - Emby: X-Emby-Token, everything under /emby, PascalCase parameters, short numeric library ids;
 * - 204 for refresh and Playing/Stop, 404 for an unknown session; logs are plain text;
 * - test knobs: `forbid` (paths that answer 403 to a valid credential), `stop_fail` (session ids whose Stop answers 500),
 *   `stop_ignored` (session ids whose Stop answers 204 and that keep playing, as a live 12.2.0 and 4.10.1 did for a
 *   session made with POST /Sessions/Playing on 2026-10-14),
 *   `latest_many` (each library has that many recent items; the answer holds at most `limit` of them);
 * - /Items/Counts as the live servers answered with an API key for two movies: Jellyfin ItemCount 8, Emby ItemCount 0.
 * Every request is recorded (method, path without the base, query, body) for the tests to inspect.
 */
$dir = getenv('MEDIA_FAKE_DIR');
$s = json_decode((string) file_get_contents("$dir/state.json"), true);
$h = array_change_key_case(function_exists('getallheaders') ? (array) getallheaders() : [], CASE_LOWER);
list($rawPath) = explode('?', $_SERVER['REQUEST_URI'], 2);
$query = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
parse_str($query, $q);
$out = function ($code, $body = null) use (&$s, $dir) {
    file_put_contents("$dir/state.json", json_encode($s));
    http_response_code($code);
    if ($body !== null) {
        header('Content-Type: ' . (is_string($body) ? 'text/plain' : 'application/json'));
        echo is_string($body) ? $body : json_encode($body);
    }
    exit;
};
$base = $s['base'];
if ($base !== '' && strpos($rawPath, $base . '/') !== 0) {
    $s['calls'][] = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => "(outside base) $rawPath", 'query' => $query, 'body' => ''];
    header('Location: ' . $base . '/web/');
    $out(302);
}
$path = substr($rawPath, strlen($base));
$m = $_SERVER['REQUEST_METHOD'];
$body = (string) file_get_contents('php://input');
$s['calls'][] = ['method' => $m, 'path' => $path, 'query' => $query, 'body' => $body];
$emby = $s['kind'] === 'emby';
$p = function ($name) use ($q, $emby) { // the spec's spelling: camelCase for Jellyfin, PascalCase for Emby
    $k = $emby ? ucfirst($name) : $name;
    return isset($q[$k]) ? $q[$k] : null;
};
if (trim((string) @file_get_contents("$dir/power")) !== 'up') {
    $out(503, 'starting');
}
if ($m === 'GET' && $path === '/System/Info/Public') {
    $out(200, ['ServerName' => 'fake', 'Version' => $s['version'], 'Id' => 'abc', 'StartupWizardCompleted' => true]);
}
if ($m === 'POST' && $path === '/Users/AuthenticateByName') {
    $s['logins']++;
    $a = json_decode($body, true);
    $authz = (string) (isset($h['authorization']) ? $h['authorization'] : '');
    $ok = isset($a['Username'], $a['Pw']) && $a['Username'] === $s['user'] && $a['Pw'] === $s['password']
        && preg_match('/DeviceId="pikkumies-[0-9a-f]{16}"/', $authz);
    $ok ? $out(200, ['User' => ['Id' => 'u1', 'Name' => $s['user']], 'SessionInfo' => [], 'AccessToken' => $s['session_token'],
        'ServerId' => 'abc'])
        : $out(401, 'Invalid user or password entered.');
}
$token = $emby ? (isset($h['x-emby-token']) ? $h['x-emby-token'] : '')
    : (isset($h['x-emby-token']) ? ''
        : (preg_match('/^MediaBrowser .*\bToken="([^"]*)"/', (string) (isset($h['authorization']) ? $h['authorization'] : ''), $t)
            ? $t[1] : ''));
if (!in_array($token, $s['tokens'], true) || !empty($s['reject'])) {
    $out(401, 'Unauthorized');
}
if (in_array($path, isset($s['forbid']) ? $s['forbid'] : [], true)) {
    $out(403, 'Forbidden');
}
if (!empty($s['fail'])) {
    $out((int) $s['fail'], 'Internal Server Error: ' . $token); // a server that echoes the token back must not leak it
}
$lib = function ($l) use ($emby) {
    return ['Name' => $l[1], 'Locations' => ["/media/{$l[1]}"], 'CollectionType' => $l[2], 'ItemId' => $l[0], 'RefreshProgress' => $l[3],
        'RefreshStatus' => $l[3] === null ? ($emby ? null : 'Idle') : 'Active'];
};
$route = "$m $path";
if ($route === 'GET /System/Info') {
    $out(200, ['ServerName' => 'fake', 'Version' => $s['version'], 'Id' => 'abc', 'HasPendingRestart' => false,
        'HasUpdateAvailable' => true,
        'CanSelfRestart' => true]);
}
if ($route === 'GET /Library/VirtualFolders') {
    $out(200, array_map($lib, $s['libraries']));
}
if ($route === 'GET /Items/Counts') {
    $out(200, $emby ? ['MovieCount' => 2, 'SeriesCount' => 0, 'EpisodeCount' => 0, 'GameCount' => 0, 'ItemCount' => 0]
        : ['MovieCount' => 2, 'SeriesCount' => 1, 'EpisodeCount' => 4, 'ArtistCount' => 0, 'ItemCount' => 8]);
}
if ($route === 'GET /Items') {
    $out(200, ['Items' => [], 'TotalRecordCount' => $p('parentId') === $s['libraries'][0][0] ? 5 : 2]);
}
if ($route === 'GET /System/Configuration/encoding') {
    $out(200, [$emby ? 'HardwareAccelerationMode' : 'HardwareAccelerationType' => $emby ? 'Auto' : 'none',
        'EncoderAppPath' => '/usr/bin/ffmpeg']);
}
if ($route === 'GET /ScheduledTasks') {
    $out(200, [['Name' => 'Scan Media Library', 'State' => 'Running'], ['Name' => 'Clean Cache', 'State' => 'Idle']]);
}
if ($route === 'GET /Users') {
    $out(200, [['Id' => 'u0', 'Name' => 'guest', 'Policy' => ['IsAdministrator' => false]],
        ['Id' => 'u1', 'Name' => $s['user'], 'Policy' => ['IsAdministrator' => true, 'IsDisabled' => false]]]);
}
if ($route === 'GET /Sessions') {
    $out(200, array_values($s['sessions']));
}
if (($route === 'GET /Items/Latest' && !$emby && $p('userId') === 'u1') || ($emby && $route === 'GET /Users/u1/Items/Latest')) {
    if (!empty($s['latest_many'])) {
        $items = [];
        for ($i = 0; $i < min((int) $s['latest_many'], (int) $p('limit')); $i++) {
            $at = gmdate('Y-m-d\\TH:i:s\\Z', 1790000000 - $i * 60);
            $items[] = ['Name' => $p('parentId') . "-$i", 'Type' => 'Movie', 'DateCreated' => $at];
        }
        $out(200, $items);
    }
    $out(200, $p('parentId') === $s['libraries'][0][0]
        ? [['Name' => 'New Film', 'Type' => 'Movie', 'DateCreated' => '2026-10-13T10:00:00.0000000Z']]
        : [['Name' => 'Pilot', 'SeriesName' => 'A Show', 'Type' => 'Episode', 'DateCreated' => '2026-10-14T07:00:00Z']]);
}
if ($route === 'GET /System/Logs') {
    $out(200, [['Name' => 'old.log', 'DateModified' => '2026-10-01T00:00:00Z', 'Size' => 10],
        ['Name' => 'log_20261014.log', 'DateModified' => '2026-10-14T07:48:53Z', 'Size' => 99]]);
}
if ($route === 'GET /System/Logs/Log' && $p('name') === 'log_20261014.log') {
    $out(200, "[INF] start\n[INF] Playback started by 203.0.113.7 with api_key=$token\n[INF] Token=\"$token\" from 2001:db8::7\n"
        . "[INF] done\n");
}
if ($m === 'POST' && preg_match('#^/Items/([^/]+)/Refresh$#', $path)) {
    $out(204);
}
if ($m === 'POST' && preg_match('#^/Sessions/([^/]+)/(Playing/Stop|Message)$#', $path, $x)) {
    if (!isset($s['sessions'][$x[1]])) {
        $out(404, 'session not found');
    }
    if ($x[2] === 'Playing/Stop' && in_array($x[1], isset($s['stop_fail']) ? $s['stop_fail'] : [], true)) {
        $out(500, 'stop failed');
    }
    if ($x[2] === 'Playing/Stop' && !in_array($x[1], isset($s['stop_ignored']) ? $s['stop_ignored'] : [], true)) {
        unset($s['sessions'][$x[1]]);
    }
    $out(204);
}
$out(204); // anything else (a DELETE, /Auth/Keys...) "succeeds", so a test sees it in the calls if it was ever sent
