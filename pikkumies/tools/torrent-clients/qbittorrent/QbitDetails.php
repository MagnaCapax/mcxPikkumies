<?php
/**
 * QbitDetails: what `show` adds to a list record (torrents/properties and torrents/trackers,
 * qbt-wiki-5.md:1340-1440) and the torrent's files with absolute paths for the delete preview
 * (torrents/files, :1540-1600).
 */
class QbitDetails
{
    public static function details(QbittorrentClient $c, $native)
    {
        $p = $c->api->json('torrents/properties', ['hash' => $native]);
        if ($p === null) {
            throw new CliError("torrent $native is gone from qBittorrent", 'run `list` again');
        }
        $keep = ['total_size', 'piece_size', 'pieces_num', 'pieces_have', 'seeds', 'seeds_total', 'peers', 'peers_total', 'total_uploaded',
            'total_downloaded', 'time_elapsed', 'seeding_time', 'eta', 'creation_date', 'completion_date', 'comment', 'infohash_v1', 'infohash_v2'];
        $out = array_intersect_key($p, array_flip($keep));
        $out['trackers'] = [];
        foreach ((array) $c->api->json('torrents/trackers', ['hash' => $native]) as $t) {
            if (isset($t['url']) && strpos((string) $t['url'], '** [') !== 0) { // skip the DHT/PeX/LSD rows (tc-5.0.0.cpp:175-245)
                $out['trackers'][] = ['url' => (string) $t['url'], 'status' => isset($t['status']) ? (int) $t['status'] : 0, 'message' => isset($t['msg']) ? (string) $t['msg'] : ''];
            }
        }
        return $out;
    }

    /**
     * Absolute file paths. File names are relative to the torrent's base folder (qbt-wiki-5.md:1567);
     * content_path is the root folder or the single file (:1242), where the data actually is (the
     * incomplete folder while downloading). The base is content_path minus the single file's name or
     * the common root folder; with no root folder, content_path itself [UNVERIFIED: TorrentImpl::contentPath
     * is not in the local source copies]; save_path only when content_path is empty.
     */
    public static function files(QbittorrentClient $c, $native)
    {
        $rows = $c->rows([$native]);
        $files = $rows ? self::paths((array) $c->api->json('torrents/files', ['hash' => $native]), $rows[0]) : [];
        if ($files === null) {
            throw new CliError("qBittorrent gives no folder for torrent $native; its files cannot be listed safely",
                'check it in the WebUI and try again');
        }
        return $files;
    }

    /**
     * [native => files] for the shared-file check: the rows from the paged list, then one torrents/files per
     * torrent (it takes a single hash, qbt-wiki-5.md:1546-1551; the API has no call for several torrents' files).
     * null for a torrent that left the list or whose files the client did not give (404).
     */
    public static function many(QbittorrentClient $c, array $records)
    {
        $rows = [];
        foreach ($c->rows() as $r) {
            $rows[isset($r['hash']) ? (string) $r['hash'] : ''] = $r;
        }
        $out = [];
        foreach ($records as $r) {
            $n = (string) $r['native'];
            $files = isset($rows[$n]) ? $c->api->json('torrents/files', ['hash' => $n]) : null;
            $out[$n] = is_array($files) ? self::paths($files, $rows[$n]) : null;
        }
        return $out;
    }

    /** $files (torrents/files) with absolute paths from the torrent's list row; null when the row gives no folder. */
    private static function paths(array $files, array $row)
    {
        if (!$files) {
            return [];
        }
        $cp = rtrim(isset($row['content_path']) ? (string) $row['content_path'] : '', '/');
        $base = rtrim(isset($row['save_path']) ? (string) $row['save_path'] : '', '/');
        $first = (string) $files[0]['name'];
        $root = explode('/', $first)[0];
        $tails = count($files) === 1 ? ["/$first"] : [];
        if (strpos($first, '/') !== false && count(array_filter($files, function ($f) use ($root) {
            return strpos((string) $f['name'], "$root/") === 0;
        })) === count($files)) {
            $tails[] = "/$root"; // every file under one root folder
        }
        if ($cp !== '' && !$tails) {
            $base = $cp; // several files and no root folder: content_path is the folder that holds them
        }
        foreach ($cp === '' ? [] : $tails as $tail) {
            if (substr($cp, -strlen($tail)) === $tail) {
                $base = substr($cp, 0, -strlen($tail));
                break;
            }
        }
        if ($base === '') {
            return null;
        }
        return array_map(function ($f) use ($base) {
            return [$base . '/' . $f['name'], (int) $f['size']];
        }, $files);
    }
}
