<?php
/**
 * DelugeAdd: magnets, URLs and .torrent files into Deluge (deluge/core/core.py 2.0.3):
 * core.add_torrent_magnet(uri, options) :556-571, core.add_torrent_url(url, options) :518-553 (the daemon
 * downloads the file), core.add_torrent_file(filename, base64 contents, options) :465-488. Each returns the
 * torrent id. Options add_paused and download_location (deluge/core/torrent.py:134-169).
 * One failed item does not stop the others; each record says what happened to its item.
 */
class DelugeAdd
{
    const MAX_FILE = 10485760; // a .torrent file larger than 10 MiB is not a torrent file

    public static function run(DelugeRpc $rpc, array $items, array $opt)
    {
        $options = ['add_paused' => !empty($opt['paused'])];
        if (isset($opt['dir']) && $opt['dir'] !== null) {
            $options['download_location'] = $opt['dir']; // an existing folder inside $HOME, as its real path (Torrents::dir)
        }
        $out = [];
        foreach ($items as $item) {
            $item = (string) $item;
            try {
                list($method, $params, $name) = self::request($item, (object) $options);
                $id = $rpc->call($method, $params);
                if (!is_string($id) || !preg_match('/^[0-9a-fA-F]{40}$/', $id)) {
                    throw new CliError('Deluge did not return a torrent id' . (is_string($id) ? '' : ' (already added, or not a torrent?)'));
                }
                $out[] = ['id' => strtolower($id), 'name' => $name, 'result' => !empty($opt['paused']) ? 'added paused' : 'added'];
            } catch (CliError $e) {
                $out[] = ['id' => '', 'name' => self::label($item), 'result' => 'failed', 'error' => $e->getMessage()];
            }
        }
        if (!array_filter(array_column($out, 'id'), 'strlen')) {
            throw new CliError('nothing added: ' . implode('; ', array_map(function ($r) {
                return $r['name'] . ': ' . $r['error'];
            }, $out)), 'check the link or file; `list --search <name>` shows whether it is already in Deluge');
        }
        return $out;
    }

    /** [method, params, name] for one item; names come from a magnet's dn or the file name. */
    private static function request($item, $options)
    {
        if (stripos($item, 'magnet:') === 0) {
            parse_str((string) parse_url($item, PHP_URL_QUERY), $q);
            return ['core.add_torrent_magnet', [$item, $options], isset($q['dn']) && is_string($q['dn']) ? $q['dn'] : ''];
        }
        if (preg_match('#^https?://#i', $item)) {
            return ['core.add_torrent_url', [$item, $options], self::label($item)];
        }
        if (!is_file($item)) {
            throw new CliError('not a magnet link, an http(s) URL or an existing file');
        }
        $size = (int) @filesize($item);
        $data = $size > 0 && $size <= self::MAX_FILE ? @file_get_contents($item) : false;
        if ($data === false || $data === '' || $data[0] !== 'd') {
            throw new CliError('not a readable .torrent file (a bencoded dictionary up to 10 MiB)');
        }
        return ['core.add_torrent_file', [basename($item), base64_encode($data), $options], basename($item)];
    }

    /** A short printable label for an item, without its query string (tracker URLs can carry keys). */
    private static function label($item)
    {
        return stripos($item, 'magnet:') === 0 ? 'magnet' : basename((string) preg_replace('/[?#].*$/', '', $item));
    }
}
