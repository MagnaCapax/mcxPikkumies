<?php
/**
 * SessionRewriteLocate: which directory and glob `rewrite` reads for each client.
 */
class SessionRewriteLocate
{
    const GLOB = ['rtorrent' => '*.rtorrent', 'qbittorrent' => '*.fastresume', 'transmission' => '*.resume'];

    /** The session/resume directory for $client under $home, or a CliError naming what was tried. */
    public static function dir($client, $home)
    {
        if ($client === 'rtorrent') {
            $dir = self::rtorrentDir($home);
        } elseif ($client === 'qbittorrent') {
            $dir = self::qbittorrentDir($home);
        } else {
            $dir = self::transmissionDir($home);
        }
        // A misconfigured session.path.set (e.g. "~" or ".") would otherwise make the whole account home
        // "the session directory": tar would back up and rewrite -- and tar --apply would overwrite -- the
        // entire home, not just the client's own session files (security review N6).
        $realDir = realpath($dir);
        $realHome = realpath($home);
        if ($realDir !== false && $realHome !== false && $realDir === rtrim($realHome, '/')) {
            throw new CliError("$client's session directory resolved to your home folder itself ($dir); refusing to back up or rewrite the whole home",
                "check $client's own configuration (rtorrent: session.path.set in ~/.rtorrent.rc); this is almost certainly a misconfiguration");
        }
        return $dir;
    }

    /**
     * ~/session (PMSS default: etc/seedbox/config/template.rtorrent.rc has `session.path.set = ~/session`,
     * read in the PMSS source for this unit), else the current `session.path.set = <path>`
     * line of ~/.rtorrent.rc or ~/.config/rtorrent/rtorrent.rc (the same two rc files
     * tools/torrent-clients/rtorrent/Locate.php tries), else a CliError naming both.
     */
    private static function rtorrentDir($home)
    {
        if (is_dir("$home/session")) {
            return "$home/session";
        }
        foreach (['.rtorrent.rc', '.config/rtorrent/rtorrent.rc'] as $rel) {
            $rc = (string) @file_get_contents("$home/$rel");
            if ($rc !== '' && preg_match('/^\s*session\.path\.set\s*=\s*(.+?)\s*$/m', $rc, $m)) {
                $dir = self::expand(trim($m[1]), $home);
                if (is_dir($dir)) {
                    return $dir;
                }
            }
        }
        throw new CliError("cannot find rtorrent's session directory (tried $home/session and session.path.set in ~/.rtorrent.rc, ~/.config/rtorrent/rtorrent.rc)",
            'run this on the account that actually has rtorrent installed, with its session directory already created');
    }

    /**
     * qBittorrent's BT_backup, Linux layout: the current path under $XDG_DATA_HOME (default ~/.local/share),
     * used only when that one does not exist and the legacy data/qBittorrent one does (qBittorrent master
     * src/base/profile_p.cpp, DefaultProfile::dataLocation()).
     */
    private static function qbittorrentDir($home)
    {
        $base = rtrim((string) getenv('XDG_DATA_HOME'), '/');
        $base = $base !== '' ? $base : "$home/.local/share";
        $current = "$base/qBittorrent/BT_backup";
        $legacy = "$base/data/qBittorrent/BT_backup";
        if (is_dir($current)) {
            return $current;
        }
        if (is_dir($legacy)) {
            return $legacy;
        }
        throw new CliError("cannot find qBittorrent's BT_backup directory (tried $current and the legacy $legacy)",
            'run this on the account that actually has qBittorrent installed');
    }

    /**
     * Transmission's resume directory: "resume" under the config dir on Linux (libtransmission/session.cc,
     * makeResumeDir()). PMSS's config dir is ~/.config/transmission-daemon (PMSS
     * scripts/lib/user/transmissionSettings.php, read in the PMSS source for this unit).
     * [UNVERIFIED for a non-default --config-dir install elsewhere; not auto-detected, out of scope here.]
     */
    private static function transmissionDir($home)
    {
        $dir = "$home/.config/transmission-daemon/resume";
        if (is_dir($dir)) {
            return $dir;
        }
        throw new CliError("cannot find Transmission's resume directory (tried $dir)",
            'this is the PMSS default; a custom --config-dir install is not auto-detected here');
    }

    /** rtorrent expands ~ (rak::path_expand); a relative path is read relative to $HOME. */
    private static function expand($v, $home)
    {
        if (strncmp($v, '~/', 2) === 0) {
            return $home . substr($v, 1);
        }
        return ($v !== '' && $v[0] === '/') ? $v : "$home/$v";
    }
}
