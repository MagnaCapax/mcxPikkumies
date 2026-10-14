<?php
/**
 * DeletePlan: what `remove --with-data` would delete, checked again at delete time
 * (tools/torrent-clients/README.md "Deleting data"). None of the clients refuses to delete a file
 * another torrent still uses (qBittorrent torrentcontentremover, Deluge remove_data, rTorrent has
 * no delete at all), so the check is done here, for every client, before the client is called.
 * Only torrents in the same client are compared: a client never sees another client's torrents.
 */
class DeletePlan
{
    /**
     * Files of $targets (size, hard links, on disk, `skip` when the unit will not delete it, `same_as` when
     * an earlier path of the batch is the same file) and the other torrents sharing any of them. A file is
     * the same file when stat() gives the same device and inode: another spelling, a symlinked folder, a
     * symlink or a hard link to it all count. Every other torrent in the client is compared, wherever its
     * folder is (a cross-seed elsewhere may link to the files); one whose files cannot be listed is `unlisted`.
     */
    public static function build(TorrentClient $c, array $all, array $targets)
    {
        $files = [];
        $owner = []; // identity => index of the first path of the batch with it
        $inodes = [];
        foreach ($targets as $t) {
            foreach ($c->files($t['native']) as $f) {
                $st = @lstat($f[0]);
                $e = ['torrent' => $t['id'], 'file' => $f[0], 'bytes' => $st ? (int) $st['size'] : (int) $f[1],
                    'links' => $st ? (int) $st['nlink'] : 0, 'on_disk' => $st !== false, 'shared_with' => []];
                if (isset($f[2]) && (string) $f[2] !== '') {
                    $files[] = $e + ['skip' => (string) $f[2]];
                    continue;
                }
                $key = self::identity($f[0]);
                if (isset($owner[$key])) {
                    $e['same_as'] = $files[$owner[$key]]['file']; // deleted too, counted once
                } else {
                    $owner[$key] = count($files);
                }
                DeleteFreed::add($inodes, $key, $t['id'], $f[0]);
                $files[] = $e;
            }
        }
        $ids = array_column($targets, 'id');
        $others = array_values(array_filter($all, function ($o) use ($ids) {
            return !in_array($o['id'], $ids, true);
        }));
        $lists = $others ? (array) $c->filesOf($others) : [];
        $shared = [];
        $unlisted = [];
        $seen = []; // path => identity: each listed path is stat()ed once
        foreach ($others as $o) {
            if (!isset($lists[$o['native']]) || !is_array($lists[$o['native']])) {
                $unlisted[$o['id']] = $o['name'];
                continue;
            }
            foreach ($lists[$o['native']] as $f) {
                $p = (string) $f[0];
                $key = isset($seen[$p]) ? $seen[$p] : ($seen[$p] = self::identity($p));
                if (isset($owner[$key]) && !in_array($o['id'], $files[$owner[$key]]['shared_with'], true)) {
                    $files[$owner[$key]]['shared_with'][] = $o['id'];
                    $shared[$files[$owner[$key]]['torrent']][$o['id']] = $o['name'];
                }
            }
        }
        $bytes = 0;
        foreach ($files as $f) {
            $bytes += isset($f['skip']) || isset($f['same_as']) ? 0 : $f['bytes'];
        }
        // A client that moves the files to its trash frees nothing until the trash is emptied (qBittorrent 5 by default):
        // the preview says so, as the delete does (DeleteRun::outcome).
        $disposal = method_exists($c, 'disposal') ? $c->disposal() : 'deleted';
        $freed = $disposal === 'trashed' ? 0 : array_sum(DeleteFreed::byTorrent($inodes, $ids, function () {
            return true;
        }));
        return ['files' => $files, 'inodes' => $inodes, 'bytes' => $bytes, 'freed' => $freed, 'shared' => $shared,
            'unlisted' => $unlisted, 'expect' => self::label($bytes) . ':' . $bytes, 'disposal' => $disposal];
    }

    public static function summary(array $plan)
    {
        $s = ['files' => count($plan['files']), 'bytes' => $plan['bytes'], 'freed_bytes' => $plan['freed'],
            'expect' => $plan['expect'], 'refused' => $plan['shared'] ? array_keys($plan['shared']) : []];
        $skipped = count(array_filter($plan['files'], function ($f) {
            return isset($f['skip']);
        }));
        if ($skipped) {
            $s['skipped_files'] = $skipped; // listed with `skip`: left on disk, not in bytes or freed_bytes
        }
        if (isset($plan['disposal']) && $plan['disposal'] === 'trashed') {
            $s['disposal'] = 'trashed';
            $s['note'] = 'the client moves the files to its trash: no disk space is freed until the trash is emptied';
        }
        $hints = $s['refused'] ? ['a file is shared with another torrent in this client: remove those torrents in the same call,'
            . ' or remove without --with-data'] : [];
        if ($plan['unlisted']) {
            $s['unchecked'] = array_keys($plan['unlisted']);
            $hints[] = self::unlistedText($plan) . '; --with-data is refused until they can be listed';
        }
        if ($hints) {
            $s['hint'] = implode('; ', $hints);
        }
        return $s;
    }

    /** Why the shared-file check is incomplete: the other torrents whose files the client did not list. */
    public static function unlistedText(array $plan)
    {
        $n = array_map(function ($id, $name) {
            return "$id ($name)";
        }, array_keys($plan['unlisted']), $plan['unlisted']);
        return 'the client did not list the files of ' . count($n) . ' other torrent(s), so a shared file cannot be ruled out: '
            . implode(', ', array_slice($n, 0, 5)) . (count($n) > 5 ? ', ...' : '');
    }

    /** '' when $given (the `<size>:<bytes>` show printed, or bare bytes) is exactly the plan's byte total, else why not. */
    public static function mismatch($given, array $plan)
    {
        if (!preg_match('/^(?:[0-9.,]+\s*[a-z]*:)?([0-9]+)$/i', trim((string) $given), $m)) {
            return "--expect $given is not the value `show --files` printed ({$plan['expect']}): give it as printed, size and exact bytes";
        }
        return (string) (int) $m[1] === (string) $plan['bytes'] ? '' : "the files now total {$plan['expect']}, not $given";
    }

    /** The file's identity: device and inode when it exists (symlinks followed), else its path. */
    public static function identity($path)
    {
        clearstatcache(true, $path);
        $st = @stat($path);
        return $st ? 'inode:' . $st['dev'] . ':' . $st['ino'] : 'path:' . $path;
    }

    /** The size as the user sees it, without spaces so it can be passed unquoted: 41.2GiB. */
    public static function label($bytes)
    {
        return str_replace(' ', '', CliHuman::bytes($bytes));
    }
}
