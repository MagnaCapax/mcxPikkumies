<?php
/**
 * Helpers for the tests/Media*Test.php files: a temp HOME and secrets dir, a fake server (mediabrowser.php
 * for Jellyfin and Emby, plex.php for Plex) under `php -S`, a fake `docker` on PATH with a unix socket the
 * test owns, and a runner that sets the unit's test seams and checks that no credential is ever printed.
 */
require_once dirname(__DIR__, 3) . '/pikkumies/tools/media-servers/lib/MediaServers.php';

function mediaFreePort()
{
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($srv, false);
    fclose($srv);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/** A /proc/net/tcp with one LISTEN socket on 127.0.0.1:$port owned by $uid (the Host::ownsListener seam). */
function mediaNetTable($port, $uid)
{
    return ["  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
        . sprintf("   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 4242 1 0000000000000000"
            . " 100 0 0 10 0\n", $port, $uid)];
}

/** $router: mediabrowser or plex; $state: the fake's state (kind, tokens, sessions...). */
function mediaFixture($router, array $state, $start = true)
{
    $dir = sys_get_temp_dir() . '/pk-media-' . getmypid() . '-' . mt_rand();
    foreach (["$dir/home", "$dir/secrets", "$dir/bin"] as $d) {
        mkdir($d, 0700, true);
    }
    $port = mediaFreePort();
    file_put_contents("$dir/state.json", json_encode($state + ['calls' => [], 'logins' => 0]));
    file_put_contents("$dir/power", 'up');
    // The fake docker: PHP, found through PATH; it finds its state next to the socket named by DOCKER_HOST.
    file_put_contents("$dir/bin/docker", '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/docker.php'));
    chmod("$dir/bin/docker", 0700);
    $fx = ['dir' => $dir, 'home' => "$dir/home", 'port' => $port, 'proc' => null, 'router' => $router];
    if ($start) {
        $fx['proc'] = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . "/$router.php"),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
            ['MEDIA_FAKE_DIR' => $dir]);
        for ($i = 0; $i < 50 && !($s = @fsockopen('127.0.0.1', $port, $e, $es, 0.1)); $i++) {
            usleep(100000);
        }
        if ($s) {
            fclose($s);
        }
    }
    return $fx;
}

/** A fake rootless Docker: a unix socket this test owns, and what `docker ps` / `inspect` print. */
function mediaDocker(array $fx, array $containers)
{
    $sock = stream_socket_server('unix://' . $fx['dir'] . '/docker.sock');
    file_put_contents($fx['dir'] . '/docker.json', json_encode(['containers' => $containers, 'calls' => []]));
    return $sock; // keep it open while the test runs
}

/**
 * Runs a unit with HOME, PATH and the seams set; returns [exit code, records, stdout, stderr]. Fails when
 * any of $secrets appears in stdout or stderr (the literal-credential oracle).
 */
function mediaRun(array $fx, $unit, array $args, array $seams = [], array $secrets = [])
{
    $seams += ['secrets' => $fx['dir'] . '/secrets', 'pmss_dir' => $fx['dir'] . '/no-pmss', 'docker_sock' => $fx['dir'] . '/docker.sock',
        'timeout' => 5, 'wait' => 5, 'stop_wait' => 3];
    $script = dirname(__DIR__, 3) . "/pikkumies/tools/media-servers/$unit/$unit.php";
    file_put_contents($fx['dir'] . '/run.php', '<?php
putenv("HOME=" . ' . var_export($fx['home'], true) . ');
putenv("PATH=" . ' . var_export($fx['dir'] . '/bin', true) . ' . ":" . getenv("PATH"));
$mediaSeams = ' . var_export($seams, true) . ';
require ' . var_export($script, true) . ';
');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fx['dir'] . '/run.php');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache(); // the unit changed files in another process; never trust this process's stat cache
    foreach ($secrets as $s) {
        check(strpos($out . $err, $s) === false, "a credential appeared in the output of $unit " . implode(' ', $args));
    }
    return [$code, cliLines($out), $out, $err];
}

function mediaState(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/state.json'), true);
}

function mediaSetState(array $fx, array $changes)
{
    file_put_contents($fx['dir'] . '/state.json', json_encode(array_merge(mediaState($fx), $changes)));
}

/** The calls the fake received, as "METHOD path?query" strings. */
function mediaCalls(array $fx)
{
    return array_map(function ($c) {
        return $c['method'] . ' ' . $c['path'] . ($c['query'] !== '' ? '?' . $c['query'] : '');
    }, mediaState($fx)['calls']);
}

function mediaDone(array $fx)
{
    if ($fx['proc']) {
        proc_terminate($fx['proc']);
        proc_close($fx['proc']);
        clearstatcache();
    }
    if (strpos($fx['dir'], sys_get_temp_dir() . '/pk-media-') === 0) {
        exec('rm -rf ' . escapeshellarg($fx['dir']));
    }
}
