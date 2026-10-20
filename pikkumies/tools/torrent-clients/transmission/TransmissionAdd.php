<?php
/**
 * TransmissionAdd: magnets, URLs and .torrent files into Transmission (`torrent-add`, rpc-spec.md on
 * the 4.0.6 tag, section 3.4). `filename` takes a magnet link or an http(s) URL -- Transmission fetches
 * the URL itself; a local .torrent file is sent as base64 in `metainfo`. On success a `torrent-added`
 * object; on a torrent Transmission already has, a `torrent-duplicate` object in the same shape, but
 * `result` is still the string "success" (section 3.4) -- a structured signal, unlike Deluge's
 * regex-matched duplicate error text.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class TransmissionAdd
{
    const MAX_FILE = 10485760; // a .torrent file larger than 10 MiB is not a torrent file

    public static function run(TransmissionClient $c, array $items, array $opt)
    {
        $paused = !empty($opt['paused']);
        $dir = isset($opt['dir']) && $opt['dir'] !== null ? (string) $opt['dir'] : null;
        $out = [];
        foreach ($items as $item) {
            $item = (string) $item;
            try {
                $args = ['paused' => $paused];
                if ($dir !== null) {
                    $args['download-dir'] = $dir; // an existing folder inside $HOME, as its real path (Torrents::dir)
                }
                if (stripos($item, 'magnet:') === 0 || preg_match('#^https?://#i', $item)) {
                    $args['filename'] = $item;
                } elseif (is_file($item)) {
                    $size = (int) @filesize($item);
                    $data = $size > 0 && $size <= self::MAX_FILE ? @file_get_contents($item) : false;
                    if ($data === false || $data === '' || $data[0] !== 'd') {
                        throw new CliError('not a readable .torrent file (a bencoded dictionary up to 10 MiB)');
                    }
                    $args['metainfo'] = base64_encode($data);
                } else {
                    throw new CliError('not a magnet link, an http(s) URL or an existing file');
                }
                $reply = $c->rpc()->call('torrent-add', $args);
                $added = isset($reply['torrent-added']) && is_array($reply['torrent-added']) ? $reply['torrent-added'] : null;
                $dup = $added === null && isset($reply['torrent-duplicate']) && is_array($reply['torrent-duplicate']) ? $reply['torrent-duplicate'] : null;
                $one = $added !== null ? $added : $dup;
                if ($one === null || empty($one['hashString'])) {
                    throw new CliError('Transmission did not return a torrent id (not a torrent?)');
                }
                $id = strtolower((string) $one['hashString']);
                $out[] = ['id' => $id, 'name' => isset($one['name']) && $one['name'] !== '' ? (string) $one['name'] : self::label($item),
                    'result' => $dup !== null ? 'already in the client' : 'sent'];
            } catch (CliError $e) {
                $out[] = ['id' => '', 'name' => self::label($item), 'result' => 'failed', 'error' => $e->getMessage()];
            }
        }
        if (!array_filter(array_column($out, 'id'), 'strlen')) {
            throw new CliError('nothing added: ' . implode('; ', array_map(function ($r) {
                return $r['name'] . ': ' . (isset($r['error']) ? $r['error'] : $r['result']);
            }, $out)), 'check the link or file; `list --search <name>` shows whether it is already in Transmission');
        }
        return self::confirm($c, $out, $paused);
    }

    /** Reads the list back (up to 3 s): names from Transmission, `added` once listed, `paused` and `verified`. */
    private static function confirm(TransmissionClient $c, array $out, $paused)
    {
        $want = array_filter(array_column($out, 'id'), 'strlen');
        $now = [];
        for ($i = 0; $i < 15; $i++) {
            $now = [];
            foreach ($c->torrents() as $t) {
                $now[$t['id']] = $t;
            }
            if (count(array_intersect_key(array_flip($want), $now)) === count(array_unique($want))) {
                break;
            }
            usleep(200000);
        }
        $ok = true;
        foreach ($out as $i => $r) {
            $t = $r['id'] !== '' && isset($now[$r['id']]) ? $now[$r['id']] : null;
            if ($t !== null) {
                $out[$i]['name'] = $t['name'];
            }
            if ($r['result'] === 'sent') {
                $out[$i]['result'] = $t !== null ? 'added' : 'sent, not listed yet';
                if ($paused) {
                    $out[$i]['paused'] = $t !== null && $t['state'] === 'paused';
                }
            }
            $ok = $ok && $t !== null && ($out[$i]['result'] !== 'added' || !$paused || $out[$i]['paused']);
        }
        return ['records' => $out, 'status' => ['verified' => $ok]];
    }

    /** A short printable label for an item until Transmission names it: a magnet's dn, else the file or URL name without its query. */
    private static function label($item)
    {
        if (stripos($item, 'magnet:') === 0) {
            parse_str((string) parse_url($item, PHP_URL_QUERY), $q);
            return isset($q['dn']) && is_string($q['dn']) ? $q['dn'] : 'magnet';
        }
        return PathName::base((string) preg_replace('/[?#].*$/', '', $item));
    }
}
