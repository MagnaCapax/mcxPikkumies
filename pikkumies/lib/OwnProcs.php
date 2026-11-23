<?php
/**
 * OwnProcs: this account's own processes, found in /proc by owner uid, mount namespace and exact process
 * name (never by pattern, never `pkill -f`), and a process identity that survives pid reuse: the start
 * time, field 22 of /proc/<pid>/stat. Used by the torrent clients' restart verbs and by an Eternal's bounded stop
 * (lib/EternalChild.php); $proc is a test seam.
 */
require_once __DIR__ . '/PathName.php';

class OwnProcs
{
    /**
     * Pids of processes whose comm is exactly one of $names, OR one of $names truncated to 15 characters --
     * the kernel's TASK_COMM_LEN-1 limit on /proc/<pid>/comm (e.g. "transmission-daemon" is read back as
     * "transmission-da"; lib/Detect.php's KNOWN_APPS matching already allows for this, security review N2)
     * -- owned by $uid, in this mount namespace (as PMSS does, so a Docker guest of the same name is left
     * alone). When the own namespace cannot be read, the namespace is not compared. Sorted.
     */
    public static function pids(array $names, $uid, $proc = '/proc')
    {
        $match = array_unique(array_merge($names, array_map(function ($n) {
            return substr($n, 0, 15);
        }, $names)));
        $ns = @readlink("$proc/self/ns/mnt");
        $out = [];
        foreach ((array) glob("$proc/[0-9]*", GLOB_ONLYDIR) as $dir) {
            $pid = PathName::base($dir);
            if (!ctype_digit($pid) || @fileowner($dir) !== (int) $uid || ($ns !== false && @readlink("$dir/ns/mnt") !== $ns)) {
                continue;
            }
            if (in_array(trim((string) @file_get_contents("$dir/comm")), $match, true)) {
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

    /**
     * What $uid runs, from one read of /proc: pid => ['ppid', 'start', 'comm'] (stat fields 4 and 22 and the name,
     * read around the last ')'). Zombies are left out. Pure data, so a test can hand in a stub /proc.
     */
    public static function tree($uid, $proc = '/proc')
    {
        $out = [];
        foreach ((array) glob("$proc/[0-9]*", GLOB_ONLYDIR) as $dir) {
            $stat = @fileowner($dir) === (int) $uid ? @file_get_contents("$dir/stat") : false;
            $open = is_string($stat) ? strpos($stat, '(') : false;
            $close = is_string($stat) ? strrpos($stat, ')') : false;
            if ($open === false || $close === false) {
                continue;
            }
            $f = explode(' ', substr($stat, $close + 2));
            if (isset($f[19]) && $f[0] !== 'Z' && ctype_digit($f[1]) && ctype_digit($f[19])) {
                $out[(int) PathName::base($dir)] = ['ppid' => (int) $f[1], 'start' => $f[19], 'comm' => substr($stat, $open + 1, $close - $open - 1)];
            }
        }
        return $out;
    }

    /**
     * The processes a bounded stop of $root may end: its descendants by parent pid, plus $known (pid => start time seen
     * earlier) while they still run, as (pid, start time) pairs so a reused pid is never taken for them. Never a process
     * whose name is one of $spare, nor anything above or below one (a parent that dies can hang up its terminal's
     * foreground group), except $root itself, which the stop is for; never this process or pid 1. $root -1: none.
     */
    public static function killable($root, array $known, array $spare, $uid, $proc = '/proc')
    {
        $tree = self::tree($uid, $proc);
        $children = [];
        foreach ($tree as $pid => $p) {
            $children[$p['ppid']][] = $pid;
        }
        $match = array_unique(array_merge($spare, array_map(function ($n) {
            return substr($n, 0, 15);
        }, $spare)));
        $spared = [];
        foreach ($tree as $pid => $p) {
            if (in_array($p['comm'], $match, true)) {
                foreach (self::below($children, $pid) as $d) {
                    $spared[$d] = true;
                }
                for ($up = $p['ppid'], $n = 0; isset($tree[$up]) && $n < 4096; $up = $tree[$up]['ppid'], $n++) {
                    $spared[$up] = true;
                }
            }
        }
        unset($spared[$root]);
        $candidates = array_flip(self::below($children, $root));
        foreach ($known as $pid => $start) {
            if (isset($tree[$pid]) && $tree[$pid]['start'] === (string) $start) {
                $candidates[$pid] = true;
            }
        }
        $out = [];
        foreach ($candidates as $pid => $unused) {
            if (isset($tree[$pid]) && !isset($spared[$pid]) && $pid > 1 && $pid !== getmypid()) {
                $out[$pid] = $tree[$pid]['start'];
            }
        }
        return $out;
    }

    /** $pid and everything under it, by parent pid. */
    private static function below(array $children, $pid)
    {
        $found = [(int) $pid];
        $seen = [(int) $pid => true];
        for ($i = 0; $i < count($found); $i++) {
            foreach (isset($children[$found[$i]]) ? $children[$found[$i]] : [] as $child) {
                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $found[] = $child;
                }
            }
        }
        return $found;
    }
}
