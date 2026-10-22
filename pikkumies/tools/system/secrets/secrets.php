<?php
/**
 * secrets: the encrypted store for passwords and API keys that cannot be an SSH key (ADR-0085, ADR-0015 tool unit).
 * The crypto and the key cache are lib/Secrets.php and lib/SecretsCache.php (other tools read through Secrets::get);
 * this file is the verbs. Values never go on a command line and are never printed; the passphrase is typed only at
 * a terminal and kept nowhere, so the agent cannot unlock: the user does, in their own terminal.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Config', 'Cli', 'CliHuman', 'SecretFile', 'ToolProc', 'SshLists', 'Secrets', 'SecretsCache'] as $c) {
    require_once "$lib/$c.php";
}
require_once __DIR__ . '/SecretsInput.php';
require_once __DIR__ . '/SecretsUse.php';
require_once __DIR__ . '/SecretsPhrase.php';

// Test seams: a test sets these before including the file, so the real local/, /run/user and /dev/shm are never used.
Secrets::$vaultFile = isset($secretsVault) ? $secretsVault : null;
Secrets::$now = isset($secretsNow) ? $secretsNow : null;
SecretsCache::$runtimeBase = isset($secretsRuntime) ? $secretsRuntime : null;
SecretsCache::$shmDir = isset($secretsShm) ? $secretsShm : null;
SecretsUse::$toolsDir = isset($secretsTools) ? $secretsTools : null;
SecretsInput::$ttyPath = isset($secretsTty) ? $secretsTty : '/dev/tty';
try {
    SecretsCache::sweep(); // an expired key goes at the next secrets command, whatever store it was for
} catch (Throwable $e) {
}

$usage = <<<'U'
usage: php secrets.php status | unlock [--ttl 8h] | lock | list | set <handle> [--kind password|apikey|token] [--host H[/path/]]
       | rotate <handle> [--host H[/path/]] | remove <handle> --with-data | passphrase-change
       | use <handle> --env NAME [--any-command] -- <command...>
  Values are typed at the terminal (not shown) or piped on stdin, never put on the command line.
  unlock and passphrase-change ask for the passphrase at a terminal: the user runs them in their own terminal.
  --host pins the secret to the one host (and path prefix) a tool may send it to; only set and rotate write it, with a value.
U;

$iso = function ($t) {
    return $t === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $t);
};
$change = function ($args, $mustExist) {
    $h = Secrets::handle(isset($args[0]) ? $args[0] : '');
    list($data, $key, $env, $checked) = SecretsPhrase::prove();
    if (isset($data['secrets'][$h]) !== $mustExist) {
        sodium_memzero($key);
        throw new CliError($mustExist ? "no secret named $h; nothing was changed" : "a secret named $h already exists; nothing was changed",
            $mustExist ? 'list the handles with `secrets list`' : "to replace its value use `secrets rotate $h`");
    }
    return [$h, $data, $key, $env, $checked];
};
// --host H or H/path/prefix/: where a tool may send this secret (ADR-0107), as Secrets::parsePin() reads it: ['host', 'path'],
// or null when not given. Read before the value, so a bad pin never consumes a typed or piped value.
$pinOf = function (array $opt) {
    if (!isset($opt['host'])) {
        return null;
    }
    $pin = is_string($opt['host']) ? Secrets::parsePin($opt['host']) : null;
    if ($pin === null) {
        throw new CliError('--host takes the host the secret may be sent to, e.g. box.example.com, or a host and path prefix, e.g. server.example.com/user/'
            . ' (plain characters, no %, no empty, . or .. part); nothing was stored', 'type the address you know for that box, the way its provider documents it');
    }
    return $pin;
};
// The stored entry with $pin applied: host and path replace the old pin together (no path: any path on that host).
$withPin = function (array $entry, $pin) {
    if ($pin === null) {
        return $entry;
    }
    unset($entry['host'], $entry['path']);
    return $entry + ['host' => $pin['host']] + ($pin['path'] !== null ? ['path' => $pin['path']] : []);
};
$save = function ($h, array $data, $key, array $env, $expect) {
    Secrets::write($data, $key, $env['kdf']);
    $after = Secrets::open(Secrets::readEnvelope(), $key);
    sodium_memzero($key);
    $now = isset($after['secrets'][$h]) ? $after['secrets'][$h]['value'] : null;
    return $expect === null ? $now === null : ($now !== null && hash_equals($expect, $now));
};

Cli::run($argv, [
    'status' => function () use ($iso) {
        $vault = Secrets::vaultPath();
        $r = ['sodium' => extension_loaded('sodium') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13'), 'vault' => Config::shown($vault),
            'vault_exists' => is_file($vault), 'prev_exists' => is_file("$vault.prev"), 'locked' => true, 'expires_at' => null];
        if (!$r['sodium']) {
            return [$r + ['note' => 'no PHP sodium extension: Debian PHP packages build it in; elsewhere install it (often a php-sodium package)']];
        }
        $env = Secrets::readEnvelope();
        $c = $env !== null ? SecretsCache::read($vault) : null;
        if ($env !== null) {
            $made = isset($env['created']) ? $env['created'] : [];
            $r['created_at'] = isset($made['at']) ? $made['at'] : null;
            $r['created_on'] = isset($made['tty']) ? $made['tty'] : null;
            $r['kdf'] = ['alg' => $env['kdf']['alg'], 'ops' => $env['kdf']['ops'], 'mem_mib' => intdiv($env['kdf']['mem'], 1048576)];
        }
        if ($c !== null && $c['salt'] === $env['kdf']['salt']) {
            $r = array_merge($r, ['locked' => false, 'expires_at' => $iso($c['expires']), 'expires_in_s' => $c['expires'] - Secrets::now(),
                'cache' => dirname($c['file']), 'handles' => count(Secrets::open($env, $c['key'])['secrets']), 'fingerprint' => Secrets::fingerprint($c['key'])]);
            sodium_memzero($c['key']);
        }
        return [$r];
    },
    'unlock' => function ($args, $opt) use ($iso) {
        Secrets::needSodium();
        $ttl = SecretsInput::ttl($opt);
        $vault = Secrets::vaultPath();
        $env = Secrets::readEnvelope();
        $created = $env === null;
        $phrase = $created ? SecretsInput::newPhrase() : SecretsInput::phrase('Vault passphrase (not shown): ');
        $kdf = $created ? Secrets::newKdf() : $env['kdf'];
        $key = Secrets::deriveKey($phrase, $kdf);
        sodium_memzero($phrase);
        if ($created) {
            Secrets::write(['created' => ['at' => $iso(Secrets::now()), 'tty' => SecretsInput::terminalName()], 'secrets' => []], $key, $kdf);
        } else {
            Secrets::open($env, $key); // a wrong passphrase stops here
        }
        list($file, $expires) = SecretsCache::put($vault, $key, $kdf['salt'], $ttl);
        $fp = Secrets::fingerprint($key);
        sodium_memzero($key);
        $c = SecretsCache::read($vault);
        $r = ['unlocked' => true, 'created' => $created, 'expires_at' => $iso($expires), 'ttl_s' => $ttl, 'cache' => dirname($file), 'fingerprint' => $fp];
        if ($ttl > SecretsInput::TTL_WARN) {
            $r['warning'] = 'a TTL over a day keeps the unlocked key cached that long (until it expires, `secrets lock` or a reboot);'
                . ' while it is cached, a copy of vault.enc together with the key opens it, and the agent can use every secret';
            fwrite(STDERR, 'warning: ' . $r['warning'] . "\n");
        }
        return ['records' => [$r], 'status' => ['verified' => $c !== null && $c['expires'] === $expires]];
    },
    'lock' => function () {
        $n = SecretsCache::forget(Secrets::vaultPath());
        return ['records' => [['locked' => true, 'removed' => $n]], 'status' => ['verified' => SecretsCache::read(Secrets::vaultPath()) === null]];
    },
    'list' => function () use ($iso) {
        list($data, $key) = Secrets::openWithCache();
        sodium_memzero($key);
        $out = [];
        foreach ($data['secrets'] as $h => $s) {
            $out[] = ['handle' => (string) $h, 'kind' => $s['kind'], 'added_at' => $s['added_at'], 'rotated_at' => $s['rotated_at'],
                'host' => isset($s['host']) ? $s['host'] : null, 'path' => isset($s['path']) ? $s['path'] : null];
        }
        usort($out, function ($a, $b) {
            return strcmp($a['handle'], $b['handle']);
        });
        return ['records' => $out, 'status' => ['total' => count($out)]];
    },
    'set' => function ($args, $opt) use ($change, $save, $iso, $pinOf, $withPin) {
        $kind = isset($opt['kind']) ? $opt['kind'] : 'password';
        if (!in_array($kind, Secrets::KINDS, true)) {
            throw new CliError('--kind is password, apikey or token', 'leave it out for password');
        }
        $pin = $pinOf($opt);
        list($h, $data, $key, $env, $checked) = $change($args, false);
        $v = SecretsInput::value($h);
        $data['secrets'][$h] = $withPin(['value' => $v, 'kind' => $kind, 'added_at' => $iso(Secrets::now()), 'rotated_at' => null], $pin);
        $ok = $save($h, $data, $key, $env, $v);
        sodium_memzero($v);
        return ['records' => [['handle' => $h, 'kind' => $kind, 'added_at' => $data['secrets'][$h]['added_at'],
            'host' => $pin !== null ? $pin['host'] : null, 'path' => $pin !== null ? $pin['path'] : null, 'passphrase_checked' => $checked]],
            'status' => ['verified' => $ok]];
    },
    'rotate' => function ($args, $opt) use ($change, $save, $iso, $pinOf, $withPin) {
        $pin = $pinOf($opt); // a new pin only together with a new value; without --host the old pin stays
        list($h, $data, $key, $env, $checked) = $change($args, true);
        $v = SecretsInput::value($h);
        $prev = Secrets::keepPrev(); // only once the new value is in hand
        $data['secrets'][$h] = $withPin(array_merge($data['secrets'][$h], ['value' => $v, 'rotated_at' => $iso(Secrets::now())]), $pin);
        $ok = $save($h, $data, $key, $env, $v);
        sodium_memzero($v);
        return ['records' => [['handle' => $h, 'rotated_at' => $data['secrets'][$h]['rotated_at'], 'host' => isset($data['secrets'][$h]['host']) ? $data['secrets'][$h]['host'] : null,
            'path' => isset($data['secrets'][$h]['path']) ? $data['secrets'][$h]['path'] : null,
            'previous_store' => Config::shown($prev), 'passphrase_checked' => $checked]], 'status' => ['verified' => $ok]];
    },
    'remove' => function ($args, $opt) use ($change, $save) {
        if (empty($opt['with-data'])) {
            throw new CliError('remove deletes the stored value; nothing was removed', 'add --with-data to remove it (the previous store stays as vault.enc.prev until the next rotate or remove)');
        }
        list($h, $data, $key, $env) = $change($args, true);
        $prev = Secrets::keepPrev();
        unset($data['secrets'][$h]);
        return ['records' => [['handle' => $h, 'removed' => true, 'previous_store' => Config::shown($prev)]],
            'status' => ['verified' => $save($h, $data, $key, $env, null)]];
    },
    'passphrase-change' => function () {
        return SecretsPhrase::change();
    },
    'use' => function ($args, $opt) {
        $h = Secrets::handle(isset($args[0]) ? $args[0] : '');
        $name = SecretsUse::envName(isset($opt['env']) ? $opt['env'] : null);
        $any = !empty($opt['any-command']);
        if ($any && SecretsInput::terminal() === null) {
            throw new CliError('--any-command needs the user at a terminal to confirm, and there is none; nothing was run',
                'use a Pikkumies tool command (php <tool script>), or the user runs this in their own terminal');
        }
        $run = SecretsUse::argv(array_slice($args, 1), $any);
        list($data, $key, $env) = Secrets::openWithCache();
        if (!isset($data['secrets'][$h])) {
            sodium_memzero($key);
            throw new CliError("no secret named $h; nothing was run", 'list the handles with `secrets list`');
        }
        if ($any) {
            SecretsUse::confirm($run, $h, $name, $env, $key);
        }
        sodium_memzero($key);
        $v = (string) $data['secrets'][$h]['value'];
        unset($data);
        $r = SecretsUse::run($run, $name, $v);
        sodium_memzero($v);
        return ['records' => [['handle' => $h, 'env' => $name] + $r], 'status' => ['exit_code' => $r['exit_code']]];
    },
], $usage, ['any-command', 'with-data'], ['env', 'kind', 'ttl', 'host']);
