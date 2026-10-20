<?php
/**
 * RtorrentExport: `export <id>` for rTorrent (lib/TorrentExport.php checks it and saves or hands it over). rTorrent keeps each torrent's
 * file in its session folder as <INFO-HASH>.torrent, upper-case hex (rtorrent 0.9.8 core/download_store.cc:218-219,
 * rak/string_manip.h value_to_hexchar), written from the torrent's own bencode without the session data
 * (download_store.cc:166-168). The folder is asked from the running client (`session.path`, command_local.cc:313), or
 * read from the rc file's `session.path.set` / `session` line when the answer is empty (PMSS: `~/session`,
 * etc/seedbox/config/template.rtorrent.rc:23).
 */
class RtorrentExport
{
    public static function verb(TorrentClient $c, array $args, array $opt)
    {
        return TorrentExport::verb($c, $args, function (RtorrentClient $c, array $t) {
            $dir = rtrim((string) $c->call('session.path'), '/');
            if ($dir === '') {
                $dir = self::fromRc($c->home);
            }
            if ($dir === '') {
                return ['', 'rTorrent has no session folder (no session.path); without one it keeps no .torrent files'];
            }
            return TorrentExport::firstFile(["$dir/" . strtoupper($t['native']) . '.torrent', "$dir/" . strtolower($t['native']) . '.torrent']);
        }, 'rtorrent');
    }

    /** The session folder named in the rc file, '' when none; ~ and relative paths read from $home as rTorrent does. */
    public static function fromRc($home)
    {
        $dir = '';
        foreach (RtorrentLocate::RC_FILES as $rel) {
            foreach (explode("\n", (string) @file_get_contents("$home/$rel", false, null, 0, 1048576)) as $l) {
                if (preg_match('/^\s*(session\.path\.set|session)\s*=\s*"?([^"#]+?)"?\s*(#.*)?$/', $l, $m)) {
                    $v = trim($m[2]);
                    $dir = strncmp($v, '~/', 2) === 0 ? $home . substr($v, 1) : ($v !== '' && $v[0] === '/' ? $v : "$home/$v");
                }
            }
            if ($dir !== '') {
                return rtrim($dir, '/');
            }
        }
        return '';
    }
}
