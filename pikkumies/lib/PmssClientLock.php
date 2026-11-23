<?php
/**
 * PmssClientLock: the account lock PMSS holds around every start, stop and version switch of a torrent client,
 * ~/.config/pmss/torrentClients/<client>.lock (PMSS scripts/cron/checkQbittorrentInstances.php and
 * checkDelugeInstances.php; the panel's start in etc/skel/www/scriptsIncAppProcess.php). A restart that holds it
 * cannot race the watchdog, the panel or a version switch.
 *
 * The file is opened close-on-exec ('e'), so a daemon started while the lock is held never inherits it: an
 * inherited descriptor would keep the lock for the daemon's whole life and block every later switch.
 */
class PmssClientLock
{
    /**
     * Takes the lock without waiting. $create makes the lock directory (mode 0700) when it is missing; without
     * it a missing directory means this account has no lock to take, and null comes back. Refuses (CliError,
     * nothing changed) when the directory is a link, cannot be made or the lock cannot be opened, or when
     * another operation holds the lock.
     */
    public static function take($home, $client, $label, $create)
    {
        $dir = $home . '/.config/pmss/torrentClients';
        if (is_link($dir)) {
            throw new CliError("the PMSS operation directory is a link, so $label is not restarted", 'the provider repairs ~/.config/pmss/torrentClients: report it in your answer');
        }
        if (!is_dir($dir)) {
            if (!$create) {
                return null;
            }
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new CliError("the PMSS operation directory could not be made, so $label is not restarted", 'report it in your answer');
            }
        }
        $lock = @fopen("$dir/$client.lock", 'ce');
        if (!is_resource($lock)) {
            throw new CliError("the $label operation lock could not be opened, so it is not restarted", 'report it in your answer');
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new CliError("another $label operation (the panel, a version switch or the watchdog) is running; nothing was stopped", 'try again in a minute');
        }
        return $lock;
    }

    /** Lets go of a lock from take(); null (no lock on this account) is fine. */
    public static function release($lock)
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
