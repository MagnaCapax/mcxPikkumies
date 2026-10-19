<?php
/**
 * Helpers for tests/BackupRemote*Test.php: the storage harness (temp HOME, a bin of fakes only) plus the fakes in bin/
 * here. The fake ssh plays the backup machine under $FAKE_DIR/remote and runs the real recv.sh installed there.
 */
require_once dirname(__DIR__) . '/storage/harness.php';

/** A made-up one-time login with a quote, a space and a dollar: it must never appear in an argv, an output or a file. */
const BR_LOGIN = "Fake'Login \"x\" \$y-7Qz";
const BR_HOST = 'backup.example.com';

function brFixture(array $state = [])
{
    $fx = stFixture([], ['nice', 'ionice', 'timeout', 'find']);
    foreach ((array) glob(__DIR__ . '/bin/*') as $f) {
        copy($f, $fx['bin'] . '/' . basename($f));
        chmod($fx['bin'] . '/' . basename($f), 0755);
    }
    $fx['localdir'] = $fx['home'] . '/pikkumies/local';
    $fx['secrets'] = $fx['localdir'] . '/secrets/backup-remote';
    $fx['remote'] = $fx['dir'] . '/remote';
    mkdir($fx['localdir'], 0700, true);
    file_put_contents($fx['dir'] . '/state.json', json_encode($state + ['login_sha1' => sha1(BR_LOGIN)]));
    return $fx;
}

function brSetState(array $fx, array $state)
{
    file_put_contents($fx['dir'] . '/state.json', json_encode($state + stState($fx)));
}

/** Runs remote.php with the fixture's seams; returns [exit code, JSON records, raw output]. */
function brRun(array $fx, array $args, array $env = [])
{
    list($code, $out) = stRun($fx, 'backup/remote/remote.php', $args, ['remoteLocalDir' => $fx['localdir'],
        'remoteMountinfo' => $fx['mountinfo']], $env);
    return [$code, stLines($out), $out];
}

function brSetup(array $fx, array $extra = [])
{
    return brRun($fx, array_merge(['setup', BR_HOST, 'xyzjohn'], $extra), ['PIKKUMIES_REMOTE_PASSWORD' => BR_LOGIN]);
}

/** The fingerprint the fake ssh-keygen prints for the fake backup machine's host key. */
function brFingerprint($hostKey = 'AAAAC3NzaC1lZDI1NTE5AAAAIHostKeyOfTheFakeBackupMachine0000000')
{
    return 'SHA256:' . substr(rtrim(base64_encode(hash('sha256', $hostKey, true)), '='), 0, 43);
}

/** Every logged ssh call: ['argv' => [...], 'opt' => [-o name => value], 'pw_set', 'askpass_force']. */
function brSshCalls(array $fx)
{
    $out = [];
    foreach (stLog($fx) as $c) {
        if ($c['bin'] !== 'ssh') {
            continue;
        }
        $opt = [];
        foreach ($c['argv'] as $i => $w) {
            if ($w === '-o' && isset($c['argv'][$i + 1])) {
                list($k, $v) = explode('=', $c['argv'][$i + 1], 2) + [1 => ''];
                $opt[$k] = $v;
            }
        }
        $out[] = $c + ['opt' => $opt];
    }
    return $out;
}

/** Text of every file under $dir (recursively), for "the secret is nowhere" checks. */
function brAllText($dir)
{
    $text = '';
    if (!is_dir($dir)) {
        return $text;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $text .= $f->isFile() ? (string) @file_get_contents((string) $f) : '';
    }
    return $text;
}

/** Runs the receiver directly, as sshd would for the forced command; returns [exit code, stdout, stderr]. */
function brRecv($home, $command, $stdin = '', $keep = '30')
{
    $recv = dirname(__DIR__, 3) . '/pikkumies/tools/backup/remote/recv.sh';
    $p = proc_open('exec sh ' . escapeshellarg($recv) . ' box ' . escapeshellarg($keep), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $home, ['HOME' => $home, 'PATH' => (string) getenv('PATH'), 'SSH_ORIGINAL_COMMAND' => $command]);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache();
    return [$code, $out, $err];
}
