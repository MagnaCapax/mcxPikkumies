<?php
/**
 * tools/system/secrets and lib/Secrets.php (ADR-0085): the vault is ciphertext only, a changed or wrongly opened file
 * decrypts nothing, the unlocked key lives only in a checked 0700 directory outside the home for its TTL, `use` runs
 * only Pikkumies tools without a terminal confirmation and takes the value's encoded forms out of the output, and
 * unlock needs a terminal. Every path is a sys_get_temp_dir() fixture; the oracle is the files on disk.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/secrets/SecretsUse.php';

const SC_PHRASE = 'fixture-phrase-for-tests';
const SC_VALUE = 'Val+ue/0racle=9';

function scFixture()
{
    if (!extension_loaded('sodium')) {
        skip('this PHP has no sodium extension');
    }
    $dir = sys_get_temp_dir() . '/pk-secrets-' . getmypid() . '-' . mt_rand();
    $fx = ['dir' => $dir, 'home' => "$dir/home", 'run' => "$dir/run", 'shm' => "$dir/shm", 'vault' => "$dir/local/secrets/vault.enc",
        'tools' => "$dir/tools", 'oldHome' => getenv('HOME')];
    foreach (['home' => 0700, 'run' => 0700, 'shm' => 01777, 'tools' => 0700] as $k => $mode) {
        mkdir($fx[$k], 0700, true);
        chmod($fx[$k], $mode);
    }
    mkdir("$dir/tools/x/echo", 0700, true);
    file_put_contents("$dir/tools/x/echo/echo.php", '<?php $v = (string) getenv("API_KEY"); echo "raw=$v b64=" . base64_encode($v) . " b64url="'
        . ' . rtrim(strtr(base64_encode($v), "+/", "-_"), "=") . " hex=" . bin2hex($v) . " HEX=" . strtoupper(bin2hex($v)) . " url=" . rawurlencode($v)'
        . ' . " json=" . json_encode(["k" => $v]) . "\n"; fwrite(STDERR, "err=$v\n");');
    putenv('HOME=' . $fx['home']);
    Secrets::$vaultFile = $fx['vault'];
    SecretsCache::$runtimeBase = $fx['run'];
    SecretsCache::$shmDir = $fx['shm'];
    return $fx;
}

function scDone(array $fx)
{
    Secrets::$vaultFile = Secrets::$now = SecretsCache::$runtimeBase = SecretsCache::$shmDir = SecretsUse::$toolsDir = null;
    putenv('HOME=' . $fx['oldHome']);
    exec('rm -rf ' . escapeshellarg($fx['dir']));
}

/** A store holding $secrets, unlocked for $ttl seconds. Returns the KDF block. */
function scSeed(array $fx, array $secrets = [], $ttl = 3600)
{
    $kdf = Secrets::newKdf();
    $key = Secrets::deriveKey(SC_PHRASE, $kdf);
    Secrets::write(['secrets' => $secrets], $key, $kdf);
    if ($ttl > 0) {
        SecretsCache::put($fx['vault'], $key, $kdf['salt'], $ttl);
    }
    return $kdf;
}

function scEntry($value = SC_VALUE)
{
    return ['value' => $value, 'kind' => 'apikey', 'added_at' => '2026-10-20T00:00:00Z', 'rotated_at' => null];
}

function scScript(array $fx, $tty = null)
{
    $code = "<?php\nputenv(" . var_export('HOME=' . $fx['home'], true) . ");\n";
    foreach (['secretsVault' => $fx['vault'], 'secretsRuntime' => $fx['run'], 'secretsShm' => $fx['shm'], 'secretsTools' => $fx['tools'],
        'secretsTty' => $tty !== null ? $tty : $fx['dir'] . '/no-tty'] as $k => $v) {
        $code .= "\$$k = " . var_export($v, true) . ";\n";
    }
    $code .= 'require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/system/secrets/secrets.php', true) . ";\n";
    file_put_contents($fx['dir'] . '/run.php', $code);
    return $fx['dir'] . '/run.php';
}

/** Runs the tool with stdin from a pipe (no terminal). Returns [exit code, records, output]. */
function scRun(array $fx, array $args, $stdin = '')
{
    list($code, $out) = runPhp(scScript($fx), $args, $stdin);
    return [$code, scLines($out), $out];
}

/** Runs the tool at a pseudo-terminal (util-linux script(1)) where each of $typed is typed as a line. */
function scTty(array $fx, array $args, array $typed)
{
    $script = null;
    foreach (explode(':', (string) getenv('PATH')) as $d) {
        $script = $script ?: (is_executable("$d/script") ? "$d/script" : null);
    }
    if ($script === null) {
        skip('needs script(1) from util-linux to give the tool a terminal');
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(scScript($fx, '/dev/tty')) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $p = proc_open(escapeshellarg($script) . ' -qec ' . escapeshellarg($cmd) . ' /dev/null', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], implode("\n", $typed) . "\n");
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache();
    return [$code, scLines($out), $out];
}

function scLines($out)
{
    $l = [];
    foreach (explode("\n", (string) $out) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '{') {
            $l[] = json_decode($line, true);
        }
    }
    return $l;
}

return [
    'secrets: the first unlock at a terminal creates the store; set from stdin, list shows handles but no value; vault.enc holds no plaintext' => function () {
        $fx = scFixture();
        try {
            list($code, $l, $out) = scTty($fx, ['unlock'], [SC_PHRASE, SC_PHRASE]);
            check($code === 0 && $l[0]['created'] === true && end($l)['verified'] === true, 'unlock: ' . $out);
            list($code, $l, $out) = scRun($fx, ['set', 'box1', '--kind', 'apikey'], SC_VALUE . "\n");
            check($code === 0 && end($l)['verified'] === true, 'set: ' . $out);
            list($code, $l, $out) = scRun($fx, ['list']);
            check($code === 0 && $l[0]['handle'] === 'box1' && $l[0]['kind'] === 'apikey' && strpos($out, SC_VALUE) === false, 'list: ' . $out);
            $raw = (string) file_get_contents($fx['vault']);
            check(strpos($raw, SC_VALUE) === false && strpos($raw, 'box1') === false && strpos($raw, base64_encode(SC_VALUE)) === false, 'plaintext in vault.enc');
            check(substr(sprintf('%o', fileperms($fx['vault'])), -3) === '600' && substr(sprintf('%o', fileperms(dirname($fx['vault']))), -3) === '700', 'modes');
            check(Secrets::get('secret:box1') === SC_VALUE, 'Secrets::get did not return the stored value');
        } finally {
            scDone($fx);
        }
    },
    'secrets: a wrong passphrase at unlock decrypts nothing and leaves the store locked; unlock without a terminal is refused' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, ['box1' => scEntry()], 0);
            list($code, $l, $out) = scTty($fx, ['unlock'], ['not-the-phrase-at-all']);
            check($code === 1 && strpos(end($l)['error'], 'wrong passphrase') !== false, 'wrong phrase: ' . $out);
            check(SecretsCache::read($fx['vault']) === null, 'a key was cached after a wrong passphrase');
            list($code, $l, $out) = scRun($fx, ['unlock'], SC_PHRASE . "\n");
            check($code === 1 && strpos(end($l)['error'], 'no terminal') !== false && SecretsCache::read($fx['vault']) === null, 'piped unlock: ' . $out);
            list($code, $l) = scTty($fx, ['unlock', '--ttl', '7d'], [SC_PHRASE]);
            check($code === 0 && isset($l[0]['warning']) && $l[0]['ttl_s'] === 604800, 'a long TTL must carry the warning');
        } finally {
            scDone($fx);
        }
    },
    'secrets: a changed ciphertext byte, salt or KDF header, or out-of-range KDF limits in vault.enc are refused; no secret is read' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, ['box1' => scEntry()]);
            $good = (string) file_get_contents($fx['vault']);
            $e = json_decode($good, true);
            $ct = base64_decode($e['ct']);
            $ct[5] = chr(ord($ct[5]) ^ 1);
            $salt = base64_decode($e['kdf']['salt']);
            $salt[0] = chr(ord($salt[0]) ^ 1);
            $bad = [['ct' => base64_encode($ct)] + $e, ['kdf' => ['salt' => base64_encode($salt)] + $e['kdf']] + $e,
                ['kdf' => ['ops' => 3] + $e['kdf']] + $e, ['kdf' => ['ops' => 99] + $e['kdf']] + $e, ['kdf' => ['mem' => PHP_INT_MAX] + $e['kdf']] + $e,
                ['version' => 2] + $e];
            foreach ($bad as $i => $b) {
                file_put_contents($fx['vault'], json_encode($b));
                try {
                    Secrets::get('box1');
                    check(false, "tampered variant $i was opened");
                } catch (CliError $x) {
                    check(preg_match('/nothing was decrypted|no secret was read/', $x->getMessage()) === 1 || strpos($x->getMessage(), 'locked') === 0, "variant $i: " . $x->getMessage());
                }
            }
            file_put_contents($fx['vault'], $good);
            check(Secrets::get('box1') === SC_VALUE, 'the untouched file must still open');
        } finally {
            scDone($fx);
        }
    },
    'secrets: past its TTL the cached key counts as locked, Secrets::get names the unlock command, and the key file is wiped' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, ['box1' => scEntry()], 600);
            $c = SecretsCache::read($fx['vault']);
            check($c !== null && Secrets::get('box1') === SC_VALUE, 'fresh key must open');
            Secrets::$now = time() + 601;
            try {
                Secrets::get('box1');
                check(false, 'an expired key still opened the store');
            } catch (CliError $x) {
                check(strpos($x->getMessage(), 'locked: run `secrets unlock` in your own terminal') === 0 && strpos($x->hint, 'secrets.php unlock') !== false, $x->getMessage());
            }
            check(!file_exists($c['file']), 'the expired key file was left behind');
        } finally {
            scDone($fx);
        }
    },
    'secrets: a /dev/shm cache dir that is a symlink, another mode, or under HOME is refused; a missing one is made 0700' => function () {
        $fx = scFixture();
        try {
            SecretsCache::$runtimeBase = $fx['dir'] . '/no-runtime-dir';
            $shm = $fx['shm'] . '/pikkumies-' . SecretsCache::uid();
            mkdir($fx['dir'] . '/elsewhere', 0700);
            symlink($fx['dir'] . '/elsewhere', $shm);
            foreach (['symlink' => 'a symlink', 'mode' => 'mode 0755', 'home' => 'inside the home'] as $case => $why) {
                if ($case === 'mode') {
                    unlink($shm);
                    mkdir($shm, 0755);
                    chmod($shm, 0755);
                } elseif ($case === 'home') {
                    SecretsCache::$shmDir = $fx['home'];
                    mkdir($fx['home'] . '/pikkumies-' . SecretsCache::uid(), 0700);
                }
                try {
                    SecretsCache::put($fx['vault'], str_repeat("\1", 32), 'salt', 60);
                    check(false, "$case: the key was cached");
                } catch (CliError $x) {
                    check(strpos($x->getMessage(), $why) !== false, "$case: " . $x->getMessage());
                }
            }
            check(glob($fx['dir'] . '/elsewhere/*') === [], 'something was written through the symlink');
            SecretsCache::$shmDir = $fx['shm'];
            rmdir($shm);
            list($file) = SecretsCache::put($fx['vault'], str_repeat("\1", 32), 'salt', 60);
            check(dirname($file) === $shm && substr(sprintf('%o', fileperms($shm)), -3) === '700' && substr(sprintf('%o', fileperms($file)), -3) === '600', 'fresh dir/file modes');
            SecretsCache::$runtimeBase = $fx['run'];
            list($file) = SecretsCache::put($fx['vault'], str_repeat("\1", 32), 'salt', 60);
            check(dirname($file) === $fx['run'] . '/pikkumies', 'the runtime dir must be preferred: ' . $file);
        } finally {
            scDone($fx);
        }
    },
    'secrets: use refuses a command that is not a Pikkumies tool, and --any-command without a terminal; nothing runs' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, ['box1' => scEntry()]);
            $marker = $fx['dir'] . '/ran';
            $cases = [['use', 'box1', '--env', 'API_KEY', '--', '/usr/bin/touch', $marker], ['use', 'box1', '--env', 'API_KEY', '--', 'php', '-r', "touch('$marker');"],
                ['use', 'box1', '--env', 'API_KEY', '--any-command', '--', '/usr/bin/touch', $marker],
                ['use', 'box1', '--env', 'LD_PRELOAD', '--', 'php', $fx['tools'] . '/x/echo/echo.php']];
            foreach ($cases as $args) {
                list($code, $l, $out) = scRun($fx, $args);
                check($code === 1 && end($l)['ok'] === false && !file_exists($marker) && strpos($out, SC_VALUE) === false, 'not refused: ' . implode(' ', $args) . ' -> ' . $out);
            }
        } finally {
            scDone($fx);
        }
    },
    'secrets: use gives a tool the value in its variable and takes the raw, base64, base64url, hex, URL and JSON forms out of stdout and stderr' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, ['box1' => scEntry()]);
            list($code, $l, $out) = scRun($fx, ['use', 'box1', '--env', 'API_KEY', '--', 'php', $fx['tools'] . '/x/echo/echo.php']);
            $r = $l[0];
            check($code === 0 && $r['exit_code'] === 0 && substr_count($r['stdout'], '[REDACTED]') === 7 && $r['stderr'] === "err=[REDACTED]\n", 'use: ' . $out);
            foreach ([SC_VALUE, base64_encode(SC_VALUE), bin2hex(SC_VALUE), strtoupper(bin2hex(SC_VALUE)), rawurlencode(SC_VALUE), 'Val+ue\/0racle'] as $form) {
                check(strpos($out, $form) === false, "a form of the value reached the output: $form");
            }
            check(SecretsUse::redact('a ' . SC_VALUE . ' b', SC_VALUE) === 'a [REDACTED] b' && SecretsUse::redact('plain', SC_VALUE) === 'plain', 'redact()');
        } finally {
            scDone($fx);
        }
    },
    'secrets: rotate keeps vault.enc.prev, which opens to the old value; remove needs --with-data; lock wipes the key' => function () {
        $fx = scFixture();
        try {
            $kdf = scSeed($fx, ['box1' => scEntry()]);
            list($code, $l, $out) = scRun($fx, ['rotate', 'box1'], "New-Value-2\n");
            check($code === 0 && end($l)['verified'] === true && Secrets::get('box1') === 'New-Value-2', 'rotate: ' . $out);
            $prev = Secrets::open(Secrets::readEnvelope($fx['vault'] . '.prev'), Secrets::deriveKey(SC_PHRASE, $kdf));
            check($prev['secrets']['box1']['value'] === SC_VALUE, 'vault.enc.prev does not hold the old value');
            list($code, $l) = scRun($fx, ['remove', 'box1']);
            check($code === 1 && strpos(end($l)['hint'], '--with-data') !== false && Secrets::get('box1') === 'New-Value-2', 'remove without --with-data');
            list($code, $l) = scRun($fx, ['remove', 'box1', '--with-data']);
            check($code === 0 && end($l)['verified'] === true, 'remove --with-data');
            list($code, $l) = scRun($fx, ['lock']);
            check($code === 0 && $l[0]['removed'] === 1 && end($l)['verified'] === true && glob($fx['run'] . '/pikkumies/key-*') === [], 'lock');
            list($code, $l) = scRun($fx, ['list']);
            check($code === 1 && strpos(end($l)['error'], 'locked') === 0, 'list after lock must say locked');
        } finally {
            scDone($fx);
        }
    },
    'secrets: passphrase-change re-encrypts the store and vault.enc.prev under a new salt; the old passphrase no longer opens either' => function () {
        $fx = scFixture();
        try {
            $kdf = scSeed($fx, ['box1' => scEntry()]);
            Secrets::keepPrev();
            list($code, $l, $out) = scTty($fx, ['passphrase-change'], [SC_PHRASE, 'a-brand-new-phrase', 'a-brand-new-phrase']);
            check($code === 0 && end($l)['verified'] === true && strpos($l[0]['previous_store'], 're-encrypted') === 0, 'change: ' . $out);
            foreach (['', '.prev'] as $suffix) {
                $env = Secrets::readEnvelope($fx['vault'] . $suffix);
                check($env['kdf']['salt'] !== $kdf['salt'], "vault.enc$suffix kept the old salt");
                check(Secrets::open($env, Secrets::deriveKey('a-brand-new-phrase', $env['kdf']))['secrets']['box1']['value'] === SC_VALUE, "new phrase, vault.enc$suffix");
            }
            check(Secrets::get('box1') === SC_VALUE, 'the cache must hold the new key');
        } finally {
            scDone($fx);
        }
    },
    'secrets: at a terminal, set asks for the passphrase on /dev/tty; a store or cached key the user did not make is refused and nothing is stored (review F1)' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, [], 0); // the user's store, locked: set at a terminal proves the passphrase and works without unlock
            list($code, $l, $out) = scTty($fx, ['set', 'box1'], [SC_PHRASE, SC_VALUE]);
            check($code === 0 && $l[0]['passphrase_checked'] === true && end($l)['verified'] === true, 'proved set: ' . $out);
            // The agent swaps in a store and a cached key of its own; the user's next set at a terminal must refuse.
            $kdf = Secrets::newKdf();
            $agentKey = Secrets::deriveKey('agent-chosen-phrase', $kdf);
            Secrets::write(['secrets' => []], $agentKey, $kdf);
            SecretsCache::put($fx['vault'], $agentKey, $kdf['salt'], 3600);
            list($code, $l, $out) = scTty($fx, ['set', 'box2'], [SC_PHRASE, 'User-Secret-2']);
            check($code === 1 && strpos(end($l)['error'], 'wrong passphrase') !== false && strpos(json_encode($l), 'User-Secret-2') === false, 'swapped store: ' . $out);
            check(Secrets::open(Secrets::readEnvelope(), $agentKey)['secrets'] === [], 'the value landed in the swapped store');
            // Only the cached key swapped: the passphrase opens the store but differs from the cache.
            scSeed($fx, ['box1' => scEntry()], 0);
            $env = Secrets::readEnvelope();
            SecretsCache::put($fx['vault'], $agentKey, $env['kdf']['salt'], 3600);
            list($code, $l, $out) = scTty($fx, ['rotate', 'box1'], [SC_PHRASE, 'User-Secret-3']);
            check($code === 1 && strpos(end($l)['error'], 'cached unlocked key differs') !== false, 'swapped cache: ' . $out);
            check(Secrets::open(Secrets::readEnvelope(), Secrets::deriveKey(SC_PHRASE, $env['kdf']))['secrets']['box1']['value'] === SC_VALUE, 'rotated anyway');
        } finally {
            scDone($fx);
        }
    },
    'secrets: status shows when and on which terminal the store was made, and the key fingerprint while unlocked; the created block is authenticated' => function () {
        $fx = scFixture();
        try {
            list($code, $l, $out) = scTty($fx, ['unlock'], [SC_PHRASE, SC_PHRASE]);
            $fp = $l[0]['fingerprint'];
            check($code === 0 && preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $fp) === 1, 'unlock fingerprint: ' . $out);
            list($code, $l, $out) = scRun($fx, ['status']);
            check($code === 0 && preg_match('/^\d{4}-\d\d-\d\dT/', (string) $l[0]['created_at']) === 1 && $l[0]['fingerprint'] === $fp
                && array_key_exists('created_on', $l[0]), 'status: ' . $out);
            $e = json_decode((string) file_get_contents($fx['vault']), true);
            $e['created']['at'] = '2020-01-01T00:00:00Z';
            file_put_contents($fx['vault'], json_encode($e));
            list($code, $l, $out) = scRun($fx, ['list']);
            check($code === 1 && strpos(end($l)['error'], 'no secret was read') !== false, 'a changed created block must be refused: ' . $out);
        } finally {
            scDone($fx);
        }
    },
    'secrets: the --any-command confirmation shows control, bidi and zero-width characters as visible escapes (review F2)' => function () {
        $shown = SecretsUse::visible("echo safe\r\x1b[2Kcurl evil \xE2\x80\xAE\xE2\x80\x8B");
        check(strpos($shown, "\r") === false && strpos($shown, "\x1b") === false && strpos($shown, "\xE2\x80\xAE") === false
            && strpos($shown, '\x0D\x1B[2K') !== false && strpos($shown, '\xE280AE\xE2808B') !== false, $shown);
    },
    'secrets: any secrets command wipes an expired key file, also one of another store (review F3)' => function () {
        $fx = scFixture();
        try {
            Secrets::$now = time() - 7200;
            list($other) = SecretsCache::put($fx['dir'] . '/other/vault.enc', str_repeat("\2", 32), 'salt', 60);
            list($live) = SecretsCache::put($fx['dir'] . '/third/vault.enc', str_repeat("\3", 32), 'salt', 30000);
            Secrets::$now = null;
            list($code) = scRun($fx, ['status']);
            check($code === 0 && !file_exists($other) && file_exists($live), 'expired key left, or a live one removed');
        } finally {
            scDone($fx);
        }
    },
    'secrets: use redacts after the output cleaning, so zero-width or control characters inside the value do not bring it back; a cut never shows half a value (review F5)' => function () {
        $fx = scFixture();
        try {
            $zw = implode("\xE2\x80\x8B", str_split(SC_VALUE));
            $ctl = implode("\x01", str_split(SC_VALUE));
            file_put_contents($fx['dir'] . '/zw.php', '<?php echo ' . var_export("a $zw b $ctl c\n", true) . ';');
            $r = SecretsUse::run([PHP_BINARY, $fx['dir'] . '/zw.php'], 'API_KEY', SC_VALUE);
            check(strpos(Cli::cleanString(SecretsUse::redact("a $zw b $ctl c", SC_VALUE)), SC_VALUE) === false, 'redact() alone lets the cleaning rebuild the value');
            check(strpos(Cli::cleanString($r['stdout']), SC_VALUE) === false && substr_count($r['stdout'], '[REDACTED]') === 2, 'zero-width/control: ' . json_encode($r));
            // Stderr is kept to its first MAX_ERR bytes: a value straddling that edge must not show its start.
            $pad = ToolProc::MAX_ERR - 5;
            file_put_contents($fx['dir'] . '/edge.php', '<?php fwrite(STDERR, str_repeat("x", ' . $pad . ') . ' . var_export(SC_VALUE . str_repeat('y', 200000), true) . ');');
            $r = SecretsUse::run([PHP_BINARY, $fx['dir'] . '/edge.php'], 'API_KEY', SC_VALUE);
            check(strpos($r['stderr'], substr(SC_VALUE, 0, 4)) === false, 'the start of the value showed at the cut: ' . substr($r['stderr'], -40));
            // Stdout past the tail size is redacted before it is cut.
            file_put_contents($fx['dir'] . '/big.php', '<?php for ($i = 0; $i < 3000; $i++) { echo str_repeat("z", 7), ' . var_export(SC_VALUE, true) . ', "\n"; }');
            $r = SecretsUse::run([PHP_BINARY, $fx['dir'] . '/big.php'], 'API_KEY', SC_VALUE);
            check($r['stdout_cut'] === true && strpos($r['stdout'], substr(SC_VALUE, 0, 5)) === false, 'big stdout');
        } finally {
            scDone($fx);
        }
    },
    'secrets: a use child gets the value only in its variable, without PHPRC, PHP_INI_SCAN_DIR or LD_* from the caller (review F6)' => function () {
        $fx = scFixture();
        $saved = [getenv('PHPRC'), getenv('PHP_INI_SCAN_DIR'), getenv('LD_FIXTURE_X')];
        try {
            putenv('PHPRC=' . $fx['dir']);
            putenv('PHP_INI_SCAN_DIR=' . $fx['dir']);
            putenv('LD_FIXTURE_X=1');
            file_put_contents($fx['dir'] . '/env.php', '<?php echo json_encode([getenv("PHPRC"), getenv("PHP_INI_SCAN_DIR"), getenv("LD_FIXTURE_X"), getenv("API_KEY") !== false]);');
            $r = SecretsUse::run([PHP_BINARY, $fx['dir'] . '/env.php'], 'API_KEY', SC_VALUE);
            check(trim($r['stdout']) === '[false,false,false,true]' && getenv('API_KEY') === false, 'child env: ' . $r['stdout']);
        } finally {
            foreach (['PHPRC', 'PHP_INI_SCAN_DIR', 'LD_FIXTURE_X'] as $i => $k) {
                putenv($saved[$i] === false ? $k : "$k=" . $saved[$i]);
            }
            scDone($fx);
        }
    },
    'secrets: --any-command runs another program only after the vault passphrase is typed on the terminal' => function () {
        $fx = scFixture();
        try {
            scSeed($fx, ['box1' => scEntry()]);
            $echo = ToolProc::which('echo');
            if ($echo === null) {
                skip('no echo in PATH');
            }
            list($code, $l, $out) = scTty($fx, ['use', 'box1', '--env', 'API_KEY', '--any-command', '--', $echo, 'ran-it'], ['wrong-phrase-typed']);
            check($code === 1 && strpos(end($l)['error'], 'not confirmed') === 0, 'wrong passphrase: ' . $out);
            list($code, $l, $out) = scTty($fx, ['use', 'box1', '--env', 'API_KEY', '--any-command', '--', $echo, 'ran-it'], [SC_PHRASE]);
            check($code === 0 && $l[0]['stdout'] === "ran-it\n" && strpos($out, 'Run with secret box1') !== false, 'confirmed: ' . $out);
        } finally {
            scDone($fx);
        }
    },
];
