<?php
/**
 * Secrets: the encrypted secret store (ADR-0085). Passwords and API keys that cannot be an SSH key live in one file,
 * local/secrets/vault.enc: a JSON envelope {version, kdf {alg, ops, mem, salt}, nonce, ct}, where ct is
 * sodium_crypto_secretbox of {"kdf": <a copy of the header's>, "secrets": {handle: {value, kind, added_at, rotated_at[, host, path]}}} under a key that
 * Argon2id13 (sodium_crypto_pwhash, OPSLIMIT/MEMLIMIT_INTERACTIVE) derives from the user's passphrase. Nothing keeps
 * the passphrase; the derived key is cached outside the home for a TTL (lib/SecretsCache.php), so a lost or copied
 * vault.enc is ciphertext only. Other tools read a value with Secrets::get($handle) (a services entry names it as
 * "secret:<handle>") and never print it; locked means the user unlocks in their own terminal. A secret may carry a pin
 * (host, optionally a path prefix) that limits where a tool may send it (ADR-0105).
 */
require_once __DIR__ . '/SshLists.php';

class Secrets
{
    const VERSION = 1;
    const ALG = 'argon2id13';
    const HANDLE_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';
    const KINDS = ['password', 'apikey', 'token'];
    const VALUE_MAX = 8192;
    const FILE_MAX = 65000; // SecretFile::read keeps at most 64 KiB
    /** KDF limits a store may carry: wide enough for any libsodium's interactive and sensitive presets, so a store
     * opens on another machine; narrow enough that a changed file cannot ask for absurd memory. */
    const OPS_MAX = 16;
    const MEM_MIN = 8388608;
    const MEM_MAX = 1073741824;

    /** Test seams: the vault file and the clock. */
    public static $vaultFile = null;
    public static $now = null;

    public static function vaultPath()
    {
        return self::$vaultFile !== null ? self::$vaultFile : Config::localDir() . '/secrets/vault.enc';
    }

    public static function now()
    {
        return self::$now !== null ? (int) self::$now : time();
    }

    /** The command a person types to unlock, as they can type it from any directory. */
    public static function unlockCommand()
    {
        return 'php ' . Config::shown(Config::root() . '/tools/system/secrets/secrets.php') . ' unlock';
    }

    public static function needSodium()
    {
        if (!extension_loaded('sodium') || !defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')) {
            throw new CliError('this PHP has no sodium extension (with Argon2id), so the secret store cannot encrypt or decrypt',
                'Debian PHP packages build it in; elsewhere install the PHP sodium extension (often a php-sodium package); nothing was read or written');
        }
    }

    /** A handle, or "secret:<handle>" as a services entry writes it. */
    public static function handle($ref)
    {
        $h = strncmp((string) $ref, 'secret:', 7) === 0 ? substr((string) $ref, 7) : (string) $ref;
        if (!preg_match(self::HANDLE_RE, $h)) {
            throw new CliError("not a secret handle: $h", 'a handle is 1-64 letters, digits, dot, underscore or dash, starting with a letter or digit');
        }
        return $h;
    }

    /** The value of one secret for a tool to use (never to print). Throws CliError when locked or missing. */
    public static function get($ref)
    {
        return (string) self::entry($ref)['value'];
    }

    /**
     * The host one secret is pinned to (`secrets set|rotate <handle> --host <host>[/path/]`, normalised when stored), or null
     * when it has none. Throws CliError when locked or missing, as get().
     */
    public static function host($ref)
    {
        $p = self::pin($ref);
        return $p === null ? null : $p['host'];
    }

    /**
     * The pin of one secret: ['host' => normalised host, 'path' => a path prefix ending in "/", or null for any path], or null
     * when it has none. A tool that sends the secret over the network refuses a request pinAllows() does not allow (ADR-0105).
     * Only set and rotate write it, each together with a new value.
     */
    public static function pin($ref)
    {
        $e = self::entry($ref);
        if (!isset($e['host']) || !is_string($e['host']) || $e['host'] === '') {
            return null;
        }
        return ['host' => $e['host'], 'path' => isset($e['path']) && is_string($e['path']) && $e['path'] !== '' ? $e['path'] : null];
    }

    /**
     * A pin as `--host` takes it, "host" (any path on that host, the weaker form) or "host/path/prefix" (only URLs under that
     * prefix, on a "/" boundary): ['host', 'path'], or null when it cannot be read. The host is normalised by SshLists::host;
     * the path is plain characters only, with no empty, "." or ".." segment and no %-encoding, so nothing can be read two ways.
     */
    public static function parsePin($text)
    {
        $text = (string) $text;
        if ($text === '' || strlen($text) > 300 || preg_match('/[\x00-\x20\x7f%?#@\\\\]/', $text) !== 0) {
            return null;
        }
        $cut = $text[0] === '[' ? strpos($text, ']') : strpos($text, '/');
        if ($text[0] === '[') {
            $cut = $cut === false ? false : $cut + 1;
            if ($cut === false) {
                return null;
            }
        }
        $host = SshLists::host($cut === false ? $text : substr($text, 0, $cut));
        $rest = $cut === false ? '' : (string) substr($text, $cut);
        if ($host === null) {
            return null;
        }
        if ($rest === '' || $rest === '/') {
            return ['host' => $host, 'path' => null];
        }
        $path = self::cleanPath($rest);
        return $path === null ? null : ['host' => $host, 'path' => $path];
    }

    /** A URL path or pin prefix ending in "/" ("/a/b" -> "/a/b/"), or null when it holds an empty, "." or ".." segment or other characters. */
    public static function cleanPath($p)
    {
        $p = (string) $p === '' ? '/' : (string) $p;
        if (preg_match('#^/[A-Za-z0-9._~/+=,:-]*\z#', $p) !== 1) {
            return null;
        }
        $inner = trim($p, '/');
        foreach ($inner === '' ? [] : explode('/', $inner) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return null;
            }
        }
        if (strpos(substr($p, 1), '//') !== false || strpos($p, '//') === 0) {
            return null;
        }
        return $inner === '' ? '/' : '/' . $inner . '/';
    }

    /** Whether $pin (from pin()) allows a request to $host (normalised) at URL path $path: same host, and under the prefix when it has one. */
    public static function pinAllows(array $pin, $host, $path)
    {
        if (!isset($pin['host']) || $pin['host'] !== $host) {
            return false;
        }
        if (empty($pin['path'])) {
            return true;
        }
        $p = self::cleanPath($path);
        return $p !== null && strncmp($p, $pin['path'], strlen($pin['path'])) === 0;
    }

    /** A pin as the user wrote it, for messages: host, or host plus path prefix (an IPv6 host in brackets). */
    public static function pinText(array $pin)
    {
        $h = strpos($pin['host'], ':') !== false ? '[' . $pin['host'] . ']' : $pin['host'];
        return $h . (empty($pin['path']) ? '' : $pin['path']);
    }

    /** One stored entry {value, kind, added_at, rotated_at[, host, path]} with the cached key. */
    private static function entry($ref)
    {
        $h = self::handle($ref);
        list($data, $key) = self::openWithCache();
        sodium_memzero($key);
        if (!isset($data['secrets'][$h])) {
            throw new CliError("no secret named $h", 'list the handles with `secrets list`; the user adds one with `secrets set ' . $h . '` in their own terminal');
        }
        return $data['secrets'][$h];
    }

    /** [data, key, envelope] with the cached key; CliError "locked" when there is none. Caller memzeroes the key. */
    public static function openWithCache()
    {
        self::needSodium();
        $env = self::readEnvelope();
        if ($env === null) {
            throw new CliError('no secret store yet (' . Config::shown(self::vaultPath()) . ' does not exist)',
                'the user creates it with `' . self::unlockCommand() . '` in their own terminal, then adds secrets with `secrets set <handle>`');
        }
        $c = SecretsCache::read(self::vaultPath());
        if ($c === null || $c['salt'] !== $env['kdf']['salt']) {
            throw new CliError('locked: run `secrets unlock` in your own terminal',
                'the user types `' . self::unlockCommand() . '` in their own terminal (the agent never gets the passphrase); then try again');
        }
        $key = $c['key'];
        return [self::open($env, $key), $key, $env];
    }

    /** The envelope of $file (default the vault), checked for shape and sane KDF limits, or null when absent. */
    public static function readEnvelope($file = null)
    {
        $file = $file !== null ? $file : self::vaultPath();
        $raw = SecretFile::read($file, 'move it away and run `secrets unlock` to make a new store');
        if ($raw === false) {
            return null;
        }
        $e = json_decode((string) $raw, true);
        $ok = is_array($e) && isset($e['version'], $e['kdf']['alg'], $e['kdf']['ops'], $e['kdf']['mem'], $e['kdf']['salt'], $e['nonce'], $e['ct'])
            && $e['version'] === self::VERSION && $e['kdf']['alg'] === self::ALG && is_int($e['kdf']['ops']) && is_int($e['kdf']['mem'])
            && $e['kdf']['ops'] >= 1 && $e['kdf']['ops'] <= self::OPS_MAX && $e['kdf']['mem'] >= self::MEM_MIN && $e['kdf']['mem'] <= self::MEM_MAX
            && strlen((string) base64_decode((string) $e['kdf']['salt'], true)) === SODIUM_CRYPTO_PWHASH_SALTBYTES
            && strlen((string) base64_decode((string) $e['nonce'], true)) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            && (!isset($e['created']) || is_array($e['created']));
        if (!$ok) {
            throw new CliError(Config::shown($file) . ' is not a secret store this version reads (unknown format or KDF limits out of range); nothing was decrypted',
                'restore it from vault.enc.prev or a backup; a failed read never rewrites it');
        }
        return $e;
    }

    /** A fresh KDF block: Argon2id13 at the interactive limits with a new random salt. */
    public static function newKdf()
    {
        return ['alg' => self::ALG, 'ops' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, 'mem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            'salt' => base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES))];
    }

    public static function deriveKey($phrase, array $kdf)
    {
        self::needSodium();
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, (string) $phrase, (string) base64_decode($kdf['salt'], true),
            $kdf['ops'], $kdf['mem'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    /** Decrypts an envelope; a wrong key and a changed file look the same (Poly1305 refuses both). */
    public static function open(array $env, $key)
    {
        $plain = sodium_crypto_secretbox_open((string) base64_decode($env['ct'], true), (string) base64_decode($env['nonce'], true), $key);
        $data = $plain === false ? null : json_decode($plain, true);
        if ($plain !== false) {
            sodium_memzero($plain);
        }
        $same = is_array($data) && isset($data['kdf']) && is_array($data['kdf']);
        foreach (['alg', 'ops', 'mem', 'salt'] as $k) { // the header is not encrypted, so its copy inside must match
            $same = $same && isset($data['kdf'][$k]) && $data['kdf'][$k] === $env['kdf'][$k];
        }
        $same = $same && (isset($data['created']) ? $data['created'] : null) === (isset($env['created']) ? $env['created'] : null);
        if (!$same || !isset($data['secrets']) || !is_array($data['secrets'])) {
            throw new CliError('the secret store could not be opened: wrong passphrase, or vault.enc was changed or damaged; no secret was read',
                'type the passphrase again; if it is right, the file was altered: restore vault.enc.prev or a backup copy');
        }
        return $data;
    }

    /** Encrypts $data under $key with a new nonce and writes it atomically (0600 in a 0700 directory). */
    public static function write(array $data, $key, array $kdf, $file = null)
    {
        $file = $file !== null ? $file : self::vaultPath();
        $created = isset($data['created']) && is_array($data['created']) ? $data['created'] : null;
        $plain = json_encode(['kdf' => $kdf, 'created' => $created, 'secrets' => (object) $data['secrets']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($plain === false) {
            throw new CliError('the secrets could not be encoded (a value that is not valid UTF-8?); nothing was written', 'enter the value again');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $env = ['version' => self::VERSION, 'kdf' => $kdf, 'created' => $created, 'nonce' => base64_encode($nonce), 'ct' => base64_encode(sodium_crypto_secretbox($plain, $nonce, $key))];
        sodium_memzero($plain);
        if (strlen((string) json_encode($env)) > self::FILE_MAX) {
            throw new CliError('the secret store would grow past 64 KiB; nothing was written', 'remove secrets you no longer use');
        }
        SecretFile::write($file, $env, 'secret store');
    }

    /** A short keyed hash of the key, shown at unlock and by status: a different one means a different passphrase or store. */
    public static function fingerprint($key)
    {
        return implode('-', str_split(substr(bin2hex(sodium_crypto_generichash('pikkumies vault fingerprint', $key, 16)), 0, 16), 4));
    }

    /** Keeps the current vault as vault.enc.prev (still encrypted) before a change that loses a value. */
    public static function keepPrev()
    {
        $env = self::readEnvelope();
        if ($env !== null) {
            SecretFile::write(self::vaultPath() . '.prev', $env, 'previous secret store');
        }
        return self::vaultPath() . '.prev';
    }
}
