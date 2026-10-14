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
