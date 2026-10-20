<?php
/**
 * DelugeRepath: `repath` for Deluge (tools/torrent-clients/session-rewrite/README.md "Repath (live)", this
 * unit's USAGE.md). Deluge has no "point at a new location without touching the files" RPC, and this tool
 * never edits its on-disk session state: torrents.state is a Python pickle, the offline session-rewrite
 * tool refuses it outright (session-rewrite/DelugeRewrite.php). So, like the other two clients, repath
 * re-adds: refuses unless every file core.get_torrent_status reports already exists at --to with the exact
 * size (deluge/core/torrent.py "files" status key: path, size), removes the torrent keeping its data
 * (core.remove_torrent(id, remove_data=false); deluge/core/core.py), re-adds Deluge's OWN saved copy of the
 * .torrent file (no export RPC is needed: Deluge writes every added torrent's .torrent to
 * <config>/state/<lowercase hash>.torrent on add, deluge/core/torrentmanager.py, and never removes that
 * copy on a plain remove_torrent without remove_data) paused at the new download_location
 * (core.add_torrent_file(filename, filedumpBase64, options); deluge/core/torrent.py TorrentOptions:
 * download_location, add_paused), then core.force_recheck, then core.resume_torrents only when it was not
 * already paused. Deluge's category/label equivalent is the Label plugin, not core, and this unit does not
 * assume it is installed or enabled, so no label is carried in v1 — report this to the user if they need it.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class DelugeRepath
{
    public static function run(DelugeClient $c, $home, array $args, array $opt)
    {
        if (!isset($opt['to']) || !is_string($opt['to']) || $opt['to'] === '') {
            throw new CliError('repath needs --to <dir>; nothing was changed', 'repath <id>... --to <dir>');
        }
        $to = Torrents::dir($opt['to'], $home);
        $all = $c->torrents();
        $targets = Torrents::resolve($all, $args, 8);
        $out = [];
        foreach ($targets as $t) {
            $out[] = self::one($c, $home, $t, $to);
        }
        return $out;
    }

    private static function one(DelugeClient $c, $home, array $t, $to)
    {
        $native = $t['native']; // Deluge's own lowercase-hex id
        $status = (array) $c->rpc()->call('core.get_torrent_status', [$native, ['files', 'state']]);
        if (!$status) {
            throw new CliError('nothing repathed: torrent ' . $native . ' is gone from Deluge', 'run `list` again');
        }
        $missing = [];
        foreach ((array) (isset($status['files']) ? $status['files'] : []) as $f) {
            $f = (array) $f;
            $rel = isset($f['path']) ? (string) $f['path'] : '';
            $size = isset($f['size']) ? (int) $f['size'] : 0;
            if ($rel === '' || $rel[0] === '/' || strpos('/' . $rel . '/', '/../') !== false) {
                throw new CliError("nothing repathed: torrent {$t['id']} lists a file path that leaves its directory ("
                    . TorrentsSafety::untrustedList([$rel]) . ')', 'fix the torrent (remove and re-add it) before repathing');
            }
            $path = $to . '/' . $rel;
            clearstatcache(true, $path);
            if (!is_file($path)) {
                $missing[] = "$rel (missing, expected $size bytes)";
                continue;
            }
            $got = (int) filesize($path);
            if ($got !== $size) {
                $missing[] = "$rel (expected $size bytes, found $got)";
            }
        }
        if ($missing) {
            throw new CliError("nothing repathed for {$t['id']}: data is not at $to yet (" . TorrentsSafety::untrustedList($missing) . ')',
                'copy every file to the target directory first, with the exact sizes, then retry repath');
        }
        $wasPaused = isset($status['state']) && (string) $status['state'] === 'Paused'; // deluge/core/torrent.py:51-60 state words
        $torrentFile = rtrim($home, '/') . '/.config/deluge/state/' . $native . '.torrent';
        clearstatcache(true, $torrentFile);
        if (!is_file($torrentFile) || !is_readable($torrentFile)) {
            throw new CliError("nothing repathed for {$t['id']}: Deluge's own copy of the .torrent file is missing or unreadable ($torrentFile)",
                "the client's state directory is unusable; do not repath until Deluge reports this torrent correctly again");
        }
        $data = (string) @file_get_contents($torrentFile);
        if ($data === '') {
            throw new CliError("nothing repathed for {$t['id']}: Deluge's own .torrent file is empty or unreadable",
                'check the permissions of ~/.config/deluge/state');
        }
        $removed = $c->rpc()->call('core.remove_torrent', [$native, false]);
        if ($removed !== true) {
            $data = ''; // never kept in a local past this point (the "never log .torrent bytes" rule)
            throw new CliError("nothing repathed for {$t['id']}: Deluge did not confirm the removal (kept data)", 'try again; nothing was changed');
        }
        $addOpt = (object) ['download_location' => $to, 'add_paused' => true];
        $newId = $c->rpc()->call('core.add_torrent_file', [PathName::base($torrentFile), base64_encode($data), $addOpt]);
        $data = ''; // never kept in a local past this point (the "never log .torrent bytes" rule)
        if (!is_string($newId) || strtolower($newId) !== $native) {
            throw new CliError("nothing verified for {$t['id']}: Deluge did not report the same id after re-adding it",
                'check Deluge directly (`list`); the torrent may need to be re-added by hand with the data already at ' . $to);
        }
        $c->rpc()->call('core.force_recheck', [[$native]]);
        if (!$wasPaused) {
            $c->rpc()->call('core.resume_torrents', [[$native]]);
        }
        return ['id' => $t['id'], 'name' => $t['name'], 'to' => $to, 'was_paused' => $wasPaused, 'result' => 'recheck requested', 'verified' => true];
    }
}
