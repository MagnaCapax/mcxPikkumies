<?php
/**
 * RtorrentDeleter: `remove` for rTorrent. rTorrent has no delete-data command: d.erase only takes
 * the download out of the client (command_download.cc:731), and its default erased hook already
 * deletes the tied watch-dir .torrent (main.cc:282), so d.delete_tied is never called here.
 * ruTorrent's erasedata plugin adds erase hooks that delete the data when d.custom5 is set
 * (PMSS rutorrent/plugins/erasedata/init.php:10-22, update.php:21-57); a value left over from a failed
 * "delete with data" would turn a plain remove into a delete, so custom5 is cleared once every plan
 * stands, before any erase (d.custom5.set, command_download.cc:664-668, 756). With data, the unit deletes the torrent's own files itself, in
 * the order that frees the disk (erase first, so rtorrent holds no open file; then unlink), only
 * inside the torrent's directory and $HOME, never through a symlinked directory. DeletePlan has
 * already checked the size the user approved and that no other torrent shares a file.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class RtorrentDeleter
{
    public static function remove(RtorrentClient $c, array $natives, $withData)
    {
        $plans = [];
        foreach ($natives as $n) {
            $plans[$n] = $withData ? self::plan($c, $n) : ['record' => $c->record($n)];
        }
        foreach ($plans as $n => $p) { // only once every plan stands: a refused plan leaves every torrent as it was
            self::clearEraseData($c, $n, $p['record']['id']);
        }
        foreach ($natives as $n) {
            $c->call('d.erase', [$n]);
        }
        $left = array_flip(array_map('strval', (array) $c->call('download_list')));
        $out = [];
        foreach ($plans as $n => $p) {
            $r = ['id' => $p['record']['id'], 'name' => $p['record']['name'], 'disposal' => 'kept', 'errors' => []];
            if (isset($left[$n])) {
                $r['errors'][] = 'still in rtorrent after d.erase; nothing deleted';
            } elseif ($withData) {
                $r = self::unlinkAll($p, $r);
            }
            $out[] = $r;
        }
        return $out;
    }

    /** The torrent's directory and files, refused as the preview refuses it (RtorrentFiles): no folder inside $HOME, a path leaving it. */
    public static function plan(RtorrentClient $c, $native)
    {
        $record = $c->record($native);
        $folder = RtorrentFiles::folder($c, $native, 'nothing deleted: ');
        $files = [];
        foreach (RtorrentFiles::one($c, $native, 'nothing deleted: ', $folder) as $f) {
            $files[] = substr($f[0], strlen($folder[0]) + 1);
        }
        $multi = (bool) $c->call('d.is_multi_file', [$native]);
        return ['record' => $record, 'dir' => $folder[0], 'root' => $folder[1], 'home' => $folder[2], 'multi' => $multi, 'files' => $files];
    }

    /** d.custom5 emptied, or a refusal before anything is erased. */
    private static function clearEraseData(RtorrentClient $c, $native, $id)
    {
        if ((string) $c->call('d.custom5', [$native]) === '') {
            return;
        }
        $c->call('d.custom5.set', [$native, '']);
        if ((string) $c->call('d.custom5', [$native]) !== '') {
            throw new CliError("nothing removed: torrent $id carries ruTorrent's delete-data flag (d.custom5) and it could not be cleared", 'removing it now would delete its files: say so in your answer, and use --with-data only when the user\'s request covers the files going');
        }
    }

    /** Unlinks the planned files; a file refused by RtorrentFiles::unsafe() stays as an orphan, an unlink that fails is an error too. */
    private static function unlinkAll(array $p, array $r)
    {
        $orphans = [];
        $parents = [];
        foreach ($p['files'] as $rel) {
            $path = $p['dir'] . '/' . $rel;
            if (RtorrentFiles::unsafe($p['dir'], $p['root'], $p['home'], $rel) !== '') {
                $orphans[] = $path; // left on purpose; `show --files` marked it `skip`
                continue;
            }
            if (@lstat($path) === false) {
                continue; // already gone
            }
            $why = is_dir($path) && !is_link($path) ? 'is a directory, not a file' : (@unlink($path) ? '' : 'unlink failed');
            if ($why !== '') {
                $r['errors'][] = "$path: $why";
                $orphans[] = $path;
                continue;
            }
            for ($d = dirname($rel); $d !== '.' && $d !== '/' && $d !== ''; $d = dirname($d)) {
                $parents[$d] = substr_count($d, '/');
            }
        }
        arsort($parents); // deepest first
        foreach (array_keys($parents) as $d) {
            self::rmdirEmpty($p['dir'] . '/' . $d);
        }
        if ($p['multi'] && PathName::base($p['root']) === PathName::base((string) $p['record']['name']) && $p['root'] !== $p['home']) {
            self::rmdirEmpty($p['dir']); // the torrent's own folder (d.directory.set appends the name, command_download.cc:181-188)
        }
        $r['disposal'] = $orphans ? 'partial' : 'deleted';
        $r['orphans'] = $orphans;
        return $r;
    }

    private static function rmdirEmpty($dir)
    {
        if (is_dir($dir) && !is_link($dir) && count((array) @scandir($dir)) === 2) {
            @rmdir($dir);
        }
    }
}
