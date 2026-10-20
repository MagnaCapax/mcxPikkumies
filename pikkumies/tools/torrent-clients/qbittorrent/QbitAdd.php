<?php
/**
 * QbitAdd: `add` through torrents/add (multipart/form-data: `urls` one per line, or a `torrents` file
 * part; qbt-wiki-5.md:1792-1884). The answer is "Ok." or "Fails.", 415 for a bad file (tc-5.0.0.cpp:693-806);
 * 5.2 answers JSON instead (QbitExtras::addAnswer). The add-stopped field is `stopped` from API 2.11 (tc-5.0.0.cpp:702) and
 * `paused` before (qbt-wiki-4.1.md:1912). qBittorrent does not return the new id, so it is computed:
 * the id is the v1 info-hash, or the v2 info-hash truncated to 40 hex for v2 and hybrid torrents
 * (tc-5.0.0.cpp:421-423); then the list is read back to confirm.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class QbitAdd
{
    const MAX_FILE = 20971520;

    private $c;

    public function __construct(QbittorrentClient $c)
    {
        $this->c = $c;
    }

    public function add(array $items, array $opt)
    {
        $api = $this->c->api->open();
        $have = [];
        foreach ($this->c->torrents() as $t) {
            $have[$t['id']] = $t['name'];
        }
        $out = [];
        foreach ($items as $item) {
            $r = ['id' => '', 'name' => '', 'result' => ''];
            $parts = [];
            if (preg_match('/^magnet:\?/i', $item)) {
                list($r['id'], $r['name']) = self::magnet($item);
                $parts[] = ['urls', $item, ''];
            } elseif (preg_match('#^https?://#i', $item)) {
                $parts[] = ['urls', $item, ''];
            } elseif (is_file($item) && is_readable($item) && filesize($item) <= self::MAX_FILE) {
                $data = (string) file_get_contents($item);
                list($r['id'], $r['name']) = self::torrentFile($data);
                $parts[] = ['torrents', $data, preg_replace('/[^\w.-]/', '_', PathName::base($item))];
            } else {
                $out[] = ['id' => '', 'name' => '', 'result' => 'error: not a magnet link, an http(s) URL or a readable .torrent file (up to 20 MiB)', 'item' => $item];
                continue;
            }
            if ($r['id'] !== '' && isset($have[$r['id']])) {
                $out[] = ['id' => $r['id'], 'name' => $have[$r['id']], 'result' => 'already in the client'];
                continue;
            }
            if (!empty($opt['dir'])) {
                $parts[] = ['savepath', $opt['dir'], ''];
            }
            if (!empty($opt['paused'])) {
                $parts[] = [$api->stopped() ? 'stopped' : 'paused', 'true', ''];
            }
            $boundary = '----pikkumies' . bin2hex(random_bytes(12));
            list($code, $body) = $api->post('torrents/add', [], self::multipart($parts, $boundary), 'multipart/form-data; boundary=' . $boundary);
            list($r['result'], $ids) = QbittorrentClient::addAnswer($code, $body);
            if ($r['id'] === '' && count($ids) === 1) {
                $r['id'] = strtolower((string) $ids[0]); // a URL: 5.2 names the id it added
            }
            $out[] = $r;
        }
        return $this->confirm($out);
    }

    /** Reads the list back: a sent item whose id is listed is "added". */
    private function confirm(array $out)
    {
        $ids = [];
        foreach ($out as $r) {
            if ($r['result'] === 'sent' && $r['id'] !== '') {
                $ids[] = $r['id'];
            }
        }
        $now = [];
        for ($i = 0; $ids && $i < 25 && count($now) < count($ids); $i++) {
            usleep($i ? 200000 : 0);
            $now = $this->c->torrents($ids); // poll up to 5 s until every new id is listed
        }
        $listed = [];
        foreach ($now as $t) {
            $listed[$t['id']] = $t['name'];
        }
        $ok = true;
        foreach ($out as $i => $r) {
            if ($r['result'] === 'sent' && isset($listed[$r['id']])) {
                $out[$i]['result'] = 'added';
                $out[$i]['name'] = $listed[$r['id']];
            } elseif ($r['result'] === 'sent') {
                $out[$i]['result'] = $r['id'] === '' ? 'sent (the client fetches the URL first; find it with `list`)' : 'sent, not listed yet';
            }
            $ok = $ok && in_array($out[$i]['result'], ['added', 'already in the client'], true);
        }
        return ['records' => $out, 'status' => ['verified' => $ok]];
    }

    /** [id, name] from a magnet: btmh (v2, truncated) wins over btih, as qBittorrent's id does. */
    public static function magnet($uri)
    {
        $id = '';
        $name = '';
        foreach (explode('&', (string) parse_url($uri, PHP_URL_QUERY)) as $kv) {
            list($k, $v) = array_pad(explode('=', $kv, 2), 2, '');
            $v = urldecode($v);
            if ($k === 'xt' && preg_match('/^urn:btmh:1220([0-9a-f]{64})$/i', $v, $m)) {
                $id = strtolower(substr($m[1], 0, 40));
            } elseif ($k === 'xt' && $id === '' && preg_match('/^urn:btih:([0-9a-f]{40}|[a-z2-7]{32})$/i', $v, $m)) {
                $id = strlen($m[1]) === 40 ? strtolower($m[1]) : self::base32hex($m[1]);
            } elseif ($k === 'dn') {
                $name = $v;
            }
        }
        return [$id, $name];
    }

    private static function base32hex($s)
    {
        $bits = '';
        foreach (str_split(strtoupper($s)) as $ch) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $ch)), 5, '0', STR_PAD_LEFT);
        }
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }
        return $hex;
    }

    /** [id, name] of a .torrent: hash of the raw bencoded info dictionary; ['', ''] when unreadable. */
    public static function torrentFile($data)
    {
        $top = Bencode::dict($data, 0);
        if (!isset($top['info'])) {
            return ['', ''];
        }
        list($start, $end) = $top['info'];
        $span = substr($data, $start, $end - $start);
        $info = Bencode::dict($data, $start);
        $v2 = isset($info['meta version']) && substr($data, $info['meta version'][0], 3) === 'i2e';
        $name = isset($info['name']) ? Bencode::string($data, $info['name'][0]) : '';
        return [$v2 ? substr(hash('sha256', $span), 0, 40) : sha1($span), $name];
    }

    private static function multipart(array $parts, $boundary)
    {
        $body = '';
        foreach ($parts as $p) {
            list($name, $value, $file) = $p;
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"" . ($file !== '' ? "; filename=\"$file\"\r\nContent-Type: application/x-bittorrent" : '') . "\r\n\r\n$value\r\n";
        }
        return $body . "--$boundary--\r\n";
    }
}
