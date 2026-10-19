<?php
/**
 * HookInterval: the interval hook units (ADR-0067). A unit whose hook.md says `event: transcript` never becomes a
 * Hermes entry (Hermes refuses an event it does not know): the orchestrator spools the session's records
 * (lib/HookSpool.php) and starts the unit in the background at most once per interval per session
 * (lib/HookAsync.php). Its hook.md holds the usual fields plus `every` (the default seconds between runs, 60 to 3600)
 * and, optionally, `wake` (the record kinds that make a run worth starting; default any). It never fails closed, and
 * only shipped units may be interval units: a local one is refused (lib/LocalHooks.php).
 *
 * Settings: hooks.every.<unit name> = seconds overrides one unit's interval (clamped; 0 turns it off), and
 * hooks.async.disabled turns every interval unit off. The key is the unit's name, not its category path: the configure
 * tool, its undo log and the config-change hook read keys of letters, digits, dots and dashes only.
 */
require_once __DIR__ . '/HookUnits.php';

class HookInterval
{
    const EVENT = 'transcript';
    const MIN = 60;
    const MAX = 3600;
    const MAX_TIMEOUT = 110;  // a worker lives at most timeout + 10 s, inside ADR-0022's "nothing long-lived"
    const MARGIN = 15;        // every must be more than timeout + 15 s, so a unit's runs never overlap
    const WAKE_KINDS = ['user', 'assistant', 'reply', 'tool', 'change'];

    /** The optional frontmatter fields a unit of $event may carry besides HookUnits::FIELDS. */
    public static function fields($event)
    {
        return $event === self::EVENT ? ['every', 'wake'] : [];
    }

    /** Problems of an interval unit's own fields; [] for any other unit. */
    public static function errors(array $u)
    {
        if ($u['event'] !== self::EVENT) {
            return [];
        }
        $m = $u['meta'];
        $e = [];
        if (!isset($m['every']) || !ctype_digit($m['every']) || (int) $m['every'] < self::MIN || (int) $m['every'] > self::MAX) {
            $e[] = 'every must be whole seconds from ' . self::MIN . ' to ' . self::MAX;
        } elseif ((int) $m['every'] <= $u['timeout'] + self::MARGIN) {
            $e[] = 'every must be more than timeout + ' . self::MARGIN . ' seconds';
        }
        if (isset($m['wake']) && array_diff(self::split($m['wake']), self::WAKE_KINDS)) {
            $e[] = 'wake takes a comma list of ' . implode(', ', self::WAKE_KINDS);
        }
        return $e;
    }

    /** The unit's id, as settings, logs and the agent name it. */
    public static function id(array $u)
    {
        return $u['category'] . '/' . $u['name'];
    }

    /** The record kinds that wake the unit ([] = any). */
    public static function wakes(array $u)
    {
        return isset($u['meta']['wake']) ? self::split($u['meta']['wake']) : [];
    }

    /**
     * Seconds between runs: hooks.every.<name> when set, else hook.md's every; 0 when that is 0 or hooks.async.disabled.
     * Clamped to MIN..MAX and above timeout + MARGIN, so an override can never make runs overlap.
     */
    public static function interval(array $u, array $config)
    {
        if (!empty($config['hooks']['async']['disabled'])) {
            return 0;
        }
        $name = $u['name'];
        $set = isset($config['hooks']['every'][$name]) && is_numeric($config['hooks']['every'][$name]) ? (int) $config['hooks']['every'][$name] : null;
        $every = $set === null ? (int) $u['meta']['every'] : $set;
        return $every <= 0 ? 0 : max(self::MIN, $u['timeout'] + self::MARGIN + 1, min(self::MAX, $every));
    }

    /** The valid shipped interval units that are on, [id => unit + 'every' (seconds) + 'wakes'], in run order. */
    public static function on(array $config, $hooksDir = null)
    {
        $out = [];
        foreach (self::units($hooksDir) as $u) {
            $u['every'] = self::interval($u, $config);
            $u['wakes'] = self::wakes($u);
            if ($u['every'] > 0) {
                $out[self::id($u)] = $u;
            }
        }
        return $out;
    }

    /** The valid shipped interval units, on or off, in run order. */
    public static function units($hooksDir = null)
    {
        return HookUnits::byName(array_values(array_filter(HookUnits::all($hooksDir), function ($u) {
            return $u['event'] === self::EVENT && !$u['errors'];
        })));
    }

    /** "every 600s" or "every 300s (600s by default)", for the index and doctor. */
    public static function describe(array $u, array $config = [])
    {
        $now = self::interval($u, $config);
        $default = (int) $u['meta']['every'];
        return $now === 0 ? "off ({$default}s by default)" : "every {$now}s" . ($now !== $default ? " ({$default}s by default)" : '');
    }

    private static function split($list)
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $list)), 'strlen'));
    }
}
