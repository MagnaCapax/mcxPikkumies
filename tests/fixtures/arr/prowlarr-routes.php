<?php
/**
 * Prowlarr-only routes of the fake (Prowlarr 12c32780): indexer test and testall
 * (ProviderControllerBase.cs:207-238: testall answers 400 with the list when any indexer fails),
 * search (SearchController.cs:147-187, ReleaseResource of the spec).
 */
$appText = isset($s['appText']) ? $s['appText'] : 'Unable to connect to indexer'; // a test may set the failure text
if ($method === 'POST' && $rel === 'indexer/test') {
    $s['tested'][] = $body;
    if (!empty($body['broken'])) {
        $send(400, [['propertyName' => 'BaseUrl', 'errorMessage' => $appText]]);
    }
    $send(200, '{}');
}
if ($method === 'POST' && $rel === 'indexer/testall') {
    $out = array_map(function ($i) use ($appText) {
        return ['id' => $i['id'], 'isValid' => empty($i['broken']), 'validationFailures' => empty($i['broken']) ? [] : [['propertyName' => '', 'errorMessage' => $appText]]];
    }, $s['items']);
    $send(array_filter(array_column($out, 'isValid'), function ($v) {
        return !$v;
    }) ? 400 : 200, $out);
}
if ($method === 'GET' && $rel === 'search') {
    $s['searched'][] = $q;
    $send(200, $s['releases']);
}
// grab (docs/adr/0046 §2): GrabRelease, SearchController.cs:55-87.
if ($method === 'POST' && $rel === 'search') {
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
if ($method === 'GET' && $rel === 'downloadclient') {
    $send(200, isset($s['downloadclients']) ? $s['downloadclients'] : []);
}
