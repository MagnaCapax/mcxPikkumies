<?php
/**
 * RtorrentRepath: `repath` for rTorrent (tools/torrent-clients/session-rewrite/README.md "Repath (live)",
 * this unit's USAGE.md). Refuses unless every file the torrent lists already exists at --to with the exact
 * size rTorrent reports (Torrents::dir() also requires --to to already be a real folder inside $HOME), then:
 * d.stop (always; a no-op on an already-stopped torrent, as pause already relies on, Extras.php), the setter
 * that never renames anything, d.check_hash, d.start only when the torrent was started before.
 *
 * Which setter: d.directory.set vs d.directory_base.set (rakshasa/rtorrent tag v0.9.8, the version this
 * unit's other citations use, src/command_download.cc):
 *   CMD2_DL_STRING_V("d.directory.set",       std::bind(&apply_d_directory, ...));
 *   CMD2_DL_STRING_V("d.directory_base.set",  std::bind(&core::Download::set_root_directory, ...));
 * `apply_d_directory` (src/core/download.cc set_directory()) appends the torrent's sanitized name to the
 * given path for a multi-file torrent before calling set_root_directory itself; for a single-file torrent
 * it calls set_root_directory unchanged, same as d.directory_base.set. --to is already the torrent's exact
 * final directory (every file checked there first), so d.directory_base.set is the one setter that is
 * correct for BOTH shapes: it never appends or renames anything, where d.directory.set would nest a
 * multi-file torrent's data one level deeper than where it already is. (A newer rtorrent fork renamed this
 * setter d.directory.base.set, master branch 2026-10; v0.9.8, what PMSS and this unit target, uses the
 * underscore name.) d.directory and d.directory_base are the same getter (CMD2_ON_FL(root_dir) for both):
 * there is only one stored path, so either getter reads back what either setter wrote.
 */
class RtorrentRepath
{
    public static function run(RtorrentClient $c, array $args, array $opt)
    {
        if (!isset($opt['to']) || !is_string($opt['to']) || $opt['to'] === '') {
            throw new CliError('repath needs --to <dir>; nothing was changed', 'repath <id>... --to <dir>');
        }
        $to = Torrents::dir($opt['to'], $c->home);
        $all = $c->torrents(); // fresh list: current flags and directories, read once for every target below
        $targets = Torrents::resolve($all, $args, 8);
        $out = [];
        foreach ($targets as $t) {
            $out[] = self::one($c, $t, $to);
        }
        return $out;
    }

    private static function one(RtorrentClient $c, array $t, $to)
    {
        $native = $t['native'];
        $files = (array) $c->call('f.multicall', [$native, '', 'f.path=', 'f.size_bytes=']);
        $missing = [];
        foreach ($files as $f) {
            $rel = (string) $f[0];
            if (RtorrentFiles::leaves($rel)) {
                throw new CliError('nothing repathed: torrent ' . strtolower($native) . ' lists a file path that leaves its directory ('
                    . TorrentsSafety::untrustedList([$rel]) . ')', 'fix the torrent (remove and re-add it) before repathing');
            }
            $size = isset($f[1]) ? (int) $f[1] : 0;
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
        $flags = isset($c->flags[$native]) ? $c->flags[$native] : ['state' => 0];
        $wasStarted = (int) $flags['state'] === 1;
        $c->call('d.stop', [$native]);
        $c->call('d.directory_base.set', [$native, $to]);
        $c->call('d.check_hash', [$native]);
        if ($wasStarted) {
            $c->call('d.start', [$native]);
        }
        $now = rtrim((string) $c->call('d.directory', [$native]), '/');
        return ['id' => $t['id'], 'name' => $t['name'], 'to' => $to, 'directory' => $now, 'was_started' => $wasStarted,
            'result' => 'recheck requested', 'verified' => $now === $to];
    }
}
