<?php
/**
 * Helpers for the phase-2 tests (tests/ArrLidarrTest.php, ArrReadarrTest.php, ArrBazarrTest.php, ArrAutobrrTest.php):
 * the key oracle for a Servarr unit, the port-owner refusal through Host::ownsListener's $tables seam (always runs,
 * no foreign listener needed), and arrOtherFixture(): a temp HOME with Bazarr's config.yaml or autobrr's config.toml
 * and its fake API (bazarr-router.php, autobrr-router.php under `php -S`). Same state file and arrRun() as harness.php.
 */
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/seed-other.php';

const ARR_DECOY = 'decoy0000000000000000000000000000'; // a key-shaped value in another section: never the one used

function arrUid()
{
    return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
}

/** This uid's user name: the PMSS installer puts autobrr's API under /public-<user>/autobrr/ (install-media-stack.sh:104). */
function arrUser()
{
    $pw = function_exists('posix_getpwuid') ? posix_getpwuid(arrUid()) : false;
    return is_array($pw) ? $pw['name'] : '';
}

/** /proc/net/tcp with one LISTEN socket on 127.0.0.1:$port owned by $uid, and an empty tcp6. */
function arrP2Tables($port, $uid, $inode = 4242)
{
    $head = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n";
    return [$head . sprintf('   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 %d 1', $port, $uid, $inode), ''];
}

/** $find($tables) must accept this user's own listener and refuse another account's before any key is sent. */
function arrPhase2OwnerRefusal(callable $find, $port)
{
    $uid = arrUid();
    $w = $find(arrP2Tables($port, $uid));
    check($w['port'] === $port, 'this user\'s own listener was refused: ' . json_encode($w));
    $msg = '';
    try {
        $find(arrP2Tables($port, $uid + 1));
    } catch (CliError $e) {
        $msg = $e->getMessage();
    }
    check(strpos($msg, "port $port") !== false && strpos($msg, 'another account') !== false, 'a foreign port was used: ' . $msg);
}

/** A Servarr unit: 401 (read config.xml again, retry once), a 500 that echoes the key, a dead port; no key in a URL. */
function arrPhase2KeyOracle($app, array $over, $item)
{
    $fx = arrFixture($app, $over + ['key' => 'another-key-the-app-has-now-000000']);
    try {
        list($code, $l, $out) = arrRun($fx, ['list']);
        check($code === 1 && strpos($out, 'HTTP 401') !== false && count(arrCalls($fx, 'GET', $item)) === 2, '401: ' . $out);
        arrSet($fx, ['key' => ARR_KEY, 'mode' => 'http500']);
        list($code, $l, $out, $err) = arrRun($fx, ['stats', '--human']);
        check($code === 1 && strpos($err, 'HTTP 500') !== false && strpos($err, 'REDACTED') !== false, '500: ' . $out . $err);
        arrSet($fx, ['mode' => '']);
        list($code) = arrRun($fx, ['list']);
        check($code === 0, 'the header key works');
        foreach (arrState($fx)['calls'] as $c) {
            check(stripos($c['query'], 'apikey') === false, 'a key in the query: ' . $c['query']);
        }
    } finally {
        arrDone($fx);
    }
    $dead = arrFixture($app, $over + ['start' => false]);
    try {
        list($code, $l, $out) = arrRun($dead, ['history']);
        check($code === 1 && strpos($out, 'not running') !== false, 'dead port: ' . $out);
    } finally {
        arrDone($dead);
    }
}

/** YAML text from section => [key => scalar, or a nested [key => scalar]], two-space indent, the way Bazarr writes it. */
function arrYaml(array $sections)
{
    $out = "---\n";
    foreach ($sections as $name => $keys) {
        $out .= "$name:\n";
        foreach ($keys as $k => $v) {
            $out .= is_array($v) ? "  $k:\n" . implode('', array_map(function ($kk, $vv) {
                return "    $kk: $vv\n";
            }, array_keys($v), $v)) : "  $k: $v\n";
        }
    }
    return $out;
}

/** bazarr|autobrr with its config file and fake API. $over: state changes; 'where' => default|docker; 'config'; 'start' => false. */
function arrOtherFixture($app, array $over = [])
{
    $dir = sys_get_temp_dir() . '/pk-arr2-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    foreach (["$home/media", "$dir/bin", "$dir/pmss"] as $d) {
        mkdir($d, 0700, true);
    }
    file_put_contents("$dir/bin/tmux", "#!/bin/sh\necho \"\$*\" >> \"$dir/tmux.log\"\n"
        . "[ \"\$1\" = has-session ] && [ -f \"$dir/tmux-up\" ] && exit 0\nexit 1\n");
    chmod("$dir/bin/tmux", 0700);
    $port = arrFreePort();
    $docker = isset($over['where']) && $over['where'] === 'docker';
    if ($app === 'bazarr') {
        $cfg = $docker ? "$home/docker/bazarr/config/config/config.yaml" : "$home/bazarr/data/config/config.yaml";
        $text = arrYaml(['analytics' => ['enabled' => 'false'], 'plex' => ['apikey' => ARR_DECOY, 'port' => 1], 'auth' => ['apikey' => ARR_KEY],
            'general' => ['base_url' => '/bazarr', 'extra' => ['port' => 2], 'ip' => "'*'", 'port' => $docker ? 6767 : $port],
            'sonarr' => ['apikey' => ARR_DECOY, 'base_url' => '/']]);
        $state = arrBazarrSeed($home) + ['apiBase' => '/bazarr/api/', 'header' => 'X-API-KEY'];
    } else {
        $cfg = "$home/.config/autobrr/config.toml";
        $base = '/public-' . arrUser() . '/autobrr/'; // where the installer's environment puts it on PMSS
        $text = "# config.toml\nhost = \"127.0.0.1\"\nport = $port\nbaseUrl = \"$base\"\nbaseUrlModeLegacy = false\n"
            . "databaseType = \"sqlite\"\n\n[metrics]\nport = 1\n";
        $state = arrAutobrrSeed() + ['apiBase' => $base . 'api/', 'header' => 'X-API-Token'];
    }
    @mkdir(dirname($cfg), 0700, true);
    file_put_contents($cfg, isset($over['config']) ? $over['config'] : $text);
    $state = array_merge($state, ['app' => $app, 'key' => ARR_KEY, 'dir' => $dir, 'mode' => '', 'sleep' => 0, 'calls' => [], 'violations' => []],
        array_diff_key($over, array_flip(['where', 'config', 'start'])));
    file_put_contents("$dir/state.json", json_encode($state));
    $fx = ['app' => $app, 'dir' => $dir, 'home' => $home, 'port' => $port, 'proc' => null, 'config' => $cfg,
        'router' => __DIR__ . "/$app-router.php"];
    if ($app === 'autobrr') {
        $fx['script'] = __DIR__ . '/autobrr-wrapper.php';
    }
    if (!isset($over['start']) || $over['start']) {
        arrP2Serve($fx);
    }
    return $fx;
}

/** Starts the fake on $fx['port'] (exec: the recorded pid is php's own), waits until it accepts connections. */
function arrP2Serve(array $fx)
{
    $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:{$fx['port']} " . escapeshellarg($fx['router']),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['ARR_FAKE_STATE' => $fx['dir'] . '/state.json']);
    file_put_contents($fx['dir'] . '/server.pid', proc_get_status($p)['pid']);
    for ($i = 0; $i < 50 && !($c = @fsockopen('127.0.0.1', $fx['port'], $e1, $e2, 0.1)); $i++) {
        usleep(100000);
    }
    if (!empty($c)) {
        fclose($c);
    }
}

/** Stops whatever fake runs now (the fake installer may have started a new one) and removes the temp tree. */
function arrP2Done(array $fx)
{
    $pid = (int) @file_get_contents($fx['dir'] . '/server.pid');
    if ($pid > 1) {
        exec('kill ' . $pid . ' 2>/dev/null');
        usleep(100000);
    }
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

/** A fake `docker` (port answers with $portOut, restart counts a start) and a socket this user owns, as ArrLocateTest's. */
function arrP2Docker(array $fx, $portOut)
{
    file_put_contents($fx['dir'] . '/docker-port.txt', $portOut);
    file_put_contents($fx['dir'] . '/bin/docker', "#!/bin/sh\nd=\$(dirname \"\$0\")/..\necho \"\$DOCKER_HOST \$*\" >> \"\$d/docker.log\"\n"
        . "case \"\$1\" in port) cat \"\$d/docker-port.txt\" ;; restart) echo x >> \"\$d/restarts\" ;;"
        . " inspect) echo \"2026-10-14T0\$(wc -l < \"\$d/restarts\" 2>/dev/null || echo 0):00:00Z\" ;; esac\n");
    chmod($fx['dir'] . '/bin/docker', 0700);
    return stream_socket_server('unix://' . $fx['dir'] . '/docker.sock');
}
