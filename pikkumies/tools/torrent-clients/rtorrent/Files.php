<?php
/**
 * RtorrentFiles: a torrent's files as d.directory + f.path (command_download.cc:886; command_file.cc:121,132),
 * and the rules RtorrentDeleter deletes by, so `show --files` and `remove --with-data` agree: a torrent the
 * deleter refuses (folder outside $HOME, a path leaving its folder) is refused by the preview too, and a file
 * the deleter leaves on disk is listed with its reason (`skip`).
 */
class RtorrentFiles
{
    /** [absolute path, bytes(, skip reason)] per file of a torrent the deleter would accept; CliError otherwise. */
    public static function one(RtorrentClient $c, $native, $what = '', ?array $folder = null)
    {
        list($dir, $root, $home) = $folder !== null ? $folder : self::folder($c, $native, $what);
        $out = [];
        foreach ((array) $c->call('f.multicall', [$native, '', 'f.path=', 'f.size_bytes=']) as $f) {
            $rel = (string) $f[0];
            if (self::leaves($rel)) {
                throw new CliError($what . 'torrent ' . strtolower($native) . " lists a file path that leaves its directory ($rel)",
                    'remove it without --with-data');
            }
            $why = self::unsafe($dir, $root, $home, $rel);
            $out[] = [$dir . '/' . $rel, isset($f[1]) ? (int) $f[1] : 0] + ($why !== '' ? [2 => $why] : []);
        }
        return $out;
    }

    /**
     * [native => files] of other torrents for the shared-file check: the folder from the list (d.multicall2
     * d.directory), then one f.multicall each, which rTorrent answers locally; rTorrent has no call that lists
     * several torrents' files. null for a torrent without an absolute folder or whose files rTorrent refused.
     */
    public static function many(RtorrentClient $c, array $records)
    {
        $out = [];
        foreach ($records as $r) {
            $n = (string) $r['native'];
            $dir = isset($c->dirs[$n]) ? $c->dirs[$n] : '';
            $out[$n] = null;
            if ($dir === '' || $dir[0] !== '/') {
                continue;
            }
            try {
                $out[$n] = array_map(function ($f) use ($dir) {
                    return [$dir . '/' . ltrim((string) $f[0], '/'), isset($f[1]) ? (int) $f[1] : 0];
                }, (array) $c->call('f.multicall', [$n, '', 'f.path=', 'f.size_bytes=']));
            } catch (CliError $e) {
                $out[$n] = null;
            }
        }
        return $out;
    }

    /** [d.directory, its real path, real $HOME], or the refusal: no absolute directory, or one outside $HOME. */
    public static function folder(RtorrentClient $c, $native, $what = '')
    {
        $dir = rtrim((string) $c->call('d.directory', [$native]), '/');
        $home = realpath($c->home);
        $root = $dir === '' || $dir[0] !== '/' ? false : realpath($dir);
        if ($root === false || $home === false || strpos($root . '/', rtrim($home, '/') . '/') !== 0) {
            throw new CliError($what . 'torrent ' . strtolower($native) . " has no directory inside your home ('$dir'),"
                . ' so --with-data will not delete it',
                'remove it without --with-data and delete the files yourself after checking them');
        }
        return [$dir, $root, $home];
    }

    /**
     * '' when $rel may be unlinked, else why not: no `..` or absolute path, the folder inside $HOME, no
     * symlinked directory on the way, the parent inside the torrent directory and $HOME.
     */
    public static function unsafe($dir, $root, $home, $rel)
    {
        if (self::leaves($rel)) {
            return 'its path leaves the torrent folder';
        }
        if ($root === false || $home === false || strpos($root . '/', rtrim($home, '/') . '/') !== 0) {
            return 'the torrent folder is not inside your home';
        }
        $parts = explode('/', $rel);
        array_pop($parts);
        $walk = $dir;
        foreach ($parts as $part) {
            $walk .= '/' . $part;
            if (is_link($walk)) {
                return 'symlinked directory not followed';
            }
        }
        $parent = realpath(dirname($dir . '/' . $rel));
        if ($parent !== false && (strpos($parent . '/', $root . '/') !== 0 || strpos($parent . '/', rtrim($home, '/') . '/') !== 0)) {
            return 'outside the torrent directory or your home';
        }
        return '';
    }

    public static function leaves($rel)
    {
        return $rel === '' || $rel[0] === '/' || in_array('..', explode('/', $rel), true);
    }
}
