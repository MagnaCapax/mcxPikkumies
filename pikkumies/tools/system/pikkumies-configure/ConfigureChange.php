<?php
/**
 * ConfigureChange: one settings change, from plan to log (ADR-0059). plan() decides the tier (ConfigureTiers) and checks
 * the value against what is shipped (tool categories, skill names, *arr apps); the config-change hook calls the same
 * plan() to know whether to ask. apply() refuses the person's changes and a key a PIKKUMIES_CONFIG overlay forces,
 * checks a model id against the endpoint's model list (fail closed), writes through Config::set (which
 * refuses the person's changes again, for every caller) and logs the old value. The log is only what the person's
 * shell undo reads (ConfigureUndo), and it is the agent's to write too: nothing trusts it.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/Units.php';
require_once dirname(__DIR__, 3) . '/lib/ConfigureTiers.php';
require_once __DIR__ . '/ConfigureModel.php';

class ConfigureChange
{
    /** What the person's own change does and where they make it: a key, else a key prefix; the first match wins. */
    const HOW = [
        'terms.' => 'only the person accepts the terms: they are asked at the first interactive start, or install with `pikkumies install --accept-terms`',
        'provider.' => 'the AI provider and its key file are the person\'s: they set them in ~/pikkumies/local/config.php or in the key file',
        'host.' => 'host.* overrides what detection found; the person sets it by hand in ~/pikkumies/local/config.php',
        'root.disabled' => 'turning root\'s tools back on lets the agent act as root; the person sets root.disabled to false by hand in ~/pikkumies/local/config.php',
        'searchFirst.disabled' => 'it turns off the search-first gate, a safety layer; the person sets it by hand in ~/pikkumies/local/config.php',
        'halt.disabled' => 'it turns off the halt guard, a safety layer; the person sets it by hand in ~/pikkumies/local/config.php',
        'fileopsCheck.disabled' => 'it turns off the re-check of bulk chmod, chown and mv, a safety layer; the person sets it by hand in ~/pikkumies/local/config.php',
        'hooks.llm.disabled' => 'it stops every hook model call, which also turns off the halt guard\'s judging; the person sets it by hand in ~/pikkumies/local/config.php',
        'hooks.async.disabled' => 'it turns off the background checks, among them the change check, a safety layer; the person sets it by hand in ~/pikkumies/local/config.php',
        'hooks.every.' => 'a guard check run less often, or turned off with 0, is a weaker safety layer; the person sets it by hand in ~/pikkumies/local/config.php',
        'autoUpdate.disabled' => 'it stops the daily update, and with it security and bug fixes; the person sets it by hand in ~/pikkumies/local/config.php',
        'hermes.lazyInstalls' => 'it lets Hermes download and run packages and a browser from the internet while it runs; the person sets it by hand in ~/pikkumies/local/config.php',
        'ssh.deny' => 'removing a host from the SSH deny list lets the agent connect there again; the person edits ssh.deny by hand in ~/pikkumies/local/config.php',
    ];
    /** Said after every HOW: a set rewrites the file, so the person knows their comments there go. */
    const REWRITES = ' (every set rewrites that file from its values, so comments in it are not kept)';

    /** ['tier', 'old', 'why'] for setting $key to the typed $value; a CliError for a value this tool never writes. */
    public static function plan(array $config, $key, $value)
    {
        if (ConfigureTiers::rule($key) === null) {
            throw new CliError("$key is not a Pikkumies setting; nothing was changed", "review lists the settings; *arr config files are arr.<app>.config");
        }
        $old = self::value($config, $key);
        $tier = ConfigureTiers::classify($key, $old, $value);
        $type = Config::type($key);
        if ($tier === ConfigureTiers::PERSON || $type === null) {
            return ['tier' => ConfigureTiers::PERSON, 'old' => $old, 'why' => ''];
        }
        if (!Config::valid($type, $value)) {
            throw new CliError("$key: wrong value " . json_encode($value) . '; nothing was changed', 'see: pikkumies-configure.php review');
        }
        $why = ConfigureTiers::why($key, $value);
        $added = $type === 'names' ? array_values(array_diff($value, is_array($old) ? $old : [])) : [];
        if ($key === 'tools.disabled') {
            $scan = Tree::scan(Units::toolsDir(), 'skill.md');
            self::known($key, $added, array_keys($scan['categories']), 'tool categories');
            $lost = array_keys(array_diff_key(Units::doctrines(is_array($old) ? $old : []), Units::doctrines($value)));
            $why .= $lost ? '; the rules of ' . implode(', ', $lost) . ' (their DOCTRINE.md) leave the agent\'s instructions' : '';
        } elseif ($key === 'skills.disabled') {
            self::known($key, $added, array_column(Units::all(), 'name'), 'shipped skills');
        } elseif ($type === 'path') {
            self::ownConfigFile($key, $value);
        }
        return ['tier' => $tier, 'old' => $old, 'why' => $why];
    }

    /** Refuse names that are not among $known. */
    private static function known($key, array $names, array $known, $what)
    {
        $unknown = array_diff($names, $known);
        if ($unknown) {
            throw new CliError("$key: not among the $what: " . implode(', ', $unknown) . '; nothing was changed', "$what: " . implode(', ', $known));
        }
    }

    /**
     * arr.<app>.config is free only because it names this account's own file of a shipped *arr tool: another account's
     * file, or one that is not there, is refused. A hand edit by the person is read as before (tools/arr).
     */
    private static function ownConfigFile($key, $path)
    {
        if (!is_file(Units::toolsDir() . '/arr/' . explode('.', $key)[1] . '/skill.md')) {
            throw new CliError("$key: no shipped *arr tool of that name; nothing was changed", 'the apps are the tools under tools/arr/');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($path !== '' && (!is_file($path) || @fileowner($path) !== $uid)) {
            throw new CliError("$key: $path is not a file this account owns; nothing was changed", 'name the app\'s own config file, in this account');
        }
    }

    /** Make the change: refuses the person's and a key the overlay forces, checks a model id first, writes, logs. Returns the record. */
    public static function apply(array $config, $key, $value, $file = null, $log = null)
    {
        // Changes are judged against local/config.php (Config::fileConfig), so an overlay (the question suite, automation) does
        // not stop them; only a key the overlay itself forces is refused: the change would not show while it is in effect.
        if (Config::dotted(Config::overlay(), $key) !== null) {
            throw new CliError("$key is forced by the PIKKUMIES_CONFIG overlay (" . Config::overlayPath() . ') for this run, so a change in local/config.php would not show; nothing was changed',
                'change it in a session or shell without PIKKUMIES_CONFIG');
        }
        $plan = self::plan($config, $key, $value);
        if ($plan['tier'] === ConfigureTiers::PERSON) {
            throw new CliError("$key: this one is yours to change; nothing was changed", self::how($key));
        }
        $rate = Config::type($key) === 'model' && $value !== '' && $value !== $plan['old'] ? ConfigureModel::check($config, $key, $value) : null;
        try {
            $value = Config::set($key, $value, $file);
        } catch (InvalidArgumentException $x) {
            throw new CliError($x->getMessage() . '; nothing was changed', 'see: pikkumies-configure.php review');
        }
        $r = ['key' => $key, 'value' => $value, 'old' => $plan['old'], 'tier' => $plan['tier'], 'note' => 'takes effect when the next session starts; to go back, set it to old'];
        if ($rate !== null) {
            $r['credits_per_million_tokens'] = $rate;
        }
        $line = json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z'), 'key' => $key, 'old' => $plan['old'], 'new' => $value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $log = $log ?: self::logFile($file);
        @mkdir(dirname($log), 0700, true);
        if (@file_put_contents($log, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            $r['note'] .= '; the change could not be logged (disk full?), so `pikkumies configure undo` cannot restore it';
        }
        @chmod($log, 0600);
        return $r;
    }

    /** skills disable|enable <name>: one name added to or removed from skills.disabled; a disabled name must be a shipped skill. */
    public static function skills(array $config, $verb, $name, $file = null, $log = null)
    {
        $now = self::value($config, 'skills.disabled');
        $now = is_array($now) ? array_values($now) : [];
        $new = $verb === 'disable' ? array_values(array_unique(array_merge($now, [$name]))) : array_values(array_diff($now, [$name]));
        return self::apply($config, 'skills.disabled', $new, $file, $log);
    }

    /** The change log next to the config file: local/state/configure-changes.jsonl. */
    public static function logFile($file = null)
    {
        return dirname($file ?: Config::localDir() . '/config.php') . '/state/configure-changes.jsonl';
    }

    /**
     * [key, value] that undoes the last logged change: its old value. Refused when the setting is no longer what that
     * change wrote (changed by hand since), so undo never overwrites a later edit.
     */
    public static function undoTarget(array $config, $log)
    {
        $size = is_file($log) ? (int) filesize($log) : 0;
        $tail = $size > 0 ? (string) file_get_contents($log, false, null, max(0, $size - 65536)) : '';
        $lines = array_values(array_filter(explode("\n", $tail), 'strlen'));
        $last = $lines ? json_decode(end($lines), true) : null;
        // The log is the agent's to write too: a key is only printed once it is a plain dotted setting name with a rule, so
        // control characters from a forged line never reach the person's terminal (CWE-150).
        if (!is_array($last) || !isset($last['key']) || !is_string($last['key']) || !array_key_exists('old', $last) || !array_key_exists('new', $last)
            || preg_match('/^[A-Za-z][A-Za-z0-9.-]{0,99}\z/', $last['key']) !== 1 || ConfigureTiers::rule($last['key']) === null) {
            throw new CliError('no settings change to undo (the last line of the change log names no setting); nothing was changed', 'changes made with set are logged in ' . Config::shown($log));
        }
        $now = self::value($config, $last['key']);
        if ($now !== $last['new']) {
            throw new CliError("the last logged change set {$last['key']} to " . json_encode($last['new']) . ', but it is now ' . json_encode($now) . '; nothing was changed', 'set it with: set ' . $last['key'] . ' <value>');
        }
        return [$last['key'], $last['old']];
    }

    /** Where and how the person makes their own change to $key. */
    public static function how($key)
    {
        foreach (self::HOW as $prefix => $how) {
            if ($key === $prefix || (substr($prefix, -1) === '.' && strpos($key, $prefix) === 0)) {
                return $how . ($prefix === 'terms.' ? '' : self::REWRITES);
            }
        }
        return 'the person changes it by hand in ~/pikkumies/local/config.php' . self::REWRITES;
    }

    /** The effective value of a dotted key, null when it is not set. */
    public static function value(array $config, $key)
    {
        return Config::dotted($config, $key);
    }
}
