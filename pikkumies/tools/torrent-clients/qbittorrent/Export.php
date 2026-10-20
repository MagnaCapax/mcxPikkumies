<?php
/**
 * QbitExport: `export <id>` for qBittorrent (lib/TorrentExport.php checks it and saves or hands it over).
 * - WebUI API 2.8.18 and later (qBittorrent 4.5.0): `GET torrents/export?hash=H` answers the whole .torrent, trackers
 *   included (release-4.5.0 torrentscontroller.cpp:1405 exportAction, webapplication.h:55 API_VERSION {2, 8, 18}; not in
 *   release-4.4.5, API 2.8.5; release-5.0.0 torrentscontroller.cpp:1476; GET allowed: not in webapplication.h
 *   m_allowedMethod, which lists the POST-only actions).
 * - Older, or when that answer is not the torrent: the file in BT_backup named by the id (the two folders the backup
 *   tool also reads, tools/backup/remote/RemoteRun.php:12). Such a file may hold no tracker: the head record says
 *   `trackers: false`, and move refuses a private torrent without one.
 */
class QbitExport
{
    const EXPORT_API = '2.8.18';
    const BACKUP = ['.local/share/qBittorrent/BT_backup', '.local/share/data/qBittorrent/BT_backup'];

    public static function verb(TorrentClient $c, array $args, array $opt)
    {
        return TorrentExport::verb($c, $args, function (QbittorrentClient $c, array $t) {
            $api = $c->api->open();
            if (version_compare($api->api, self::EXPORT_API, '>=')) {
                $raw = $api->text('torrents/export?hash=' . strtolower($t['native'])); // trim keeps a bencoded file whole: d...e
                if (TorrentExport::hashes($raw) !== null) {
                    return [$raw, 'qBittorrent torrents/export'];
                }
            }
            $home = rtrim((string) getenv('HOME'), '/');
            return TorrentExport::firstFile(array_map(function ($d) use ($home, $t) {
                return "$home/$d/" . strtolower($t['native']) . '.torrent';
            }, self::BACKUP));
        }, 'qbittorrent');
    }
}
