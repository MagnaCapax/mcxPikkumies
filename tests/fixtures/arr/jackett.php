<?php
/**
 * tests/ArrJackettTest.php: a temp HOME with Jackett's ServerConfig.json (decoy secrets in the fields the tool must
 * never read) and the fake Jackett (jackett-router.php). Same state file, arrRun() and arrP2Serve/arrP2Done as phase2.php.
 */
require_once __DIR__ . '/phase2.php';
require_once dirname(__DIR__, 3) . '/pikkumies/tools/arr/jackett/JackettConfig.php';
require_once dirname(__DIR__, 3) . '/pikkumies/tools/arr/jackett/JackettIndexers.php';

/** ServerConfig.json text the way Jackett writes it (Newtonsoft, PascalCase), with $port and decoys. */
function arrJackettConfig($port, $key = ARR_KEY)
{
    return json_encode(['Port' => $port, 'LocalBindAddress' => '127.0.0.1', 'AllowExternal' => false, 'APIKey' => $key,
        'AdminPassword' => str_repeat('ab', 64), 'InstanceId' => ARR_DECOY . ARR_DECOY, 'BasePathOverride' => '/jackett/',
        'ProxyPassword' => ARR_DECOY, 'OmdbApiKey' => ARR_DECOY, 'CacheEnabled' => true, 'CacheTtl' => 2100], JSON_PRETTY_PRINT);
}

/** $over: state changes; 'where' => default|docker; 'config' => raw text; 'start' => false. */
function arrJackettFixture(array $over = [])
{
    $dir = sys_get_temp_dir() . '/pk-arrj-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    foreach (["$home", "$dir/bin", "$dir/pmss"] as $d) {
        mkdir($d, 0700, true);
    }
    $port = arrFreePort();
    $docker = isset($over['where']) && $over['where'] === 'docker';
    $cfg = $docker ? "$home/docker/jackett/config/Jackett/ServerConfig.json" : "$home/.config/Jackett/ServerConfig.json";
    mkdir(dirname($cfg), 0700, true);
    file_put_contents($cfg, isset($over['config']) ? $over['config'] : arrJackettConfig($docker ? 9117 : $port));
    $ix = function ($id, $name, $type, array $test, $configured = true) {
        return ['id' => $id, 'name' => $name, 'type' => $type, 'configured' => $configured, 'link' => "https://$id.example/?ref=abc",
            'description' => "$name tracker & more", 'test' => $test];
    };
    $state = ['app' => 'jackett', 'key' => ARR_KEY, 'base' => '/jackett', 'dir' => $dir, 'mode' => '', 'health' => 'OK',
        'calls' => [], 'violations' => [], 'indexers' => [
            $ix('1337x', '1337x', 'public', [2, 50, null]),
            $ix('privtr', 'Private <Tr>', 'private', [1, 0,
                "Jackett.Common.IndexerException: Login failed for https://privtr.example/login?passkey=SECRETPASS7 key "
                . ARR_KEY . "\n   at Jackett.Common.Indexers.BaseIndexer.Login() in /src/x.cs:line 9"]),
            $ix('quiet', 'Quiet', 'semi-private', [2, 0, null]),
            $ix('unused', 'Not Configured', 'public', [2, 9, null], false),
        ]];
    $state = array_merge($state, array_diff_key($over, array_flip(['where', 'config', 'start'])));
    file_put_contents("$dir/state.json", json_encode($state));
    $fx = ['app' => 'jackett', 'dir' => $dir, 'home' => $home, 'port' => $port, 'proc' => null, 'config' => $cfg,
        'router' => __DIR__ . '/jackett-router.php'];
    if (!isset($over['start']) || $over['start']) {
        arrP2Serve($fx);
    }
    return $fx;
}
