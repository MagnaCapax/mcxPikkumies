<?php
/**
 * serve.php: the forced command of a peer's key on THIS box (ADR-0084). sshd runs it instead of anything the peer asks
 * for, from the authorized_keys line `services accept` / `setup` installs:
 *   restrict,command="php ~/pikkumies/tools/seedbox/services/serve.php <peer-label> [--allow-delete]" ssh-ed25519 ...
 * It reads SSH_ORIGINAL_COMMAND, splits it without any shell (ServeGate), runs only a whitelisted tool verb (a fixed
 * script path, PHP_BINARY, every word escaped by ToolProc::line), a read-only rsync pull from one data root (ServeGate::
 * rootsFor: never the home, dot-folders or ~/pikkumies), or `ping`, and logs one line
 * per request to local/state/services-serve.jsonl: never the .torrent bytes, never arguments beyond their count.
 */
$serveLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'Config', 'ToolProc', 'HomePath'] as $serveClass) {
    require_once "$serveLib/$serveClass.php";
}
require_once dirname(__DIR__, 2) . '/torrent-clients/lib/Bencode.php';
require_once __DIR__ . '/ServeGate.php';

// Test seams: a test may set these before including this file. Nothing is read from the environment but the request.
$serveLocalDir = isset($serveLocalDir) ? $serveLocalDir : Config::localDir();
$serveToolsDir = isset($serveToolsDir) ? $serveToolsDir : dirname(__DIR__, 2);
$serveRrsync = isset($serveRrsync) ? $serveRrsync : '/usr/bin/rrsync';

$serveLog = function (array $fields) use ($serveLocalDir) {
    $dir = rtrim($serveLocalDir, '/') . '/state';
    @mkdir($dir, 0700, true);
    $old = umask(0077);
    @file_put_contents("$dir/services-serve.jsonl", json_encode(['ts' => gmdate('c')] + $fields, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    umask($old);
};
$serveRefuse = function ($peer, $kind, $error, $hint) use ($serveLog) {
    $serveLog(['peer' => $peer, 'request' => $kind, 'decision' => 'refuse', 'reason' => substr($error, 0, 300), 'rc' => 1]);
    $line = json_encode(['ok' => false, 'error' => $error, 'hint' => $hint, 'serve' => 'pikkumies'], JSON_UNESCAPED_SLASHES) . "\n";
    fwrite($kind === 'rsync' ? STDERR : STDOUT, $line);
    exit(1);
};
/** Runs $argv with this process's stdin/stdout/stderr (or $in), in $cwd, with $env added; returns the exit code. */
$serveRun = function (array $argv, $cwd, $in = null, array $env = []) {
    $p = proc_open('exec ' . ToolProc::line($argv), [0 => $in !== null ? ['file', $in, 'r'] : STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd,
        $env ? array_merge(getenv(), $env) : null);
    return is_resource($p) ? proc_close($p) : 127;
};

$peer = isset($argv[1]) && preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $argv[1]) ? $argv[1] : null;
$allowDelete = in_array('--allow-delete', array_slice($argv, 2), true);
if ($peer === null || count($argv) > 3 || (count($argv) === 3 && !$allowDelete)) {
    $serveRefuse('?', 'setup', 'the key line names no valid peer label', 'install the line with `services accept`');
}
$command = getenv('SSH_ORIGINAL_COMMAND');
try {
    $home = HomePath::home();
    $words = ServeGate::tokenize($command === false ? '' : $command);
    $kind = $words[0] === 'rsync' ? 'rsync' : ($words[0] === 'tool' ? 'tool' : $words[0]);
    $req = ServeGate::decide($words, $allowDelete, $home, ServeGate::rootsFor($home, rtrim($serveLocalDir, '/') . '/config.php'));
} catch (CliError $e) {
    $serveRefuse($peer, isset($kind) ? substr($kind, 0, 32) : 'invalid', $e->getMessage(), $e->hint);
}

if ($req['kind'] === 'ping') {
    $serveLog(['peer' => $peer, 'request' => 'ping', 'decision' => 'allow', 'rc' => 0]);
    echo json_encode(['serve' => 'pikkumies', 'version' => 1, 'peer' => $peer, 'allow_delete' => $allowDelete]), "\n", json_encode(['ok' => true]), "\n";
    exit(0);
}

if ($req['kind'] === 'rsync') {
    $rr = is_file($serveRrsync) && is_executable($serveRrsync);
    $rsync = ToolProc::which('rsync');
    if (!$rr && $rsync === null) {
        $serveRefuse($peer, 'rsync', 'rsync is not installed on this box', 'install rsync');
    }
    // Both ways serve one data root, never the home: rrsync gets that root as its directory and the request rewritten
    // with paths relative to it (it reads SSH_ORIGINAL_COMMAND; -ro: no writes or deletes; -munge: links); the own check
    // runs rsync in that root with the same relative paths.
    $rewritten = implode(' ', array_merge(['rsync'], $req['opts'], ['.'], $req['rel']));
    $argvRun = $rr ? [$serveRrsync, '-ro', '-munge', $req['root']] : array_merge([$rsync], $req['opts'], ['--', '.'], $req['rel']);
    $rc = $serveRun($argvRun, $req['root'], null, ['SSH_ORIGINAL_COMMAND' => $rewritten]);
    $serveLog(['peer' => $peer, 'request' => 'rsync', 'decision' => 'allow', 'via' => $rr ? 'rrsync' : 'own-check', 'paths' => count($req['paths']), 'rc' => $rc]);
    exit($rc);
}

$script = rtrim($serveToolsDir, '/') . '/' . $req['unit'] . '/' . basename($req['unit']) . '.php';
if (!is_file($script)) {
    $serveRefuse($peer, 'tool', "{$req['unit']} is not installed on this box", 'update Pikkumies on this box');
}
$args = $req['args'];
$tmp = null;
if ($req['stdin_torrent']) {
    $torrent = stream_get_contents(STDIN, 16777217);
    if (!is_string($torrent) || strlen($torrent) > 16777216 || substr($torrent, 0, 1) !== 'd' || strpos($torrent, '4:info') === false) {
        $serveRefuse($peer, 'tool', 'add needs one .torrent file (at most 16 MiB) on stdin', 'send the file contents, not a path or a link');
    }
    try {
        ServeGate::torrentNames($torrent); // no dot-named file or folder from another box
    } catch (CliError $e) {
        $serveRefuse($peer, 'tool', $e->getMessage(), $e->hint);
    }
    $tmpDir = rtrim($serveLocalDir, '/') . '/state/tmp';
    @mkdir($tmpDir, 0700, true);
    $tmp = $tmpDir . '/serve-' . bin2hex(random_bytes(8)) . '.torrent';
    $old = umask(0077);
    $ok = @file_put_contents($tmp, $torrent) === strlen($torrent);
    umask($old);
    if (!$ok) {
        @unlink($tmp);
        $serveRefuse($peer, 'tool', 'could not store the .torrent here', 'check the disk space on this box');
    }
    $args[0] = $tmp;
}
// PIKKUMIES_SERVE=1 tells the unit it answers a peer, so it can leave out what is for this box only (telemetry usage-*).
$rc = $serveRun(array_merge([PHP_BINARY, $script, $req['verb']], $args), $home, $tmp !== null ? '/dev/null' : null, ['PIKKUMIES_SERVE' => '1']);
if ($tmp !== null) {
    @unlink($tmp);
}
$serveLog(['peer' => $peer, 'request' => 'tool', 'tool' => $req['unit'], 'verb' => $req['verb'], 'argc' => count($req['args']), 'decision' => 'allow', 'rc' => $rc]);
exit($rc);
