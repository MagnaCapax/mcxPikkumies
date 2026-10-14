<?php
/**
 * DelugeRpc: deluge-web JSON-RPC through lib/Http.php. Shapes from the Deluge 2.0.3 source
 * (deluge/ui/web/json_api.py, auth.py; copies in the unit README's sources):
 * - POST <base or />json, Content-Type exactly application/json (json_api.py:197-200; server.py:687-689 serves / too);
 * - request {"method","params","id"}, reply {"result","error":{message,code},"id"}; code 1 = not authenticated,
 *   2 = unknown method, 3/4 = the call failed (json_api.py:139-190);
 * - auth.login([secret]) answers true and sets the _session_id cookie with path <base>json (auth.py:90-111, 243-256).
 *   The cookie is sent back by hand: Http keeps no cookie jar, and on PMSS the path would not match /json.
 * The secret goes only into the login request body; it is never printed.
 */
class DelugeRpc
{
    private $url;
    private $secret;
    private $timeout;
    private $uid;
    private $cookie = '';
    private $id = 0;

    public function __construct($url, $secret, $timeout, $uid = null)
    {
        $this->url = $url;
        $this->secret = $secret;
        $this->timeout = $timeout;
        $this->uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
    }

    /**
     * Logs in and makes sure deluge-web talks to this user's daemon; CliError when it cannot.
     * $owns (test seam): fn(port) => Host::ownsListener result.
     */
    public function open($daemonPort, ?callable $owns = null)
    {
        if ($this->call('auth.login', [$this->secret]) !== true) {
            throw new CliError('deluge-web refused the localclient secret from ~/.config/deluge/auth', 'on PMSS the web password follows that secret (PMSS delugePasswords.php); elsewhere the web password may differ from it, which this tool does not support');
        }
        $uid = $this->uid;
        $owns = $owns !== null ? $owns : function ($port) use ($uid) {
            return Host::ownsListener($port, $uid);
        };
        // web.get_hosts rows are [id, host, port, username] (json_api.py:776-782; hostlist.py:167-174).
        $hosts = array_values(array_filter((array) $this->call('web.get_hosts', []), function ($h) {
            return is_array($h) && count($h) >= 4;
        }));
        if ($this->call('web.connected', []) === true) { // json_api.py:469-477
            // Connected earlier (the web UI, or a previous call). Which daemon is asked of deluge-web, never of the
            // daemon: web.get_host_status (json_api.py:784-797) answers 'Connected' for the host deluge-web is
            // connected to (hostlist.py:218-229) and probes the others, without logging in (:230-236). Only hosts on
            // this machine are asked, so deluge-web never probes a remote host; a connection to one is replaced below.
            foreach ($hosts as $h) {
                if (!in_array($h[1], ['127.0.0.1', 'localhost'], true)) {
                    continue;
                }
                $st = (array) $this->call('web.get_host_status', [$h[0]]);
                if (isset($st[1]) && $st[1] === 'Connected') {
                    self::mine((int) $h[2], $owns((int) $h[2]), 'deluge-web is connected to a daemon');
                    return;
                }
            }
            // No local host says so (a remote daemon, or a 'localhost' entry: hostlist.py compares the resolved
            // address with the name used): disconnect (json_api.py:479-490) and connect to this user's daemon below.
            $this->call('web.disconnect', []);
        }
        // PMSS leaves default_daemon empty (template.deluge.web.conf), so the web UI starts disconnected.
        // The localclient entry on 127.0.0.1 or localhost is this user's daemon (PMSS delugeManagedConfig.php:187-193).
        $pick = null;
        foreach ($hosts as $h) {
            if ($h[3] === 'localclient' && in_array($h[1], ['127.0.0.1', 'localhost'], true)) {
                if ($pick === null || (int) $h[2] === (int) $daemonPort) {
                    $pick = $h;
                }
            }
        }
        if ($pick === null) {
            throw new CliError('deluge-web has no localclient daemon in its host list', 'open the Deluge web UI once and use its Connection Manager, or let PMSS rewrite ~/.config/deluge/hostlist.conf');
        }
        // web.connect (json_api.py:454-468) makes deluge-web log in to that host with the stored localclient
        // secret (hostlist.py:285-292), so the port must be this user's: when the daemon is down, another
        // account could listen there (ADR-0019).
        self::mine((int) $pick[2], $owns((int) $pick[2]), "not connecting deluge-web to port {$pick[2]}");
        $this->call('web.connect', [$pick[0]]);
        if ($this->call('web.connected', []) !== true) {
            throw new CliError("deluge-web runs but cannot reach the deluged daemon on port {$pick[2]} (not running?)", 'on PMSS the watchdog starts deluged within about a minute; or run `restart`');
        }
    }

    /** CliError unless $owner says this user listens on $port. */
    private static function mine($port, $owner, $what)
    {
        if ($owner === 'other' || $owner === 'unknown') {
            throw new CliError("$what on port $port: " . ($owner === 'other' ? 'another account listens there' : 'cannot check who owns it (/proc/net is not readable)'), 'start your own deluged first (`restart`), or check ~/.config/deluge/hostlist.conf');
        }
        if ($owner === 'none') {
            throw new CliError("deluge-web runs but nothing listens on the deluged port $port (daemon not running?)", 'on PMSS the watchdog starts deluged within about a minute; or run `restart`');
        }
    }

    /** One JSON-RPC call; the result, or CliError naming the cause. */
    public function call($method, array $params)
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: _session_id=' . $this->cookie;
        }
        $body = json_encode(['method' => $method, 'params' => $params, 'id' => ++$this->id], JSON_UNESCAPED_SLASHES);
        $r = Http::request('POST', $this->url, ['headers' => $headers, 'body' => $body, 'timeout' => $this->timeout]);
        if ($r['code'] === 0) {
            throw self::transportError($r['error']);
        }
        foreach (isset($r['headers']['set-cookie']) ? $r['headers']['set-cookie'] : [] as $c) {
            if (preg_match('/^_session_id=([^;\s]+)/', $c, $m)) {
                $this->cookie = $m[1];
            }
        }
        $reply = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($reply) || !array_key_exists('result', $reply)) {
            throw new CliError("deluge-web answered HTTP {$r['code']} without a JSON-RPC reply to $method", 'is the port in the config really deluge-web?');
        }
        if (!empty($reply['error'])) {
            $e = $reply['error'];
            $code = isset($e['code']) ? (int) $e['code'] : 0;
            if ($code === 1) {
                throw new CliError("deluge-web: not authenticated for $method", 'the login did not hold; run the command again');
            }
            if ($code === 2) {
                throw new CliError("deluge-web does not know $method", 'this Deluge version lacks it, or the web UI is not connected to the daemon');
            }
            throw new CliError("Deluge refused $method: " . (isset($e['message']) ? (string) $e['message'] : 'no message'));
        }
        return $reply['result'];
    }

    /** Http's error text -> busy (timeout: unknown, not down), refused, or another account's port. */
    public static function transportError($error)
    {
        if (stripos($error, 'timed out') !== false) {
            return new CliError('Deluge is busy: no answer in time (state unknown, not necessarily down)', 'try again in a minute; a large torrent list or a disk check can make it slow');
        }
        if (stripos($error, 'another account') !== false) {
            return new CliError($error, 'the port is not this user\'s deluge-web; check ~/.delugeWebPort or web.conf');
        }
        if (stripos($error, 'refused') !== false || stripos($error, 'connect') !== false) {
            return new CliError('deluge-web refused the connection', 'it may be starting or stopping; try again in a minute');
        }
        return new CliError('cannot reach deluge-web: ' . $error);
    }
}
