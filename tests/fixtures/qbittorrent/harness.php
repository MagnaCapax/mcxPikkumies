<?php
/**
 * Helpers for the tests/Qbittorrent*Test.php files: a temp HOME with qBittorrent's files, the fake WebUI
 * (router.php under `php -S`), and a wrapper that runs the unit with the test seams set.
 */
function qbitFreePort()
{
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($srv, false), strrpos((string) stream_socket_get_name($srv, false), ':') + 1);
    fclose($srv);
    return $port;
}

/** A fixture: temp dir, HOME files, stored password, fake WebUI running. $over changes the fake's state. */
function qbitFixture(array $over = [], $start = true)
{
    $dir = sys_get_temp_dir() . '/pk-qbit-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    $data = "$home/data";
    foreach (["$home/.config/qBittorrent", "$data/Beta/sub", "$dir/secrets", "$dir/proc", "$dir/bin"] as $d) {
        mkdir($d, 0700, true);
    }
    file_put_contents("$data/Alpha.mkv", str_repeat('a', 3000));
    file_put_contents("$data/Beta/x.bin", str_repeat('b', 5000));
    file_put_contents("$data/Beta/sub/y.bin", str_repeat('y', 1000));
    $port = qbitFreePort();
    file_put_contents("$home/.config/qBittorrent/qBittorrent.conf", "[BitTorrent]\nSession\\Port=51413\n\n[Preferences]\nWebUI\\Address=127.0.0.1\nWebUI\\Port=$port\nWebUI\\Username=alice\n");
    file_put_contents("$home/.qbittorrentPort", "$port\n");
    touch("$home/.qbittorrentEnable");
    file_put_contents("$dir/secrets/qbittorrent", json_encode(['password' => 'right-one', 'rejected' => false]));
    mkdir("$dir/proc/self/ns", 0700, true);
    symlink('mnt:[4026531841]', "$dir/proc/self/ns/mnt"); // the tool's own mount namespace in the fake /proc
    $t = function ($hash, $name, $state, $progress, array $extra = []) use ($data) {
        return $extra + ['hash' => $hash, 'name' => $name, 'state' => $state, 'progress' => $progress, 'size' => 1000, 'completed' => (int) (1000 * $progress),
            'ratio' => 0.5, 'dlspeed' => 10, 'upspeed' => 20, 'added_on' => 1700000000, 'category' => 'tv', 'content_path' => "$data/$name", 'save_path' => $data,
            'files' => [['index' => 0, 'name' => "$name/f.bin", 'size' => 10]], 'props' => ['total_size' => 1000]];
    };
    $states = ['checkingUP' => 'e', 'queuedDL' => 'f', 'error' => '0', 'metaDL' => '1', 'moving' => '2', 'stalledDL' => 'd', 'missingFiles' => '3'];
    $torrents = [
        $t(str_repeat('a', 40), 'Alpha', 'uploading', 1, ['size' => 3000, 'completed' => 3000, 'content_path' => "$data/Alpha.mkv",
            'completion_on' => 1700000900, 'seeding_time' => 7200, 'private' => true, 'uploaded' => 9000, // the key live 5.1/5.2 send
            'files' => [['index' => 0, 'name' => 'Alpha.mkv', 'size' => 3000]], 'props' => ['total_size' => 3000, 'seeds' => 4, 'comment' => 'hi', 'infohash_v1' => str_repeat('a', 40), 'save_path' => $data]]),
        $t(str_repeat('B', 40), 'Beta', 'stoppedUP', 1, ['size' => 6000, 'completed' => 6000, 'isPrivate' => false, // the wiki's key
            'files' => [['index' => 0, 'name' => 'Beta/x.bin', 'size' => 5000], ['index' => 1, 'name' => 'Beta/sub/y.bin', 'size' => 1000]]]),
        $t(str_repeat('c', 40), 'Gamma', 'pausedDL', 0.5, ['completion_on' => 4294967295]),
            // an unfinished torrent as qBittorrent 4.x reports it [UNVERIFIED]
    ];
    foreach ($states as $state => $ch) {
        $torrents[] = $t(str_repeat($ch, 40), "T-$state", $state, 0.2);
    }
    $state = $over + ['auth' => true, 'user' => 'alice', 'password' => 'right-one', 'api' => '2.11.2', 'version' => 'v5.0.0', 'logins' => 0, 'calls' => [], 'deleted' => [],
        'slow' => 0, 'base32_hash' => '', 'torrents' => $torrents, 'transfer' => ['dl_info_speed' => 100, 'up_info_speed' => 200, 'dl_info_data' => 1000,
        'up_info_data' => 2000, 'dl_rate_limit' => 0, 'up_rate_limit' => 1048576, 'connection_status' => 'connected', 'dht_nodes' => 5],
        'prefs' => ['torrent_content_remove_option' => 'MoveToTrash', 'listen_port' => 51413, 'save_path' => $data]];
    file_put_contents("$dir/state.json", json_encode($state));
    $fx = ['dir' => $dir, 'home' => $home, 'data' => $data, 'port' => $port, 'proc' => null];
    if ($start) {
        $fx['proc'] = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/router.php'),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['QBIT_FAKE_STATE' => "$dir/state.json"]);
        for ($i = 0; $i < 50 && !($s = @fsockopen('127.0.0.1', $port, $e, $es, 0.1)); $i++) {
            usleep(100000);
        }
        if ($s) {
            fclose($s);
        }
    }
    return $fx;
}

/** Runs the unit as a CLI with HOME, PATH and the seams set; returns [exit code, stdout]. */
function qbitRun(array $fx, array $args, $family = 'pmss')
{
    $unit = dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients/qbittorrent/qbittorrent.php';
    file_put_contents($fx['dir'] . '/run.php', '<?php
putenv("HOME=" . ' . var_export($fx['home'], true) . ');
putenv("PATH=" . ' . var_export($fx['dir'] . '/bin', true) . ' . ":" . getenv("PATH"));
$qbitFamily = ' . var_export($family, true) . ';
$qbitSecretDir = ' . var_export($fx['dir'] . '/secrets', true) . ';
$qbitProcRoot = ' . var_export($fx['dir'] . '/proc', true) . ';
$qbitPage = ' . var_export(isset($fx['page']) ? $fx['page'] : null, true) . ';
require ' . var_export($unit, true) . ';
');
    return runPhp($fx['dir'] . '/run.php', $args);
}

/** A fake /proc entry for $pid: comm qbittorrent-nox, the binary, and the mount namespace $ns. */
function qbitFakeProc(array $fx, $pid, $ns)
{
    mkdir($fx['dir'] . "/proc/$pid/ns", 0700, true);
    file_put_contents($fx['dir'] . "/proc/$pid/comm", "qbittorrent-nox\n");
    symlink('/usr/bin/qbittorrent-nox', $fx['dir'] . "/proc/$pid/exe");
    symlink($ns, $fx['dir'] . "/proc/$pid/ns/mnt");
}

function qbitState(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/state.json'), true);
}

/** The calls the fake received, as "METHOD api" strings. */
function qbitCalls(array $fx)
{
    return array_map(function ($c) {
        return $c[0] . ' ' . $c[1];
    }, qbitState($fx)['calls']);
}

function qbitDone(array $fx)
{
    if ($fx['proc']) {
        proc_terminate($fx['proc']);
        proc_close($fx['proc']);
        clearstatcache();
    }
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

/** Runs $fn($fx) with a fixture and always cleans up; skipped without PHP curl, which the unit needs like every HTTP tool (ADR-0012). */
function qbitWith(array $over, callable $fn, $start = true)
{
    if (!function_exists('curl_init')) {
        return;
    }
    $fx = qbitFixture($over, $start);
    try {
        $fn($fx);
    } finally {
        qbitDone($fx);
    }
}
