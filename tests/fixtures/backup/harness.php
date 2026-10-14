<?php
/** Helpers for tests/BackupTest.php and tests/BackupBorgTest.php (the fakes are in bin/, the shared harness in ../storage/). */
require_once dirname(__DIR__) . '/storage/harness.php';

/** The test oracle: a made-up repository secret that must never appear in any output or argv. */
const BK_ORACLE = 'Oracle-7f3k-xQ!z';

/** A logged-in fixture: secret file, saved repository, three snapshots (or archives), a plan that removes two. */
function bkFixture($tool = 'restic', array $over = [], array $fakes = ST_FAKES)
{
    $fx = stFixture([], $fakes);
    $fx['secrets'] = $fx['dir'] . '/secrets';
    mkdir($fx['secrets'], 0700);
    mkdir($fx['local'] . '/backup', 0700);
    mkdir($fx['home'] . '/data', 0700);
    file_put_contents($fx['home'] . '/data/a.txt', "a\n");
    file_put_contents($fx['secrets'] . "/$tool", BK_ORACLE);
    file_put_contents($fx['local'] . "/backup/$tool.json", json_encode(['repo' => 'sftp:me@box:repo']));
    $snap = function ($c, $t) {
        return ['time' => "2026-10-0{$t}T03:00:00.123456789+03:00", 'tree' => str_repeat('t', 64), 'paths' => ['/home/u/data'],
            'hostname' => 'box', 'username' => 'u',
            'uid' => 1000, 'gid' => 1000, 'id' => str_repeat($c, 64), 'short_id' => str_repeat($c, 8)];
    };
    $arch = function ($n, $t, $c) {
        return ['id' => str_repeat($c, 64), 'name' => $n, 'start' => "2026-10-0{$t}T03:00:00.000000"];
    };
    $state = $over + ['secret' => BK_ORACLE, 'snapshots' => [$snap('a', 1), $snap('b', 2), $snap('c', 3)], 'plan' => [str_repeat('a',
        64), str_repeat('b', 64)],
        'archives' => [$arch('box-1', 1, 'a'), $arch('box-2', 2, 'b'), $arch('box-3', 3, 'c')], 'locks' => [],
        'files' => [['name' => 'a.txt', 'type' => 'file', 'path' => '/home/u/data/a.txt', 'uid' => 1000, 'gid' => 1000, 'size' => 2,
            'mtime' => '2026-10-01T02:00:00+03:00']]];
    if ($tool === 'borg') {
        $state['plan'] = isset($over['plan']) ? $over['plan'] : ['box-1', 'box-2'];
        $state['files'] = isset($over['files']) ? $over['files']
            : [['type' => '-', 'path' => 'home/u/data/a.txt', 'size' => 2, 'mtime' => '2026-10-01T02:00:00.000000']];
    }
    file_put_contents($fx['dir'] . '/state.json', json_encode($state, JSON_UNESCAPED_SLASHES));
    return $fx;
}

function bkSeams(array $fx)
{
    return ['backupSecretDir' => $fx['secrets'], 'backupLocalDir' => $fx['local'], 'backupMountinfo' => $fx['mountinfo'],
        'backupProcRoot' => $fx['proc']];
}

function bkRun(array $fx, $tool, array $args, array $env = [])
{
    list($code, $out) = stRun($fx, "backup/$tool/$tool.php", $args, bkSeams($fx), $env);
    return [$code, stLines($out), $out];
}

/** `login --repo $repo` at a pseudo-terminal (util-linux script(1)) where $typed is typed. Returns [exit code, JSON records, output]. */
function bkLogin(array $fx, $tool, $repo, $typed)
{
    $script = null;
    foreach (explode(':', (string) getenv('PATH')) as $d) {
        $script = $script ?: (is_executable("$d/script") ? "$d/script" : null);
    }
    check($script !== null, 'this test needs script(1) from util-linux (bsdutils on Debian) to give login a terminal');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(stScript($fx, "backup/$tool/$tool.php", bkSeams($fx))) . ' login --repo '
        . escapeshellarg($repo);
    $p = proc_open(escapeshellarg($script) . ' -qec ' . escapeshellarg($cmd) . ' /dev/null', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
        2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], "$typed\n");
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache(); // login wrote files in another process
    $json = array_values(array_filter(array_map('trim', explode("\n", $out)), function ($l) {
        return $l !== '' && $l[0] === '{';
    }));
    return [$code, array_map(function ($l) {
        return json_decode($l, true);
    }, $json), $out];
}

/** The subcommands a fake received, in order. */
function bkVerbs(array $fx, $bin)
{
    return array_map(function ($c) {
        return explode(' ', $c)[0];
    }, stCalls($fx, $bin));
}
