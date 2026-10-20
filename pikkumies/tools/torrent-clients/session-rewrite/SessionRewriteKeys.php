<?php
/**
 * SessionRewriteKeys: the only bencode keys `rewrite` ever touches, one list per client, each verified from
 * source (cited below). A key not on this list is never rewritten, even when its value happens to start
 * with --from: narrower than PMSS's own blanket rewrite (scripts/lib/userTransfer/sessionRewrite.php, which
 * walks every bencoded string in the file, read in the PMSS source for this unit), and it
 * keeps a torrent's "info" dictionary (never on any of these lists; SessionRewriteEngine::verify() checks
 * this too, belt and braces) out of reach by construction. If a key cannot be verified from source, it is
 * left out and reported, never guessed into this list (deluge is refused outright: see DelugeRewrite.php).
 */
class SessionRewriteKeys
{
    const KEYS = [
        // rtorrent's own <hash>.rtorrent session file IS the "rtorrent" bencode sub-object DownloadStore::save()
        // writes to its own file (rakshasa/rtorrent tag v0.9.8, src/core/download_store.cc): its only
        // insert_key() call for a path is "directory" (src/core/download.cc set_directory() / set_base_directory()).
        // rTorrent keeps no separate on-disk "directory_base" key: d.directory and d.directory_base are the
        // same getter (CMD2_ON_FL(root_dir) for both, src/command_download.cc) over one stored value, so
        // there is nothing else here for either RPC setter to have written.
        'rtorrent' => ['directory'],
        // qBittorrent's BT_backup/<hash>.fastresume: the plain libtorrent save_path lt::write_resume_data()
        // writes (after Profile::toPortablePath(), which on a normal, non-portable install is the identity),
        // plus qBittorrent's own qBt-savePath and qBt-downloadPath (the separate incomplete-downloads folder,
        // present only when that feature is configured) -- all three set the same way (qBittorrent master
        // src/base/bittorrent/bencoderesumedatastorage.cpp, Worker::store()). No "info" key here either: that,
        // and comment/creation-date, are extracted to the separate .torrent file on disk.
        'qbittorrent' => ['save_path', 'qBt-savePath', 'qBt-downloadPath'],
        // Transmission's <config>/resume/<name>.<16-hex>.resume: "destination" (the save path) and
        // "incomplete-dir" (present only when the incomplete-downloads feature is on); neither an "info" nor
        // an "info-hash" key is ever written to this file (transmission libtransmission/resume.cc, save()).
        'transmission' => ['destination', 'incomplete-dir'],
    ];

    public static function clients()
    {
        return array_keys(self::KEYS);
    }
}
