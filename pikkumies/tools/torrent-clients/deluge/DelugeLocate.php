<?php
/**
 * DelugeLocate: where this user's deluge-web listens and the secret it takes (README.md of this
 * unit, "Where Deluge is found"). PMSS first, then the user's own web.conf. Errors name the cause:
 * not installed, not enabled, not running, port of another account. Nothing here contacts Deluge.
 */
class DelugeLocate
{
    /** PMSS panel binaries (PMSS etc/skel/www/scriptsInc.php:1121). */
    const PMSS_BINARIES = ['/usr/bin/deluged', '/usr/local/bin/deluged'];

    /**
     * ['url', 'port', 'secret', 'daemon_port' (0 = unknown), 'how'], or CliError. $tables: test seam for /proc/net/tcp(6).
     */
    public static function find($home, $family, $uid, ?array $tables = null)
    {
        $web = self::config("$home/.config/deluge/web.conf");
        $port = 0;
        $how = '';
        if ($family === 'pmss') {
            // PMSS writes the web port to ~/.delugeWebPort and web.conf (PMSS scripts/lib/user/deluge.php:99-118, 184-187);
            // ~/.delugePort is the daemon port, kept last as the legacy fallback PMSS itself reads (scripts/lib/user/torrentPort.php:75-78).
            foreach (['.delugeWebPort' => self::portFile("$home/.delugeWebPort"), 'web.conf' => self::port($web, 'port'), '.delugePort' => self::portFile("$home/.delugePort")] as $src => $p) {
                if ($p > 0) {
                    list($port, $how) = [$p, "PMSS ~/$src"];
                    break;
                }
            }
        } elseif (($p = self::port($web, 'port')) > 0) {
            list($port, $how) = [$p, '~/.config/deluge/web.conf'];
        }
        if ($port === 0) {
            throw self::absent($home, $family, 'no deluge-web port found (' . ($family === 'pmss' ? '~/.delugeWebPort, web.conf' : '~/.config/deluge/web.conf') . ')');
        }
        if ($web !== null && !empty($web['https'])) {
            throw new CliError('deluge-web is set to https with its own certificate; this tool talks to it over http only', 'turn https off in ~/.config/deluge/web.conf, or use the web UI');
        }
        $owner = Host::ownsListener($port, $uid, $tables);
        if ($owner === 'other') {
            throw new CliError("port $port belongs to another account; not connecting", "the port in $how is not this user's deluge-web; check the file");
        }
        if ($owner === 'unknown') {
            throw new CliError("cannot check who owns port $port (/proc/net is not readable); not connecting", 'run the tool on the seedbox itself');
        }
        if ($owner === 'none') {
            throw self::absent($home, $family, "deluge-web is not running (nothing listens on port $port from $how)", $how);
        }
        $secret = self::secret($home);
        if ($secret === '') {
            throw new CliError('no localclient line in ~/.config/deluge/auth', 'Deluge writes it on first start; on PMSS the panel setup does');
        }
        $daemon = $family === 'pmss' ? self::portFile("$home/.delugePort") : 0; // PMSS scripts/lib/user/deluge.php:157,187
        if ($daemon === 0) {
            $daemon = self::port(self::config("$home/.config/deluge/core.conf"), 'daemon_port');
        }
        return ['url' => "http://127.0.0.1:$port/json", 'port' => $port, 'secret' => $secret, 'daemon_port' => $daemon, 'how' => $how];
    }

    /**
     * The error for a client that cannot be reached: not installed, not enabled, or $why. $found: the config that
     * named the port. $binaries (test seam, default unchanged): the fixed PMSS binary paths installed() checks
     * instead of self::PMSS_BINARIES; production callers never pass it.
     */
    public static function absent($home, $family, $why, $found = '', ?array $binaries = null)
    {
        if (!self::installed($family, $binaries) && $found !== '') { // a config but no binary: stopped, or run elsewhere (a container)
            return new CliError("$why; found $found but no deluged binary is on PATH", 'start Deluge the way your provider documents it');
        }
        if (!self::installed($family, $binaries)) {
            return new CliError('Deluge is not installed on this machine', 'the provider installs it (on PMSS Deluge comes with the server): report it in your answer');
        }
        if ($family === 'pmss' && !file_exists("$home/.delugeEnable")) {
            // The PMSS watchdog starts Deluge only while ~/.delugeEnable exists (PMSS scripts/cron/checkDelugeInstances.php:18; etc/skel/www/deluge.php:6).
            return new CliError('Deluge is not enabled', 'the user enables Deluge in the PMSS web panel (Deluge: start); the watchdog keeps it running after that');
        }
        $hint = $family === 'pmss' ? 'the PMSS watchdog starts it within about a minute; or run `restart`' : 'start Deluge the way your provider documents it';
        return new CliError($why, $hint);
    }

    /** $binaries (test seam, default unchanged): the fixed PMSS binary paths to check instead of self::PMSS_BINARIES. */
    public static function installed($family, ?array $binaries = null)
    {
        $dirs = array_filter(explode(':', (string) getenv('PATH')), 'strlen');
        $fixed = $family === 'pmss' ? ($binaries !== null ? $binaries : self::PMSS_BINARIES) : [];
        $paths = array_merge($fixed, array_map(function ($d) {
            return rtrim($d, '/') . '/deluged';
        }, $dirs));
        foreach ($paths as $p) {
            if (is_file($p) && is_executable($p)) {
                return true;
            }
        }
        return false;
    }

    /** The localclient secret, matched the way PMSS does (scripts/lib/user/delugePasswords.php:51). */
    public static function secret($home)
    {
        $text = (string) @file_get_contents("$home/.config/deluge/auth");
        return preg_match('/^localclient:([^:\r\n]+):[0-9]+$/m', $text, $m) ? $m[1] : '';
    }

    public static function portFile($path)
    {
        $v = trim((string) @file_get_contents($path));
        return ctype_digit($v) && (int) $v > 0 && (int) $v < 65536 ? (int) $v : 0;
    }

    private static function port($config, $key)
    {
        return is_array($config) && isset($config[$key]) && is_int($config[$key]) && $config[$key] > 0 && $config[$key] < 65536 ? $config[$key] : 0;
    }

    /**
     * A Deluge config file: a {"file","format"} header object followed by the config object
     * (PMSS etc/seedbox/config/template.deluge.web.conf; PMSS scripts/lib/user/delugeManagedConfig.php:75).
     * Returns the config object, or null.
     */
    public static function config($path)
    {
        $raw = (string) @file_get_contents($path);
        $depth = 0;
        $in = false;
        $esc = false;
        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $ch = $raw[$i];
            if ($in) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $in = false;
                }
                continue;
            }
            if ($ch === '"') {
                $in = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}' && --$depth === 0) {
                $second = json_decode(substr($raw, $i + 1), true);
                return is_array($second) ? $second : null;
            }
        }
        return null;
    }
}
