<?php
/** The prowlarr unit against the fake API (tests/fixtures/arr/, prowlarr-routes.php). */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'prowlarr: list, releases with chosen indexers, and only its own verbs and options' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && count($l) === 3 && $l[0]['name'] === 'Tracker One' && $l[0]['enabled'] === true && !isset($l[0]['fields']), 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['releases', 'ubuntu', '24.04', '--indexer', '1,2', '--limit', '5']);
            $q = arrCalls($fx, 'GET', 'search')[0]['query'];
            check($code === 0 && $l[0]['size'] === 6000000000 && $l[0]['seeders'] === 10 && $l[0]['categories'] === ['Movies'], 'releases: ' . $out);
            check($q === 'query=ubuntu%2024.04&type=search&limit=6&offset=0&indexerIds=1&indexerIds=2', 'search query: ' . $q);
            list($code, $l, $out) = arrRun($fx, ['releases', 'x', '--indexer', 'abc']);
            check($code === 1 && count(arrCalls($fx, 'GET', 'search')) === 1, 'a bad --indexer was searched: ' . $out);
            arrSet($fx, ['releases' => []]);
            list($code, $l, $out) = arrRun($fx, ['releases', 'nothing']);
            check($code === 0 && strpos($out, 'failed inside Prowlarr') !== false, 'empty answer note: ' . $out);
            list($code) = arrRun($fx, ['add', 'tmdb:1']);
            check($code === 2, 'prowlarr has no add verb');
            list($code) = arrRun($fx, ['show', '1', '--files']);
            check($code === 2, '--files is not a prowlarr option');
        } finally {
            arrDone($fx);
        }
    },
    'prowlarr: test reports each indexer, sends the full object back unprinted, and sync is ApplicationIndexerSync, not forced' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['test']);
            check($code === 0 && $l[0]['valid'] === true && $l[1]['valid'] === false && $l[1]['errors'] === ['Unable to connect to indexer'], 'testall: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['test', '1', '2']);
            check($code === 0 && $l[0]['valid'] === true && $l[1]['errors'] === ['BaseUrl: Unable to connect to indexer'], 'test: ' . $out);
            $sent = arrState($fx)['tested'][0];
            check($sent['fields'][1]['value'] === 'SECRETFIELDVALUE1', 'the test must send the indexer back unchanged (secrets included)');
            check(arrCalls($fx, 'POST', 'indexer/test')[0]['query'] === 'forceTest=false', 'forceTest sent explicitly');
            list($code, $l, $out) = arrRun($fx, ['sync']);
            $cmds = array_values(arrState($fx)['commands']);
            check($code === 0 && $l[0]['state'] === 'completed' && $cmds[0]['body'] === ['name' => 'ApplicationIndexerSync', 'forceSync' => false], 'sync: ' . json_encode($cmds));
        } finally {
            arrDone($fx);
        }
    },
    'prowlarr: a URL in app text (test failures, list, health, command errors) keeps no query or fragment' => function () {
        $fx = arrFixture('prowlarr');
        // Prowlarr's test failure carries the request URL (HttpIndexerBase.cs:815-818); x_api is no name Cli::cleanString knows
        $text = 'Unable to connect to indexer, check the log: https://tracker.example/rss?x_api=SECRETVALUE123#t=SECRETVALUE123 end';
        try {
            $items = arrState($fx)['items'];
            $items[0]['message'] = ['message' => $text, 'type' => 'error'];
            arrSet($fx, ['appText' => $text, 'commandMode' => 'fail', 'items' => $items,
                'health' => [['id' => 1, 'source' => 'IndexerStatusCheck', 'type' => 'warning', 'message' => $text, 'wikiUrl' => '']]]);
            $all = '';
            $codes = [];
            foreach ([['test'], ['test', '2'], ['list'], ['health'], ['sync']] as $args) {
                list($codes[], , $out, $err) = arrRun($fx, $args);
                $all .= $out . $err;
            }
            arrSet($fx, ['failCommandAt' => arrState($fx)['commandPosts'] + 1]);
            list($codes[], , $out, $err) = arrRun($fx, ['sync']);
            $all .= $out . $err;
            check($codes === [0, 0, 0, 0, 1, 1], 'exit codes: ' . json_encode($codes) . ' ' . $all);
            check(strpos($all, 'SECRETVALUE123') === false, 'a query value of an app-supplied URL was printed: ' . $all);
            check(substr_count($all, 'https://tracker.example/rss?…') >= 6, 'the URL without its query is kept: ' . $all);
        } finally {
            arrDone($fx);
        }
    },
];
