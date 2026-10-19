<?php
/**
 * JellyfinSettings: `config show [<field>]` and `config set <field> <value>` over Jellyfin's own system configuration,
 * GET and POST /System/Configuration (Jellyfin.Api/Controllers/ConfigurationController.cs:49-69 at tag v12.2: GET for any
 * signed-in user, POST needs an administrator and REPLACES the whole ServerConfiguration, so the tool posts back exactly
 * what it read with one field changed). The fields are MediaBrowser.Model/Configuration/ServerConfiguration.cs and
 * BaseApplicationConfiguration.cs at v12.2. The same rules as ArrSettings (ADR-0078): show hides secret-named values and
 * skips lists and objects; set changes one top-level field, keeps its type (true/false, a whole number, one line of
 * text), refuses a field with no value (its type is unknown), and reads it back. Never set here (NEVER): the login and its
 * methods (EnableLegacyAuthorization, QuickConnectAvailable, IsStartupWizardCompleted: false would reopen /Startup/User
 * to anyone on this machine), the network (IsPortAuthorized, EnableMetrics: an unauthenticated /metrics on the shared
 * loopback), the data folders and the internal migration flags. Network settings live in the separate named
 * configuration /System/Configuration/network, which is not on the allowlist at all (ADR-0019).
 */
class JellyfinSettings
{
    const NEVER = ['IsStartupWizardCompleted', 'EnableLegacyAuthorization', 'QuickConnectAvailable', 'IsPortAuthorized', 'EnableMetrics',
        'MetadataPath', 'CachePath', 'PreviousVersion', 'PreviousVersionStr', 'EnableCaseSensitiveItemIds', 'EnableNormalizedItemByNameIds',
        'SkipDeserializationForBasicTypes', 'DisableLiveTvChannelUserDataName'];

    public static function run(Mediabrowser $mb, array $args)
    {
        $sub = isset($args[0]) ? $args[0] : '';
        if ($sub === 'show' && count($args) <= 2) {
            return self::show(self::get($mb), isset($args[1]) ? $args[1] : null);
        }
        if ($sub === 'set' && count($args) === 3) {
            return [self::set($mb, $args[1], $args[2])];
        }
        throw new CliError('config takes `show [<field>]` or `set <field> <value>`', '`config show` lists the fields');
    }

    /** One record per scalar field: field, value ('hidden' when the name looks secret). */
    private static function show($cfg, $only)
    {
        $out = [];
        foreach (get_object_vars($cfg) as $f => $v) {
            if (($only !== null && $f !== $only) || is_array($v) || is_object($v)) {
                continue;
            }
            $out[] = ['field' => $f, 'value' => ArrSettings::secret($f) && $v !== '' && $v !== null ? 'hidden' : $v];
        }
        if ($only !== null && !$out) {
            throw new CliError("no setting $only here", '`config show` lists them; names are case-sensitive');
        }
        return $out;
    }

    private static function set(Mediabrowser $mb, $f, $raw)
    {
        $cfg = self::get($mb);
        if (!property_exists($cfg, $f) || is_array($cfg->$f) || is_object($cfg->$f)) {
            throw new CliError("Jellyfin's configuration has no setting $f", '`config show` lists them; names are case-sensitive');
        }
        if (ArrSettings::secret($f) || in_array($f, self::NEVER, true)) {
            throw new CliError("$f is not set with this verb; nothing changed",
                'the login, the network, the data folders and Jellyfin\'s internal flags stay as they are (ADR-0019)');
        }
        if ($cfg->$f === null) {
            throw new CliError("$f has no value now, so its type is unknown; nothing changed", 'set it once in Jellyfin\'s dashboard');
        }
        $old = $cfg->$f;
        $v = ArrSettings::typed($old, $raw, $f);
        $cfg->$f = $v;
        $mb->post('/System/Configuration', [], json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $now = self::get($mb);
        $back = property_exists($now, $f) ? $now->$f : null;
        $ok = is_string($v) && is_string($back) ? $v === $back : $back === $v;
        ArrAuth::log('config set', 'jellyfin', $ok ? 'set' : 'not-read-back', ['field' => $f]);
        if (!$ok) {
            throw new CliError("$f was sent, but Jellyfin reads back " . json_encode($back), "see `config show $f`; Jellyfin may have adjusted it");
        }
        return ['field' => $f, 'old' => $old, 'value' => $v];
    }

    /** The configuration as an object, so empty JSON objects inside it stay objects when it is posted back. */
    private static function get(Mediabrowser $mb)
    {
        $cfg = json_decode((string) $mb->text('/System/Configuration'));
        if (!is_object($cfg) || !get_object_vars($cfg)) {
            throw new CliError('Jellyfin answered /System/Configuration without its settings', 'run `stats`');
        }
        return $cfg;
    }
}
