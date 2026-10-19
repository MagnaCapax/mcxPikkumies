<?php
/**
 * QbitProc: this account's own qbittorrent-nox processes, found in /proc by owner uid and exact
 * process name, never by pattern (no `pkill -f`). Mirrors the PMSS panel stop, which signals only
 * the user's own PIDs of the qBittorrent binaries, SIGTERM, waits, then SIGKILL
 * (PMSS etc/skel/www/scriptsInc.php:1136-1166 pmssCustomerNativePidsRead, :1187-1191 pmssCustomerNativeStop).
 * The wait here is longer (up to 10 s) because qBittorrent saves its state on a graceful exit
 * (research torrent-clients.md §2.5).
 */
require_once dirname(__DIR__, 3) . '/lib/OwnProcs.php';

class QbitProc
{
    const NAME = 'qbittorrent-nox'; // 15 characters, so /proc/<pid>/comm holds it untruncated

    public $root;
    /** SIGTERM wait: polls of 0.2 s (50 = 10 s). */
    public $wait = 50;

    public function __construct($root = '/proc')
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * PIDs owned by this uid, in this mount namespace, whose comm (and exe, when readable) is exactly
     * qbittorrent-nox. None when the own namespace cannot be read, as PMSS does (scriptsInc.php:1139-1140).
     */
    public function pids()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $out = [];
        if (@readlink($this->root . '/self/ns/mnt') === false) { // same mount namespace only, as PMSS does (scriptsInc.php:1139, :1164)
            return $out;
        }
        foreach (OwnProcs::pids([self::NAME], $uid, $this->root) as $pid) {
            $exe = @readlink($this->root . "/$pid/exe");
            if (!is_string($exe) || basename(preg_replace('/ \(deleted\)$/', '', $exe)) === self::NAME) {
                $out[] = $pid;
            }
        }
        return $out;
    }

    /**
     * Graceful stop of the own processes: SIGTERM, wait up to 10 s, SIGKILL only the survivors that
     * pids() still finds (owner, name, namespace), so a pid reused during the wait is never killed.
     */
    public function stop()
    {
        $pids = $this->pids();
        foreach ($pids as $p) {
            $this->signal($p, 15);
        }
        $left = $pids;
        for ($i = 0; $i < $this->wait && $left; $i++) {
            usleep(200000);
            $left = array_values(array_filter($left, [$this, 'alive']));
        }
        $left = array_values(array_intersect($left, $this->pids()));
        foreach ($left as $p) {
            $this->signal($p, 9);
        }
        return ['stopped' => count($pids) - count($left), 'killed' => count($left)];
    }

    /** True while the process exists and is not a zombie (checked in the real /proc). */
    public function alive($pid)
    {
        $stat = @file_get_contents("/proc/$pid/stat");
        if (!is_string($stat)) {
            return false;
        }
        $after = substr($stat, strrpos($stat, ')') + 2);
        return $after !== '' && $after[0] !== 'Z';
    }

    public function signal($pid, $sig)
    {
        if (function_exists('posix_kill')) {
            return @posix_kill((int) $pid, (int) $sig);
        }
        exec('kill -' . (int) $sig . ' ' . (int) $pid . ' 2>/dev/null', $o, $code);
        return $code === 0;
    }

    /** Seconds since the process started (stat field 22, in clock ticks of 100 per second); null when unreadable. */
    public function uptime($pid)
    {
        $stat = @file_get_contents($this->root . "/$pid/stat");
        $up = @file_get_contents($this->root . '/uptime');
        if (!is_string($stat) || !is_string($up)) {
            return null;
        }
        $f = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return isset($f[19]) ? max(0, (int) ((float) $up - ((int) $f[19]) / 100)) : null;
    }
}
