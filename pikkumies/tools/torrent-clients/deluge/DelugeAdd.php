<?php
/**
 * DelugeAdd: magnets, URLs and .torrent files into Deluge (deluge/core/core.py 2.0.3):
 * core.add_torrent_magnet(uri, options) :556-571, core.add_torrent_url(url, options) :518-553 (the daemon
 * downloads the file), core.add_torrent_file(filename, base64 contents, options) :465-488. Each returns the
 * torrent id. Options add_paused and download_location (deluge/core/torrent.py:134-169).
 * One failed item does not stop the others; each record says what happened to its item. The records follow the
 * shared contract (qBittorrent's QbitAdd): `added` or `already in the client`, the torrent's own name read back
 * from the list, `paused` when --paused was given, and `verified` when every item is listed (and paused if asked).
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class DelugeAdd
{
    const MAX_FILE = 10485760; // a .torrent file larger than 10 MiB is not a torrent file
    /** Deluge's refusal of a torrent it already has (AddTorrentError; live 2.2.0 2026-10-14: "... (<id>).") */
    const DUPLICATE = '/Torrent already in session \(([0-9a-fA-F]{40})\)/';

    public static function run(DelugeClient $c, array $items, array $opt)
    {
        $paused = !empty($opt['paused']);
        $options = ['add_paused' => $paused];
        if (isset($opt['dir']) && $opt['dir'] !== null) {
            $options['download_location'] = $opt['dir']; // an existing folder inside $HOME, as its real path (Torrents::dir)
        }
        $out = [];
        foreach ($items as $item) {
            $item = (string) $item;
            try {
                list($method, $params) = self::request($item, (object) $options);
                $out[] = ['id' => self::send($c->rpc(), $method, $params), 'name' => self::label($item), 'result' => 'sent'];
            } catch (DelugeDuplicate $e) {
                $out[] = ['id' => $e->id, 'name' => self::label($item), 'result' => 'already in the client'];
            } catch (CliError $e) {
                $out[] = ['id' => '', 'name' => self::label($item), 'result' => 'failed', 'error' => $e->getMessage()];
            }
        }
        if (!array_filter(array_column($out, 'id'), 'strlen')) {
            throw new CliError('nothing added: ' . implode('; ', array_map(function ($r) {
                return $r['name'] . ': ' . $r['error'];
            }, $out)), 'check the link or file; `list --search <name>` shows whether it is already in Deluge');
        }
        return self::confirm($c, $out, $paused);
    }

    /** The torrent id Deluge returns; DelugeDuplicate when it already has the torrent. */
    private static function send(DelugeRpc $rpc, $method, array $params)
    {
        try {
            $id = $rpc->call($method, $params);
        } catch (CliError $e) {
            if (preg_match(self::DUPLICATE, $e->getMessage(), $m)) {
                throw new DelugeDuplicate(strtolower($m[1]));
            }
            throw $e;
        }
        if (!is_string($id) || !preg_match('/^[0-9a-fA-F]{40}$/', $id)) {
            throw new CliError('Deluge did not return a torrent id (not a torrent?)');
        }
        return strtolower($id);
    }

    /** Reads the list back (up to 3 s): names from Deluge, `added` once listed, `paused` and `verified` from the state read. */
    private static function confirm(DelugeClient $c, array $out, $paused)
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

    /** [method, params] for one item. */
    private static function request($item, $options)
    {
        if (stripos($item, 'magnet:') === 0) {
            return ['core.add_torrent_magnet', [$item, $options]];
        }
        if (preg_match('#^https?://#i', $item)) {
            return ['core.add_torrent_url', [$item, $options]];
        }
        if (!is_file($item)) {
            throw new CliError('not a magnet link, an http(s) URL or an existing file');
        }
        $size = (int) @filesize($item);
        $data = $size > 0 && $size <= self::MAX_FILE ? @file_get_contents($item) : false;
        if ($data === false || $data === '' || $data[0] !== 'd') {
            throw new CliError('not a readable .torrent file (a bencoded dictionary up to 10 MiB)');
        }
        return ['core.add_torrent_file', [PathName::base($item), base64_encode($data), $options]];
    }

    /** A short printable label for an item until Deluge names it: a magnet's dn, else the file or URL name without its query. */
    private static function label($item)
    {
        if (stripos($item, 'magnet:') === 0) {
            parse_str((string) parse_url($item, PHP_URL_QUERY), $q);
            return isset($q['dn']) && is_string($q['dn']) ? $q['dn'] : 'magnet';
        }
        return PathName::base((string) preg_replace('/[?#].*$/', '', $item));
    }
}

/** Deluge already has the torrent (its id). */
class DelugeDuplicate extends CliError
{
    public $id;

    public function __construct($id)
    {
        parent::__construct("already in Deluge: $id");
        $this->id = $id;
    }
}
