<?php
/**
 * TorrentExport: the `export <id>` verb every client unit adds through its Export.php (the unit reads the .torrent its
 * client saved; this checks it). The info-hash is recomputed from the raw info dictionary (lib/Bencode.php): SHA-1
 * (BEP 3), or for qBittorrent's id of a v2 or hybrid torrent SHA-256 cut to 40 (BEP 52; qbittorrent/README.md "Ids of
 * added torrents"), and must equal the id the client lists, so a stale or foreign file is never exported.
 * - `export <id>` (for people and the agent): writes the file to local/state/exports/<id>.torrent (folder 0700, file
 *   0600) and prints only its path, the hashes and the size. The file holds the tracker URLs with their passkeys, which
 *   the output redaction cannot see inside base64, so the bytes never reach the terminal.
 * - `export <id> base64` (for the move tool on the receiving box, through the source's serve; in no USAGE example):
 *   the head record, then the file as base64 `part` records of CHUNK characters, two per answer (lib/Cli.php BUDGET
 *   16 KiB), paged with `--offset`.
 */
require_once __DIR__ . '/Bencode.php';
require_once dirname(__DIR__, 3) . '/lib/Config.php';

class TorrentExport
{
    const CHUNK = 7800;           // base64 characters per part, a multiple of 4
    const MAX_FILE = 20971520;    // a .torrent larger than 20 MiB is not a torrent file (qbittorrent/QbitAdd.php)

    /** Test seam: the folder `export` writes to (default local/state/exports). */
    public static $dir = null;

    /** The verb for Torrents::run's $extra: $read(TorrentClient, record) => [raw bytes or '', where it came from]. */
    public static function verb(TorrentClient $c, array $args, callable $read, $client)
    {
        $parts = count($args) === 2 && $args[1] === 'base64';
        if (count($args) !== 1 && !$parts) {
            throw new CliError('export takes one full torrent id', 'export <40-hex id from list>');
        }
        $t = Torrents::resolve($c->torrents(), [$args[0]], 40, 'not found')[0];
        list($raw, $source) = call_user_func($read, $c, $t);
        $h = self::hashes($raw);
        if ($h === null) {
            throw new CliError("not exported: no readable .torrent for {$t['id']} ($source)", 'the client keeps no copy of this torrent\'s file');
        }
        $id = self::idFor($h, $client);
        if ($id !== $t['id']) {
            throw new CliError("not exported: the saved .torrent ($source) hashes to $id, not {$t['id']}", 'the client\'s saved file does not belong to this torrent');
        }
        $head = ['id' => $t['id'], 'name' => $t['name'], 'info_hash' => $h['v1'], 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw),
            'private' => $h['private'], 'trackers' => $h['trackers'], 'source' => $source];
        if (!$parts) {
            return ['records' => [$head + ['file' => self::save($t['id'], $raw)]], 'status' => ['verified' => true]];
        }
        $b64 = str_split(base64_encode($raw), self::CHUNK);
        $out = [$head + ['parts' => count($b64)]];
        foreach ($b64 as $n => $part) {
            $out[] = ['part' => $n, 'b64' => $part];
        }
        return ['records' => $out, 'status' => ['verified' => true]];
    }

    /** Writes the .torrent as <dir>/<id>.torrent, 0600 in a 0700 folder, through a temp file; returns its path. */
    private static function save($id, $raw)
    {
        $dir = self::$dir !== null ? self::$dir : Config::localDir() . '/state/exports';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check the permissions of ~/pikkumies/local');
        }
        @chmod($dir, 0700);
        $file = "$dir/$id.torrent";
        $tmp = "$file.tmp" . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $raw) === strlen($raw) && @chmod($tmp, 0600) && @rename($tmp, $file);
        umask($old);
        if (!$ok) {
            @unlink($tmp);
            throw new CliError("cannot write $file", 'check the free space of ~/pikkumies/local');
        }
        return $file;
    }

    /** ['v1', 'v2id' (null unless meta version 2), 'private', 'trackers'] of a .torrent, or null when it is not one. */
    public static function hashes($raw)
    {
        $raw = (string) $raw;
        $top = $raw !== '' && strlen($raw) <= self::MAX_FILE && Bencode::skip($raw, 0) === strlen($raw) ? Bencode::dict($raw, 0) : [];
        if (!isset($top['info'])) {
            return null;
        }
        list($start, $end) = $top['info'];
        $span = substr($raw, $start, $end - $start);
        $info = Bencode::dict($raw, $start);
        $v2 = isset($info['meta version']) && substr($raw, $info['meta version'][0], 3) === 'i2e';
        return ['v1' => sha1($span), 'v2id' => $v2 ? substr(hash('sha256', $span), 0, 40) : null,
            'private' => isset($info['private']) && substr($raw, $info['private'][0], 3) === 'i1e',
            'trackers' => isset($top['announce']) || isset($top['announce-list'])];
    }

    /** The id $client lists for a torrent with these hashes: qBittorrent uses the cut v2 hash for v2 and hybrid torrents (tc-5.0.0.cpp:421-423). */
    public static function idFor(array $h, $client)
    {
        return $client === 'qbittorrent' && $h['v2id'] !== null ? $h['v2id'] : $h['v1'];
    }

    /** The contents of the first of $paths that is a readable regular file within the size limit, and its path. */
    public static function firstFile(array $paths)
    {
        foreach ($paths as $p) {
            if (is_file($p) && !is_link($p) && filesize($p) <= self::MAX_FILE && is_readable($p)) {
                return [(string) file_get_contents($p), $p];
            }
        }
        return ['', 'none of ' . implode(', ', $paths)];
    }
}
