<?php
/**
 * LocalHooks: the user's own hook units under local/hooks/ (ADR-0054), laid out like the shipped ones
 * (local/hooks/<category>/<hook>/, local/hooks/tools/<tool>/<hook>/, same hook.md). Never tracked, never updated.
 *
 * They are read only when local/ is this account's and closed to group and others (Pikkumies keeps it 0700); each
 * unit must pass LocalHookCheck (plain names, no symlink inside, this account's files, at most 1 MiB). That keeps out
 * other accounts, never the agent: the agent runs as this account and can write local hooks itself.
 * A unit runs only as it was recorded at the last start by the user (local/state/local-changes.json, written by
 * generate, which prints what is new to the terminal and puts it in SOUL.md): a unit written or edited mid-session,
 * also its lib/ code, waits for the next start. A start by an agent (PIKKUMIES_DEPTH 1 or more) records nothing. A local unit with a
 * problem is skipped and named, never blocks; hooks/orchestrator.php runs local units after the shipped ones and
 * always fails open for them.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HookUnits.php';
require_once __DIR__ . '/LocalHookCheck.php';

class LocalHooks
{
    /** One record and one notice for everything local that reaches the agent (ADR-0054); keys say which tree. */
    const RECORD = 'state/local-changes.json';
    const NOTICE = 'notices/local-changes.txt';

    /** [root => scan] of the last generate, after it recorded the units: HermesConfig reads it instead of scanning again. */
    private static $generated = [];

    /**
     * ['units' => usable local units recorded at the last start, 'pending' => [id => unit] usable but new or changed
     * since, 'problems' => [id => why], 'off' => '' or why no local unit is read]. $root is the pikkumies directory
     * (local/ and tools/ inside it). Each unit gets 'local' => true, 'id' => 'local/<category>/<name>' (the hook log's
     * unit name) and 'hash' (its files).
     */
    public static function scan($root = null)
    {
        $root = $root ?: Config::root();
        $dir = "$root/local/hooks";
        $out = ['units' => [], 'pending' => [], 'problems' => [], 'off' => ''];
        if (!file_exists($dir) && !is_link($dir)) {
            return $out;
        }
        $st = @stat("$root/local");
        if ($st === false || $st['uid'] !== self::uid() || ($st['mode'] & 077)) {
            $out['off'] = Config::shown("$root/local") . ' is not owned by this account or is open to others (it must be 0700)';
        } elseif (is_link($dir) || !is_dir($dir) || @fileowner($dir) !== self::uid()) {
            $out['off'] = Config::shown($dir) . ' is a symlink or not this account\'s directory';
        }
        if ($out['off'] !== '') {
            return $out;
        }
        $recorded = self::record($root);
        foreach (HookUnits::all($dir, "$root/tools") as $u) {
            $u['local'] = true;
            // Shown to the agent every turn: only in cleaned form, and a name that needs cleaning refuses the unit.
            $u['id'] = LocalHookCheck::cleanId('local/' . ltrim($u['category'] . '/' . $u['name'], '/'));
            $why = LocalHookCheck::badPath($dir, $u['dir']);
            $why = $why !== '' ? $why : implode('; ', $u['errors']);
            if ($why === '') {
                list($u['hash'], $why) = LocalHookCheck::check($dir, $u, self::uid());
            }
            if ($why !== '') {
                $out['problems'][$u['id']] = $why;
                continue;
            }
            $key = self::key($u);
            if (isset($recorded[$key]) && $recorded[$key] === $u['hash']) {
                $out['units'][] = $u;
            } else {
                $out['pending'][$u['id']] = $u;
            }
        }
        return $out;
    }

    /** The usable local units of $event from a scan, in name order like the shipped ones; with $pending also the new ones. */
    public static function forEvent($event, array $scan, $pending = false)
    {
        $units = $pending ? array_merge($scan['units'], array_values($scan['pending'])) : $scan['units'];
        return HookUnits::byName(array_values(array_filter($units, function ($u) use ($event) {
            return $u['event'] === $event;
        })));
    }

    /** The scan the last noteChanges() left for $root, or a new one. */
    public static function generated($root = null)
    {
        $root = $root ?: Config::root();
        return isset(self::$generated[$root]) ? self::$generated[$root] : self::scan($root);
    }

    /**
     * At generate (each start): with $record, records every usable local unit as it is now, so they run from this start;
     * writes the one local-changes notice (HermesConfig::notices shows it in SOUL.md in a slot of its own), or removes
     * it when nothing is new. Returns the notice text ('' = none), which generate also prints to the terminal. Without
     * $record (a start by an agent) nothing new is recorded and the notice says the units wait for a start by the user.
     * The record is the agent's own state: the guard asks before a visible write to it, but it is a record of what the
     * user was told, not a lock.
     */
    public static function noteChanges($root = null, $scan = null, $record = true)
    {
        $root = $root ?: Config::root();
        $local = "$root/local";
        if (!is_dir($local)) {
            return '';
        }
        $scan = $scan !== null ? $scan : self::scan($root);
        $hashes = [];
        foreach (array_merge($scan['units'], array_values($scan['pending'])) as $u) {
            $hashes[self::key($u)] = $u['hash'];
        }
        $changed = array_map(function ($u) {
            return self::key($u);
        }, array_values($scan['pending']));
        $parts = [];
        if ($scan['off'] !== '') {
            $parts[] = 'local hooks are off: ' . $scan['off'];
        }
        if ($changed) {
            $parts[] = count($changed) . ' local hook' . (count($changed) > 1 ? 's' : '') . ' new or changed since the last start: ' . implode(', ', $changed)
                . ($record ? '; they run from this start' : '; not recorded, because an agent started this, so they wait for a start by the user');
        }
        if ($scan['problems']) {
            $parts[] = count($scan['problems']) . ' local hook' . (count($scan['problems']) > 1 ? 's' : '') . ' skipped, see pikkumies doctor';
        }
        if ($record && $hashes !== self::record($root)) {
            self::write("$local/" . self::RECORD, json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        }
        $text = implode('; ', $parts);
        if ($text === '') {
            @unlink("$local/" . self::NOTICE);
        } else {
            self::write("$local/" . self::NOTICE, $text . "\n");
        }
        if ($record) {
            $scan['units'] = HookUnits::byName(array_merge($scan['units'], array_values($scan['pending'])));
            $scan['pending'] = [];
        }
        self::$generated[$root] = $scan;
        return $text;
    }

    /** The record's key for a unit: the tree, then its path (hooks/<category>/<hook>), so local notes can join later. */
    private static function key(array $u)
    {
        return 'hooks/' . substr($u['id'], strlen('local/'));
    }

    /** [key => hash] from local-changes.json; [] when missing, unreadable or not this account's plain file. */
    private static function record($root)
    {
        $file = "$root/local/" . self::RECORD;
        if (is_link($file) || !is_file($file) || @fileowner($file) !== self::uid()) {
            return [];
        }
        $d = json_decode((string) @file_get_contents($file, false, null, 0, 1048576), true);
        return is_array($d) ? $d : [];
    }

    /** Best effort, 0600, through a temporary file: a failure leaves the old file and never stops generate. */
    private static function write($path, $content)
    {
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        $tmp = $path . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@chmod($tmp, 0600) || !@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    private static function uid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }
}
