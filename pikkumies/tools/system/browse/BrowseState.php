<?php
/**
 * BrowseState: local/browse/state.json, the one record of a running browser: each part's pid with its start time (a
 * reused pid never passes for the part), the namespace's network namespace id, the versions. Written after every step
 * of a start, so a start that fails or dies half way can still be stopped completely.
 */
class BrowseState
{
    /** The parts in start order; every one must be alive for the browser to be used. */
    const PARTS = ['outer', 'inner', 'slirp', 'daemon', 'guard'];

    public static function read()
    {
        $s = json_decode((string) @file_get_contents(BrowsePaths::file('state')), true);
        return is_array($s) ? $s : null;
    }

    /** Throws when it cannot be written: a start must not go on without a record of what it started. */
    public static function write(array $s)
    {
        HermesConfig::put(BrowsePaths::file('state'), json_encode($s, JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }

    public static function clear()
    {
        foreach (['state', 'ready', 'intent', 'panelAuth'] as $f) {
            @unlink(BrowsePaths::file($f));
        }
    }

    /** '' when every part in $s is alive, else which one is gone ("guard stopped"). */
    public static function gone(array $s, $proc)
    {
        foreach (self::PARTS as $part) {
            if (!isset($s[$part]['pid'], $s[$part]['started']) || !$proc->alive($s[$part]['pid'], $s[$part]['started'])) {
                return "$part stopped";
            }
        }
        return '';
    }

    /** The state when every part is alive, else null. */
    public static function running($proc = null)
    {
        $s = self::read();
        return $s !== null && self::gone($s, $proc !== null ? $proc : new BrowseProc()) === '' ? $s : null;
    }

    /** ['pid' => n, 'started' => ticks] for a process that was just started; null start time when it already died. */
    public static function part($pid, $proc)
    {
        return ['pid' => (int) $pid, 'started' => $proc->started($pid)];
    }

    /** Seconds since the last CLI call (mtime of last-use); null when there was none. */
    public static function idleSeconds($now)
    {
        clearstatcache(true, BrowsePaths::file('lastUse'));
        $t = @filemtime(BrowsePaths::file('lastUse'));
        return $t === false ? null : $now - $t;
    }

    public static function touch()
    {
        @touch(BrowsePaths::file('lastUse'));
    }

    /** One line in browse.log: time and what happened (no URL, no credential). */
    public static function log($line)
    {
        @file_put_contents(BrowsePaths::file('log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND);
    }
}
