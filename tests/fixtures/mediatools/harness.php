<?php
/**
 * Helpers for the media/ffmpeg and media/transcribe tests: a temp HOME, a bin directory holding
 * only the fake programs here plus `php`, `curl`, `tar` and `cat` (real; curl/tar only ever reach
 * loopback test fixtures here, never the real network), and a wrapper that runs a unit with HOME
 * and PATH set. Same pattern as tests/fixtures/storage/harness.php.
 */
require_once dirname(__DIR__, 3) . '/pikkumies/tools/media/transcribe/TranscribeSecret.php';

const MT_FAKES = ['ffmpeg', 'ffprobe', 'whisper-cli', 'nproc', 'cmake', 'gcc', 'g++', 'make'];

function mtFixture(array $state = [], array $fakes = MT_FAKES)
{
    $dir = sys_get_temp_dir() . '/pk-mediatools-' . getmypid() . '-' . mt_rand();
    $fx = ['dir' => $dir, 'home' => "$dir/home", 'bin' => "$dir/bin", 'local' => "$dir/local"];
    foreach ([$fx['home'], $fx['bin'], $fx['local']] as $d) {
        mkdir($d, 0700, true);
    }
    $fx['home'] = realpath($fx['home']);
    $src = __DIR__ . '/bin';
    copy("$src/fakelib.php", $fx['bin'] . '/fakelib.php');
    foreach ($fakes as $f) {
        copy("$src/$f", $fx['bin'] . "/$f");
        chmod($fx['bin'] . "/$f", 0755);
    }
    foreach (['php', 'curl', 'tar', 'gzip', 'cat', 'nice', 'ionice', 'timeout'] as $real) {
        foreach (explode(':', (string) getenv('PATH')) as $p) {
            if ($p !== '' && is_executable("$p/$real")) {
                symlink("$p/$real", $fx['bin'] . "/$real");
                break;
            }
        }
    }
    file_put_contents("$dir/state.json", json_encode($state, JSON_UNESCAPED_SLASHES));
    touch("$dir/argv.log");
    return $fx;
}

/** Writes $fx/run.php: sets the environment and the seams (PHP variables), then includes the unit. Returns its path. */
function mtScript(array $fx, $unit, array $seams = [], array $env = [])
{
    $env += ['HOME' => $fx['home'], 'PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir']];
    $code = "<?php\n";
    foreach ($env as $k => $v) {
        $code .= 'putenv(' . var_export("$k=$v", true) . ");\n";
    }
    foreach ($seams as $k => $v) {
        $code .= "\$$k = " . var_export($v, true) . ";\n";
    }
    $code .= 'require ' . var_export(dirname(__DIR__, 3) . '/pikkumies/tools/' . $unit, true) . ";\n";
    file_put_contents($fx['dir'] . '/run.php', $code);
    return $fx['dir'] . '/run.php';
}

/** Runs a unit script; $seams are PHP variables set before it is included. Returns [exit code, stdout]. */
function mtRun(array $fx, $unit, array $args, array $seams = [], array $env = [])
{
    $r = runPhp(mtScript($fx, $unit, $seams, $env), $args);
    clearstatcache();
    return $r;
}

function mtLog(array $fx)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", (string) file_get_contents($fx['dir'] . '/argv.log')), 'strlen')));
}

/** The argv of every logged call of $bin, each joined with spaces. */
function mtCalls(array $fx, $bin)
{
    $out = [];
    foreach (mtLog($fx) as $c) {
        if ($c['bin'] === $bin) {
            $out[] = implode(' ', $c['argv']);
        }
    }
    return $out;
}

function mtSetState(array $fx, array $state)
{
    file_put_contents("$fx[dir]/state.json", json_encode($state, JSON_UNESCAPED_SLASHES));
}

/** JSON Lines output -> list of arrays. */
function mtLines($out)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", trim((string) $out)), 'strlen')));
}

function mtLast($out)
{
    $l = mtLines($out);
    return $l ? end($l) : null;
}

function mtDone(array $fx)
{
    foreach (['pid', 'dlPid'] as $k) {
        if (!empty($fx[$k])) {
            exec('kill ' . (int) $fx[$k] . ' 2>/dev/null');
        }
    }
    if (!empty($fx['pid']) || !empty($fx['dlPid'])) {
        usleep(100000);
    }
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

/** Starts the fake cloud endpoint (tests/fixtures/mediatools/router.php) on a free port; returns [fx-with-port-and-pid, baseUrl]. */
function mtServe(array $fx, array $cloudState = [])
{
    $port = testFreePort();
    $stateFile = $fx['dir'] . '/cloud-state.json';
    file_put_contents($stateFile, json_encode($cloudState + ['calls' => []]));
    $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/router.php'),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MT_FAKE_STATE' => $stateFile]);
    $fx['pid'] = proc_get_status($p)['pid'];
    $fx['cloudState'] = $stateFile;
    for ($i = 0; $i < 50 && !($c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1)); $i++) {
        usleep(100000);
    }
    if (!empty($c)) {
        fclose($c);
    }
    return [$fx, "http://127.0.0.1:$port"];
}

function mtCloudCalls(array $fx)
{
    $s = json_decode((string) @file_get_contents($fx['cloudState']), true);
    return is_array($s) && isset($s['calls']) ? $s['calls'] : [];
}

/** Writes the transcription secret directly (bypassing the login prompt) for tests that only need a stored login. */
function mtStoreLogin(array $fx, $endpoint, $model, $key)
{
    (new TranscribeSecret($fx['dir'] . '/secrets'))->save($endpoint, $model, $key);
}

/**
 * Runs transcribe.php through tests/fixtures/mediatools/transcribe-wrapper.php, with the fake PATH
 * and this fixture's secrets/local dirs by default. $opts: typed (login's answers, in order), pins
 * (a pins array for install-local, JSON-encoded), secretDir, localDir (either overrides the default).
 * Returns [exit code, stdout, stderr] — a test seam setup this exact (closures, env vars) cannot go
 * through mtScript's var_export, so this runs the wrapper directly (tests/fixtures/arr's own pattern).
 */
function mtTranscribeRun(array $fx, array $args, array $opts = [])
{
    $env = ['HOME' => $fx['home'], 'PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir'],
        'MT_SECRET_DIR' => isset($opts['secretDir']) ? $opts['secretDir'] : "$fx[dir]/secrets",
        'MT_LOCAL_DIR' => isset($opts['localDir']) ? $opts['localDir'] : $fx['local']];
    if (isset($opts['typed'])) {
        $env['MT_TYPED'] = implode("\x1f", $opts['typed']);
    }
    if (isset($opts['pins'])) {
        $env['MT_PINS'] = json_encode($opts['pins']);
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/transcribe-wrapper.php')
        . implode('', array_map(function ($a) {
            return ' ' . escapeshellarg($a);
        }, $args));
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache();
    return [$code, $out, $err];
}

/** Builds a real .tar.gz (real tar, for install-local tests) whose one top directory is $topDirName, holding $files (path => content). */
function mtMakeTarGz($workDir, $topDirName, array $files)
{
    $src = "$workDir/mk-src/$topDirName";
    @mkdir($src, 0700, true);
    foreach ($files as $rel => $content) {
        @mkdir(dirname("$src/$rel"), 0700, true);
        file_put_contents("$src/$rel", $content);
        if (substr($rel, -4) !== '.txt') {
            chmod("$src/$rel", 0755); // the fakes need +x after tar extracts them
        }
    }
    $tgz = "$workDir/" . $topDirName . '.tar.gz';
    exec('tar -czf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg("$workDir/mk-src") . ' ' . escapeshellarg($topDirName), $o, $code);
    if ($code !== 0) {
        throw new Exception("tar -czf failed building the $topDirName fixture");
    }
    return $tgz;
}

/** Starts the static download fixture (tests/fixtures/mediatools/download-router.php) serving $dlDir; returns [fx-with-pid, baseUrl]. */
function mtServeDownloads(array $fx, $dlDir)
{
    $port = testFreePort();
    $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/download-router.php'),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MT_DL_DIR' => $dlDir]);
    $fx['dlPid'] = proc_get_status($p)['pid'];
    for ($i = 0; $i < 50 && !($c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1)); $i++) {
        usleep(100000);
    }
    if (!empty($c)) {
        fclose($c);
    }
    return [$fx, "http://127.0.0.1:$port"];
}
