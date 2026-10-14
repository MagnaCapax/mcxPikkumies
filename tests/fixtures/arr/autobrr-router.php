<?php
/**
 * A fake autobrr API for tests/ArrAutobrrTest.php, run with `php -S 127.0.0.1:<port> autobrr-router.php`. Shapes follow
 * internal/http/*.go and internal/domain/*.go at the commit in tools/arr/autobrr/README.md:
 * - API under <baseUrl>api (server.go:299-302); healthz/* needs no key (server.go:226), the rest the X-API-Token header
 *   (middleware.go:71-85; ?apikey= is answered 400 and logged as a violation here);
 * - GET filters, filters/{id}; PUT filters/{id}/enabled {"enabled": bool} -> 204 (filter.go:60,268-295);
 * - GET release?limit&offset -> {data, count, next_cursor}; release/stats; irc; config;
 * - any DELETE, POST or IRC network restart is a violation: the unit sends none.
 */
$file = getenv('ARR_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$uri = parse_url($_SERVER['REQUEST_URI']);
$raw = isset($uri['query']) ? $uri['query'] : '';
parse_str($raw, $q);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true);
$send = function ($code, $data, $text = false) use (&$s, $file) {
    file_put_contents($file, json_encode($s));
    http_response_code($code);
    header('Content-Type: ' . ($text ? 'text/plain' : 'application/json'));
    echo $code === 204 ? '' : ($text ? $data : json_encode($data));
    exit;
};
$rel = strpos($uri['path'], $s['apiBase']) === 0 ? substr($uri['path'], strlen($s['apiBase'])) : null;
$s['calls'][] = ['method' => $method, 'path' => $rel === null ? $uri['path'] : $rel, 'query' => $raw, 'body' => $body];
if (isset($q['apikey'])) {
    $s['violations'][] = "apikey in the query of {$uri['path']}";
    $send(400, ['message' => 'no key in a URL here']);
}
if ($rel === null) {
    $send(404, 'page not found', true);
}
if ($rel === 'healthz/liveness') {
    $send(200, 'OK', true);
}
if ($rel === 'healthz/readiness') {
    $send($s['ready'] ? 200 : 500, $s['ready'] ? 'OK' : 'Unhealthy. Database unreachable', true);
}
if ((isset($_SERVER['HTTP_X_API_TOKEN']) ? $_SERVER['HTTP_X_API_TOKEN'] : '') !== $s['key']) {
    $send(isset($_SERVER['HTTP_X_API_TOKEN']) ? 401 : 403, 'Unauthorized', true);
}
if ($s['mode'] === 'http500') {
    $send(500, ['message' => 'database is locked; key in use: ' . $s['key']]);
}
if ($method === 'DELETE' || $method === 'POST' || preg_match('#^irc/network/[0-9]+/restart#', $rel)) {
    $s['violations'][] = "$method $rel";
    $send(405, ['message' => 'not here']);
}
$byId = function ($id) use (&$s) {
    foreach ($s['filters'] as $n => $f) {
        if ($f['id'] === (int) $id) {
            return $n;
        }
    }
    return null;
};
if ($method === 'GET' && $rel === 'filters') {
    $send(200, $s['filters']);
}
if (preg_match('#^filters/([0-9]+)(/enabled)?$#', $rel, $m)) {
    $n = $byId($m[1]);
    if ($n === null) {
        $send(404, ['message' => "filter with id {$m[1]} not found"]);
    }
    if ($method === 'GET' && empty($m[2])) {
        $send(200, $s['filters'][$n]);
    }
    if ($method === 'PUT' && !empty($m[2]) && in_array((int) $m[1], isset($s['failPut']) ? (array) $s['failPut'] : [], true)) {
        $send(500, ['message' => 'database is locked']); // nothing changed
    }
    if ($method === 'PUT' && !empty($m[2]) && is_array($body) && is_bool($body['enabled'])) {
        $s['filters'][$n]['enabled'] = empty($s['stuck']) ? $body['enabled'] : $s['filters'][$n]['enabled'];
        $send(204, null);
    }
}
if ($method === 'GET' && $rel === 'release') {
    $limit = isset($q['limit']) ? (int) $q['limit'] : 20;
    $send(200, ['data' => array_slice($s['releases'], isset($q['offset']) ? (int) $q['offset'] : 0, $limit), 'count' => count($s['releases']), 'next_cursor' => 0]);
}
if ($method === 'GET' && $rel === 'release/stats') {
    $send(200, $s['releaseStats']);
}
if ($method === 'GET' && in_array($rel, ['irc', 'config'], true)) {
    $send(200, $s[$rel]);
}
$send(404, ['message' => "NotFound: $method $rel"]);
