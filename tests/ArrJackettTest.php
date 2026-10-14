<?php
/** The jackett unit against the fake Jackett (tests/fixtures/arr/jackett-router.php, jackett.php). */
require_once __DIR__ . '/fixtures/arr/jackett.php';

return [
    'jackett: ServerConfig.json gives Port, BasePathOverride and APIKey only; empty, foreign or keyless files are named' => function () {
        $tmp = sys_get_temp_dir() . '/pk-jk-' . getmypid() . '-' . mt_rand() . '.json';
        file_put_contents($tmp, arrJackettConfig(9117));
        $c = JackettConfig::read($tmp);
        check($c['valid'] && $c['port'] === 9117 && $c['urlBase'] === '/jackett' && $c['key'] === ARR_KEY, 'read: ' . json_encode($c));
        foreach (['', '[1,2]', '{"Port": "<x>", "APIKey": {"a": 1}}', 'Port=9117'] as $text) {
            file_put_contents($tmp, $text);
            $c = JackettConfig::read($tmp);
            check($c['key'] === '' && $c['port'] === 0, 'a value from an unusable file: ' . $text . ' ' . json_encode($c));
        }
        check(JackettConfig::read($tmp)['valid'] === false && JackettConfig::read('/nonexistent.json') === null, 'invalid file');
        unlink($tmp);
        $fx = arrJackettFixture(['config' => '', 'start' => false]);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'empty or not Jackett') !== false, 'empty config: ' . $out);
            file_put_contents($fx['config'], json_encode(['Port' => $fx['port']]));
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'never started') !== false, 'no key: ' . $out);
            unlink($fx['config']);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'not installed') !== false
                && strpos($out, '~/.config/Jackett/ServerConfig.json') !== false, 'none: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'jackett: list, show, stats and health; XML text decoded; the key only as ?apikey= on the API routes, never on /health' => function () {
        $fx = arrJackettFixture();
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && count($l) === 4 && $l[0] === ['id' => '1337x', 'name' => '1337x', 'enabled' => true, 'privacy' => 'public',
                'language' => 'en-US', 'site' => 'https://1337x.example/?…'] && $l[1]['name'] === 'Private <Tr>', 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['list', '--search', 'quiet']);
            check($code === 0 && count($l) === 2 && $l[0]['id'] === 'quiet', 'search: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', 'privtr']);
            check($code === 0 && $l[0]['description'] === 'Private <Tr> tracker & more' && $l[0]['categories'] === ['Movies', 'TV & Shows']
                && $l[0]['search_modes']['tv-search'] === ['q', 'season', 'ep'] && $l[0]['search_modes']['movie-search'] === false
                && $l[0]['limit_max'] === 100, 'show: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', 'privtr', 'nope', '../x']);
            check($code === 1 && strpos($out, 'nope: no such configured indexer') !== false
                && strpos($out, '../x: not an id') !== false, 'bad ids: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 0 && $l[0]['indexers'] === 3 && $l[0]['found'] === 'default' && $l[0]['url_base'] === '/jackett'
                && $l[0]['version'] === null && $l[0]['health_warnings'] === 0 && $l[0]['port'] === $fx['port'], 'stats: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['health']);
            check($code === 0 && count($l) === 1 && strpos($out, '/health answered OK') !== false, 'health: ' . $out);
            arrSet($fx, ['health' => 'Degraded']);
            list($code, $l, $out) = arrRun($fx, ['health']);
            check($code === 0 && $l[0]['message'] === 'health answered: Degraded', 'health warning: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['history']);
            check($code === 1 && strpos($out, 'not available in Jackett') !== false, 'history: ' . $out);
            foreach (arrState($fx)['calls'] as $c) {
                $api = strpos($c['path'], 'api/') === 0;
                check($api === (strpos($c['query'], 'apikey=' . ARR_KEY) !== false), 'key placement: ' . json_encode($c));
            }
        } finally {
            arrP2Done($fx);
        }
    },
    'jackett: test reports each indexer from the per-indexer part only; download links and the key never reach the output' => function () {
        $fx = arrJackettFixture();
        try {
            list($code, $l, $out) = arrRun($fx, ['test']);
            check($code === 0 && count($l) === 4 && $l[0]['valid'] === true && $l[0]['results'] === 50 && $l[0]['elapsed_ms'] === 120
                && $l[1]['valid'] === false && strpos($l[1]['error'], 'Login failed for https://privtr.example/login?…') !== false
                && strpos($l[1]['error'], ' at Jackett') === false && $l[2]['valid'] === false
                && strpos($l[2]['error'], 'no results') !== false, 'test: ' . $out);
            check(strpos($out, 'jackett_apikey') === false && strpos($out, 'SECRETPASS7') === false
                && strpos($out, 'Some.Release') === false,
                'a download link or a tracker secret was printed: ' . $out);
            arrSet($fx, ['calls' => []]);
            list($code, $l, $out) = arrRun($fx, ['test', 'quiet', '1337x']);
            $calls = arrCalls($fx, 'GET', 'api/v2.0/indexers/all/results');
            check($code === 0 && count($l) === 3 && $l[0]['id'] === 'quiet'
                && strpos($calls[0]['query'], 'Tracker%5B%5D=quiet%2C1337x') !== false,
                'test ids: ' . $out . json_encode($calls));
            $ixs = arrState($fx)['indexers'];
            $ixs[2]['skip'] = true;
            arrSet($fx, ['indexers' => $ixs, 'calls' => []]);
            list($code, $l, $out) = arrRun($fx, ['test', 'quiet']);
            check($code === 0 && strpos($l[0]['error'], 'not tested') !== false, 'skipped: ' . $out);
            arrSet($fx, ['calls' => []]);
            list($code, $l, $out) = arrRun($fx, ['test', 'unused']);
            check($code === 1 && strpos($out, 'unused: no such configured indexer') !== false
                && !arrCalls($fx, 'GET', 'api/v2.0/indexers/all/results'),
                'an unconfigured indexer was tested: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'jackett: the key never reaches the output (Torznab code 100, a 401 read again once, a 500 echoing URL and key, a dead port)' => function () {
        $fx = arrJackettFixture(['key' => 'another-key-the-app-has-now-000000']);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'rejected the APIKey') !== false && strpos($out, 'Torznab error 100') !== false, 'code 100: ' . $out);
            arrSet($fx, ['key' => ARR_KEY, 'resultsKey' => 'another-key-the-app-has-now-000000']);
            list($code, $l, $out) = arrRun($fx, ['test']);
            check($code === 1 && strpos($out, 'rejected the APIKey') !== false && strpos($out, 'HTTP 401') !== false
                && count(arrCalls($fx, 'GET', JackettIndexers::RESULTS)) === 2, '401, read again once: ' . $out);
            arrSet($fx, ['resultsKey' => ARR_KEY, 'mode' => 'http500']);
            list($code, $l, $out, $err) = arrRun($fx, ['test', '--human']);
            check($code === 1 && strpos($err, 'HTTP 500') !== false && strpos($err, 'REDACTED') !== false, '500: ' . $out . $err);
        } finally {
            arrP2Done($fx);
        }
        $dead = arrJackettFixture(['start' => false]);
        try {
            list($code, $l, $out) = arrRun($dead, ['test']);
            check($code === 1 && strpos($out, 'not running') !== false, 'dead port: ' . $out);
        } finally {
            arrP2Done($dead);
        }
    },
    'jackett: a port another account owns is refused; restart: Docker only, by the container start time' => function () {
        $fx = arrJackettFixture();
        try {
            arrPhase2OwnerRefusal(function ($tables) use ($fx) {
                return JackettConfig::find($fx['home'], 'generic', arrUid(), [], false, $tables);
            }, $fx['port']);
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'done by the provider') !== false, 'restart outside Docker: ' . $out);
        } finally {
            arrP2Done($fx);
        }
        $fx = arrJackettFixture(['where' => 'docker']);
        try {
            $sock = arrP2Docker($fx, "127.0.0.1:{$fx['port']}\n");
            $env = ['DOCKER_HOST' => 'unix://' . $fx['dir'] . '/docker.sock'];
            list($code, $l, $out) = arrRun($fx, ['stats'], $env);
            check($code === 0 && $l[0]['found'] === 'docker' && $l[0]['port'] === $fx['port']
                && strpos((string) file_get_contents($fx['dir'] . '/docker.log'), 'port jackett 9117/tcp') !== false,
                'docker stats: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['restart'], $env);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['started_at'] > $l[0]['previous_started_at']
                && strpos((string) file_get_contents($fx['dir'] . '/docker.log'), 'restart jackett') !== false, 'docker restart: ' . $out);
            fclose($sock);
        } finally {
            arrP2Done($fx);
        }
    },
];
