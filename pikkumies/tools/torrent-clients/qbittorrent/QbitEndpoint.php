<?php
/**
 * QbitEndpoint: where this account's qBittorrent WebUI listens, and why it cannot be reached.
 * PMSS: port in ~/.qbittorrentPort (PMSS scripts/util/userConfig.php:214), the same as WebUI\Port in
 * ~/.config/qBittorrent/qBittorrent.conf (template.qbittorrent.conf:59), bound to 127.0.0.1 (:50),
 * username WebUI\Username (:61). The watchdog starts the client only while ~/.qbittorrentEnable
 * exists (scripts/lib/user/watchdog.php:179-180; scripts/cron/checkQbittorrentInstances.php:14-17).
 * Other hosts: WebUI\Port and WebUI\Username in the same qBittorrent.conf (research §4).
 */
class QbitEndpoint
{
    const CONF = '/.config/qBittorrent/qBittorrent.conf';
    /** The all-zero PBKDF2 value PMSS keeps until a WebUI password is chosen (PMSS scripts/lib/user/qbittorrentWebUiAuth.php). */
    const NO_PASSWORD = '@ByteArray(AAAAAAAAAAAAAAAAAAAAAA==:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==)';

    public $home;
    public $family;
    public $port = 0;
    public $username = '';
    public $conf;
    /** Test seam: /proc/net/tcp(6) contents for the owner check (null = the real ones). */
    public $tables = null;

    public function __construct($home, $family)
    {
        $this->home = rtrim($home, '/');
        $this->family = $family;
        $this->conf = $this->home . self::CONF;
    }

    public function base()
    {
        return 'http://127.0.0.1:' . $this->port . '/api/v2/';
    }

    public function enabled()
    {
        return $this->family !== 'pmss' || is_file($this->home . '/.qbittorrentEnable');
    }

    /** Reads port and username from the user's own files; throws when there is no port (not installed). */
    public function readConfig()
    {
        $prefs = self::readConf($this->conf);
        $this->username = isset($prefs['WebUI\\Username']) ? $prefs['WebUI\\Username'] : '';
        $file = $this->home . '/.qbittorrentPort';
        $this->port = ($this->family === 'pmss' && is_file($file)) ? (int) trim((string) @file_get_contents($file)) : 0;
        if ($this->port <= 0 && isset($prefs['WebUI\\Port'])) {
            $this->port = (int) $prefs['WebUI\\Port'];
        }
        if ($this->port <= 0 || $this->port > 65535) {
            throw new CliError('qBittorrent is not installed for this account (no WebUI port in ' . ($this->family === 'pmss' ? '~/.qbittorrentPort or ' : '') . '~' . self::CONF . ')', $this->family === 'pmss'
                ? 'enable qBittorrent in the Pulsed Media panel (the qBittorrent start button), then try again'
                : 'install qBittorrent the way this provider documents it, or start qbittorrent-nox once so it writes its config');
        }
        return $this;
    }

    /** readConfig(), then who listens on the port. Throws CliError naming the cause. */
    public function locate()
    {
        $this->readConfig();
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $owner = Host::ownsListener($this->port, $uid, $this->tables);
        if ($owner === 'other') {
            throw new CliError("port {$this->port} belongs to another account; not connecting (the login is never sent there)", 'the configured WebUI port is in use by someone else; restart qBittorrent from the panel so it gets its port back');
        }
        if ($owner === 'unknown') {
            throw new CliError("cannot check who owns port {$this->port} (/proc/net is not readable); not connecting", 'this host hides socket owners, so the tool cannot safely log in');
        }
        if ($owner === 'none') {
            if (!$this->enabled()) {
                throw new CliError('qBittorrent is not enabled for this account (~/.qbittorrentEnable is missing)', 'the user enables it in the Pulsed Media panel (qBittorrent: start); the watchdog then keeps it running');
            }
            throw new CliError("qBittorrent is not running (nothing listens on port {$this->port})", $this->family === 'pmss'
                ? 'the PMSS watchdog starts it within about 2 minutes, or run `restart`'
                : 'start it the way this provider documents it, then try again');
        }
        return $this;
    }

    /**
     * PMSS only: true when no WebUI password was chosen yet, so no password can log in and an attempt would only
     * count toward a ban. The panel's own rule (PMSS etc/skel/www/qbittorrentPasswordRequired.php:3-11): no
     * WebUI\Password_PBKDF2 line, or one holding the placeholder. Then the panel asks for a password on qBittorrent
     * start or restart (etc/skel/www/qbittorrent.php:14-27), so the next step below holds by construction.
     */
    public function passwordMissing()
    {
        $conf = is_link($this->conf) ? false : @file_get_contents($this->conf);
        if ($this->family !== 'pmss' || !is_string($conf)) {
            return false;
        }
        return preg_match('/^WebUI\\\\Password_PBKDF2=/m', $conf) !== 1
            || preg_match('/^WebUI\\\\Password_PBKDF2=' . preg_quote(self::NO_PASSWORD, '/') . '$/m', $conf) === 1;
    }

    /** Throws instead of a login attempt that cannot work (passwordMissing()). */
    public function refuseWithoutPassword()
    {
        if (!$this->passwordMissing()) {
            return;
        }
        throw new CliError('qBittorrent has no WebUI password yet (the panel keeps a placeholder until one is chosen), so no login was tried',
            'the user presses qBittorrent start or restart in the Pulsed Media panel, which asks them to choose a WebUI password; then they run `php ' . __DIR__ . '/qbittorrent.php login` in a terminal with it');
    }

    /** A failed Http::request as the cause the user can act on. */
    public static function failure(array $r, $what)
    {
        $e = (string) $r['error'];
        if (stripos($e, 'timed out') !== false || stripos($e, 'timeout') !== false) {
            return new CliError("qBittorrent is busy: no answer to $what in time (state unknown, not necessarily down)", 'try again in a minute; a client checking or moving many files answers slowly');
        }
        if (strpos($e, 'another account') !== false) {
            return new CliError($e, 'the configured WebUI port is in use by someone else; restart qBittorrent from the panel');
        }
        return new CliError("qBittorrent refused the connection ($what): " . $e, 'check that it runs (`stats`), or run `restart`');
    }

    /** The [Preferences] keys of a qBittorrent.conf (keys like WebUI\Port keep their backslash). */
    public static function readConf($file)
    {
        $out = [];
        $section = '';
        foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '[') {
                $section = trim($line, '[]');
            } elseif ($section === 'Preferences' && strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $out[trim($k)] = trim($v);
            }
        }
        return $out;
    }
}
