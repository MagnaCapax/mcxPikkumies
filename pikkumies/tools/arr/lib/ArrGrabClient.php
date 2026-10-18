<?php
/**
 * ArrGrabClient: the user's own torrent client for grab's in-process path B (docs/adr/0046 §2).
 * "Installed and running" reuses each client unit's own Locate code (RtorrentLocate, QbitEndpoint,
 * DelugeLocate, tools/torrent-clients/README.md "Where each client is found"): no login, no API
 * call, the same cheap check each unit's own `restart` uses. $detect/$build are test seams (a fake
 * client set never touches a real rTorrent/qBittorrent/Deluge or local/secrets/qbittorrent).
 */
$torrent = dirname(__DIR__, 2) . '/torrent-clients';
require_once dirname(__DIR__, 3) . '/lib/SecretFile.php';
require_once "$torrent/lib/TorrentClient.php";
require_once "$torrent/lib/Bencode.php";
foreach (['rtorrent' => ['XmlRpc', 'Scgi', 'Locate', 'Records', 'Add', 'Deleter', 'Files', 'Restart', 'Extras', 'RtorrentClient'],
    'deluge' => ['DelugeLocate', 'DelugeRpc', 'DelugeFiles', 'DelugeExtras', 'DelugeClient', 'DelugeAdd', 'DelugePause', 'DelugeRemove', 'DelugeStats', 'DelugeRestart'],
    'qbittorrent' => ['QbitSecret', 'QbitEndpoint', 'QbitApi', 'QbitProc', 'QbitRestart', 'QbitStats', 'QbitAdd', 'QbitChange', 'QbitDetails', 'QbitExtras', 'QbittorrentClient']] as $clientDir => $classes) {
    foreach ($classes as $class) {
        require_once "$torrent/$clientDir/$class.php";
    }
}

class ArrGrabClient
{
    const IDS = ['rtorrent', 'qbittorrent', 'deluge'];

    /** Test seams (tests/ArrGrabTest.php): fn($home, $uid) => string[] / fn($id, $home, $uid) => TorrentClient. */
    public static $detect = null;
    public static $build = null;

    /** $want (a validated --client), or the one client that is present; CliError listing the choices otherwise. */
    public static function pick($want, $home, $uid)
    {
        if ($want !== null) {
            if (!in_array($want, self::IDS, true)) {
                throw new CliError("--client takes rtorrent, qbittorrent or deluge, not $want", 'grab <handle> --client rtorrent');
            }
            return $want;
        }
        $present = self::present($home, $uid);
        if (count($present) === 1) {
            return $present[0];
        }
        return self::fail($present);
    }

    /** Every client of IDS that is installed and running for this account; no network call, no password read. */
    public static function present($home, $uid)
    {
        if (self::$detect !== null) {
            return call_user_func(self::$detect, $home, $uid);
        }
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        $out = [];
        foreach (self::IDS as $id) {
            try {
                self::locate($id, $home, $family, $uid);
                $out[] = $id;
            } catch (CliError $e) {
                // not this account's client; try the next one
            }
        }
        return $out;
    }

    /** A working TorrentClient for $id, built the same way its own entry script builds one. */
    public static function make($id, $home, $uid)
    {
        if (self::$build !== null) {
            return call_user_func(self::$build, $id, $home, $uid);
        }
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        if ($id === 'rtorrent') {
            return new RtorrentClient($family, $home, $uid);
        }
        if ($id === 'deluge') {
            return new DelugeClient($home, $family, $uid, Arr::timeout());
        }
        $secret = new QbitSecret(Config::localDir() . '/secrets');
        return new QbittorrentClient(new QbitApi(new QbitEndpoint($home, $family), $secret), new QbitRestart(new QbitProc()));
    }

    private static function locate($id, $home, $family, $uid)
    {
        if ($id === 'rtorrent') {
            RtorrentLocate::address($family, $home, $uid);
        } elseif ($id === 'qbittorrent') {
            (new QbitEndpoint($home, $family))->locate();
        } else {
            DelugeLocate::find($home, $family, $uid);
        }
    }

    private static function fail(array $present)
    {
        if (!$present) {
            throw new CliError('no supported torrent client is installed or running for this account', '--client rtorrent|qbittorrent|deluge, or --via-prowlarr');
        }
        throw new CliError('more than one torrent client is installed; choose one', '--client ' . implode('|', $present));
    }
}
