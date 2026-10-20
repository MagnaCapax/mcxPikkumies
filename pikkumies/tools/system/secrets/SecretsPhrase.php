<?php
/**
 * SecretsPhrase: `secrets passphrase-change` (ADR-0085). The current passphrase opens the store, a new one typed twice
 * gets a new salt, and the store is written again under the new key. vault.enc.prev is re-encrypted too when the old
 * key opens it, so no copy stays under a passphrase the user may be retiring because it leaked; one the old key does
 * not open is left alone and named. The cached key is replaced by the new one (8 h).
 */
class SecretsPhrase
{
    /**
     * [data, key, envelope, checked] for a change by `set` or `rotate`. With a terminal the user proves the passphrase on
     * /dev/tty: its key must open the store and, when a key is cached, be that key. Otherwise the agent, running as the same
     * account, could make or swap vault.enc and the cached key so the user's next secret lands under a key it chose. Without
     * a terminal (a script, the agent piping a value it made) the cached key is used and `checked` is false.
     */
    public static function prove()
    {
        Secrets::needSodium();
        if (SecretsInput::terminal() === null) {
            list($data, $key, $env) = Secrets::openWithCache();
            return [$data, $key, $env, false];
        }
        $env = Secrets::readEnvelope();
        if ($env === null) {
            throw new CliError('no secret store yet; nothing was stored', 'create it with `' . Secrets::unlockCommand() . '` in your own terminal');
        }
        $typed = SecretsInput::phrase('Confirm with the vault passphrase (not shown) ');
        $key = Secrets::deriveKey($typed, $env['kdf']);
        sodium_memzero($typed);
        try {
            $data = Secrets::open($env, $key);
        } catch (CliError $e) {
            sodium_memzero($key);
            throw new CliError($e->getMessage(), 'type it again; if it was right, this store is not the one you made (`secrets status` shows when and on'
                . ' which terminal it was made), so move vault.enc away and run unlock to make your own');
        }
        $c = SecretsCache::read(Secrets::vaultPath());
        if ($c !== null && !hash_equals($c['key'], $key)) {
            sodium_memzero($key);
            throw new CliError('the cached unlocked key differs from the key your passphrase gives, so the store or its cache was replaced since you unlocked; nothing was stored',
                'run `secrets lock`, check `secrets status`, then unlock again in your own terminal');
        }
        return [$data, $key, $env, true];
    }

    public static function change()
    {
        Secrets::needSodium();
        $vault = Secrets::vaultPath();
        $env = Secrets::readEnvelope();
        if ($env === null) {
            throw new CliError('no secret store yet; nothing was changed', 'the user creates one with `' . Secrets::unlockCommand() . '` in their own terminal');
        }
        $old = SecretsInput::phrase('Current vault passphrase (not shown): ');
        $oldKey = Secrets::deriveKey($old, $env['kdf']);
        sodium_memzero($old);
        $data = Secrets::open($env, $oldKey);
        $new = SecretsInput::newPhrase();
        $kdf = Secrets::newKdf();
        $newKey = Secrets::deriveKey($new, $kdf);
        sodium_memzero($new);
        Secrets::write($data, $newKey, $kdf);
        $prev = 'none';
        if (is_file("$vault.prev")) {
            try {
                Secrets::write(Secrets::open(Secrets::readEnvelope("$vault.prev"), $oldKey), $newKey, $kdf, "$vault.prev");
                $prev = 're-encrypted with the new passphrase';
            } catch (CliError $e) {
                $prev = 'left as is: the old passphrase does not open it; delete it if the old one is why you change it';
            }
        }
        sodium_memzero($oldKey);
        SecretsCache::forget($vault);
        list(, $expires) = SecretsCache::put($vault, $newKey, $kdf['salt'], SecretsInput::TTL_DEFAULT);
        sodium_memzero($newKey);
        list($check, $k) = Secrets::openWithCache();
        sodium_memzero($k);
        return ['records' => [['changed' => true, 'handles' => count($check['secrets']), 'previous_store' => $prev,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires)]], 'status' => ['verified' => count($check['secrets']) === count($data['secrets'])]];
    }
}
