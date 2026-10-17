<?php
/**
 * Helpers for the tests/Containers*Test.php files: a throwaway HOME, local/ and proc/ dir, the fake `docker`
 * (tests/fixtures/media/docker.php in containers mode) on PATH, a unix socket this test owns, and a runner that
 * includes docker.php with $dockerSeams set, so nothing reads the real pikkumies/local/, /proc or /run/user.
 */

/** $state: the fake's containers-mode state (an 'engine' key is added when missing). $socket false: no socket. */
function containersFixture(array $state = [], $socket = true)
{
    $dir = testTempDir('pk-containers');
    foreach (['home', 'local', 'proc', 'bin', 'run'] as $d) {
        mkdir("$dir/$d", 0700, true);
    }
    file_put_contents("$dir/bin/docker", '#!' . PHP_BINARY . "\n" . file_get_contents(dirname(__DIR__) . '/media/docker.php'));
    chmod("$dir/bin/docker", 0700);
    file_put_contents("$dir/run/docker.json", json_encode($state + ['engine' => [], 'containers' => [], 'images' => [], 'calls' => []]));
    $fx = ['dir' => $dir, 'home' => "$dir/home", 'sock' => "$dir/run/docker.sock", 'server' => null];
    if ($socket) {
        $fx['server'] = @stream_socket_server('unix://' . $fx['sock']);
        if ($fx['server'] === false) {
            testRmTree($dir);
            skip('unix sockets unavailable here');
        }
    }
    return $fx;
}

function containersDone(array $fx)
{
    if (is_resource($fx['server'])) {
        fclose($fx['server']);
    }
    testRmTree($fx['dir']);
}

/** The seams a test passes to DockerCli / docker.php for this fixture. */
function containersSeams(array $fx, array $extra = [])
{
    return $extra + ['uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(), 'home' => $fx['home'], 'candidates' => [$fx['sock']],
        'pmss_dir' => $fx['dir'] . '/no-pmss', 'proc_root' => $fx['dir'] . '/proc', 'local_dir' => $fx['dir'] . '/local',
        'docker_config' => $fx['home'] . '/.docker/config.json', 'system_socket' => $fx['dir'] . '/no-system.sock', 'linger_dir' => $fx['dir'] . '/linger'];
}

/** The fake's state as it is now (calls, contexts, containers...). */
function containersState(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/run/docker.json'), true);
}

/**
 * Runs docker.php <args> with HOME, PATH (fake docker first) and the seams; DOCKER_HOST and DOCKER_CONTEXT come from
 * $env (null unsets). Returns [exit code, records, stdout, stderr].
 */
function containersRun(array $fx, array $args, array $seams = [], array $env = [])
{
    $script = dirname(__DIR__, 3) . '/pikkumies/tools/containers/docker/docker.php';
    $env += ['DOCKER_HOST' => null, 'DOCKER_CONTEXT' => null];
    $lines = '';
    foreach ($env as $k => $v) {
        $lines .= $v === null ? 'putenv(' . var_export($k, true) . ");\n" : 'putenv(' . var_export("$k=$v", true) . ");\n";
    }
    file_put_contents($fx['dir'] . '/run.php', "<?php\n" . 'putenv("HOME=" . ' . var_export($fx['home'], true) . ");\n"
        . 'putenv("PATH=" . ' . var_export($fx['dir'] . '/bin', true) . ' . ":" . getenv("PATH"));' . "\n" . $lines
        . '$dockerSeams = ' . var_export(containersSeams($fx, $seams), true) . ";\n"
        . 'require ' . var_export($script, true) . ";\n");
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
    return [$code, cliLines($out), $out, $err];
}
