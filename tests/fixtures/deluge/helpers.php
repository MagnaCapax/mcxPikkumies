<?php
/** Helpers for the tests/Deluge*Test.php files: a fake home and a fake deluge-web (router.php under `php -S`). */
const DELUGE_SECRET = 'fakeLocalclientSecret42';

/** A fake home (auth, web.conf, data files) and a running fake deluge-web; returns the fixture. */
function delugeFixture(array $over = [])
{
    $dir = sys_get_temp_dir() . '/pk-deluge-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    @mkdir("$home/.config/deluge", 0700, true);
    @mkdir("$home/data/Show/sub", 0700, true);
    @mkdir("$home/data/other", 0700, true);
    @mkdir("$dir/bin", 0700, true);
    file_put_contents("$home/data/Show/e1.mkv", str_repeat('1', 3000));
    file_put_contents("$home/data/Show/sub/e2.srt", str_repeat('2', 1000));
    file_put_contents("$home/data/other/o.iso", str_repeat('o', 2000));
    file_put_contents("$home/.config/deluge/auth", "localclient:" . DELUGE_SECRET . ":10\n");
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
    fclose($srv);
    // Deluge's two-object config format (PMSS etc/seedbox/config/template.deluge.web.conf).
    file_put_contents("$home/.config/deluge/web.conf", "{\n  \"file\": 2,\n  \"format\": 1\n}{\n  \"base\": \"/user-fake/deluge/\",\n  \"https\": false,\n  \"port\": $port\n}");
    $t = function ($id, $name, $state, $progress, $files, $over = []) use ($home) {
        return $over + ['hash' => $id, 'name' => $name, 'state' => $state, 'progress' => $progress, 'total_wanted' => 4000, 'total_done' => 4000,
            'ratio' => 1.5, 'download_payload_rate' => 10, 'upload_payload_rate' => 20, 'time_added' => 1700000000.5, 'download_location' => "$home/data",
            'message' => 'OK', 'tracker_status' => 'Announce OK', 'num_files' => count($files), 'tracker_host' => 'tracker.example',
                'files' => $files,
            'completed_time' => 0, 'seeding_time' => 0, 'private' => false, 'total_uploaded' => 0,
            'trackers' => [['url' => 'https://tracker.example/a1b2c3d4e5f6a7b8c9d0/announce?passkey=SECRETDELUGEKEY99', 'tier' => 0]]];
    };
    $f = function ($path, $size) {
        return ['index' => 0, 'path' => $path, 'size' => $size, 'offset' => 0];
    };
    $state = $over + ['secret' => DELUGE_SECRET, 'sid' => 'abc123def', 'connected' => false, 'daemon_up' => true, 'sleep' => 0, 'calls' => [],
        'hosts' => [['h0', '10.0.0.9', 58846, 'someone'], ['h1', '127.0.0.1', $port, 'localclient']], // a port this test owns: the tool checks who listens before deluge-web connects
        'daemon_port' => $port, // what a connected daemon says about itself (core.get_config_value): never what the tool checks
        'connected_host' => null, // the host deluge-web is connected to (web.get_host_status answers 'Connected' for it)
        'torrents' => [
            str_repeat('a', 40) => $t(str_repeat('a', 40), 'Show', 'Seeding', 100.0, [$f('Show/e1.mkv', 3000), $f('Show/sub/e2.srt', 1000)],
                ['completed_time' => 1700000500, 'seeding_time' => 86400, 'private' => true, 'total_uploaded' => 6000]),
            str_repeat('b', 40) => $t(str_repeat('b', 40), 'Other', 'Paused', 50.0, [$f('other/o.iso', 2000)], ['tracker_status' => 'Error: unregistered torrent']),
            str_repeat('c', 40) => $t(str_repeat('c', 40), 'Grabbing', 'Downloading', 12.5, []),
            str_repeat('d', 40) => $t(str_repeat('d', 40), 'Moving one', 'Moving', 0.0, [], ['message' => 'Moving storage']),
            str_repeat('e', 40) => $t(str_repeat('e', 40), 'Allocating one', 'Allocating', 0.0, [], ['ratio' => -1.0]),
        ]];
    file_put_contents("$dir/state.json", json_encode($state));
    $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/router.php'),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['DELUGE_FAKE_STATE' => "$dir/state.json"]);
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($c) {
            fclose($c);
            break;
        }
        usleep(100000);
    }
    return ['dir' => $dir, 'home' => $home, 'port' => $port, 'proc' => $proc];
}

function delugeDone(array $fx)
{
    if ($fx['proc']) {
        proc_terminate($fx['proc']);
        proc_close($fx['proc']);
        clearstatcache();
    }
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

function delugeState(array $fx)
{
    return json_decode(file_get_contents($fx['dir'] . '/state.json'), true);
}

/** Runs the unit with HOME, PATH and the test seams set; returns [exit code, records, output]. */
function delugeRun(array $fx, array $args, array $env = [])
{
    $env += ['HOME' => $fx['home'], 'PATH' => $fx['dir'] . '/bin', 'PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/no-pmss', 'PIKKUMIES_TORRENT_TIMEOUT' => '5'];
    $old = [];
    foreach ($env as $k => $v) {
        $old[$k] = getenv($k);
        putenv("$k=$v");
    }
    try {
        list($code, $out) = runPhp(dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients/deluge/deluge.php', $args);
    } finally {
        foreach ($old as $k => $v) {
            putenv($v === false ? $k : "$k=$v");
        }
    }
    check(strpos($out, DELUGE_SECRET) === false, 'the secret appeared in the output');
    return [$code, cliLines($out), $out];
}

function delugeCalls(array $fx, $method)
{
    return array_values(array_filter(delugeState($fx)['calls'], function ($c) use ($method) {
        return $c['method'] === $method;
    }));
}

/** Changes the fake's state with $fn(array &$state); the fake reads it on every request. */
function delugeEdit(array $fx, callable $fn)
{
    $s = delugeState($fx);
    $fn($s);
    file_put_contents($fx['dir'] . '/state.json', json_encode($s));
}

/** Moves h0 (another account's daemon, port 58846) onto this machine: only local hosts are asked which one is connected. */
function delugeLocalForeignHost(array &$s)
{
    $s['hosts'][0][1] = '127.0.0.1';
}
