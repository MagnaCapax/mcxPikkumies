<?php
/**
 * A fake Sonarr/Radarr/Prowlarr API for tests/Arr*Test.php, run with `php -S 127.0.0.1:<port> router.php`.
 * State: the JSON file named by ARR_FAKE_STATE (made by harness.php). Shapes follow the OpenAPI specs
 * of Sonarr bc54afbd, Radarr a96bf7c3, Prowlarr 12c32780 (see the unit READMEs) and their source:
 * - auth: header X-Api-Key only here; a key in the query is answered 400 and logged as a violation;
 * - a path without the UrlBase answers an empty 307 (PMSS install-media-stack.sh:1645-1647);
 * - errors: ErrorModel {message} or a list of {propertyName, errorMessage} (RadarrErrorPipeline.cs:33-52);
 * - every boolean query parameter the spec declares for a route must be present (bools.php in the spec
 *   review), so a request that leans on an API default fails the test;
 * - DELETE item ?deleteFiles=true deletes or recycles the folder (MediaFileDeletionService.cs:91-127)
 *   unless the state says the app refuses.
 */
$file = getenv('ARR_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$uri = parse_url($_SERVER['REQUEST_URI']);
$path = $uri['path'];
$raw = isset($uri['query']) ? $uri['query'] : '';
$q = [];
foreach (array_filter(explode('&', $raw), 'strlen') as $kv) {
    list($k, $v) = array_pad(explode('=', $kv, 2), 2, '');
    $q[rawurldecode($k)][] = rawurldecode($v);
}
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true);
$save = function () use (&$s, $file) {
    file_put_contents($file, json_encode($s));
};
$send = function ($code, $data, $type = 'application/json') use ($save) {
    $save();
    http_response_code($code);
    header("Content-Type: $type");
    echo is_string($data) && $type !== 'application/json' ? $data : json_encode($data);
    exit;
};
$prefix = $s['base'] . '/api/' . $s['api'] . '/';
$rel = strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : null;
$s['calls'][] = ['method' => $method, 'path' => $rel === null ? $path : $rel, 'query' => $raw];
// Prowlarr's download proxy (NewznabController.cs GetDownload, docs/adr/0046): unversioned path, key
// in the query (ConvertToProxyLink), never a header; answered before the ordinary api-key rules below.
if ($s['app'] === 'prowlarr' && $method === 'GET' && preg_match('#^' . preg_quote($s['base'], '#') . '/[0-9]+/download$#', $path)) {
    $dl = isset($s['downloadProxy']) ? $s['downloadProxy'] : ['code' => 200, 'body' => 'd4:infodee'];
    if (isset($dl['location'])) {
        header('Location: ' . $dl['location']);
        $save();
        http_response_code($dl['code']);
        exit;
    }
    $send($dl['code'], $dl['body'], isset($dl['type']) ? $dl['type'] : 'text/plain');
}
foreach (array_keys($q) as $k) {
    if (strtolower($k) === 'apikey') {
        $s['violations'][] = "apikey in the query of $path";
        $send(400, ['message' => 'no key in the query here']);
    }
}
if (!empty($s['sleep'])) {
    sleep((int) $s['sleep']);
}
if ($s['mode'] === 'html') {
    $send(200, '<html>login</html>', 'text/html');
}
if ($rel === null || $s['mode'] === 'redirect') {
    $send(307, '', 'text/plain');
}
$given = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
if ($given !== $s['key']) {
    $send(401, '', 'text/plain');
}
if ($s['mode'] === 'http500') {
    $send(500, ['message' => 'Database is locked; key in use: ' . $s['key'], 'description' => 'trace']); // a server that leaks: the tool must still never print the key
}
require __DIR__ . '/routes.php';
$send(404, ['message' => "NotFound: $method $rel"]);
