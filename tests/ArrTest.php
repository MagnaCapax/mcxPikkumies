<?php
/**
 * *ARR units, shared rules: the API key, secret fields, config.xml parsing, the command allowlist,
 * explicit booleans and the category contract. The fake API is tests/fixtures/arr/router.php.
 */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'arr: the key never reaches stdout or stderr (401, a 500 that echoes it, a dead port) and never goes in the URL' => function () {
        $fx = arrFixture('radarr', ['key' => 'another-key-the-app-has-now-000000']);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'HTTP 401') !== false, '401: ' . $out);
            check(count(arrCalls($fx, 'GET', 'movie')) === 2, 'a 401 reads config.xml again and retries exactly once');
            arrSet($fx, ['key' => ARR_KEY, 'mode' => 'http500']);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'HTTP 500') !== false && strpos($out, 'Database is locked') !== false
                && strpos($out, 'REDACTED') !== false,
                '500: ' . $out);
            list($code, $l, $out, $err) = arrRun($fx, ['stats', '--human']);
            check($code === 1 && strpos($err, 'HTTP 500') !== false, '500 --human: ' . $out . $err);
            arrSet($fx, ['mode' => '']);
            list($code) = arrRun($fx, ['list']);
            check($code === 0, 'the header key works');
            foreach (arrState($fx)['calls'] as $c) {
                check(stripos($c['query'], 'apikey') === false, 'a key in the query: ' . $c['query']);
            }
        } finally {
            arrDone($fx);
        }
        $dead = arrFixture('sonarr', ['start' => false]);
        try {
            list($code, $l, $out) = arrRun($dead, ['history']);
            check($code === 1 && strpos($out, 'not running') !== false && strpos($out, 'tmux session: stopped') !== false, 'dead port: ' . $out);
        } finally {
            arrDone($dead);
        }
    },
    'arr: fields keep a value only for privacy "normal"; releases lose downloadUrl, magnetUrl and guid' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['show', '1', '2']);
            check($code === 0 && strpos($out, 'https://tracker.example/') !== false, 'show: ' . $out);
            check(!preg_match('/SECRET/', $out), 'a secret field value was printed: ' . $out);
            $f = array_column($l[0]['fields'], null, 'name');
            check($f['baseUrl']['value'] === 'https://tracker.example/' && !empty($f['apiKey']['hidden']) && !isset($f['cookie2']['value']),
                'fields: ' . json_encode($f));
            list($code, $l, $out) = arrRun($fx, ['releases', 'ubuntu']);
            check($code === 0 && $l[0]['title'] === 'Ubuntu 24.04 ISO' && !preg_match('/SECRET|downloadUrl|magnet|guid/i', $out),
                'releases: ' . $out);
        } finally {
            arrDone($fx);
        }
        $s = ArrRecords::scrub(['fields' => [['name' => 'apiKey', 'value' => 'SECRET', 'privacy' => 'apiKey'],
            ['name' => 'k', 'value' => 'SECRET2'],
            ['name' => 'u', 'value' => 'SECRET3', 'privacy' => 'userName'], ['name' => 'p', 'value' => 'SECRET4', 'privacy' => 'Normal']],
            'nested' => [['guid' => 'x', 'magnetUrl' => 'y', 'title' => 't']]]);
        check(strpos(json_encode($s), 'SECRET') === false && $s['nested'][0] === ['title' => 't'], 'scrub: ' . json_encode($s));
    },
    'arr: config.xml is read with a regex, never an XML parser; empty, invalid and keyless files name the fix' => function () {
        // An XML parser that expands entities would read a valid port from this file and go on to connect.
        $portFile = sys_get_temp_dir() . '/pk-arr-xxe-' . getmypid() . '-' . mt_rand();
        file_put_contents($portFile, '8989');
        $entity = "<!DOCTYPE c [<!ENTITY p SYSTEM \"file://$portFile\">]>\n<Config><Port>&p;</Port><ApiKey>" . ARR_KEY . "</ApiKey></Config>";
        $cases = [
            [$entity, 'no <Port>'],
            ['', 'empty or invalid (0 bytes'],
            ["<Config><Port>8989</Port>\n", 'empty or invalid'],
            ["<Config><Port>8989</Port><UrlBase></UrlBase></Config>", 'no ApiKey'],
        ];
        foreach ($cases as $case) {
            $fx = arrFixture('sonarr', ['config' => $case[0], 'start' => false]);
            try {
                list($code, $l, $out) = arrRun($fx, ['stats']);
                check($code === 1 && strpos($out, $case[1]) !== false, "config case '{$case[1]}': $out");
                if ($case[0] === '') {
                    check(strpos($out, 'bash ~/install-media-stack.sh') !== false, 'the 0-byte hint must name the installer: ' . $out);
                }
            } finally {
                arrDone($fx);
            }
        }
        unlink($portFile);
        $c = ArrConfig::read(dirname(__DIR__) . '/AGENTS.md'); // not a Servarr file
        check($c['valid'] === false && $c['key'] === '' && ArrConfig::read('/nonexistent/config.xml') === null, 'a non-config file is invalid');
        check(ArrConfig::urlBase('/public-u/sonarr/') === '/public-u/sonarr' && ArrConfig::urlBase('/a/../b') === ''
            && ArrConfig::port('70000') === 0,
            'urlBase/port checks');
    },
    'arr: commands come only from each app\'s allowlist; update, key reset, deletes, renames and moves never' => function () {
        $where = ['port' => 1, 'urlBase' => '', 'key' => 'k', 'config' => '', 'app' => 'x', 'source' => 'x', 'home' => ''];
        $c = new ArrClient($where, function () {
            throw new Exception('the client must not be reached');
        }, 'v3');
        $never = [
            'sonarr' => ['ApplicationUpdate', 'ResetApiKey', 'DeleteSeriesFiles', 'ClearLog', 'RenameSeries', 'MoveSeries', 'Shutdown',
                'MoviesSearch', 'RssSync'],
            'radarr' => ['ApplicationCheckUpdate', 'BulkMoveMovie', 'SeriesSearch'],
            'prowlarr' => ['ApplicationUpdate', 'ApplicationUpdateCheck'],
        ];
        foreach ($never as $id => $names) {
            $app = ArrApps::get($id);
            foreach ($app['commands'] as $ok) {
                check(!preg_match(ArrApps::NEVER, $ok), "$id allows a forbidden command $ok");
            }
            foreach ($names as $n) {
                $refused = false;
                try {
                    ArrCommand::run($c, $app, [[$n, []]]);
                } catch (CliError $e) {
                    $refused = strpos($e->getMessage(), 'not allowed') !== false;
                }
                check($refused, "$id sent $n");
            }
        }
    },
    'arr: every boolean the endpoint declares is sent; queue removal is not offered' => function () {
        $fx = arrFixture('sonarr');
        try {
            foreach ([['list'], ['queue'], ['wanted'], ['wanted', '--cutoff'], ['history', '--since', '2026-10-01'], ['remove', '2']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args); // the fake answers 400 and logs a violation when one is missing
                check($code === 0, implode(' ', $args) . ': ' . $out);
            }
            $del = arrCalls($fx, 'DELETE', 'series/2');
            check(count($del) === 1 && $del[0]['query'] === 'deleteFiles=false&addImportListExclusion=false',
                'remove params: ' . json_encode($del));
            check(!array_filter(arrState($fx)['calls'], function ($c) {
                return $c['method'] === 'DELETE' && strpos($c['path'], 'queue') === 0;
            }), 'a queue delete was sent');
            list(, $out) = runPhp(dirname(__DIR__) . '/pikkumies/tools/arr/sonarr/sonarr.php', ['--verbs']);
            check(!preg_match('/dequeue|queue-remove|command/', $out), 'a queue-removal or command verb exists: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr: the category README\'s first table is the verbs every unit has, and the contract test sees every unit' => function () {
        $units = array_values(array_filter(Units::all(), function ($u) {
            return $u['category'] === 'arr';
        }));
        check(array_column($units, 'name') === ['autobrr', 'bazarr', 'jackett', 'lidarr', 'prowlarr', 'radarr', 'readarr', 'sonarr', 'whisparr'],
            'arr units: ' . json_encode(array_column($units, 'name')));
        $shared = array_column(Units::verbs((string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/arr/README.md')), 'verb');
        check($shared === ['list', 'show', 'stats', 'health', 'history', 'restart'], 'first table: ' . json_encode($shared));
    },
];
