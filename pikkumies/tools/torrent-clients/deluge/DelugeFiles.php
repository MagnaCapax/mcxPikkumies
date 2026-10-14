<?php
/**
 * DelugeFiles: a torrent's files with absolute paths; Deluge gives them relative to download_location
 * (torrent.py:93-123, 962-965; `files` is get_files, :759-770 and :1128, empty until the metadata is in).
 * The shared-file check reads the files of every torrent in one core.get_torrents_status call
 * (core.py:771-790; the empty filter selects every torrent), never one call per torrent.
 */
class DelugeFiles
{
    /** One torrent's files (core.get_torrent_status, core.py:758). */
    public static function one(DelugeRpc $rpc, $native)
    {
        return self::paths($native, (array) $rpc->call('core.get_torrent_status', [$native, ['files', 'download_location']]));
    }

    /** [native => files] for $records, null for a torrent Deluge did not list or listed without usable paths. */
    public static function many(DelugeRpc $rpc, array $records)
    {
        $all = (array) $rpc->call('core.get_torrents_status', [new stdClass(), ['files', 'download_location']]);
        $out = [];
        foreach ($records as $r) {
            $n = (string) $r['native'];
            $s = isset($all[$n]) && is_array($all[$n]) && isset($all[$n]['files']) && is_array($all[$n]['files']) ? $all[$n] : null;
            try {
                $out[$n] = $s === null ? null : self::paths($n, $s);
            } catch (CliError $e) {
                $out[$n] = null;
            }
        }
        return $out;
    }

    private static function paths($native, array $s)
    {
        $root = rtrim(isset($s['download_location']) ? (string) $s['download_location'] : '', '/');
        $out = [];
        foreach (isset($s['files']) ? (array) $s['files'] : [] as $f) {
            $f = (array) $f;
            if ($root === '' || !isset($f['path']) || strpos('/' . $f['path'] . '/', '/../') !== false) {
                throw new CliError("Deluge reported a file of $native without a usable path",
                    'nothing was changed; check the torrent in the web UI');
            }
            $out[] = [$root . '/' . ltrim((string) $f['path'], '/'), isset($f['size']) ? (int) $f['size'] : 0];
        }
        return $out;
    }
}
