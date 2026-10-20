<?php
/**
 * MoveMeta: what move needs from a .torrent (lib/Bencode.php, BEP 3, BEP 47 padding files, BEP 52 meta version):
 * the v1 info-hash (SHA-1 of the raw info dictionary), the qBittorrent id of a v2 or hybrid torrent (SHA-256 of the
 * same bytes cut to 40, as qbittorrent/QbitAdd.php), the name, the private flag, whether it names a tracker, and the
 * v1 file list relative to the folder a client is given (single file: the name; several: name/path...).
 * Every path element is checked: not empty, no slash, no NUL, and not starting with a dot (no `..`, no `.ssh`), so a
 * file list can never leave the target folder or name a dot-file. A v2-only torrent (no v1 file list) is refused: no client unit here reads one. root() finds the folder
 * on the source that holds those paths.
 */
require_once dirname(__DIR__) . '/lib/Bencode.php';
require_once dirname(__DIR__) . '/lib/TorrentExport.php';

class MoveMeta
{
    /** ['v1','v2id','name','private','trackers','files' => [[rel, bytes], ...], 'bytes'] or a CliError. */
    public static function parse($raw)
    {
        $raw = (string) $raw;
        $top = Bencode::skip($raw, 0) === strlen($raw) ? Bencode::dict($raw, 0) : [];
        if (!isset($top['info'])) {
            throw new CliError('not a valid .torrent (no info dictionary)', 'export it again from the source client');
        }
        $h = TorrentExport::hashes($raw);
        $info = Bencode::dict($raw, $top['info'][0]);
        if (!isset($info['name'])) {
            throw new CliError('the .torrent has no name', 'export it again from the source client');
        }
        $name = Bencode::string($raw, $info['name'][0]);
        self::part($name);
        $files = [];
        if (isset($info['files'])) {
            foreach (self::items($raw, $info['files'][0]) as $at) {
                $f = Bencode::dict($raw, $at);
                if (!isset($f['length'], $f['path'])) {
                    throw new CliError('a file entry of the .torrent has no length or path', 'the .torrent is damaged');
                }
                if (isset($f['attr']) && strpos(Bencode::string($raw, $f['attr'][0]), 'p') !== false) {
                    continue; // BEP 47 padding file: clients never write it to disk
                }
                $parts = [$name];
                foreach (self::items($raw, $f['path'][0]) as $p) {
                    $parts[] = self::part(Bencode::string($raw, $p));
                }
                $files[] = [implode('/', $parts), self::int($raw, $f['length'][0])];
            }
        } elseif (isset($info['length'])) {
            $files[] = [$name, self::int($raw, $info['length'][0])];
        }
        if (!$files) {
            throw new CliError('the .torrent has no v1 file list (a v2-only torrent)', 'move cannot copy a v2-only torrent yet');
        }
        return ['v1' => $h['v1'], 'v2id' => $h['v2id'], 'name' => $name, 'private' => $h['private'], 'trackers' => $h['trackers'], 'files' => $files,
            'bytes' => array_sum(array_column($files, 1))];
    }

    /** The id $client lists for this torrent (TorrentExport::idFor). */
    public static function idFor(array $meta, $client)
    {
        return TorrentExport::idFor($meta, $client);
    }

    /** Offsets of the values of the list at $at. */
    private static function items($raw, $at)
    {
        if (!isset($raw[$at]) || $raw[$at] !== 'l') {
            throw new CliError('the .torrent file list is malformed', 'the .torrent is damaged');
        }
        $out = [];
        $i = $at + 1;
        while (isset($raw[$i]) && $raw[$i] !== 'e') {
            $out[] = $i;
            $i = Bencode::skip($raw, $i);
            if ($i < 0) {
                throw new CliError('the .torrent file list is malformed', 'the .torrent is damaged');
            }
        }
        return $out;
    }

    private static function int($raw, $at)
    {
        $e = strpos($raw, 'e', $at);
        $n = $e === false ? '' : substr($raw, $at + 1, $e - $at - 1);
        if ($raw[$at] !== 'i' || !preg_match('/^\d+$/', $n)) {
            throw new CliError('a file length in the .torrent is not a number', 'the .torrent is damaged');
        }
        return (int) $n;
    }

    /** One path element, refused when it could leave the folder. */
    private static function part($p)
    {
        if ($p === '' || $p[0] === '.' || strpos($p, '/') !== false || strpos($p, "\0") !== false) {
            throw new CliError('the .torrent names an unsafe path element: ' . json_encode($p) . ' (empty, a slash, or starting with a dot)',
                'move refuses torrents whose paths could leave their folder or name a dot-file such as .ssh');
        }
        return $p;
    }

    /**
     * The folder on the source that holds the .torrent's paths: each file's absolute path minus its path in the
     * .torrent must give the same folder, inside the service's home. Returned relative to that home ('' = the home).
     */
    public static function root(array $meta, array $files, $home, $id)
    {
        $home = rtrim((string) $home, '/');
        $byPath = [];
        foreach ($files as $f) {
            if ($f[2]) {
                throw new CliError("nothing planned: $id has files behind a symlinked folder on the source", 'move only torrents whose files are plain files');
            }
            $byPath[$f[0]] = $f[1];
        }
        $root = null;
        foreach ($meta['files'] as $mf) {
            $hit = null;
            foreach ($byPath as $path => $bytes) {
                if (substr($path, -strlen($mf[0]) - 1) === '/' . $mf[0]) {
                    if ($bytes !== $mf[1]) {
                        throw new CliError("nothing planned: $path on the source is $bytes bytes, the .torrent says {$mf[1]}", 'recheck the torrent on the source first');
                    }
                    $hit = substr($path, 0, -strlen($mf[0]) - 1);
                    break;
                }
            }
            if ($hit === null || ($root !== null && $hit !== $root)) {
                throw new CliError("nothing planned: the files of $id on the source are not laid out as its .torrent names them (renamed or moved files)",
                    'move it in its client first so the files sit under one folder as named, or copy it by hand');
            }
            $root = $hit;
        }
        if ($home === '' || strpos($root . '/', $home . '/') !== 0 || $root === $home || preg_match('#/\.#', substr($root, strlen($home)))) {
            throw new CliError("nothing planned: the data of $id is not in a folder of the service's home ($home), or is in the home itself or a dot-folder", 'move takes data from data folders only');
        }
        return ltrim((string) substr($root, strlen($home)), '/');
    }
}
