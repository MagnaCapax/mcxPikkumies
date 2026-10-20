<?php
/**
 * DelugeExport: `export <id>` for Deluge (lib/TorrentExport.php checks it and saves or hands it over). Deluge writes each torrent's file
 * to its config folder as state/<torrent_id>.torrent (deluge 2.1.1 deluge/core/torrent.py:1340-1363, the folder at
 * core/torrentmanager.py:161); a torrent added from a magnet gets a file built from its metadata, which may name no
 * tracker (torrent.py:1359-1361: `trackers: false` in the head record then).
 */
class DelugeExport
{
    public static function verb(TorrentClient $c, array $args, array $opt)
    {
        return TorrentExport::verb($c, $args, function (TorrentClient $c, array $t) {
            $home = rtrim((string) getenv('HOME'), '/');
            return TorrentExport::firstFile(["$home/.config/deluge/state/" . strtolower($t['native']) . '.torrent']);
        }, 'deluge');
    }
}
