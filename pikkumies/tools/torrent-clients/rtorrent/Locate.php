<?php
/**
 * RtorrentLocate: where this user's rTorrent listens, or a CliError naming why it cannot be used
 * (tools/torrent-clients/README.md "Where each client is found"; ADR-0019).
 * - PMSS: the unix socket ~/.rtorrent.socket (PMSS etc/seedbox/config/template.rtorrent.rc:28).
 * - Elsewhere: the network.scgi.open_local / scgi_local (unix socket) or network.scgi.open_port /
 *   scgi_port (TCP) line of the rc file (rtorrent 0.9.8 command_network.cc:295-296; the old names
 *   are redirects, main.cc:408-409). A TCP port is used only when this user owns its listener.
 */
require_once dirname(__DIR__, 3) . '/lib/Host.php';

class RtorrentLocate
{
    /** rc files tried outside PMSS: Feral and SeedHost use ~/.rtorrent.rc, Whatbox ~/.config/rtorrent/rtorrent.rc. */
    const RC_FILES = ['.rtorrent.rc', '.config/rtorrent/rtorrent.rc'];
    /** Where PMSS installs the binary (PMSS scripts/lib/update/apps/rtorrent.php:78, etc/skel/www/rtorrentRestart.php:17). */
    const BINARIES = ['/usr/local/bin/rtorrent', '/usr/bin/rtorrent'];

    /** 'unix:///path' or 'tcp://127.0.0.1:port'. $tables: test seam for /proc/net/tcp(6). */
    public static function address($family, $home, $uid, ?array $tables = null)
    {
        if ($family === 'pmss') {
            return self::socket("$home/.rtorrent.socket", $uid, $home, '~/.rtorrent.socket');
        }
        $found = self::fromRc($home);
        if ($found === null) {
            throw new CliError(self::installed() ? 'rtorrent is not enabled: no rc file opens an SCGI socket or port (looked in ~/' . implode(', ~/', self::RC_FILES) . ')'
                : 'rtorrent is not installed (no rtorrent binary and no rc file in ~/' . implode(', ~/', self::RC_FILES) . ')', 'add `network.scgi.open_local = ~/.rtorrent.socket` to the rc file (back it up first) and restart rtorrent; when rtorrent is not installed, report it in your answer');
        }
        list($kind, $value, $rc) = $found;
        if ($kind === 'local') {
            return self::socket($value, $uid, $home, "$value (from $rc)", $rc);
        }
        if (!preg_match('/^(?:(localhost|127\.[0-9.]+|0\.0\.0\.0|\[::1\]|::1|\[::\])?):([0-9]{1,5})$/', $value, $m) || (int) $m[2] < 1 || (int) $m[2] > 65535) {
            throw new CliError("rtorrent's SCGI port in $rc is not on this machine ($value)", 'this tool talks only to an rtorrent on 127.0.0.1 or a unix socket');
        }
        $port = (int) $m[2];
        $owner = Host::ownsListener($port, $uid, $tables);
        if ($owner === 'other') {
            throw new CliError("port $port on this machine belongs to another account; not connecting", "the rtorrent rc file ($rc) points at a port you do not own");
        }
        if ($owner === 'unknown') {
            throw new CliError("cannot check who owns port $port (/proc/net is not readable); not connecting", 'use a unix socket (network.scgi.open_local) instead');
        }
        if ($owner === 'none') {
            throw new CliError("rtorrent is not running: nothing listens on port $port (from $rc)", 'start rtorrent the way your provider says (`restart` explains)');
        }
        return 'tcp://' . (strpos($m[1], ':') !== false ? '[::1]' : '127.0.0.1') . ":$port";
    }

    /** $rc: the rc file that named the socket; with it, a missing binary means stopped (or run elsewhere), not absent. */
    private static function socket($path, $uid, $home, $label, $rc = '')
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            if (!self::installed() && $rc !== '') {
                throw new CliError("rtorrent is not running: found $rc but $path does not exist, and no rtorrent binary is on PATH",
                    'start rtorrent the way your provider says (it may run in a container or under another name)');
            }
            if (!self::installed()) {
                throw new CliError("rtorrent is not installed (no $label and no rtorrent binary)", 'the provider installs rtorrent: report it in your answer');
            }
            if (is_file("$home/.rtorrentDisable")) {
                throw new CliError('rtorrent is not enabled: it was stopped in the panel (~/.rtorrentDisable)', 'the user starts it again with the start button in the panel');
            }
            throw new CliError("rtorrent is not running: $label does not exist", 'start it with `restart`');
        }
        if (!Host::ownsSocket($path, $uid)) {
            throw new CliError(@filetype($path) === 'socket' ? "$label belongs to another account; not connecting" : "$label is not a socket", 'the SCGI socket must be a socket owned by your own account');
        }
        return 'unix://' . $path;
    }

    /** [local|port, value, rc file] from the last SCGI line, following one level of import/try_import. */
    public static function fromRc($home)
    {
        $found = null;
        foreach (self::RC_FILES as $rel) {
            $rc = "$home/$rel";
            if (!is_file($rc)) {
                continue;
            }
            $files = [$rc];
            foreach (self::lines($rc) as $l) {
                if (preg_match('/^(?:try_)?import\s*=\s*(.+)$/', $l, $m)) {
                    $files[] = self::path(self::unquote($m[1]), $home);
                }
            }
            foreach ($files as $f) {
                foreach (is_file($f) ? self::lines($f) : [] as $l) {
                    if (preg_match('/^(network\.scgi\.open_local|scgi_local|network\.scgi\.open_port|scgi_port)\s*=\s*(.+)$/', $l, $m)) {
                        $local = in_array($m[1], ['network.scgi.open_local', 'scgi_local'], true);
                        $v = self::unquote($m[2]);
                        $found = [$local ? 'local' : 'port', $local ? self::path($v, $home) : $v, '~/' . ltrim(substr($f, strlen($home)), '/')];
                    }
                }
            }
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    public static function installed()
    {
        foreach (array_merge(self::BINARIES, array_map(function ($d) {
            return rtrim($d, '/') . '/rtorrent';
        }, array_filter(explode(':', (string) getenv('PATH'))))) as $bin) {
            if (@is_file($bin) && @is_executable($bin)) {
                return true;
            }
        }
        return false;
    }

    private static function lines($file)
    {
        $out = [];
        foreach (explode("\n", (string) @file_get_contents($file, false, null, 0, 1048576)) as $l) {
            $l = trim($l);
            if ($l !== '' && $l[0] !== '#') {
                $out[] = $l;
            }
        }
        return $out;
    }

    private static function unquote($v)
    {
        $v = trim(preg_replace('/\s+#.*$/', '', trim($v)));
        return (strlen($v) > 1 && $v[0] === '"' && substr($v, -1) === '"') ? substr($v, 1, -1) : $v;
    }

    /** rtorrent expands ~ (rak::path_expand); a relative path is read relative to $HOME, where PMSS and most hosts start it. */
    private static function path($v, $home)
    {
        if (strncmp($v, '~/', 2) === 0) {
            return $home . substr($v, 1);
        }
        return ($v !== '' && $v[0] === '/') ? $v : "$home/$v";
    }
}
