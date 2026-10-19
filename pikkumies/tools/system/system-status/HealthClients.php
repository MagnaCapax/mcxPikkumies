<?php
/**
 * HealthClients: the torrent-client part of `system-status health` (ADR-0066). For rTorrent, qBittorrent and Deluge:
 * the same cheap check each client unit's own verbs start with (RtorrentLocate, QbitEndpoint, DelugeLocate; no login,
 * no API call, as ArrGrabClient does), and when the account's own process of it started (lib/OwnProcs.php), so a
 * client that restarted while the machine kept running is told apart from a machine that restarted.
 */
$healthTorrent = dirname(__DIR__, 2) . '/torrent-clients';
require_once dirname(__DIR__, 3) . '/lib/OwnProcs.php';
require_once "$healthTorrent/rtorrent/Locate.php";
require_once "$healthTorrent/qbittorrent/QbitEndpoint.php";
require_once "$healthTorrent/deluge/DelugeLocate.php";

class HealthClients
{
    const NAMES = ['rtorrent' => 'rTorrent', 'qbittorrent' => 'qBittorrent', 'deluge' => 'Deluge'];
    /** A client whose process started within this many seconds of boot started with the machine. */
    const BOOT_GRACE = 600;
    /** rTorrent on PMSS: root.cron:7 runs checkRtorrent every 2 minutes; PMSS_RTORRENT_MISSING_GRACE is 180 s (checkRtorrent.php:22). */
    const PMSS_RTORRENT_WAIT = 'the platform checks rTorrent every 2 minutes and starts it once it has been missing for 3 minutes; run health again in 5 minutes, or use the rtorrent restart verb';

    /**
     * One record per client: client, state, processes, running_s, started_after_boot, why, next_step. state: listening | not_running |
     * not_enabled | unreachable. A client that is not installed, or (except rTorrent, the default client) not enabled,
     * and has no process, is left out. $upS: seconds since boot, or null.
     */
    public static function all($family, $home, $uid, $proc, $upS)
    {
        $out = [];
        foreach (self::NAMES as $id => $name) {
            list($state, $why, $hint) = self::locate($id, $family, $home, $uid);
            $ids = OwnProcs::identities(Detect::KNOWN_APPS[$id], $uid, $proc);
            $running = $ids && $upS !== null ? max(0, (int) ($upS - max($ids) / 100)) : null; // stat field 22 is in ticks of 1/100 s
            if (!$ids && ($state === 'not_installed' || ($state === 'not_enabled' && $id !== 'rtorrent'))) {
                continue;
            }
            if ($state === 'listening' && $id === 'rtorrent' && $family === 'pmss' && !$ids && $upS !== null) { // a socket outlives a crash
                list($state, $why, $hint) = ['not_running', 'rtorrent is not running: no rTorrent process of this account, only its socket file', 'use the rtorrent restart verb'];
            }
            if ($state === 'not_running' && $id === 'rtorrent' && $family === 'pmss') {
                $hint = self::PMSS_RTORRENT_WAIT;
            }
            $out[] = ['client' => $id, 'state' => $state, 'processes' => count($ids), 'running_s' => $running,
                'started_after_boot' => $running !== null ? $upS - $running > self::BOOT_GRACE : null,
                'why' => $why === '' ? null : str_replace($home, '~', $why), 'next_step' => $state === 'listening' ? null : $hint];
        }
        return $out;
    }

    /** [state, why, hint] from the client unit's own locate error (its first words name the cause). */
    private static function locate($id, $family, $home, $uid)
    {
        try {
            if ($id === 'rtorrent') {
                RtorrentLocate::address($family, $home, $uid);
            } elseif ($id === 'qbittorrent') {
                (new QbitEndpoint($home, $family))->locate();
            } else {
                DelugeLocate::find($home, $family, $uid);
            }
            return ['listening', '', ''];
        } catch (CliError $e) {
            $why = $e->getMessage();
            $causes = ['not installed' => 'not_installed', 'no deluge-web port found' => 'not_installed', 'not enabled' => 'not_enabled', 'not running' => 'not_running'];
            foreach ($causes as $words => $state) {
                if (strpos($why, $words) !== false) {
                    return [$state, $why, $e->hint];
                }
            }
            return ['unreachable', $why, $e->hint];
        }
    }
}
