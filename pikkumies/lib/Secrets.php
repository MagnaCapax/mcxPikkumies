<?php
/**
 * Secrets: the encrypted secret store (ADR-0085). Passwords and API keys that cannot be an SSH key live in one file,
 * local/secrets/vault.enc: a JSON envelope {version, kdf {alg, ops, mem, salt}, nonce, ct}, where ct is
 * sodium_crypto_secretbox of {"kdf": <a copy of the header's>, "secrets": {handle: {value, kind, added_at, rotated_at}}} under a key that
 * Argon2id13 (sodium_crypto_pwhash, OPSLIMIT/MEMLIMIT_INTERACTIVE) derives from the user's passphrase. Nothing keeps
 * the passphrase; the derived key is cached outside the home for a TTL (lib/SecretsCache.php), so a lost or copied
 * vault.enc is ciphertext only. Other tools read a value with Secrets::get($handle) (a services entry names it as
 * "secret:<handle>") and never print it; locked means the user unlocks in their own terminal.
 */
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
        $h = self::handle($ref);
        list($data, $key) = self::openWithCache();
        sodium_memzero($key);
        if (!isset($data['secrets'][$h])) {
            throw new CliError("no secret named $h", 'list the handles with `secrets list`; the user adds one with `secrets set ' . $h . '` in their own terminal');
        }
        return (string) $data['secrets'][$h]['value'];
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
