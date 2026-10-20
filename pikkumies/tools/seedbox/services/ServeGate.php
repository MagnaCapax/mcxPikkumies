<?php
/**
 * ServeGate: what the forced command (serve.php) lets a peer's key do on this box (ADR-0084). Decisions with no I/O beyond
 * realpath and the .torrent check (rootsFor() also reads the settings and the rTorrent rc): tokenize SSH_ORIGINAL_COMMAND
 * without any shell, then allow exactly `ping`, a whitelisted `tool <category>/<tool> <verb> [args]`, or a read-only rsync
 * pull (`rsync --server --sender ...`). Every folder a peer names (rsync paths, --dir, --to) must lie inside a DATA ROOT
 * (~/data, each client's configured save folder, services.serveRoots), never the home itself, a dot-folder or the Pikkumies
 * tree: a peer never reads this box's keys (local/secrets, ~/.ssh) or writes into them. services.php `remote` uses the
 * same whitelist before it connects.
 */
class ServeGate
{
    /** category/tool => verbs a peer may run. `export` prints a .torrent for moves (torrent-clients units). */
    const TOOLS = [
        'torrent-clients/rtorrent' => 'list show stats traffic add pause resume recheck repath remove export',
        'torrent-clients/qbittorrent' => 'list show stats traffic add pause resume recheck repath remove export',
        'torrent-clients/deluge' => 'list show stats traffic add pause resume recheck repath remove export',
        'torrent-clients/transmission' => 'list show stats traffic add pause resume recheck repath remove export',
        'torrent-clients/find' => 'find',
        'system/system-status' => 'show account health',
        'system/telemetry' => 'series list',
    ];
    /** Verbs that change something on the box (the caller states an intent for them). */
    const CHANGING = ['add', 'pause', 'resume', 'recheck', 'repath', 'remove'];
    /** Options a peer may pass: these take a value (next word or =value) ... */
    const VALUED = ['dir', 'to', 'expect', 'state', 'search', 'sort', 'limit', 'offset', 'fields', 'days', 'bucket'];
    /** ... and these are on/off. --all and --service are left out: a peer never makes this box reach further boxes. */
    const FLAGS = ['paused', 'files', 'trackers', 'with-data', 'reverse'];
    const ARG_RE = '/^[A-Za-z0-9._:@%+=,-]{1,256}\z/';
    /** rsync short options rrsync accepts, without s (protect-args) and K, L, k (follow links out of the directory). */
    const RSYNC_SHORT = 'ACDEHIJNORSUWXbcdglmnopqrtuvxyz';
    const RSYNC_LONG_FLAGS = 'server sender append existing from0 group hard-links ignore-errors ignore-existing ignore-missing-args ignore-times inplace links list-only msgs2stderr no-msgs2stderr new-compress old-compress no-W no-implied-dirs no-r no-relative no-specials numeric-ids one-file-system open-noatime owner partial perms recursive safe-links size-only specials stats times use-qsort munge-links mkpath fake-super';
    const RSYNC_LONG_VALUED = 'block-size bwlimit checksum-choice checksum-seed compress-choice compress-level debug iconv info max-size min-size modify-window skip-compress timeout stderr files-from';
    /** rc files of rTorrent outside PMSS (RtorrentLocate::RC_FILES); PMSS's template sets directory.default.set = ~/data. */
    const RC_FILES = ['.rtorrent.rc', '.config/rtorrent/rtorrent.rc'];

    /** Words of the command, split on single spaces; any character outside the safe set refuses the whole request. */
    public static function tokenize($cmd)
    {
        $cmd = (string) $cmd;
        if ($cmd === '' || strlen($cmd) > 8192) {
            throw new CliError('empty or overlong request', 'the request is one line of plain words');
        }
        if (!preg_match('#^[A-Za-z0-9._:/@%+=,~\- ]+\z#', $cmd)) {
            throw new CliError('the request holds a quote, a shell character, a control character or a non-ASCII byte', 'only letters, digits, spaces and . _ : / @ % + = , ~ - are accepted; nothing is passed to a shell');
        }
        return array_values(array_filter(explode(' ', $cmd), 'strlen'));
    }

    /** ['kind' => ping|tool|rsync, ...] for allowed words; CliError for anything else. $roots: rootsFor()'s real paths. */
    public static function decide(array $words, $allowDelete, $home, array $roots)
    {
        if ($words === ['ping']) {
            return ['kind' => 'ping'];
        }
        if ($words && $words[0] === 'tool') {
            return self::tool(array_slice($words, 1), $allowDelete, $home, $roots);
        }
        if ($words && $words[0] === 'rsync') {
            return ['kind' => 'rsync'] + self::rsync($words, $home, $roots);
        }
        throw new CliError('not an allowed request: ' . ($words ? $words[0] : '(empty)'), 'allowed: ping, tool <category>/<tool> <verb> [args], rsync --server --sender (read-only pull)');
    }

    /** The whitelist check alone (also used by the client before it connects). */
    public static function allowed($unit, $verb)
    {
        return isset(self::TOOLS[$unit]) && in_array($verb, explode(' ', self::TOOLS[$unit]), true);
    }

    /**
     * The data roots of this box as real paths: ~/data, rTorrent's directory.default.set in its rc file, the default save
     * folders of qBittorrent, Deluge and Transmission (clientDirs()), and the entries of services.serveRoots in local/config.php (the person's file, no overlay). Each must be an existing folder inside the
     * home that passes safe(); others are dropped.
     */
    public static function rootsFor($home, $configFile)
    {
        $want = ['data'];
        foreach (self::RC_FILES as $rc) {
            $text = is_file("$home/$rc") ? (string) @file_get_contents("$home/$rc", false, null, 0, 262144) : '';
            if (preg_match_all('/^[ \t]*directory\.default\.set[ \t]*=[ \t]*"?([^"\s#]+)/m', $text, $m)) {
                $want = array_merge($want, $m[1]);
            }
        }
        $want = array_merge($want, self::clientDirs($home));
        $config = Config::fileConfig($configFile);
        $set = isset($config['services']['serveRoots']) ? $config['services']['serveRoots'] : [];
        $want = array_merge($want, is_string($set) ? explode(',', $set) : (array) $set);
        $roots = [];
        foreach ($want as $w) {
            $w = trim((string) $w);
            $p = substr($w, 0, 2) === '~/' ? $home . substr($w, 1) : ($w !== '' && $w[0] === '/' ? $w : "$home/$w");
            $real = $w !== '' ? realpath($p) : false;
            if ($real !== false && is_dir($real) && $real !== rtrim($home, '/') && self::safe($real, $home)) {
                $roots[$real] = true;
            }
        }
        return array_keys($roots);
    }

    /**
     * The default save folders of the other clients, from each one's own config file (where the client units find it):
     * - qBittorrent ~/.config/qBittorrent/qBittorrent.conf (QbitEndpoint::CONF): [BitTorrent] Session\DefaultSavePath
     *   (qBittorrent src/base/bittorrent/sessionimpl.cpp, BITTORRENT_SESSION_KEY(u"DefaultSavePath")) and the older
     *   [Preferences] Downloads\SavePath (PMSS etc/seedbox/config/template.qbittorrent.conf:46);
     * - Deluge ~/.config/deluge/core.conf (DelugeLocate.php, read with DelugeLocate::config): download_location and
     *   move_completed_path (PMSS etc/seedbox/config/template.deluge.core.conf:16,47);
     * - Transmission ~/.config/transmission-daemon/settings.json: download-dir, or download_dir (PMSS may write snake_case).
     * Unchecked here; rootsFor() applies the root rules (so PMSS Deluge's ~/.delugeUnfinished is dropped as a dot-folder).
     */
    public static function clientDirs($home)
    {
        $out = [];
        $qbit = "$home/.config/qBittorrent/qBittorrent.conf";
        foreach (['BitTorrent' => 'Session\DefaultSavePath', 'Preferences' => 'Downloads\SavePath'] as $section => $key) {
            $v = self::iniValue($qbit, $section, $key);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        $deluge = "$home/.config/deluge/core.conf";
        if (is_file($deluge)) {
            require_once dirname(__DIR__, 2) . '/torrent-clients/deluge/DelugeLocate.php';
            $c = DelugeLocate::config($deluge);
            foreach (['download_location', 'move_completed_path'] as $k) {
                if (is_array($c) && isset($c[$k]) && is_string($c[$k])) {
                    $out[] = $c[$k];
                }
            }
        }
        $tr = is_file("$home/.config/transmission-daemon/settings.json")
            ? json_decode((string) @file_get_contents("$home/.config/transmission-daemon/settings.json", false, null, 0, 1048576), true) : null;
        foreach (['download-dir', 'download_dir'] as $k) {
            if (is_array($tr) && isset($tr[$k]) && is_string($tr[$k])) {
                $out[] = $tr[$k];
            }
        }
        return $out;
    }

    /** One value of an ini-style file (qBittorrent.conf: keys keep their backslash), '' when absent. */
    private static function iniValue($file, $section, $key)
    {
        $in = false;
        foreach (explode("\n", is_file($file) ? (string) @file_get_contents($file, false, null, 0, 1048576) : '') as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '[') {
                $in = trim($line, '[]') === $section;
            } elseif ($in && strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                if (trim($k) === $key) {
                    return trim($v);
                }
            }
        }
        return '';
    }

    /** True for a real path inside $home with no path part starting with a dot and outside ~/pikkumies. */
    public static function safe($real, $home)
    {
        $home = rtrim($home, '/');
        if (strpos($real, $home . '/') !== 0) {
            return false;
        }
        $parts = explode('/', substr($real, strlen($home) + 1));
        foreach ($parts as $part) {
            if ($part === '' || $part[0] === '.') {
                return false;
            }
        }
        return $parts[0] !== 'pikkumies';
    }

    /** The data root that holds the real path $real (safe() too), or null. */
    public static function rootOf($real, $home, array $roots)
    {
        if ($real === false || !self::safe($real, $home)) {
            return null;
        }
        foreach ($roots as $r) {
            if ($real === $r || strpos($real, $r . '/') === 0) {
                return $r;
            }
        }
        return null;
    }

    private static function tool(array $w, $allowDelete, $home, array $roots)
    {
        if (count($w) < 2 || !self::allowed($w[0], $w[1])) {
            throw new CliError('not a whitelisted tool and verb: ' . implode(' ', array_slice($w, 0, 2)), 'allowed: ' . json_encode(self::TOOLS));
        }
        list($unit, $verb) = $w;
        $args = [];
        $positional = [];
        for ($i = 2; $i < count($w); $i++) {
            $a = $w[$i];
            if (strncmp($a, '--', 2) === 0) {
                $eq = strpos($a, '=');
                $name = $eq === false ? substr($a, 2) : substr($a, 2, $eq - 2);
                if (in_array($name, self::FLAGS, true) && $eq === false) {
                    if ($name === 'with-data' && (!$allowDelete || $verb !== 'remove')) {
                        throw new CliError('--with-data is refused: this key may not delete data on this box', 'deleting here is the box owner\'s choice for this key (services accept --allow-delete); report the refusal, do not work around it');
                    }
                    $args[] = $a;
                    continue;
                }
                if (!in_array($name, self::VALUED, true)) {
                    throw new CliError("option --$name is not allowed here", 'allowed options: --' . implode(', --', array_merge(self::VALUED, self::FLAGS)));
                }
                $value = $eq !== false ? substr($a, $eq + 1) : (isset($w[$i + 1]) ? $w[++$i] : '');
                $value = in_array($name, ['dir', 'to'], true) ? self::dataDir($value, $home, $roots) : self::arg($value);
                array_push($args, "--$name", $value);
                continue;
            }
            $positional[] = $verb === 'add' && $a === '-' ? '-' : self::arg($a);
        }
        if ($verb === 'add' && $positional !== ['-']) {
            throw new CliError('add takes only a .torrent on stdin: add - [--paused] [--dir <dir>]', 'URLs, magnets and file paths are not accepted from another box');
        }
        return ['kind' => 'tool', 'unit' => $unit, 'verb' => $verb, 'args' => array_merge($positional, $args), 'stdin_torrent' => $verb === 'add'];
    }

    private static function arg($a)
    {
        if (!preg_match(self::ARG_RE, (string) $a) || $a[0] === '-') {
            throw new CliError('not a plain argument: ' . $a, 'ids and words only: letters, digits and . _ : @ % + = , - (no path, no leading dash)');
        }
        return $a;
    }

    /** An existing folder inside a data root (~/x, relative to the home, or absolute), as its real path. */
    public static function dataDir($v, $home, array $roots)
    {
        $v = (string) $v;
        $p = substr($v, 0, 2) === '~/' ? $home . substr($v, 1) : ($v !== '' && $v[0] === '/' ? $v : "$home/$v");
        $real = $v !== '' ? realpath($p) : false;
        if ($real === false || !is_dir($real) || self::rootOf($real, $home, $roots) === null) {
            throw new CliError("not an existing folder inside a data root of this box: $v", 'data roots: ~/data, rTorrent\'s download folder and services.serveRoots; never the home itself, a dot-folder or ~/pikkumies');
        }
        return $real;
    }

    public static function inside($real, $home)
    {
        $home = rtrim($home, '/');
        return $real === $home || strpos($real, $home . '/') === 0;
    }

    /**
     * The names inside a .torrent sent to `add` (info.name, every files[].path part, v2 file tree names): a part that is
     * empty, starts with a dot or holds a slash, backslash or NUL refuses it, so a torrent cannot land as ~/.ssh/x or a
     * dotfile. Uses the shared Bencode reader of torrent-clients/lib.
     */
    public static function torrentNames($s)
    {
        $top = Bencode::dict($s, 0);
        $info = isset($top['info']) ? Bencode::dict($s, $top['info'][0]) : [];
        if (!$info || (!isset($info['name']) && !isset($info['name.utf-8']))) {
            throw new CliError('not a .torrent file (no info dictionary with a name)', 'send the .torrent itself');
        }
        $names = [];
        foreach (['name', 'name.utf-8'] as $k) {
            if (isset($info[$k])) {
                $names[] = Bencode::string($s, $info[$k][0]);
            }
        }
        if (isset($info['files'])) {
            foreach (self::listAt($s, $info['files'][0]) as $at) {
                $file = Bencode::dict($s, $at);
                foreach (['path', 'path.utf-8'] as $k) {
                    foreach (isset($file[$k]) ? self::listAt($s, $file[$k][0]) : [] as $p) {
                        $names[] = Bencode::string($s, $p);
                    }
                }
            }
        }
        if (isset($info['file tree'])) {
            self::treeNames($s, $info['file tree'][0], $names, 0);
        }
        foreach ($names as $n) {
            if ($n === '' || $n[0] === '.' || strpbrk($n, "/\\\0") !== false) {
                throw new CliError('the torrent holds a name that is empty, starts with a dot or holds a slash: ' . json_encode(substr($n, 0, 80)),
                    'such a torrent is not added from another box');
            }
        }
        return $names;
    }

    /** Start offsets of the elements of the bencoded list at $at. */
    private static function listAt($s, $at)
    {
        $out = [];
        if (!isset($s[$at]) || $s[$at] !== 'l') {
            return $out;
        }
        for ($i = $at + 1; isset($s[$i]) && $s[$i] !== 'e'; $i = $end) {
            $end = Bencode::skip($s, $i);
            if ($end < 0) {
                throw new CliError('not a valid .torrent file', 'send the .torrent itself');
            }
            $out[] = $i;
        }
        return $out;
    }

    private static function treeNames($s, $at, array &$names, $depth)
    {
        if ($depth > 64) {
            throw new CliError('the torrent\'s file tree is too deep', 'such a torrent is not added from another box');
        }
        foreach (Bencode::dict($s, $at) as $key => $range) {
            if ($key !== '') {
                $names[] = (string) $key;
                self::treeNames($s, $range[0], $names, $depth + 1);
            }
        }
    }

    /**
     * A read-only pull: `rsync --server --sender <options> . <paths>`. Returns ['opts', 'paths', 'root', 'rel']: every path
     * lies in ONE data root (rel: the same paths relative to it, for rrsync and the own check). Pushes, deletes,
     * protect-args (-s: paths would arrive over the protocol, unchecked) and link-following options are refused; rrsync
     * refuses -s as well (rrsync(1), short_disabled = 's'). A --files-from list comes over the protocol and is not seen
     * here: rsync sanitizes each listed path below the path argument (flist.c send_file_list), which is checked.
     */
    public static function rsync(array $w, $home, array $roots)
    {
        if (!isset($w[1]) || $w[1] !== '--server') {
            throw new CliError('rsync must be --server', 'run rsync on the receiving box; this box only sends');
        }
        if (!isset($w[2]) || $w[2] !== '--sender') {
            throw new CliError('copying INTO this box is refused: data moves are pulls', 'run the copy on the box that receives the data (services remote on the destination)');
        }
        $opts = ['--server', '--sender'];
        $long = explode(' ', self::RSYNC_LONG_FLAGS);
        $valued = explode(' ', self::RSYNC_LONG_VALUED);
        for ($i = 3; $i < count($w) && $w[$i] !== '.'; $i++) {
            $a = $w[$i];
            if (preg_match('/^-([A-Za-z]*)(e[0-9]*\.[A-Za-z0-9_]*)?\z/', $a, $m) && strncmp($a, '--', 2) !== 0) {
                if (strspn($m[1], self::RSYNC_SHORT) !== strlen($m[1])) {
                    throw new CliError("rsync option $a is refused here", 'protect-args (-s) and link-following options (-L, -k, -K) are not accepted');
                }
                $opts[] = $a;
                continue;
            }
            if (preg_match('/^-[B@]-?[0-9]+\z/', $a)) {
                $opts[] = $a;
                continue;
            }
            if (!preg_match('/^--([a-zA-Z0-9-]+)(=(.*))?\z/', $a, $m)) {
                throw new CliError("rsync word $a is not an option", 'the request must end with . and the paths');
            }
            if (in_array($m[1], $long, true) && !isset($m[2])) {
                $opts[] = $a;
                continue;
            }
            if (!in_array($m[1], $valued, true)) {
                throw new CliError("rsync option --{$m[1]} is refused here", 'deletes, removes, batch and log files, temp and backup dirs are not accepted');
            }
            $value = isset($m[2]) ? $m[3] : (isset($w[$i + 1]) ? $w[++$i] : '');
            if ($m[1] === 'files-from' && $value !== '-') {
                throw new CliError('--files-from must be - (the list comes from the receiving side)', 'rsync sanitizes each listed path (flist.c send_file_list)');
            }
            $opts[] = "--{$m[1]}=$value";
        }
        if (!isset($w[$i]) || $w[$i] !== '.') {
            throw new CliError('rsync request without the . placeholder', 'not an rsync server command line');
        }
        $paths = array_slice($w, $i + 1);
        if (!$paths) {
            throw new CliError('rsync request without a path', 'name a path inside a data root (e.g. data/<folder>); the home itself is never served');
        }
        $root = null;
        $rel = [];
        foreach ($paths as $p) {
            list($r, $relPath) = self::rsyncPath($p, $home, $roots);
            if ($root !== null && $r !== $root) {
                throw new CliError('one pull reads from one data root', 'pull each data root separately');
            }
            $root = $r;
            $rel[] = $relPath;
        }
        return ['opts' => $opts, 'paths' => $paths, 'root' => $root, 'rel' => $rel];
    }

    /** [data root, path relative to it (trailing slash kept)] for one path a peer asked for. */
    private static function rsyncPath($p, $home, array $roots)
    {
        $slash = substr($p, -1) === '/' || substr($p, -2) === '/.';
        $base = preg_replace('#(/\.?)+\z#', '', $p);
        foreach (explode('/', ltrim($base, '/')) as $part) {
            if ($part === '' || $part[0] === '.') {
                throw new CliError("path $p holds an empty part, a dot-part or ..", 'name paths inside a data root, without dot-folders');
            }
        }
        $abs = $base[0] === '/' ? $base : "$home/$base";
        $real = realpath($abs);
        $root = $real !== false ? self::rootOf($real, $home, $roots) : null;
        if ($root === null) {
            throw new CliError("path $p is not inside a data root of this box (or does not exist)", 'only files under ~/data, rTorrent\'s download folder or services.serveRoots can be pulled; never keys, ~/.ssh or ~/pikkumies');
        }
        return [$root, ($real === $root ? '.' : substr($real, strlen($root) + 1)) . ($slash ? '/' : '')];
    }
}
