<?php
/**
 * Helpers for tests/Services*Test.php: backup remote's harness (temp HOME, a bin of fakes, the fake ssh that plays the
 * other machine under $FAKE_DIR/remote and runs a key's command= like sshd), plus a serve.php wrapper installed in that
 * remote home with its test seams, and fake tool units that print what they were given.
 */
require_once dirname(__DIR__) . '/backupremote/harness.php';

const SV_UNIT = 'seedbox/services/services.php';

function svFixture(array $state = [], $serveInstalled = true)
{
    $fx = brFixture($state);
    $fx['memory'] = $fx['dir'] . '/memory';
    $fx['tools'] = $fx['dir'] . '/tools';
    $fx['rlocal'] = $fx['remote'] . '/pikkumies/local';
    mkdir($fx['remote'] . '/data/films', 0700, true);
    $fx['remote'] = realpath($fx['remote']);
    if ($serveInstalled) {
        svServeWrapper($fx, $fx['remote'] . '/pikkumies/tools/seedbox/services/serve.php');
    }
    $fake = "<?php\n\$in = stream_get_contents(STDIN);\n\$f = isset(\$argv[2]) && is_file(\$argv[2]) ? (string) file_get_contents(\$argv[2]) : null;\n"
        . "echo json_encode(['unit' => basename(\$argv[0], '.php'), 'argv' => array_slice(\$argv, 1), 'file' => \$f, 'cwd' => getcwd(), 'serve_env' => getenv('PIKKUMIES_SERVE')]), \"\\n\";\n"
        . "echo json_encode(['name' => 'Some Film', 'size' => 123]), \"\\n\", json_encode(['ok' => true]), \"\\n\";\n";
    foreach (['torrent-clients/rtorrent', 'torrent-clients/find', 'system/system-status'] as $u) {
        @mkdir($fx['tools'] . "/$u", 0700, true);
        file_put_contents($fx['tools'] . "/$u/" . basename($u) . '.php', $fake);
    }
    return $fx;
}

/** A serve.php that sets the seams and includes the real one. $rrsync: a fake rrsync path (or a missing one). */
function svServeWrapper(array $fx, $at, $rrsync = null)
{
    @mkdir(dirname($at), 0700, true);
    $real = dirname(__DIR__, 3) . '/pikkumies/tools/seedbox/services/serve.php';
    file_put_contents($at, "<?php\n\$serveLocalDir = " . var_export($fx['rlocal'], true) . ";\n\$serveToolsDir = " . var_export($fx['tools'], true)
        . ";\n\$serveRrsync = " . var_export($rrsync !== null ? $rrsync : $fx['dir'] . '/no-rrsync', true) . ";\nrequire " . var_export($real, true) . ";\n");
}

function svRun(array $fx, array $args, array $env = [])
{
    list($code, $out) = stRun($fx, SV_UNIT, $args, ['servicesLocalDir' => $fx['localdir'], 'servicesMemoryDir' => $fx['memory']], $env);
    return [$code, stLines($out), $out];
}

function svAddAndSetup(array $fx, $nick = 'hdd1', array $extra = [])
{
    list($code, , $out) = svRun($fx, ['add', $nick, '--host', BR_HOST, '--user', 'xyzjohn', '--tags', 'seedbox,backup']);
    check($code === 0, 'add: ' . $out);
    return svRun($fx, array_merge(['setup', $nick], $extra), ['PIKKUMIES_REMOTE_PASSWORD' => BR_LOGIN]);
}

/** Runs the wrapped serve.php as sshd would for the forced command; returns [exit code, stdout, stderr]. */
function svServe(array $fx, $command, $stdin = '', array $serveArgs = ['peer-box'], $wrapper = null, $path = null)
{
    $wrapper = $wrapper !== null ? $wrapper : $fx['remote'] . '/pikkumies/tools/seedbox/services/serve.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper);
    foreach ($serveArgs as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fx['remote'],
        ['HOME' => $fx['remote'], 'PATH' => $path !== null ? $path : (string) getenv('PATH'), 'SSH_ORIGINAL_COMMAND' => $command]);
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

function svServeLog(array $fx)
{
    $f = $fx['rlocal'] . '/state/services-serve.jsonl';
    return is_file($f) ? array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", (string) file_get_contents($f)), 'strlen'))) : [];
}

/** A syntactically valid public key line (type name inside the base64 blob), without needing ssh-keygen. */
function svPubKey($type = 'ssh-ed25519')
{
    return $type . ' ' . base64_encode(pack('N', strlen($type)) . $type . pack('N', 32) . random_bytes(32)) . ' pikkumies-other';
}

/** A directory holding a fake program $name (PHP) that prints its argv and SSH_ORIGINAL_COMMAND as one JSON line. */
function svFakeProgram(array $fx, $name)
{
    $dir = $fx['dir'] . '/fakebin';
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/$name", "#!" . PHP_BINARY . "\n<?php\necho json_encode(['argv' => array_slice(\$argv, 1), 'cmd' => getenv('SSH_ORIGINAL_COMMAND'), 'cwd' => getcwd()]), \"\\n\";\n");
    chmod("$dir/$name", 0755);
    return "$dir/$name";
}

/** The forced-command line `accept` would install for this box's key (label as the unit computes it). */
class ServicesKeysLineForTest
{
    public static function line($b64)
    {
        require_once dirname(__DIR__, 3) . '/pikkumies/tools/seedbox/services/ServicesKeys.php';
        return ServicesKeys::keyLine('pikkumies', 'peer-box', false, 'ssh-ed25519', $b64);
    }
}

/** The forced-command line `accept` would install for a key of this box. */
function svKeyLine($b64)
{
    require_once dirname(__DIR__, 3) . '/pikkumies/tools/seedbox/services/ServicesKeys.php';
    return ServicesKeys::keyLine('pikkumies', 'peer-box', false, 'ssh-ed25519', $b64);
}

/** Bencode for test torrents: ints, strings, lists (sequential arrays) and dictionaries (sorted keys). */
function svBencode($v)
{
    if (is_int($v)) {
        return "i{$v}e";
    }
    if (is_string($v)) {
        return strlen($v) . ':' . $v;
    }
    if ($v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
        ksort($v, SORT_STRING);
        $out = 'd';
        foreach ($v as $k => $x) {
            $out .= svBencode((string) $k) . svBencode($x);
        }
        return $out . 'e';
    }
    return 'l' . implode('', array_map('svBencode', $v)) . 'e';
}

/** A .torrent with this info dictionary (default: one file named film). */
function svTorrent(array $info = ['name' => 'film', 'length' => 5, 'piece length' => 16384, 'pieces' => '01234567890123456789'])
{
    return svBencode(['announce' => 'http://tracker.example/announce', 'info' => $info]);
}

/** guard-destructive's decoded answer for one terminal command, or null when it has no opinion. */
function svGuard($cmd)
{
    list($code, $out) = runPhp(dirname(__DIR__, 3) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
        json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
    check($code === 0, "guard exited $code for $cmd");
    $d = json_decode($out, true);
    return is_array($d) ? $d : null;
}
