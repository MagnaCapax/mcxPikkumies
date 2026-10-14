<?php
/**
 * A fake Jackett for tests/ArrJackettTest.php (`php -S 127.0.0.1:<port> jackett-router.php`), shaped after Jackett
 * 6fdd78f6 (pikkumies/tools/arr/jackett/README.md): the key is accepted ONLY as ?apikey= on the results routes
 * (ResultsController.cs:35-37); a wrong one is answered with error XML code 100 and HTTP 200 on Torznab, 401 elsewhere
 * (state resultsKey: the key the results route wants, to reach that path); /health needs no key. The tool must never send the key anywhere else: a key header, or a key on /health, is logged as a violation.
 */
$file = getenv('ARR_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$uri = parse_url($_SERVER['REQUEST_URI']);
$raw = isset($uri['query']) ? $uri['query'] : '';
$q = [];
foreach (array_filter(explode('&', $raw), 'strlen') as $kv) {
    list($k, $v) = array_pad(explode('=', $kv, 2), 2, '');
    $q[rawurldecode($k)] = rawurldecode($v);
}
$rel = strpos($uri['path'], $s['base'] . '/') === 0 ? substr($uri['path'], strlen($s['base']) + 1) : null;
$s['calls'][] = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => $rel === null ? $uri['path'] : $rel, 'query' => $raw];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0 && stripos((string) $v, $s['key']) !== false) {
        $s['violations'][] = "the key in header $k";
    }
}
$send = function ($code, $body, $type) use (&$s, $file) {
    file_put_contents($file, json_encode($s));
    http_response_code($code);
    header("Content-Type: $type");
    echo $body;
    exit;
};
if ($rel === 'health') {
    if (isset($q['apikey'])) {
        $s['violations'][] = 'the key sent to /health';
    }
    $send(200, json_encode(['status' => $s['health']]), 'application/json');
}
if ($s['mode'] === 'http500') { // a server that leaks the request: the tool must still never print the key
    $send(500, 'Unhandled exception for ' . $_SERVER['REQUEST_URI'] . ' key ' . $s['key'], 'text/plain');
}
if ($rel === null || strpos($rel, 'api/v2.0/indexers/') !== 0) {
    $send(404, 'not found', 'text/plain');
}
$want = isset($s['resultsKey']) && strpos($rel, '/torznab') === false ? $s['resultsKey'] : $s['key'];
if (!isset($q['apikey']) || $q['apikey'] !== $want) { // ResultsController.cs:523-547: Torznab says so with HTTP 200, the rest 401
    $torznab = strpos($rel, '/torznab') !== false;
    $send($torznab ? 200 : 401, $torznab ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<error code="100" description="Invalid API Key" />' : '',
        'application/xml');
}
$configured = array_values(array_filter($s['indexers'], function ($i) {
    return $i['configured'];
}));
if ($rel === 'api/v2.0/indexers/all/results/torznab/api' && isset($q['t']) && $q['t'] === 'indexers') {
    $e = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<indexers>\n";
    foreach (isset($q['configured']) && $q['configured'] === 'true' ? $configured : $s['indexers'] as $i) {
        $x .= '  <indexer id="' . $e($i['id']) . '" configured="' . ($i['configured'] ? 'true' : 'false') . "\">\n"
            . "    <title>{$e($i['name'])}</title>\n"
            . ($i['description'] === '' ? "    <description />\n" : "    <description>{$e($i['description'])}</description>\n")
            . "    <link>{$e($i['link'])}</link>\n    <language>en-US</language>\n    <type>{$e($i['type'])}</type>\n"
            . "    <caps>\n      <server title=\"Jackett\" />\n      <limits default=\"100\" max=\"100\" />\n      <searching>\n"
            . "        <search available=\"yes\" supportedParams=\"q\" />\n        <tv-search available=\"yes\" supportedParams=\"q,season,ep\" />\n"
            . "        <movie-search available=\"no\" supportedParams=\"q\" />\n      </searching>\n      <categories>\n"
            . "        <category id=\"2000\" name=\"Movies\">\n          <subcat id=\"2010\" name=\"Movies/Foreign\" />\n        </category>\n"
            . "        <category id=\"5000\" name=\"TV &amp; Shows\" />\n      </categories>\n    </caps>\n  </indexer>\n";
    }
    $send(200, $x . '</indexers>', 'application/xml');
}
if ($rel === 'api/v2.0/indexers/all/results') {
    $only = isset($q['Tracker[]']) ? explode(',', $q['Tracker[]']) : null;
    $out = ['Results' => [], 'Indexers' => []];
    foreach ($configured as $i) {
        if (($only !== null && !in_array($i['id'], $only, true)) || !empty($i['skip'])) {
            continue;
        }
        $t = $i['test'];
        $out['Indexers'][] = ['ID' => $i['id'], 'Name' => $i['name'], 'Status' => $t[0],
            'Results' => $t[1], 'Error' => $t[2], 'ElapsedTime' => 120];
        $out['Results'][] = ['Tracker' => $i['name'], 'Title' => 'Some.Release',
            'Link' => 'http://127.0.0.1/dl/' . $i['id'] . '/?jackett_apikey=' . $s['key'] . '&path=x'];
    }
    $send(200, json_encode($out), 'application/json');
}
$send(404, '<error code="201" description="Indexer is not supported" />', 'application/xml');
