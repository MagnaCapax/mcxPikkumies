<?php
/**
 * ArrSettings: `config show [<section>]` and `config set <section> <field> <value>` over the app's own settings API,
 * GET and PUT /api/<v>/config/<section> (the section controllers of each app at the commits in the unit READMEs).
 * Only the sections in SECTIONS. show hides every secret-named value and the API key. set changes one field: it must
 * exist, keep its type (true/false, a whole number, text), pass the rules below, and read back as sent. Rules (ADR-0019
 * "never weaken their logins"; the PMSS installer binds every app to 127.0.0.1): the login only stays forms and
 * enabled, the address only 127.0.0.1, and user name, password, API key, port, UrlBase, update settings and file
 * permissions are never set here (set-login and repair own the first and the proxy path; updates are the provider's).
 */
class ArrSettings
{
    const SECTIONS = ['host', 'ui', 'downloadclient'];
    const LIBRARY_SECTIONS = ['mediamanagement', 'naming', 'indexer']; // the library apps only; Prowlarr has none of them
    const FIXED = ['authenticationMethod' => 'forms', 'authenticationRequired' => 'enabled', 'bindAddress' => '127.0.0.1'];
    const NEVER = ['username', 'apiKey', 'port', 'sslPort', 'urlBase', 'updateMechanism', 'updateScriptPath', 'updateAutomatically', 'branch',
        'setPermissionsLinux', 'chmodFolder', 'chownGroup', 'useScriptImport', 'scriptImportPath'];

    public static function run(array $app, ArrClient $c, array $args, callable $locate, callable $fresh)
    {
        $sub = isset($args[0]) ? $args[0] : '';
        if ($sub === 'show' && count($args) <= 2) {
            return self::show($app, $c, isset($args[1]) ? [self::section($app, $args[1])] : self::sections($app));
        }
        if ($sub === 'set' && count($args) === 4) {
            return [self::set($app, $c, self::section($app, $args[1]), $args[2], $args[3], $locate, $fresh)];
        }
        throw new CliError('config takes `show [<section>]` or `set <section> <field> <value>`', 'sections: ' . implode(', ', self::sections($app)));
    }

    public static function sections(array $app)
    {
        return $app['kind'] === 'library' ? array_merge(self::SECTIONS, self::LIBRARY_SECTIONS) : self::SECTIONS;
    }

    private static function section(array $app, $s)
    {
        if (!in_array($s, self::sections($app), true)) {
            throw new CliError("no settings section $s here", 'sections: ' . implode(', ', self::sections($app)));
        }
        return $s;
    }

    /** One record per field: section, field, value (hidden when its name looks secret, or it is the key or a password hash). */
    private static function show(array $app, ArrClient $c, array $sections)
    {
        $out = [];
        foreach ($sections as $s) {
            foreach (self::get($c, $s) as $f => $v) {
                if ($f === 'id' || is_array($v)) {
                    continue;
                }
                $hide = self::secret($f) && $v !== '' && $v !== null;
                $out[] = ['section' => $s, 'field' => $f, 'value' => $hide ? 'hidden' : (is_string($v) ? $c->redact($v) : $v)];
            }
        }
        return $out;
    }

    private static function set(array $app, ArrClient $c, $s, $f, $raw, callable $locate, callable $fresh)
    {
        $cfg = self::get($c, $s);
        if (!array_key_exists($f, $cfg) || $f === 'id' || is_array($cfg[$f])) {
            throw new CliError("$s has no setting $f", "`config show $s` lists them; names are case-sensitive");
        }
        if (self::secret($f) || in_array($f, self::NEVER, true)) {
            throw new CliError("$s.$f is not set with this verb; nothing changed",
                in_array($f, ['username', 'password'], true) ? 'a new login: `set-login`' : 'the address, login and proxy path: `repair`; updates and permissions stay as the provider set them');
        }
        $v = self::typed($cfg[$f], $raw, "$s.$f");
        if ($s === 'host' && isset(self::FIXED[$f]) && strtolower((string) $v) !== strtolower(self::FIXED[$f])) {
            throw new CliError("$s.$f stays " . self::FIXED[$f] . ' (the login is never weakened and the app listens only on this machine); nothing changed', 'leave it as it is');
        }
        $old = $cfg[$f];
        $cfg[$f] = $v;
        if ($s === 'host') {
            ArrAuth::putHost($c, $cfg);
        } else {
            $c->call('PUT', "config/$s/" . (isset($cfg['id']) ? (int) $cfg['id'] : 1), [], $cfg, ['raw' => true]);
        }
        $now = self::get($c, $s);
        $back = array_key_exists($f, $now) ? $now[$f] : null;
        $ok = is_string($v) && is_string($back) ? strcasecmp($v, $back) === 0 : $back === $v;
        ArrAuth::log('config set', $app['id'], $ok ? 'set' : 'not-read-back', ['section' => $s, 'field' => $f]);
        if (!$ok) {
            throw new CliError("$s.$f was sent, but the app reads back " . json_encode($back), 'see `config show ' . $s . '`; the app may have adjusted it');
        }
        return ['section' => $s, 'field' => $f, 'old' => $old, 'value' => $v] + ($s === 'host' ? ArrUpdates::restore($app, $locate, $fresh, "host.$f was set") : []);
    }

    private static function get(ArrClient $c, $s)
    {
        $cfg = $c->call('GET', "config/$s", [], null, ['raw' => true]);
        if (!is_array($cfg) || $cfg === [] || array_keys($cfg) === range(0, count($cfg) - 1)) {
            throw new CliError("the app answered config/$s without its settings", 'run `stats`');
        }
        return $cfg;
    }

    /** True for a field whose name looks secret (Cli::SECRET_KEY_RE) or is a password confirmation; shared with jellyfin and bazarr config. */
    public static function secret($f)
    {
        return (bool) preg_match(Cli::SECRET_KEY_RE, strtolower($f)) || $f === 'passwordConfirmation';
    }

    /** $raw as the type of $now: true/false, a whole number, or one line of text (shared with jellyfin and bazarr config). */
    public static function typed($now, $raw, $name)
    {
        if (is_bool($now)) {
            if (!in_array($raw, ['true', 'false'], true)) {
                throw new CliError("$name takes true or false", 'nothing changed');
            }
            return $raw === 'true';
        }
        if (is_int($now) || is_float($now)) {
            if (!preg_match('/^-?[0-9]{1,9}$/', $raw)) {
                throw new CliError("$name takes a whole number", 'nothing changed');
            }
            return (int) $raw;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) || strlen($raw) > 1000) {
            throw new CliError("$name takes one line of text (at most 1000 bytes)", 'nothing changed');
        }
        return $raw;
    }
}
