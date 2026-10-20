<?php
/** Helpers for the tests/Transmission*Test.php files: a fake home and a fake Transmission RPC (router.php under `php -S`). */
const TRANSMISSION_SESSION = 'fakeSessionId42';

/** A fake home (settings.json, data files, saved .torrent) and a running fake Transmission RPC; returns the fixture.
 * $settingsOver merges into settings.json (e.g. rpc-authentication-required/rpc-username for the auth tests). */
function transmissionFixture(array $over = [], array $settingsOver = [])
{
    $dir = sys_get_temp_dir() . '/pk-transmission-' . getmypid() . '-' . mt_rand();
    $home = "$dir/home";
    @mkdir("$home/.config/transmission-daemon", 0700, true);
    @mkdir("$home/data/Show/sub", 0700, true);
    @mkdir("$home/data/other", 0700, true);
    @mkdir("$home/torrents", 0700, true);
    @mkdir("$dir/bin", 0700, true);
    file_put_contents("$home/data/Show/e1.mkv", str_repeat('1', 3000));
    file_put_contents("$home/data/Show/sub/e2.srt", str_repeat('2', 1000));
    file_put_contents("$home/data/other/o.iso", str_repeat('o', 2000));
    file_put_contents("$home/torrents/a.torrent", 'd8:announce0:4:infod4:name4:Teste');
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
    fclose($srv);
    // Transmission's settings.json: one plain JSON object (no PMSS unix socket here -- the "elsewhere" TCP path;
    // see TransmissionLocateTest.php for the PMSS unix-socket branch, read against the real file format directly).
    file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode($settingsOver + [
        'rpc-enabled' => true, 'rpc-port' => $port, 'rpc-url' => '/transmission/', 'rpc-authentication-required' => false,
    ]));
    $t = function ($hash, $name, $status, $percentDone, $files, $extra = []) use ($home) {
        return $extra + ['hashString' => $hash, 'name' => $name, 'status' => $status, 'error' => 0, 'errorString' => '',
            'isStalled' => false, 'percentDone' => $percentDone, 'totalSize' => 4000, 'sizeWhenDone' => 4000, 'leftUntilDone' => 0,
            'uploadRatio' => 1.5, 'rateDownload' => 10, 'rateUpload' => 20, 'addedDate' => 1700000000, 'downloadDir' => "$home/data",
            'labels' => [], 'doneDate' => 0, 'secondsSeeding' => 0, 'isPrivate' => false, 'uploadedEver' => 0, 'files' => $files,
            'peers' => [], 'eta' => -1, 'file-count' => count($files), 'trackerStats' => [], 'torrentFile' => ''];
    };
    $f = function ($name, $length) {
        return ['name' => $name, 'length' => $length];
    };
    $tracker = function ($announce, $seeders, $leechers, $extra = []) {
        return $extra + ['announce' => $announce, 'tier' => 0, 'seederCount' => $seeders, 'leecherCount' => $leechers,
            'isBackup' => false, 'hasAnnounced' => true, 'lastAnnounceSucceeded' => true, 'lastAnnounceResult' => 'Success'];
    };
    $state = $over + ['session_id' => TRANSMISSION_SESSION, 'sleep' => 0, 'calls' => [], 'next_id' => 100,
        'session' => ['version' => '4.1.3 (fake)', 'peer-port' => 51413, 'rpc-version-semver' => '6.0.0',
            'speed-limit-down' => 0, 'speed-limit-up' => 0, 'speed-limit-down-enabled' => false, 'speed-limit-up-enabled' => false],
        'stats' => ['downloadSpeed' => 1000, 'uploadSpeed' => 2000,
            'cumulative-stats' => ['downloadedBytes' => 30000, 'uploadedBytes' => 40000]],
        'torrents' => [
            str_repeat('a', 40) => $t(str_repeat('a', 40), 'Show', 6, 1.0, [$f('Show/e1.mkv', 3000), $f('Show/sub/e2.srt', 1000)],
                ['doneDate' => 1700000500, 'secondsSeeding' => 86400, 'isPrivate' => true, 'uploadedEver' => 6000, 'labels' => ['tv', 'hd'],
                    'peers' => [['progress' => 1.0], ['progress' => 1.0], ['progress' => 0.4]],
                    'trackerStats' => [$tracker('https://tracker.example/SECRETPASSKEY0123456789/announce', 5, 2)],
                    'torrentFile' => "$home/torrents/a.torrent"]),
            str_repeat('b', 40) => $t(str_repeat('b', 40), 'Other', 0, 0.5, [$f('other/o.iso', 2000)]),
            str_repeat('c', 40) => $t(str_repeat('c', 40), 'Grabbing', 4, 0.125, []),
            str_repeat('d', 40) => $t(str_repeat('d', 40), 'Errored', 0, 0.0, [],
                ['error' => 3, 'errorString' => 'No data found! Ensure your drives are connected.']),
            str_repeat('e', 40) => $t(str_repeat('e', 40), 'StalledOne', 4, 0.2, [], ['isStalled' => true,
                'trackerStats' => [$tracker('https://tracker.example/announce', -1, -1,
                    ['hasAnnounced' => false, 'lastAnnounceSucceeded' => false, 'lastAnnounceResult' => ''])]]),
        ]];
    file_put_contents("$dir/state.json", json_encode($state));
    $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/router.php'),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['TRANSMISSION_FAKE_STATE' => "$dir/state.json"]);
    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($conn) {
            fclose($conn);
            break;
        }
        usleep(100000);
    }
    return ['dir' => $dir, 'home' => $home, 'port' => $port, 'proc' => $proc];
}

function transmissionDone(array $fx)
{
    if ($fx['proc']) {
        proc_terminate($fx['proc']);
        proc_close($fx['proc']);
        clearstatcache();
    }
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

function transmissionState(array $fx)
{
    return json_decode(file_get_contents($fx['dir'] . '/state.json'), true);
}

function transmissionEdit(array $fx, callable $fn)
{
    $s = transmissionState($fx);
    $fn($s);
    file_put_contents($fx['dir'] . '/state.json', json_encode($s));
}

/** Runs the unit with HOME, PATH and the test seams set; returns [exit code, records, output]. */
function transmissionRun(array $fx, array $args, array $env = [])
{
    $env += ['HOME' => $fx['home'], 'PATH' => $fx['dir'] . '/bin', 'PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/no-pmss', 'PIKKUMIES_TORRENT_TIMEOUT' => '5'];
    $old = [];
    foreach ($env as $k => $v) {
        $old[$k] = getenv($k);
        putenv("$k=$v");
    }
    try {
        list($code, $out) = runPhp(dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients/transmission/transmission.php', $args);
    } finally {
        foreach ($old as $k => $v) {
            putenv($v === false ? $k : "$k=$v");
        }
    }
    check(strpos($out, TRANSMISSION_SESSION) === false, 'the session id appeared in the output');
    return [$code, cliLines($out), $out];
}

function transmissionCalls(array $fx, $method)
{
    return array_values(array_filter(transmissionState($fx)['calls'], function ($c) use ($method) {
        return $c['method'] === $method;
    }));
}

/**
 * Runs the unit with a controlled `$transmissionSecretDir` test seam (the login/auth tests need this;
 * transmission.php is a subprocess entrypoint, so the seam is set by writing a tiny wrapper script that
 * assigns the variable and then requires the real unit, the same technique tests/fixtures/qbittorrent/
 * harness.php uses for `$qbitSecretDir`).
 */
function transmissionRunAuth(array $fx, array $args, $secretDir)
{
    $unit = dirname(__DIR__, 3) . '/pikkumies/tools/torrent-clients/transmission/transmission.php';
    $wrapper = $fx['dir'] . '/run-auth.php';
    file_put_contents($wrapper, '<?php
putenv("HOME=" . ' . var_export($fx['home'], true) . ');
putenv("PATH=" . ' . var_export($fx['dir'] . '/bin', true) . ');
putenv("PIKKUMIES_PMSS_DIR=" . ' . var_export($fx['dir'] . '/no-pmss', true) . ');
putenv("PIKKUMIES_TORRENT_TIMEOUT=5");
$transmissionSecretDir = ' . var_export($secretDir, true) . ';
require ' . var_export($unit, true) . ';
');
    list($code, $out) = runPhp($wrapper, $args);
    check(strpos($out, TRANSMISSION_SESSION) === false, 'the session id appeared in the output');
    return [$code, cliLines($out), $out];
}
