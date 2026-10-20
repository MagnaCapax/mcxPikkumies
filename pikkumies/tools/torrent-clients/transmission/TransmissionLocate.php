<?php
/**
 * TransmissionLocate: where this user's Transmission daemon serves its RPC (README.md "Where
 * Transmission is found"). Reads ~/.config/transmission-daemon/settings.json for every provider --
 * Transmission's own file, not a PMSS invention. PMSS always manages a per-user unix socket there
 * (PMSS development/PMSS/scripts/lib/user/transmissionSettings.php:21-24, docs/adr/0108): mode 0700,
 * rpc-url "/user-<user>/transmission/". Elsewhere falls back to Transmission's own defaults, rpc-url
 * "/transmission/" and port 9091 (rpc-spec.md on the 4.0.6 tag, section 2.3). ADR-0108 is "Status:
 * Proposed" ("No account receives a Transmission proxy or launch from this catalog today"), so the
 * PMSS path is verified against the PMSS source only, not against a live account.
 */
class TransmissionLocate
{
    /** PMSS panel binary names for other torrent clients (deluge/qbittorrent) live on PATH; Transmission
     * on PMSS has no system package -- PMSS development/PMSS/etc/skel/www/torrentClientsResolve.php:107-110
     * resolves it from a per-account `~/bin/transmission/default` symlink into a curated store build instead. */
    const BINARIES = ['/usr/bin/transmission-daemon', '/usr/local/bin/transmission-daemon'];

    /** Linux `comm` is 15 bytes; PMSS reads the truncated name back the same way (PMSS development/PMSS/
     * etc/skel/www/transmissionLaunch.php:23, `pmssTransmissionNativePids()`: `'transmission-da'`). */
    const PROCESS = 'transmission-da';

    /**
     * ['unix_socket' => path or '', 'url' => the base to append the request to (e.g. "http://localhost/user-x/
     * transmission/rpc" or "http://127.0.0.1:9091/transmission/rpc"), 'port' => int or 0, 'how' => string,
     * 'auth' => ['required' => bool, 'username' => string (rpc-username, never secret)]]. CliError when
     * nothing usable is found. Authentication being required is NEVER refused here and NEVER a reason to
     * suggest turning it off (a TCP endpoint with auth off lets any co-tenant run the daemon as this
     * account, and this RPC can run scripts): the caller sends the stored `login` password as Basic auth,
     * or the account switches to the PMSS-managed unix socket, which has authentication off by design.
     * $tables (test seam): passed straight to Host::ownsListener for a TCP bind address.
     */
    public static function find($home, $family, $uid, ?array $tables = null)
    {
        $path = "$home/.config/transmission-daemon/settings.json";
        $settings = self::config($path);
        if ($settings === null) {
            throw self::absent($family, 'Transmission has not been started on this account yet (no ' . $path . ')');
        }
        $enabled = self::raw($settings, 'rpc-enabled', 'rpc_enabled');
        if ($enabled !== null && !$enabled) {
            throw new CliError('Transmission RPC is disabled in settings.json (rpc-enabled: false)',
                'turn rpc-enabled on in ' . $path . ' while Transmission is stopped, then start it');
        }
        $auth = ['required' => (bool) self::raw($settings, 'rpc-authentication-required', 'rpc_authentication_required'),
            'username' => self::str($settings, 'rpc-username', 'rpc_username')];
        $rpcUrl = self::str($settings, 'rpc-url', 'rpc_url');
        $rpcUrl = $rpcUrl !== '' ? $rpcUrl : '/transmission/'; // Transmission's own default (rpc-spec.md 2.3)
        $rpcPath = rtrim($rpcUrl, '/') . '/rpc';
        $bindAddress = self::str($settings, 'rpc-bind-address', 'rpc_bind_address');
        if (strncmp($bindAddress, 'unix:', 5) === 0) {
            $socket = substr($bindAddress, 5);
            if ($socket === '') {
                throw self::absent($family, 'settings.json rpc-bind-address is "unix:" with no path');
            }
            clearstatcache(true, $socket);
            $type = @filetype($socket);
            if ($type === false) {
                throw self::absent($family, "Transmission is not running (no socket at $socket)");
            }
            if ($type !== 'socket') {
                throw new CliError("$socket exists but is not a socket", 'check rpc-bind-address in ' . $path);
            }
            if (!Host::ownsSocket($socket, $uid)) {
                throw new CliError("the socket at $socket is not this account's own; not connecting",
                    'another account may run a Transmission of its own here (ADR-0019)');
            }
            return ['unix_socket' => $socket, 'url' => 'http://localhost' . $rpcPath, 'port' => 0,
                'how' => 'settings.json rpc-bind-address (unix socket)', 'auth' => $auth];
        }
        $port = self::int($settings, 'rpc-port', 'rpc_port');
        $port = $port > 0 ? $port : 9091; // Transmission's own default port (rpc-spec.md 2.3)
        $owner = Host::ownsListener($port, $uid, $tables);
        if ($owner === 'other') {
            throw new CliError("port $port belongs to another account; not connecting", 'check rpc-port in ' . $path);
        }
        if ($owner === 'unknown') {
            throw new CliError("cannot check who owns port $port (/proc/net is not readable); not connecting", 'run the tool on the seedbox itself');
        }
        if ($owner === 'none') {
            throw self::absent($family, "Transmission is not running (nothing listens on port $port)");
        }
        return ['unix_socket' => '', 'url' => "http://127.0.0.1:$port" . $rpcPath, 'port' => $port,
            'how' => 'settings.json rpc-port', 'auth' => $auth];
    }

    /** The error for a client that cannot be reached: not installed/selected, or $why. */
    public static function absent($family, $why)
    {
        if (!self::installed($family)) {
            return $family === 'pmss'
                ? new CliError('no Transmission build is selected on this account', 'select one in the PMSS panel (torrent client switch)')
                : new CliError('Transmission is not installed on this machine', 'the provider installs it: report it in your answer');
        }
        $hint = $family === 'pmss' ? 'start it from the PMSS panel (torrent client switch), or run `restart`'
            : 'start Transmission the way your provider documents it';
        return new CliError($why, $hint);
    }

    /** PMSS: the account picked a curated build (`~/bin/transmission/default` symlink, ADR-0108). Elsewhere:
     * a `transmission-daemon` binary on PATH. $binaries (test seam): fixed paths instead of self::BINARIES. */
    public static function installed($family, ?array $binaries = null)
    {
        if ($family === 'pmss') {
            $home = (string) getenv('HOME');
            return $home !== '' && is_link("$home/bin/transmission/default");
        }
        $dirs = array_filter(explode(':', (string) getenv('PATH')), 'strlen');
        $fixed = $binaries !== null ? $binaries : self::BINARIES;
        $paths = array_merge($fixed, array_map(function ($d) {
            return rtrim($d, '/') . '/transmission-daemon';
        }, $dirs));
        foreach ($paths as $p) {
            if (is_file($p) && is_executable($p)) {
                return true;
            }
        }
        return false;
    }

    /** settings.json as a plain array, or null when it cannot be read as one (unlike Deluge's two-part
     * config files, Transmission's settings.json is one ordinary JSON object). */
    public static function config($path)
    {
        $raw = (string) @file_get_contents($path);
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /** $settings[$kebab] or $settings[$snake] (PMSS writes either spelling), as a string, '' when neither is set. */
    public static function str(array $settings, $kebab, $snake)
    {
        $v = self::raw($settings, $kebab, $snake);
        return is_string($v) ? $v : '';
    }

    /** As above, as an int, 0 when neither is set or it is not a positive integer. */
    public static function int(array $settings, $kebab, $snake)
    {
        $v = self::raw($settings, $kebab, $snake);
        return is_int($v) && $v > 0 ? $v : (is_numeric($v) && (int) $v > 0 ? (int) $v : 0);
    }

    /** $settings[$kebab] if set, else $settings[$snake] if set, else null. */
    public static function raw(array $settings, $kebab, $snake)
    {
        if (array_key_exists($kebab, $settings)) {
            return $settings[$kebab];
        }
        return array_key_exists($snake, $settings) ? $settings[$snake] : null;
    }
}
