<?php
/**
 * RtorrentAdd: `add` for rTorrent. Magnets and http(s)/ftp URLs go to load.start (load.normal with
 * --paused); a local .torrent file is sent as its contents with load.raw_start (load.raw), base64
 * encoded (rtorrent 0.9.8 command_events.cc:383-392, 198-213: extra string parameters run as
 * commands on the new download; download_factory.cc:68-95 for the URL and magnet prefixes).
 * Loading is asynchronous and returns no hash, so the id comes from the magnet's btih or the
 * SHA-1 of the file's info dictionary, and the result is read back from download_list.
 */
require_once dirname(__DIR__) . '/lib/Bencode.php';

class RtorrentAdd
{
    const MAX_FILE = 3145728; // base64 of 3 MiB stays under the 4M XML-RPC limit PMSS sets (template.rtorrent.rc:35)

    public static function run(RtorrentClient $c, array $items, array $opt)
    {
        $cmds = $opt['dir'] !== null ? ['d.directory.set=' . self::dir($opt['dir'])] : [];
        $plan = [];
        foreach ($items as $item) {
            $plan[] = self::item((string) $item); // every item is checked before anything is sent
        }
        $before = array_flip(array_map('strval', (array) $c->call('download_list'))); // no arguments, no target (PMSS postSetup.php:157)
        $out = [];
        foreach ($plan as $p) {
            if ($p['id'] !== '' && isset($before[$p['id']])) {
                $out[] = ['id' => strtolower($p['id']), 'name' => $p['name'], 'result' => 'already in rtorrent'];
                continue;
            }
            $method = $p['raw'] !== null ? (empty($opt['paused']) ? 'load.raw_start' : 'load.raw') : (empty($opt['paused']) ? 'load.start' : 'load.normal');
            $c->call($method, array_merge(['', $p['raw'] !== null ? new RtorrentBase64($p['raw']) : $p['uri']], $cmds));
            $out[] = ['id' => strtolower($p['id']), 'name' => $p['name'], 'result' => 'sent'];
        }
        $after = array_flip(array_map('strval', (array) $c->call('download_list')));
        foreach ($out as $i => $r) {
            if ($r['result'] === 'sent') {
                $out[$i]['result'] = $r['id'] === '' ? 'sent; the id appears in `list` once rtorrent has loaded it'
                    : (isset($after[strtoupper($r['id'])]) ? 'added' : 'sent, not listed yet; check `list` in a minute');
            }
        }
        return $out;
    }

    /** A magnet, a URL, or a readable .torrent file -> id (upper-case hex or ''), name, uri, raw contents. */
    public static function item($s)
    {
        if (strncmp($s, 'magnet:?', 8) === 0) {
            parse_str((string) substr($s, 8), $q);
            $id = '';
            foreach (preg_split('/&/', substr($s, 8)) as $kv) {
                if (preg_match('/^xt=urn:btih:([0-9a-fA-F]{40}|[A-Za-z2-7]{32})$/', rawurldecode($kv), $m)) {
                    $id = strlen($m[1]) === 40 ? strtoupper($m[1]) : self::base32hex($m[1]);
                    break;
                }
            }
            return ['id' => $id, 'name' => isset($q['dn']) && is_string($q['dn']) ? $q['dn'] : '', 'uri' => $s, 'raw' => null];
        }
        if (preg_match('#^(https?|ftp)://#', $s)) {
            return ['id' => '', 'name' => $s, 'uri' => $s, 'raw' => null];
        }
        if (!is_file($s) || !is_readable($s)) {
            throw new CliError("nothing added: $s is not a magnet link, an http(s) or ftp URL, or a readable .torrent file", 'pass magnet:?xt=urn:btih:..., a URL, or the path of a .torrent file');
        }
        if (filesize($s) > self::MAX_FILE) {
            throw new CliError("nothing added: $s is larger than " . self::MAX_FILE . ' bytes', 'that is not a normal .torrent file');
        }
        $raw = (string) file_get_contents($s);
        list($hash, $name) = self::infoHash($raw, $s);
        return ['id' => $hash, 'name' => $name, 'uri' => '', 'raw' => $raw];
    }

    /** --dir (already an existing folder inside $HOME, Torrents::dir) goes into an rtorrent command: no command syntax may pass. */
    public static function dir($dir)
    {
        if (preg_match('/[,;"\'{}()$\\\\\x00-\x1F]/', $dir)) {
            throw new CliError("nothing added: --dir $dir contains characters rtorrent would read as command syntax", 'use a directory name without , ; quotes, brackets, $ or backslashes');
        }
        return $dir;
    }

    /** [upper-case SHA-1 of the bencoded info dictionary, its name] (lib/Bencode.php); anything that is not a torrent is refused. */
    public static function infoHash($raw, $label)
    {
        $top = Bencode::skip($raw, 0) === strlen($raw) ? Bencode::dict($raw, 0) : [];
        $info = isset($top['info']) ? Bencode::dict($raw, $top['info'][0]) : [];
        if (!isset($top['info']) || (!$info && substr($raw, $top['info'][0], 2) !== 'de')) {
            throw new CliError("nothing added: $label is not a valid .torrent file", 'download the .torrent again');
        }
        list($start, $end) = $top['info'];
        $name = isset($info['name']) ? Bencode::string($raw, $info['name'][0]) : '';
        return [strtoupper(sha1(substr($raw, $start, $end - $start))), $name];
    }

    /** A base32 btih (32 characters) as upper-case hex. */
    public static function base32hex($b32)
    {
        $bits = '';
        foreach (str_split(strtoupper($b32)) as $ch) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $ch)), 5, '0', STR_PAD_LEFT);
        }
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }
        return strtoupper($hex);
    }
}
