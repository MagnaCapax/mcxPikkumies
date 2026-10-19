<?php
/**
 * BazarrSettings: `config show [<section>]` and `config set <section> <field> <value>` over Bazarr's own settings API
 * with its own key: GET system/settings (sections of fields, app/config.py get_settings :651-667) and POST
 * system/settings with one form field settings-<section>-<field> (save_settings :690-; api/system/settings.py:43-130,
 * @authenticate: the X-API-KEY header, api/utils.py:100-115), at upstream bfe4593b. The same rules as ArrSettings
 * (ADR-0078): show hides secret-named values and skips lists; set changes one field, keeps its type, reads it back.
 * Never set here: the login (auth, set-login owns it), cors, the proxy, the database connection, the listen address,
 * port and base URL, update and permission settings, the addresses and keys Bazarr uses to reach Sonarr, Radarr, Plex and
 * Jellyfin (another account's port would receive Bazarr's key for them), a command Bazarr runs after each download
 * (postprocessing_cmd), outgoing webhooks, turning off certificate checks, and every path-valued field (NEVER).
 */
class BazarrSettings
{
    const NEVER_SECTIONS = ['auth', 'cors', 'proxy', 'postgresql'];
    const NEVER = ['ip', 'port', 'hostname', 'base_url', 'url', 'ssl', 'apikey', 'branch', 'auto_update', 'chmod', 'chmod_enabled',
        'postprocessing_cmd', 'use_postprocessing', 'use_external_webhook', 'external_webhook_url', 'external_webhook_username',
        'disable_all_providers_ssl_verify', 'verify_ssl', 'flask_secret_key',
        // Path-valued fields (app/config.py at bfe4593b: a filesystem-path default, or a name ending in folder, path or dir):
        // backup.folder :195 (the backups hold the API key and provider passwords), general.subfolder :148 and
        // general.subfolder_custom :149 (where subtitle files are written), general.path_mappings :93 and
        // general.path_mappings_movie :117 (lists, refused as lists too).
        'folder', 'subfolder', 'subfolder_custom', 'path_mappings', 'path_mappings_movie'];

    public static function run(ArrClient $c, array $args)
    {
        $sub = isset($args[0]) ? $args[0] : '';
        if ($sub === 'show' && count($args) <= 2) {
            return self::show($c, self::get($c), isset($args[1]) ? $args[1] : null);
        }
        if ($sub === 'set' && count($args) === 4) {
            return [self::set($c, $args[1], $args[2], $args[3])];
        }
        throw new CliError('config takes `show [<section>]` or `set <section> <field> <value>`', '`config show` lists the sections and fields');
    }

    /** One record per scalar field; a text value passes ArrClient::redact (the key, and URL queries cut: a webhook can hold a token). */
    private static function show(ArrClient $c, array $all, $only)
    {
        if ($only !== null && !isset($all[$only])) {
            throw new CliError("no settings section $only here", 'sections: ' . implode(', ', array_keys($all)));
        }
        $out = [];
        foreach ($only !== null ? [$only => $all[$only]] : $all as $s => $fields) {
            foreach (is_array($fields) ? $fields : [] as $f => $v) {
                if (!is_array($v)) {
                    $out[] = ['section' => $s, 'field' => $f, 'value' => self::secret($f) && $v !== '' && $v !== null ? 'hidden' : (is_string($v) ? $c->redact($v) : $v)];
                }
            }
        }
        return $out;
    }

    private static function set(ArrClient $c, $s, $f, $raw)
    {
        $all = self::get($c);
        if (!isset($all[$s]) || !is_array($all[$s]) || !array_key_exists($f, $all[$s])) {
            throw new CliError("Bazarr has no setting $s.$f", "`config show $s` lists them; names are case-sensitive");
        }
        if (in_array($s, self::NEVER_SECTIONS, true) || in_array($f, self::NEVER, true) || self::secret($f) || is_array($all[$s][$f])) {
            throw new CliError("$s.$f is not set with this verb; nothing changed", $s === 'auth' ? 'a new login: `set-login`'
                : 'the login, addresses, keys, update and permission settings, commands and webhooks stay as they are (ADR-0019)');
        }
        if ($all[$s][$f] === null) {
            throw new CliError("$s.$f has no value now, so its type is unknown; nothing changed", 'set it once in Bazarr\'s own Settings page');
        }
        $old = $all[$s][$f];
        $v = ArrSettings::typed($old, $raw, "$s.$f");
        $c->call('POST', 'system/settings', [], null, ['text' => true, 'form' => ["settings-$s-$f" => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v]]);
        $now = self::get($c);
        $back = isset($now[$s]) && array_key_exists($f, $now[$s]) ? $now[$s][$f] : null;
        $ok = $back === $v;
        ArrAuth::log('config set', 'bazarr', $ok ? 'set' : 'not-read-back', ['section' => $s, 'field' => $f]);
        if (!$ok) {
            throw new CliError("$s.$f was sent, but Bazarr reads back " . json_encode($back), "see `config show $s`; Bazarr may have adjusted it");
        }
        return ['section' => $s, 'field' => $f, 'old' => $old, 'value' => $v];
    }

    /** ArrSettings::secret, plus the provider keys named only `<name>_key` (anticaptcha.anti_captcha_key, captchaai.captchaai_key). */
    private static function secret($f)
    {
        return ArrSettings::secret($f) || (bool) preg_match('/(^|_)key$/', strtolower($f));
    }

    private static function get(ArrClient $c)
    {
        $all = $c->call('GET', 'system/settings', [], null, ['raw' => true]);
        if (!is_array($all) || !isset($all['general']) || !is_array($all['general'])) {
            throw new CliError('Bazarr answered system/settings without its settings', 'run `stats`');
        }
        unset($all['notifications']); // a list of notifier rows the settings page adds, not a section of fields
        return $all;
    }
}
