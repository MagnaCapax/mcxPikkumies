<?php
/**
 * TransmissionExport: `export <id>` prints the torrent's saved .torrent file as base64.
 *
 * Transmission's RPC has no call that returns the raw .torrent bytes -- `metainfo` in rpc-spec.md
 * (4.0.6 tag, section 3.4) is an INPUT to `torrent-add` only, never an output field of `torrent-get`.
 * `torrentFile` (section 3.3, "torrentFile", tr_info source) is the path to Transmission's own on-disk
 * copy, which this verb reads directly. A torrent added from a magnet link has no such file until
 * Transmission has the metadata, in which case `torrentFile` is empty and export refuses.
 */
class TransmissionExport
{
    const MAX = 10485760; // a .torrent file larger than 10 MiB is not a torrent file

    public static function run(TransmissionClient $c, array $targets)
    {
        $home = (string) getenv('HOME');
        $realHome = $home !== '' ? realpath($home) : false;
        $out = [];
        foreach ($targets as $t) {
            $args = $c->rpc()->call('torrent-get', ['ids' => [$t['native']], 'fields' => ['torrentFile']]);
            $path = isset($args['torrents'][0]['torrentFile']) ? (string) $args['torrents'][0]['torrentFile'] : '';
            $real = $path !== '' ? realpath($path) : false;
            if ($real === false || $realHome === false || strpos($real . '/', rtrim($realHome, '/') . '/') !== 0) {
                throw new CliError("{$t['id']} ({$t['name']}): no .torrent file on disk (torrentFile is empty or outside your home)",
                    'a torrent added from a magnet link has no .torrent file until Transmission has the metadata; check `show` first');
            }
            $size = @filesize($real);
            if ($size === false || $size <= 0 || $size > self::MAX) {
                throw new CliError("{$t['id']} ({$t['name']}): $real is not a readable .torrent file (empty, missing or over " . self::MAX . ' bytes)');
            }
            $data = @file_get_contents($real);
            if ($data === false) {
                throw new CliError("{$t['id']} ({$t['name']}): could not read $real");
            }
            $out[] = ['id' => $t['id'], 'name' => $t['name'], 'source' => 'torrentFile (torrent-get, on-disk copy)',
                'bytes' => strlen($data), 'torrent_base64' => base64_encode($data)];
        }
        return $out;
    }
}
