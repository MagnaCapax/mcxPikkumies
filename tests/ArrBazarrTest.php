<?php
/** The bazarr unit against the fake Bazarr API (tests/fixtures/arr/bazarr-router.php, phase2.php). */
require_once __DIR__ . '/fixtures/arr/phase2.php';
require_once dirname(__DIR__) . '/pikkumies/tools/arr/bazarr/BazarrConfig.php';

return [
    'bazarr: config.yaml is read line by line: auth.apikey and general.port only, never a same-named key elsewhere' => function () {
        $tmp = sys_get_temp_dir() . '/pk-bz-' . getmypid() . '-' . mt_rand() . '.yaml';
        file_put_contents($tmp, arrYaml(['plex' => ['apikey' => ARR_DECOY, 'port' => 1], 'general' => ['extra' => ['port' => 2],
            'base_url' => "'/sub/bazarr/'", 'port' => 6767], 'auth' => ['apikey' => '"' . ARR_KEY . '"']]));
        $c = BazarrConfig::read($tmp);
        check($c['valid'] && $c['port'] === 6767 && $c['urlBase'] === '/sub/bazarr' && $c['key'] === ARR_KEY, 'read: ' . json_encode($c));
        file_put_contents($tmp, arrYaml(['auth' => ['apikey' => '!!python/object:os.system x'], 'general' => ['port' => '&a 1']]));
        $c = BazarrConfig::read($tmp);
        check($c['key'] === '' && $c['port'] === 0, 'a YAML tag or anchor was taken as a value: ' . json_encode($c));
        file_put_contents($tmp, '');
        check(BazarrConfig::read($tmp)['valid'] === false && BazarrConfig::read('/nonexistent.yaml') === null, 'empty file');
        unlink($tmp);
        $dir = new RecursiveDirectoryIterator(dirname(__DIR__) . '/pikkumies/tools/arr', FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($dir) as $f) {
            if (substr((string) $f, -4) === '.php') {
                $code = (string) file_get_contents((string) $f);
                check(!preg_match('/yaml_parse|Symfony\\\\Component\\\\Yaml|\beval\s*\(|unserialize\s*\(/i', $code),
                    "a YAML library, eval or unserialize in $f");
            }
        }
    },
    'bazarr: list, show, wanted, history, stats and health; the key only in the X-API-KEY header' => function () {
        $fx = arrOtherFixture('bazarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && $l[0]['id'] === 'series:12' && $l[0]['missing'] === 3 && $l[1]['id'] === 'movie:5'
                && $l[1]['missing'] === 2
                && $l[1]['missing_languages'] === ['Finnish', 'English:forced'], 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', 'movie:5']);
            check($code === 0 && $l[0]['subtitle_files'][0]['language'] === 'English' && $l[0]['title'] === 'Film B', 'show: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', 'movie:5', 'series:99']);
            check($code === 1 && strpos($out, 'series:99: Bazarr has no such series') !== false, 'an unknown id: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['wanted']);
            check($code === 0 && $l[0]['item'] === 'series:12' && $l[0]['missing_languages'] === ['Finnish']
                && $l[2]['episodes_wanted'] === 1, 'wanted: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['history']);
            check($code === 0 && $l[0]['item'] === 'series:12' && $l[0]['provider'] === 'podnapisi'
                && $l[1]['item'] === 'movie:5', 'history: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 0 && $l[0]['version'] === '1.5.3' && $l[0]['found'] === 'default'
                && $l[0]['url_base'] === '/bazarr' && $l[0]['series'] === 1
                && $l[0]['movies_wanted'] === 1 && $l[0]['health_warnings'] === 1 && $l[0]['started_at'] === 1760000000, 'stats: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['health']);
            check($code === 0 && $l[0]['message'] === 'File not found. Path mapping issue?', 'health: ' . $out);
            foreach (arrState($fx)['calls'] as $c) {
                check(stripos($c['query'], 'apikey') === false
                    && !isset($c['form']['apikey']), 'a key in a URL or form: ' . json_encode($c));
            }
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr: search asks for missing subtitles per item with a form; a malformed id changes nothing; a slow search is reported' => function () {
        $fx = arrOtherFixture('bazarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['search', 'series:12', 'movie:5']);
            check($code === 0 && $l[0]['state'] === 'searched'
                && arrState($fx)['patched'] === ['series:12', 'movies:5'], 'search: ' . $out);
            check(arrCalls($fx, 'PATCH', 'series')[0]['form'] === ['seriesid' => '12', 'action' => 'search-missing'], 'the form');
            list($code, $l, $out) = arrRun($fx, ['search', 'movie:5', '12']);
            check($code === 1 && strpos($out, '12 is not an id') !== false
                && count(arrCalls($fx, 'PATCH', 'movies')) === 1, 'malformed: ' . $out);
            arrSet($fx, ['patchSleep' => 3]);
            list($code, $l, $out) = arrRun($fx, ['search', 'series:12', 'movie:5'], ['PIKKUMIES_ARR_WAIT' => '2']);
            check($code === 1 && strpos($out, 'series:12 (Show A): running') !== false
                && strpos($out, 'movie:5 (Film B): not_sent') !== false,
                'a slow search: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr: the key never reaches the output (401, a 500 that echoes it, a dead port)' => function () {
        $fx = arrOtherFixture('bazarr', ['key' => 'another-key-bazarr-has-now-00000']);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'rejected the auth.apikey') !== false
                && strpos($out, '--config folder') !== false, '401: ' . $out);
            arrSet($fx, ['key' => ARR_KEY, 'mode' => 'http500']);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'HTTP 500') !== false && strpos($out, 'REDACTED') !== false, '500: ' . $out);
        } finally {
            arrP2Done($fx);
        }
        $fx = arrOtherFixture('bazarr', ['start' => false]);
        try {
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'not running') !== false, 'dead port: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr: restart only for the user\'s own Docker container; a port another account owns is refused' => function () {
        $fx = arrOtherFixture('bazarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'done by the provider') !== false && !array_filter(arrState($fx)['calls'], function ($c) {
                return $c['method'] === 'POST';
            }), 'restart: ' . $out);
            arrPhase2OwnerRefusal(function ($tables) use ($fx) {
                return BazarrConfig::find($fx['home'], 'generic', arrUid(), [], false, $tables);
            }, $fx['port']);
            unlink($fx['config']);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'not installed') !== false
                && strpos($out, "'bazarr' => ['config'") !== false, 'not installed: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr: Docker: the published port of the user\'s own container, and `docker restart` until start_time is newer' => function () {
        $fx = arrOtherFixture('bazarr', ['where' => 'docker']);
        try {
            $sock = arrP2Docker($fx, "0.0.0.0:{$fx['port']}\n");
            $env = ['DOCKER_HOST' => 'unix://' . $fx['dir'] . '/docker.sock'];
            list($code, $l, $out) = arrRun($fx, ['stats'], $env);
            check($code === 0 && $l[0]['found'] === 'docker' && $l[0]['port'] === $fx['port'], 'docker stats: ' . $out);
            check(strpos((string) file_get_contents($fx['dir'] . '/docker.log'), 'port bazarr 6767/tcp') !== false, 'docker port argv');
            list($code, $l, $out) = arrRun($fx, ['restart'], $env);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['started_at'] === 1760000060
                && $l[0]['previous_started_at'] === 1760000000
                && strpos((string) file_get_contents($fx['dir'] . '/docker.log'), 'restart bazarr') !== false, 'docker restart: ' . $out);
            fclose($sock);
        } finally {
            arrP2Done($fx);
        }
    },
];
