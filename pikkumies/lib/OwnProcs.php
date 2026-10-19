<?php
/**
 * OwnProcs: this account's own processes, found in /proc by owner uid, mount namespace and exact process
 * name (never by pattern, never `pkill -f`), and a process identity that survives pid reuse: the start
 * time, field 22 of /proc/<pid>/stat. Used by the torrent clients' restart verbs; $proc is a test seam.
 */
class OwnProcs
{
    /**
     * Pids of processes whose comm is exactly one of $names, owned by $uid, in this mount namespace (as PMSS
     * does, so a Docker guest of the same name is left alone). When the own namespace cannot be read, the
     * namespace is not compared. Sorted.
     */
    public static function pids(array $names, $uid, $proc = '/proc')
    {
        $ns = @readlink("$proc/self/ns/mnt");
        $out = [];
        foreach ((array) glob("$proc/[0-9]*", GLOB_ONLYDIR) as $dir) {
            $pid = basename($dir);
            if (!ctype_digit($pid) || @fileowner($dir) !== (int) $uid || ($ns !== false && @readlink("$dir/ns/mnt") !== $ns)) {
                continue;
            }
            if (in_array(trim((string) @file_get_contents("$dir/comm")), $names, true)) {
                $out[] = (int) $pid;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * When the process started, in clock ticks since boot (stat field 22, read after the last ')' because the
     * name may hold spaces and brackets); null when it is gone or a zombie. Same pid and same start time means
     * the same process.
     */
    public static function started($pid, $proc = '/proc')
    {
        $stat = @file_get_contents("$proc/" . (int) $pid . '/stat');
        if (!is_string($stat) || strrpos($stat, ')') === false) {
            return null;
        }
        $f = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return isset($f[19]) && $f[0] !== 'Z' && ctype_digit($f[19]) ? $f[19] : null;
    }

    /** pid => start time of each process pids() finds; a process that ends between the two reads is left out. */
    public static function identities(array $names, $uid, $proc = '/proc')
    {
        $out = [];
        foreach (self::pids($names, $uid, $proc) as $pid) {
            $t = self::started($pid, $proc);
            if ($t !== null) {
                $out[$pid] = $t;
            }
        }
        return $out;
    }
}
