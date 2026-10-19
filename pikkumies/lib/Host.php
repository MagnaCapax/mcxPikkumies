<?php
/**
 * Host: which seedbox host/software stack this is, and whether a local endpoint belongs to this
 * user (ADR-0019, ADR-0023).
 *
 * TABLE is the one place provider and stack facts live: hostname suffixes, filesystem/PATH
 * markers, where that family's account-owned logs sit, and how it exposes resource limits. Each
 * row also carries the source the row was built from and the date it was checked there (`verified`
 * is null when the row rests on research rather than a direct read of the provider's own page this
 * session — ADR-0023 addendum). The table only guides where to look; tools read the user's own app
 * config first (ADR-0019).
 *
 * family() and stack() share one row matcher (rowMatches()): family() walks the provider rows by
 * hostname suffix, with a marker as a fallback signal (ultra's app-stats, never a hostname a provider
 * does not control); stack() walks the stack rows by filesystem marker only (no stack here has a
 * fixed hostname). pmss is checked directly against $pmssDir in both, exactly as before this table
 * existed, so every existing `Host::family()` caller keeps its signature and its 'pmss' result.
 *
 * ownsListener(): on shared servers every account can reach every 127.0.0.1 port, so before a
 * credential is sent the listening socket's owner is checked in /proc/net/tcp and tcp6.
 */
class Host
{
    /**
     * name => {type: provider|stack, suffixes: [hostname suffix, ...], markers: [filesystem path
     * (starts with "/") or an executable name found on PATH/~/bin, ...], logGlobs: [account-owned
     * log path relative to $HOME, ...], limitsSource: the unprivileged command/file that exposes
     * resource limits, or null, source: the URL the row was built from, verified: the date that URL
     * was read directly (this session), or null when the row rests on research of that URL rather
     * than a direct read, docker: the provider's facts for the user's own Docker daemon, or null when
     * it documents none (ADR-0034): {daemon: 'platform' when the provider starts and watches it,
     * ports: [low, high] preferred host-port window, inbound_dropped: ports the firewall drops from
     * outside, recovery: the provider page for a wedged daemon, source, verified (as above)}}.
     *
     * "What.CD" in the 2026-10-14 request is read as Whatbox (What.CD was a closed music tracker;
     * no detectable platform of that name exists, ADR-0019).
     */
    const TABLE = [
        'pmss' => [
            'type'         => 'stack',
            'suffixes'     => [],
            'markers'      => ['/etc/seedbox/config'],
            'logGlobs'     => ['.lighttpd/access.log', '.lighttpd/error.log', 'rTorrentLog'],
            'limitsSource' => 'userShowLimits',
            'source'       => 'https://github.com/MagnaCapax/PMSS/blob/main/etc/skel/bin/userShowLimits',
            'verified'     => '2026-10-15',
            // Read in the PMSS tree at c8ba1754 on 2026-10-17: the platform starts each enabled account's
            // rootless daemon (docs/docker-help.md "Rootless Docker is started automatically ... by cron
            // watchdogs"); the service-port allocator owns 2000-38000 (scripts/lib/portManager.php:15-16), so
            // 38001-49999 is a preference window for container ports, not a clear one (outbound connections
            // may hold any port above 38000: probe before use); the firewall drops inbound 8080 and 8112
            // (scripts/util/setupNetwork.php:121-122). `recovery` is a link, not a command: the documented
            // wedged-daemon step and the watchdog's own process match disagree (2026-10-17 review), so the
            // tool shows the page and checks afterwards that no daemon process of the account is left.
            'docker'       => [
                'daemon'          => 'platform',
                'ports'           => [38001, 49999],
                'inbound_dropped' => [8080, 8112],
                'recovery'        => 'https://github.com/MagnaCapax/PMSS/blob/main/docs/docker-help.md#troubleshooting',
                'source'          => 'https://github.com/MagnaCapax/PMSS/blob/main/docs/docker-help.md',
                'verified'        => '2026-10-17',
            ],
        ],
        'feral' => [
            'type'         => 'provider',
            'suffixes'     => ['feralhosting.com'],
            'markers'      => [],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://www.feralhosting.com/wiki/getting-started/newbie-info',
            'verified'     => '2026-10-15', // orchestrator re-checked this page directly this session
            'docker'       => null,
        ],
        'whatbox' => [
            'type'         => 'provider',
            'suffixes'     => ['whatbox.ca'],
            'markers'      => [],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://whatbox.ca/wiki/SSH', // "Enter ssh user@server.whatbox.ca"
            'verified'     => '2026-10-15',
            'docker'       => null,
        ],
        'ultra' => [
            'type'         => 'provider',
            'suffixes'     => ['usbx.me'],
            // app-stats is documented under Ultra's "unofficial SSH utilities", so it may be something a
            // user installs rather than a default: a secondary signal only, the hostname suffix decides.
            'markers'      => ['app-stats'],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://docs.ultra.cc/unofficial-ssh-utilities/disktraffic-usage-command',
            'verified'     => '2026-10-15', // orchestrator re-checked this page directly this session
            'docker'       => null,
        ],
        'bytesized' => [
            'type'         => 'provider',
            'suffixes'     => ['bytesized-hosting.com', 'bysh.me'], // the first is verified; bysh.me is research only
            'markers'      => [],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://bytesized-hosting.com/pages/ssh', // "servername.bytesized-hosting.com"; bysh.me is research only
            'verified'     => '2026-10-15',
            'docker'       => null,
        ],
        'seedhost' => [
            'type'         => 'provider',
            'suffixes'     => ['seedhost.eu'],
            'markers'      => [],
            'logGlobs'     => [],
            'limitsSource' => null,
            // Seedhost's own KB documents changing the server name on a Dedicated server (standard
            // Debian/CentOS hostname steps, not a Seedhost-specific marker), so a suffix match here
            // only reliably identifies a Shared App Hosting slot, not a Dedicated/renamed box.
            'source'       => 'https://www.seedhost.eu/client-area/knowledgebase/247/File-transfer-between-servers.html', // "server.seedhost.eu with your destination server hostname"
            'verified'     => '2026-10-15',
            'docker'       => null,
        ],
        'hostingbydesign' => [
            'type'         => 'provider', // Seedbox.io's successor
            'suffixes'     => ['hostingby.design'],
            'markers'      => [],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://docs.hostingby.design/application-hosting/getting-started/how-do-i-connect', // "ssh dan@app02.hostingby.design"
            'verified'     => '2026-10-15',
            'docker'       => null,
        ],
        // NO rapidseedbox row: seedbox.vip is confirmed only for app-proxy endpoints
        // (rt-*.a.seedbox.vip), not as the SSH-reachable shell hostname (providers.md).
        'swizzin' => [
            'type'         => 'stack',
            'suffixes'     => [],
            'markers'      => ['/etc/swizzin'],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://swizzin.ltd/dev/structure',
            'verified'     => null, // marker path not found on this page when re-checked 2026-10-15; research only
            'docker'       => null,
        ],
        'quickbox' => [
            'type'         => 'stack',
            'suffixes'     => [],
            'markers'      => ['/srv/rutorrent'],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://github.com/Stonedestroyer/QuickBox',
            'verified'     => '2026-10-15', // /srv/rutorrent appears on this page
            'docker'       => null,
        ],
        'saltbox' => [
            'type'         => 'stack',
            'suffixes'     => [],
            'markers'      => ['/srv/git/saltbox'],
            'logGlobs'     => [],
            'limitsSource' => null,
            'source'       => 'https://docs.saltbox.dev/saltbox/basics/paths/',
            'verified'     => null, // marker path not found on this page when re-checked 2026-10-15; research only
            'docker'       => null,
        ],
    ];

    /**
     * Addresses a local connection can reach, in /proc/net/tcp(6) notation (IPv4 is the 32-bit value
     * in host byte order, so 127.x.y.z ends in 7F): all of 127.0.0.0/8, 0.0.0.0, ::1, :: and the
     * IPv4-mapped forms of those.
     */
    const LOCAL_RE = '/^(?:[0-9A-F]{6}7F|0{8}|0{24}01000000|0{32}|0{16}FFFF0000(?:[0-9A-F]{6}7F|0{8}))$/';

    public static function family($pmssDir = '/etc/seedbox/config', $hostname = null)
    {
        if (is_dir($pmssDir)) {
            return 'pmss';
        }
        $name = self::resolveHostname($hostname);
        foreach (self::TABLE as $key => $row) {
            if ($row['type'] === 'provider' && self::rowMatches($row, $name, '')) {
                return $key;
            }
        }
        return 'generic';
    }

    /**
     * The software stack this account sits in: pmss, a known self-hosted installer, or 'none'.
     * $root is a test seam prefixed to every absolute marker path (swizzin/quickbox/saltbox markers
     * are real root-owned paths like /etc/swizzin that a test cannot create on the test machine);
     * production callers never pass it, so every marker is checked at its real, absolute path.
     */
    public static function stack($pmssDir = '/etc/seedbox/config', $root = '')
    {
        if (is_dir($pmssDir)) {
            return 'pmss';
        }
        foreach (self::TABLE as $key => $row) {
            if ($row['type'] === 'stack' && $key !== 'pmss' && self::rowMatches($row, '', $root)) {
                return $key;
            }
        }
        return 'none';
    }

    /** The log globs TABLE documents for $family or $stack, relative to $HOME; derived on use, never cached (ADR-0023). */
    public static function logGlobs($family, $stack)
    {
        foreach ([$stack, $family] as $key) {
            if (isset(self::TABLE[$key])) {
                return self::TABLE[$key]['logGlobs'];
            }
        }
        return [];
    }

    /** Lowercased hostname -f, resolved only when $hostname is not given and the short name has no dot. */
    private static function resolveHostname($hostname)
    {
        $name = strtolower(trim($hostname !== null ? $hostname : (string) gethostname()));
        if ($hostname === null && strpos($name, '.') === false) {
            $full = strtolower(trim((string) @shell_exec('timeout 3 hostname -f 2>/dev/null'))); // can be slow on broken DNS; bounded, and only when needed
            $name = $full !== '' ? $full : $name;
        }
        return $name;
    }

    /** True when $hostname matches one of the row's suffixes, or any of its markers is present. */
    private static function rowMatches(array $row, $hostname, $root)
    {
        foreach ($row['suffixes'] as $suffix) {
            if ($hostname === $suffix || substr($hostname, -strlen($suffix) - 1) === '.' . $suffix) {
                return true;
            }
        }
        foreach ($row['markers'] as $marker) {
            if (self::markerPresent($marker, $root)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A marker is a filesystem path (starts with "/", checked under $root) or an executable name on PATH or in
     * ~/bin. Public for Generate's category gate (a `docker` CLI present, ADR-0034).
     */
    public static function markerPresent($marker, $root = '')
    {
        if ($marker === '') {
            return false;
        }
        if ($marker[0] === '/') {
            return file_exists($root . $marker);
        }
        $home = (string) getenv('HOME');
        if ($home !== '' && is_file("$home/bin/$marker") && is_executable("$home/bin/$marker")) {
            return true;
        }
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("$dir/$marker") && is_executable("$dir/$marker")) {
                return true;
            }
        }
        return false;
    }

    /**
     * 'own' when the listener on $port belongs to an allowed uid ($uids: one uid or a list),
     * 'other' when any listener on it belongs to another account, 'none' when nothing listens,
     * 'unknown' when /proc/net cannot be read. Listeners on loopback and wildcard addresses count,
     * plus those on the addresses in $dests (the IP actually connected to, e.g. this machine's
     * public address). $tables (test seam): list of file contents, false for an unreadable file.
     */
    public static function ownsListener($port, $uids, ?array $tables = null, array $dests = [])
    {
        $allowed = array_map('intval', (array) $uids);
        $extra = [];
        foreach ($dests as $ip) {
            $extra = array_merge($extra, self::procAddresses($ip));
        }
        if ($tables === null) {
            $tables = [@file_get_contents('/proc/net/tcp'), @file_get_contents('/proc/net/tcp6')];
        }
        if (!array_filter($tables, 'is_string')) {
            return 'unknown';
        }
        $hexPort = sprintf('%04X', (int) $port);
        $seen = 'none';
        foreach ($tables as $table) {
            foreach (explode("\n", (string) $table) as $line) {
                $f = preg_split('/\s+/', trim($line));
                if (count($f) < 8 || $f[3] !== '0A' || strpos($f[1], ':') === false) {
                    continue; // header, short line, or not LISTEN
                }
                list($addr, $p) = explode(':', $f[1]);
                $addr = strtoupper($addr);
                if (strtoupper($p) !== $hexPort || (!preg_match(self::LOCAL_RE, $addr) && !in_array($addr, $extra, true))) {
                    continue;
                }
                if (!in_array((int) $f[7], $allowed, true)) {
                    return 'other';
                }
                $seen = 'own';
            }
        }
        return $seen;
    }

    /** $ip in /proc/net/tcp(6) notation: each 32-bit word in host (little-endian) byte order. */
    public static function procAddresses($ip)
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return [];
        }
        $hex = '';
        foreach (str_split($bin, 4) as $word) {
            $hex .= strtoupper(bin2hex(strrev($word)));
        }
        return strlen($bin) === 4 ? [$hex, '0000000000000000FFFF0000' . $hex] : [$hex];
    }

    /**
     * This uid's home folder: $HOME when it is a folder this uid owns (as it always is when nobody changed it),
     * else the passwd entry (posix_getpwuid). An injected HOME=/home/other is never used: that folder is not ours.
     * The *ARR units (ArrLocate::home), jellyfin and the guard share this rule.
     */
    public static function home($uid, $env = null)
    {
        $env = rtrim((string) ($env === null ? getenv('HOME') : $env), '/');
        if ($env !== '' && @is_dir($env) && @fileowner($env) === (int) $uid) {
            return $env;
        }
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid((int) $uid) : false;
        return is_array($pw) && isset($pw['dir']) && is_string($pw['dir']) ? rtrim($pw['dir'], '/') : '';
    }

    /**
     * The user's own Docker daemon socket: DOCKER_HOST when it is unix:// and the socket is this user's, else
     * the first of dockerSocketCandidates() that is this user's. '' otherwise: tcp:// and a daemon run by root or
     * another account are never used. The *ARR, media and containers units share this rule. $candidates (test
     * seam): socket paths tried instead of the defaults; DOCKER_HOST still comes first.
     */
    public static function dockerSocket($uid, ?array $candidates = null)
    {
        $env = getenv('DOCKER_HOST');
        if ($env !== false && $env !== '') {
            $path = strncmp($env, 'unix://', 7) === 0 ? substr($env, 7) : '';
            return $path !== '' && self::ownsSocket($path, $uid) ? $path : '';
        }
        foreach ($candidates !== null ? $candidates : self::dockerSocketCandidates($uid) as $path) {
            if (self::ownsSocket($path, $uid)) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Where an account's own daemon listens when DOCKER_HOST is unset: /run/user/<uid>/docker.sock (the
     * rootless address PMSS exports, etc/skel/.bashrc:284), and for uid 0 also /var/run/docker.sock, which
     * is then root's own daemon (ADR-0034). For any other uid that socket is root's, never used.
     */
    public static function dockerSocketCandidates($uid)
    {
        return (int) $uid === 0 ? ['/run/user/0/docker.sock', '/var/run/docker.sock'] : ["/run/user/$uid/docker.sock"];
    }

    /** The docker facts TABLE documents for $stack or $family (null when none), like logGlobs(). */
    public static function dockerFacts($family, $stack)
    {
        foreach ([$stack, $family] as $key) {
            if (isset(self::TABLE[$key])) {
                return self::TABLE[$key]['docker'];
            }
        }
        return null;
    }

    /** True when $path is a unix socket owned by $uid. */
    public static function ownsSocket($path, $uid)
    {
        clearstatcache(true, $path);
        return @filetype($path) === 'socket' && @fileowner($path) === (int) $uid;
    }
}
