<?php
/**
 * Helpers for tests/Arr*Test.php: a temp HOME with a config.xml, the fake API (router.php under
 * `php -S`), and a runner that captures stdout AND stderr and fails when the API key appears in either.
 * The oracle is the literal key string, not a copy of the tools' redaction.
 */
require_once __DIR__ . '/seed.php';
require_once dirname(__DIR__, 3) . '/pikkumies/tools/arr/lib/Arr.php';

const ARR_KEY = '0123456789abcdef0123456789abcdef';

function arrFreePort()
{
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($srv, false);
    fclose($srv);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * $app: sonarr|radarr|prowlarr. $over: changes to the fake's state; 'where' => pmss|servarr|docker|none
 * picks where config.xml goes; 'config' => raw config.xml text (default a valid one); 'start' => false.
 */
function arrFixture($app, array $over = [])
{
    $dir = sys_get_temp_dir() . '/pk-arr-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    foreach (["$home/media/Show A/Season 1", "$home/media/Film B", "$home/media/Film C", "$home/bin", "$dir/bin", "$dir/recycle"] as $d) {
        mkdir($d, 0700, true);
    }
    file_put_contents("$home/media/Show A/Season 1/e1.mkv", str_repeat('1', 3000));
    file_put_contents("$home/media/Show A/extra.nfo", str_repeat('n', 1000));
    file_put_contents("$home/media/Film B/b.mkv", str_repeat('b', 2048));
    file_put_contents("$home/media/Film C/c.mkv", str_repeat('c', 500));
    // never the host's own tmux: a session "exists" only while $dir/tmux-up does
    file_put_contents("$dir/bin/tmux", "#!/bin/sh\necho \"\$*\" >> \"$dir/tmux.log\"\n"
        . "[ \"\$1\" = has-session ] && [ -f \"$dir/tmux-up\" ] && exit 0\nexit 1\n");
    chmod("$dir/bin/tmux", 0700);
    link("$home/media/Film C/c.mkv", "$home/media/c-hardlink.mkv"); // a second hard link: frees nothing
    $port = arrFreePort();
    $api = $app === 'prowlarr' ? 'v1' : 'v3';
    $base = array_key_exists('base', $over) ? $over['base'] : '/public-fake/' . $app;
    $where = isset($over['where']) ? $over['where'] : 'pmss';
    $cfgPath = ['pmss' => "$home/.config/$app/config.xml", 'servarr' => "$home/.config/" . ucfirst($app) . '/config.xml',
        'docker' => "$home/docker/$app/config/config.xml", 'none' => "$dir/unused.xml"][$where];
    @mkdir(dirname($cfgPath), 0700, true);
    $cfgPort = $where === 'docker' ? 8989 : $port;
    file_put_contents($cfgPath, isset($over['config']) ? $over['config'] : "<Config>\n  <BindAddress>127.0.0.1</BindAddress>\n"
        . "  <Port>$cfgPort</Port>\n  <UrlBase>$base</UrlBase>\n  <ApiKey>" . ARR_KEY . "</ApiKey>\n"
        . "  <AuthenticationMethod>Forms</AuthenticationMethod>\n</Config>\n");
    @mkdir("$dir/pmss", 0700); // PMSS unless a test points PIKKUMIES_PMSS_DIR elsewhere
    $state = ['app' => $app, 'api' => $api, 'base' => $base, 'key' => ARR_KEY, 'dir' => $dir, 'mode' => '', 'sleep' => 0,
        'recycleBin' => '', 'refuse' => false, 'commandMode' => 'complete', 'commands' => [], 'calls' => [], 'violations' => []];
    $state = array_merge($state, arrSeedFor($app, $home), array_diff_key($over, array_flip(['where', 'config', 'start', 'base'])));
    file_put_contents("$dir/state.json", json_encode($state));
    $proc = null;
    if (!isset($over['start']) || $over['start']) {
        $proc = proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/router.php'),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['ARR_FAKE_STATE' => "$dir/state.json"]);
        for ($i = 0; $i < 50 && !($c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1)); $i++) {
            usleep(100000);
        }
        if (!empty($c)) {
            fclose($c);
        }
    }
    return ['app' => $app, 'dir' => $dir, 'home' => $home, 'port' => $port, 'proc' => $proc, 'config' => $cfgPath];
}

function arrDone(array $fx)
{
    if ($fx['proc']) {
        proc_terminate($fx['proc']);
        proc_close($fx['proc']);
    }
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

function arrState(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/state.json'), true);
}

function arrSet(array $fx, array $change)
{
    file_put_contents($fx['dir'] . '/state.json', json_encode(array_merge(arrState($fx), $change)));
}

/** Runs the unit; returns [exit code, records, stdout, stderr]. Fails the test when the key shows up anywhere. */
function arrRun(array $fx, array $args, array $env = [])
{
    $env += ['HOME' => $fx['home'], 'PATH' => $fx['dir'] . '/bin:/usr/bin:/bin', 'PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/pmss',
        'PIKKUMIES_ARR_TIMEOUT' => '5', 'PIKKUMIES_ARR_WAIT' => '3', 'DOCKER_HOST' => 'unix://' . $fx['dir'] . '/no-docker.sock'];
    $script = dirname(__DIR__, 3) . "/pikkumies/tools/arr/{$fx['app']}/{$fx['app']}.php";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . implode('', array_map(function ($a) {
        return ' ' . escapeshellarg($a);
    }, $args));
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    check(stripos($out . $err, ARR_KEY) === false, 'the API key appeared in the output: ' . $out . $err);
    $calls = file_exists($fx['dir'] . '/state.json') ? arrState($fx) : ['violations' => []];
    check(empty($calls['violations']), 'the fake saw a violation: ' . json_encode($calls['violations']));
    return [$code, arrLines($out), $out, $err];
}

function arrCalls(array $fx, $method, $path)
{
    return array_values(array_filter(arrState($fx)['calls'], function ($c) use ($method, $path) {
        return $c['method'] === $method && $c['path'] === $path;
    }));
}

function arrLines($out)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", $out), 'strlen')));
}
