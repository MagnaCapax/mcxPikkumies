<?php
/**
 * QbitApi: one WebUI API v2 session through lib/Http.php (never curl directly).
 * Sources (the qBittorrent WebUI API source): every API call needs a session cookie except auth/login
 * (webapp-5.0.0.cpp:323-324, 403 otherwise); login answers "Ok." with a session cookie or "Fails.",
 * and 403 when the address is banned (qbt-wiki-5.md:133-160). qBittorrent 5.2 answers 204 with the cookie
 * or 401 instead (release-5.2.4 authcontroller.cpp:43-60, webapplication.cpp:368-373, 424-425, 1016-1021;
 * live 5.2.4 / API 2.15.1 smoke run 2026-10-14). The cookie name defaults to SID but is
 * configurable (webapp-5.0.0.cpp:73,174), so the first Set-Cookie is sent back as it came. No Origin
 * or Referer is sent: CSRF protection lets such requests through (webapp-5.0.0.cpp:781-786).
 * GET for reads, POST for changes (qbt-wiki-5.md:121-125, 405 since v4.4.4).
 */
class QbitApi
{
    const PROBE_TIMEOUT = 5;
    const TIMEOUT = 20;

    public $ep;
    public $api = '';
    /** False when the WebUI answered without a login (the user turned on the localhost bypass). */
    public $localAuth = true;
    private $secret;
    private $cookie = '';
    private $open = false;

    public function __construct(QbitEndpoint $ep, QbitSecret $secret)
    {
        $this->ep = $ep;
        $this->secret = $secret;
    }

    /** Locates the client, logs in once if it asks for a login, and reads the API version. */
    public function open()
    {
        if ($this->open) {
            return $this;
        }
        $this->ep->locate();
        $r = $this->raw('GET', 'app/webapiVersion', [], null, [], self::PROBE_TIMEOUT);
        if ($r['code'] === 403) {
            $status = $this->attempt($this->secret->usable());
            if ($status === 'fails') {
                $this->secret->reject();
                throw new CliError('qBittorrent rejected the stored password; it is marked rejected and will not be tried again', 'the user runs `php ' . __DIR__ . '/qbittorrent.php login` in a terminal with the current WebUI password');
            }
            $r = $this->raw('GET', 'app/webapiVersion', [], null, [], self::PROBE_TIMEOUT);
        } elseif ($r['code'] === 200) {
            $this->localAuth = false; // never changed by this tool (ADR-0019)
        }
        if ($r['code'] !== 200 || !preg_match('/^\d+(\.\d+)*$/', trim($r['body']))) {
            throw new CliError("qBittorrent answered the API version request with HTTP {$r['code']}", 'check that the port belongs to qBittorrent (`stats`)');
        }
        $this->api = trim($r['body']);
        $this->open = true;
        return $this;
    }

    /** One login attempt: 'ok' (cookie kept), 'fails' (wrong password) or a CliError (banned, unreachable). */
    public function attempt($password)
    {
        if ($this->ep->username === '') {
            throw new CliError('no WebUI\\Username in ~' . QbitEndpoint::CONF, 'set the WebUI username in qBittorrent once (Tools > Options > WebUI)');
        }
        $r = $this->raw('POST', 'auth/login', [], http_build_query(['username' => $this->ep->username, 'password' => $password]), [], self::PROBE_TIMEOUT);
        if ($r['code'] === 403) {
            throw new CliError('qBittorrent has banned this address after failed logins (by default for an hour); no login was tried', 'wait for the ban to end; the WebUI in the browser is locked out too until then');
        }
        $body = trim($r['body']);
        if (($r['code'] === 200 && $body === 'Ok.') || $r['code'] === 204) { // 204: qBittorrent 5.2 and later
            $c = isset($r['headers']['set-cookie'][0]) ? explode(';', $r['headers']['set-cookie'][0])[0] : '';
            if (strpos($c, '=') === false) {
                throw new CliError('qBittorrent accepted the login but sent no session cookie', 'report this; the WebUI may sit behind something that strips cookies');
            }
            $this->cookie = trim($c);
            return 'ok';
        }
        // 401: a wrong login on 5.2. The same code means a refused Host/Origin header, but then the session-less probe
        // in open() gets 401 too and never reaches this login (webapplication.cpp:650-662 in 5.2.4).
        if (($r['code'] === 200 && $body === 'Fails.') || $r['code'] === 401) {
            return 'fails';
        }
        throw new CliError("unexpected login answer: HTTP {$r['code']}", 'check that the port belongs to qBittorrent');
    }

    /** True when something on the WebUI port answers HTTP at all (used after a restart). */
    public function answers($timeout = 2)
    {
        $r = Http::request('GET', $this->ep->base() . 'app/webapiVersion', ['timeout' => $timeout, 'max' => 4096]);
        return $r['code'] > 0;
    }

    public function stopped()
    {
        return version_compare($this->api, '2.11', '>='); // 5.0 renamed pause/resume to stop/start (research §2.3)
    }

    /** GET a JSON API; 404 returns null (unknown hash). */
    public function json($path, array $query = [])
    {
        $r = $this->call('GET', $path, $query, null, []);
        if ($r['code'] === 404) {
            return null;
        }
        if ($r['error'] !== '') { // e.g. lib/Http.php's answer-size limit: the body is empty then, not broken JSON
            throw new CliError("qBittorrent's answer to $path could not be read: {$r['error']}", 'try again; if it repeats, report it with this message');
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d)) {
            throw new CliError("qBittorrent sent something that is not JSON for $path", 'try again; if it repeats, the client is unhealthy');
        }
        return $d;
    }

    public function text($path)
    {
        return trim($this->call('GET', $path, [], null, [])['body']);
    }

    /** POST form fields, or a prepared multipart body with its Content-Type. Returns [code, body]. */
    public function post($path, array $fields, $multipart = null, $type = '')
    {
        $r = $this->call('POST', $path, [], $multipart !== null ? $multipart : http_build_query($fields), $type !== '' ? ['Content-Type: ' . $type] : []);
        return [$r['code'], trim($r['body'])];
    }

    private function call($method, $path, array $query, $body, array $headers)
    {
        $this->open();
        $r = $this->raw($method, $path, $query, $body, $headers, self::TIMEOUT);
        if ($r['code'] === 403) {
            throw new CliError("qBittorrent refused $path (403): the session was not accepted", 'run the command again; if it repeats, the user runs login');
        }
        if ($r['code'] >= 400 && !in_array($r['code'], [404, 409, 415], true)) {
            throw new CliError("qBittorrent answered $path with HTTP {$r['code']}", 'check the client version with `stats`');
        }
        return $r;
    }

    private function raw($method, $path, array $query, $body, array $headers, $timeout)
    {
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        $o = ['headers' => $headers, 'timeout' => $timeout];
        if ($body !== null) {
            $o['body'] = $body;
        }
        $r = Http::request($method, $this->ep->base() . $path . ($query ? '?' . http_build_query($query) : ''), $o);
        if ($r['code'] === 0) {
            throw QbitEndpoint::failure($r, $path);
        }
        return $r;
    }
}
