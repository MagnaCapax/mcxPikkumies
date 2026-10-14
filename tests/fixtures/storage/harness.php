<?php
/**
 * Helpers for the storage and backup tests: a temp HOME, a bin directory that holds only the fake
 * programs (PHP scripts, tests/fixtures/storage/bin and tests/fixtures/backup/bin) plus `php` and
 * `cat`, and a wrapper that runs a unit with HOME, PATH and its test seams set.
 */
const ST_FAKES = ['nice', 'ionice', 'timeout', 'du', 'find', 'quota', 'rclone', 'fusermount3', 'restic', 'borg'];

function stFixture(array $state = [], array $fakes = ST_FAKES)
{
    $dir = sys_get_temp_dir() . '/pk-storage-' . getmypid() . '-' . mt_rand();
    $fx = ['dir' => $dir, 'home' => "$dir/home", 'bin' => "$dir/bin", 'local' => "$dir/local", 'mountinfo' => "$dir/mountinfo",
        'proc' => "$dir/proc"];
    foreach ([$fx['home'], $fx['bin'], $fx['local'], $fx['proc']] as $d) {
        mkdir($d, 0700, true);
    }
    $fx['home'] = realpath($fx['home']);
    $src = __DIR__ . '/bin';
    copy("$src/fakelib.php", $fx['bin'] . '/fakelib.php');
    foreach ($fakes as $f) {
        $from = is_file("$src/$f") ? "$src/$f" : dirname(__DIR__) . "/backup/bin/$f";
        copy($from, $fx['bin'] . "/$f");
        chmod($fx['bin'] . "/$f", 0755);
    }
    symlink(PHP_BINARY, $fx['bin'] . '/php'); // the fakes start with #!/usr/bin/env php
    foreach (explode(':', (string) getenv('PATH')) as $p) {
        if (is_executable("$p/cat")) {
            symlink("$p/cat", $fx['bin'] . '/cat'); // borg's BORG_PASSCOMMAND runs cat
            break;
        }
    }
    file_put_contents($fx['mountinfo'], "22 1 8:1 / / rw,relatime shared:1 - ext4 /dev/sda1 rw\n");
    file_put_contents("$dir/state.json", json_encode($state, JSON_UNESCAPED_SLASHES));
    touch("$dir/argv.log");
    return $fx;
}

/** Writes $fx/run.php: sets the environment and the seams (PHP variables), then includes the unit. Returns its path. */
function stScript(array $fx, $unit, array $seams = [], array $env = [])
{
    $env += ['HOME' => $fx['home'], 'PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir'], 'FAKE_REAL_PATH' => (string) getenv('PATH'),
        'FAKE_MOUNTINFO' => $fx['mountinfo']];
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
function stRun(array $fx, $unit, array $args, array $seams = [], array $env = [])
{
    $r = runPhp(stScript($fx, $unit, $seams, $env), $args);
    clearstatcache(); // the unit and the fakes changed files in other processes; never trust this process's stat cache
    return $r;
}

/** Every call the fakes logged: list of ['bin', 'argv', 'cwd', 'env']. */
function stLog(array $fx)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", (string) file_get_contents($fx['dir'] . '/argv.log')), 'strlen')));
}

/** The argv of every logged call of $bin, each joined with spaces. */
function stCalls(array $fx, $bin)
{
    $out = [];
    foreach (stLog($fx) as $c) {
        if ($c['bin'] === $bin) {
            $out[] = implode(' ', $c['argv']);
        }
    }
    return $out;
}

function stClearLog(array $fx)
{
    file_put_contents($fx['dir'] . '/argv.log', '');
}

function stState(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/state.json'), true);
}

/** JSON Lines output -> list of arrays (the last one is the status line). */
function stLines($out)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", trim((string) $out)), 'strlen')));
}

function stLast($out)
{
    $l = stLines($out);
    return $l ? end($l) : null;
}

function stDone(array $fx)
{
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

function stUid()
{
    return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
}
