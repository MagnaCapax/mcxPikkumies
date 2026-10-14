<?php
/**
 * Host: which seedbox host this is, and whether a local endpoint belongs to this user (ADR-0019).
 *
 * family(): pmss first (/etc/seedbox/config is traversable by every PMSS user), then the
 * hostname, then generic. The host table only guides where to look; tools read the user's own
 * app config first.
 *
 * ownsListener(): on shared servers every account can reach every 127.0.0.1 port, so before a
 * credential is sent the listening socket's owner is checked in /proc/net/tcp and tcp6.
 */
class Host
{
    /** Hostname suffix => family. "What.CD" in the 2026-10-14 request is read as Whatbox. */
    const SUFFIXES = [
        'feralhosting.com' => 'feral',
        'whatbox.ca'       => 'whatbox',
        'usbx.me'          => 'ultra',
        'bysh.me'          => 'bytesized',
        'seedhost.eu'      => 'seedhost',
        'seedbox.vip'      => 'rapidseedbox',
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
        $name = strtolower(trim($hostname !== null ? $hostname : (string) gethostname()));
        if ($hostname === null && strpos($name, '.') === false) {
            $full = strtolower(trim((string) @shell_exec('hostname -f 2>/dev/null'))); // can be slow; only when needed
            $name = $full !== '' ? $full : $name;
        }
        foreach (self::SUFFIXES as $suffix => $family) {
            if ($name === $suffix || substr($name, -strlen($suffix) - 1) === '.' . $suffix) {
                return $family;
            }
        }
        return 'generic';
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
     * The user's own Docker daemon socket: DOCKER_HOST when it is unix:// and the socket is this user's, else
     * /run/user/<uid>/docker.sock (the address PMSS exports, etc/skel/.bashrc:284) when it is this user's. '' otherwise:
     * tcp:// and a daemon run by root or another account are never used. The *ARR and media units share this rule.
     */
    public static function dockerSocket($uid)
    {
        $env = getenv('DOCKER_HOST');
        $path = $env !== false && $env !== '' ? (strncmp($env, 'unix://', 7) === 0 ? substr($env, 7) : '') : "/run/user/$uid/docker.sock";
        return $path !== '' && self::ownsSocket($path, $uid) ? $path : '';
    }

    /** True when $path is a unix socket owned by $uid. */
    public static function ownsSocket($path, $uid)
    {
        clearstatcache(true, $path);
        return @filetype($path) === 'socket' && @fileowner($path) === (int) $uid;
    }
}
