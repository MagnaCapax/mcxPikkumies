<?php
/**
 * QbitRepath: `repath` for qBittorrent (tools/torrent-clients/session-rewrite/README.md "Repath (live)",
 * this unit's USAGE.md). qBittorrent has no "point at a new save path without touching the files" API, so
 * repath re-adds instead, without moving any data itself: refuses unless every file torrents/files lists
 * already exists at --to with the exact size, then exports the .torrent (GET torrents/export, qBittorrent
 * master src/webui/api/torrentscontroller.cpp exportAction(): application/x-bittorrent bytes, 404 when the
 * torrent is gone, 409 when it has no metadata yet, e.g. a magnet still fetching), removes the torrent
 * keeping its data (torrents/delete, deleteFiles=false), re-adds the exported .torrent paused with
 * savepath=--to (torrents/add, the same multipart QbitAdd.php uses), restores its category and tags with
 * torrents/setCategory and torrents/addTags (qBittorrent master torrentscontroller.cpp setCategoryAction(),
 * addTagsAction(); torrents/add itself also takes category/tags fields directly, but QbitAdd.php's shared
 * add() does not forward them, and extending it for one caller is not worth it: KISS), torrents/recheck,
 * then resumes only when it was not paused before. The exported bytes touch disk only as a temp file under
 * local/state/tmp (0600, 0700 dir), deleted once the re-add has fully succeeded; on any failure after the
 * removal it is kept instead (security review N4 -- it is the only remaining copy once the old torrent is
 * gone, and every failure's hint names its path so the user can add it back by hand). Never put in a
 * CliError message or a record directly (the safety-context rule against logging .torrent bytes).
 */
class QbitRepath
{
    public static function run(QbittorrentClient $c, array $args, array $opt)
    {
        if (!isset($opt['to']) || !is_string($opt['to']) || $opt['to'] === '') {
            throw new CliError('repath needs --to <dir>; nothing was changed', 'repath <id>... --to <dir>');
        }
        $to = Torrents::dir($opt['to'], $c->api->ep->home);
        $all = $c->torrents();
        $targets = Torrents::resolve($all, $args, 8);
        $out = [];
        foreach ($targets as $t) {
            $out[] = self::one($c, $t, $to);
        }
        return $out;
    }

    private static function one(QbittorrentClient $c, array $t, $to)
    {
        $native = $t['native'];
        $rows = $c->rows([$native]);
        if (!$rows) {
            throw new CliError('nothing repathed: torrent ' . strtolower($native) . ' is gone from qBittorrent', 'run `list` again');
        }
        $row = $rows[0];
        $files = (array) $c->api->json('torrents/files', ['hash' => $native]);
        $missing = [];
        foreach ($files as $f) {
            $f = (array) $f;
            $name = isset($f['name']) ? (string) $f['name'] : '';
            if ($name === '' || $name[0] === '/' || strpos('/' . $name . '/', '/../') !== false) {
                throw new CliError("nothing repathed: torrent {$t['id']} lists a file path that leaves its directory ("
                    . TorrentsSafety::untrustedList([$name]) . ')', 'fix the torrent (remove and re-add it) before repathing');
            }
            $size = isset($f['size']) ? (int) $f['size'] : 0;
            $path = $to . '/' . $name;
            clearstatcache(true, $path);
            if (!is_file($path)) {
                $missing[] = "$name (missing, expected $size bytes)";
                continue;
            }
            $got = (int) filesize($path);
            if ($got !== $size) {
                $missing[] = "$name (expected $size bytes, found $got)";
            }
        }
        if ($missing) {
            throw new CliError("nothing repathed for {$t['id']}: data is not at $to yet (" . TorrentsSafety::untrustedList($missing) . ')',
                'copy every file to the target directory first, with the exact sizes, then retry repath');
        }
        $wasActive = $t['state'] !== 'paused';
        $bytes = $c->api->text('torrents/export', ['hash' => $native], true);
        if ($bytes === '' || $bytes[0] !== 'd') { // 404 (gone) and 409 (no metadata yet) both answer with non-bencode text, not a .torrent
            throw new CliError("nothing repathed for {$t['id']}: qBittorrent could not export the .torrent file",
                'a magnet-added torrent with no metadata yet cannot be exported; wait until it has metadata, then retry');
        }
        $tmpDir = Config::localDir() . '/state/tmp';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true)) {
            throw new CliError('nothing repathed: cannot create ' . Config::shown($tmpDir), 'check the permissions of ' . Config::shown(Config::localDir()));
        }
        $tmpFile = $tmpDir . '/repath-' . strtolower($native) . '-' . bin2hex(random_bytes(6)) . '.torrent';
        if (@file_put_contents($tmpFile, $bytes, LOCK_EX) === false) {
            throw new CliError('nothing repathed: could not write a temporary .torrent file', 'check the free space and permissions of ' . Config::shown($tmpDir));
        }
        @chmod($tmpFile, 0600);
        $bytes = ''; // never kept in a local past this point (the "never log .torrent bytes" rule)
        // Kept (never unlinked) on any failure from here on: it is the ONLY copy once the torrent below is
        // removed, and the hint on every failure below tells the user to re-add it by hand (security review
        // N4 -- the old `finally { unlink() }` deleted this exact file on the failure path it was needed for).
        $removed = (new QbitChange($c))->remove([$native], false);
        if ($removed && $removed[0]['errors']) {
            throw new CliError("nothing repathed for {$t['id']}: qBittorrent did not confirm the removal (" . implode('; ', $removed[0]['errors'])
                . '); the exported .torrent was kept at ' . Config::shown($tmpFile),
                'nothing else was changed; try again, or add ' . Config::shown($tmpFile) . ' back by hand if the torrent is now gone');
        }
        $added = (new QbitAdd($c))->add([$tmpFile], ['dir' => $to, 'paused' => true]);
        $result = isset($added['records'][0]['result']) ? $added['records'][0]['result'] : '';
        if ($result !== 'added' && $result !== 'already in the client') {
            throw new CliError("nothing repathed for {$t['id']}: the torrent could not be re-added after it was removed ($result);"
                . ' the exported .torrent was kept at ' . Config::shown($tmpFile),
                'qBittorrent now has neither the old nor a new copy; add ' . Config::shown($tmpFile) . ' back by hand (`add --dir ' . $to
                . '`), then delete that file yourself once it is listed again');
        }
        $category = isset($row['category']) ? (string) $row['category'] : '';
        $tags = isset($row['tags']) ? (string) $row['tags'] : '';
        if ($category !== '') {
            $c->api->post('torrents/setCategory', ['hashes' => $native, 'category' => $category]);
        }
        if ($tags !== '') {
            $c->api->post('torrents/addTags', ['hashes' => $native, 'tags' => $tags]);
        }
        @unlink($tmpFile);
        $c->recheck([$native]);
        if ($wasActive) {
            $c->resume([$native]);
        }
        $now = $c->torrents([$native]);
        $nowRow = $now ? $now[0] : null;
        return ['id' => $t['id'], 'name' => $t['name'], 'to' => $to, 'path' => $nowRow ? $nowRow['path'] : null,
            'was_active' => $wasActive, 'result' => 'recheck requested', 'verified' => $nowRow !== null];
    }
}
