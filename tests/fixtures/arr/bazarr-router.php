<?php
/**
 * A fake Bazarr API for tests/ArrBazarrTest.php, run with `php -S 127.0.0.1:<port> bazarr-router.php`. Shapes follow
 * bazarr/api/** at the commit in tools/arr/bazarr/README.md:
 * - auth: header X-API-KEY only here (api/utils.py:100-115 also takes ?apikey= and a form field: those are answered
 *   400 and logged as a violation, so a key in a URL or form fails the test);
 * - answers {data, total}; start/length paging; seriesid[] and radarrid[] filters;
 * - PATCH series|movies with a form body seriesid|radarrid + action (series.py:243-273): only search-missing is allowed
 *   here, anything else is a violation; the restart/shutdown route (system/system.py) is a violation too.
 */
$file = getenv('ARR_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$uri = parse_url($_SERVER['REQUEST_URI']);
$raw = isset($uri['query']) ? $uri['query'] : '';
$q = [];
foreach (array_filter(explode('&', $raw), 'strlen') as $kv) {
    list($k, $v) = array_pad(explode('=', $kv, 2), 2, '');
    $q[rawurldecode($k)][] = rawurldecode($v);
}
$method = $_SERVER['REQUEST_METHOD'];
parse_str((string) file_get_contents('php://input'), $form);
$send = function ($code, $data) use (&$s, $file) {
    file_put_contents($file, json_encode($s));
    http_response_code($code);
    header('Content-Type: application/json');
    echo $code === 204 ? '' : json_encode($data);
    exit;
};
$rel = strpos($uri['path'], $s['apiBase']) === 0 ? substr($uri['path'], strlen($s['apiBase'])) : null;
$s['calls'][] = ['method' => $method, 'path' => $rel === null ? $uri['path'] : $rel, 'query' => $raw, 'form' => $form];
if (isset($q['apikey']) || isset($form['apikey'])) {
    $s['violations'][] = "apikey in the query or form of {$uri['path']}";
    $send(400, ['message' => 'no key in a URL here']);
}
if (!empty($s['sleep'])) {
    sleep((int) $s['sleep']);
}
if ($rel === null) {
    $send(404, 'Not Found');
}
if ((isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '') !== $s['key']) {
    $send(401, ['message' => 'The server could not verify that you are authorized to access the URL requested.']);
}
if ($s['mode'] === 'http500') {
    $send(500, ['message' => 'Internal error; key in use: ' . $s['key']]);
}
$page = function (array $rows, $idKey = null, $idParam = null) use ($q) {
    if ($idKey !== null && isset($q[$idParam])) {
        $ids = array_map('intval', $q[$idParam]);
        $rows = array_values(array_filter($rows, function ($r) use ($idKey, $ids) {
            return in_array($r[$idKey], $ids, true);
        }));
    }
    $length = isset($q['length']) ? (int) $q['length'][0] : -1;
    $start = isset($q['start']) ? (int) $q['start'][0] : 0;
    return ['data' => array_slice($rows, $start, $length < 0 ? null : $length), 'total' => count($rows)];
};
if ($method === 'GET' && $rel === 'system/status') {
    $restarts = count(array_filter(explode("\n", (string) @file_get_contents($s['dir'] . '/restarts')), 'strlen'));
    $send(200, ['data' => ['bazarr_version' => '1.5.3', 'sonarr_version' => '4.0.9', 'radarr_version' => '',
        'start_time' => 1760000000.25 + 60 * $restarts, 'bazarr_config_directory' => '/config']]);
}
if ($method === 'GET' && $rel === 'system/health') {
    $send(200, ['data' => $s['health']]);
}
if ($method === 'GET' && $rel === 'series') {
    $send(200, $page($s['series'], 'sonarrSeriesId', 'seriesid[]'));
}
if ($method === 'GET' && $rel === 'movies') {
    $send(200, $page($s['movies'], 'radarrId', 'radarrid[]'));
}
if ($method === 'GET' && in_array($rel, ['episodes/wanted', 'movies/wanted', 'episodes/history', 'movies/history'], true)) {
    $send(200, $page($s[$rel]));
}
if ($method === 'PATCH' && in_array($rel, ['series', 'movies'], true)) {
    $param = $rel === 'series' ? 'seriesid' : 'radarrid';
    if (!isset($form['action']) || $form['action'] !== 'search-missing' || !isset($form[$param])) {
        $s['violations'][] = "PATCH $rel with " . json_encode($form);
        $send(400, 'Unknown action');
    }
    if (!empty($s['patchSleep'])) {
        sleep((int) $s['patchSleep']);
    }
    if ($s['mode'] === 'searchFail') {
        $send(500, 'Series directory not found. Path mapping issue?');
    }
    $s['patched'][] = "$rel:" . $form[$param];
    $send(204, null);
}
if ($rel === 'system') {
    $s['violations'][] = 'the restart/shutdown route was called';
}
$send(404, ['message' => "NotFound: $method $rel"]);
