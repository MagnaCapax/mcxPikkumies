<?php
/**
 * MediaHttp: the only way the media-server units reach a server (README.md of this category,
 * "Safety rules"). It connects to this machine's loopback only, only to a listener this user owns
 * (ADR-0019), only with a method and path the unit's allowlist names (no DELETE exists in any list),
 * never with a credential or an overwrite flag in the URL, and its errors never carry the credential.
 */
class MediaAuthRejected extends CliError
{
}

/** No HTTP answer at all (timeout, connection refused): whether a sent change took effect is unknown (jellyfin set-login). */
class MediaNoAnswer extends CliError
{
}

class MediaHttp
{
    /** Query names that are never sent: overwrite flags (rule 6) and credentials in the URL (rule 1). */
    const BANNED_QUERY = '/^(replaceall|force$)|token|api_?key/i';

    public $name, $origin, $prefix, $port;
    private $allow, $uid, $timeout, $tables;
    private $scrub = [];

    /** $url: http://127.0.0.1|localhost|[::1]:port[/prefix]; $allow: ['GET' => [regex...], ...]; $tables: Host::ownsListener seam. */
    public function __construct($name, $url, array $allow, $uid, $timeout = 15, ?array $tables = null)
    {
        $u = self::local($url);
        $this->name = $name;
        $this->origin = $u['origin'];
        $this->prefix = $u['path'];
        $this->port = $u['port'];
        $this->allow = $allow;
        $this->uid = (int) $uid;
        $this->timeout = max(1, (int) $timeout);
        $this->tables = $tables;
    }

    /** A URL on this machine's loopback, or CliError. Nothing is resolved, so no other host is ever asked. */
    public static function local($url)
    {
        $u = parse_url((string) $url);
        // PHP 7.3's parse_url leaves out an empty query or fragment ("http://127.0.0.1:1/?"), PHP 8 sets
        // it to ''; refuse any '?' or '#' in the address itself so both versions refuse the same URLs.
        if (strpbrk((string) $url, '?#') !== false) {
            $u = false;
        }
        $host = is_array($u) && isset($u['host']) ? strtolower(trim($u['host'], '[]')) : '';
        if (!is_array($u) || !isset($u['scheme']) || strtolower($u['scheme']) !== 'http'
            || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || !isset($u['port']) || isset($u['user']) || isset($u['query']) || isset($u['fragment'])) {
            throw new CliError('not an address of a server on this machine: the media units only talk to http://127.0.0.1:<port>',
                'give --url as http://127.0.0.1:<port>[/base path]; other machines and plex.tv are never contacted');
        }
        $ip = $host === 'localhost' ? '127.0.0.1' : $host;
        $path = isset($u['path']) ? rtrim($u['path'], '/') : '';
        // The base path is plain segments only: no `.`/`..` segment, no %-escape, nothing the allowlist could not see.
        if (!preg_match('#^(/[A-Za-z0-9._~-]+)*$#', $path) || preg_match('#/\.\.?(/|$)#', $path)) {
            throw new CliError('the base path in --url may only hold plain segments (letters, digits, . _ ~ -), no . or .. segment, no %',
                'give --url as http://127.0.0.1:<port>[/base path], e.g. http://127.0.0.1:8096/jellyfin');
        }
        $origin = 'http://' . (strpos($ip, ':') !== false ? "[$ip]" : $ip) . ':' . (int) $u['port'];
        return ['origin' => $origin, 'port' => (int) $u['port'], 'ip' => $ip, 'path' => $path];
    }

    /** Literal values (the credential) removed from every error this object raises. */
    public function scrub($value)
    {
        if (is_string($value) && strlen($value) >= 4) {
            $this->scrub[] = $value;
        }
    }

    /** Refusal text for a method/path/query the allowlist does not name, or ''. */
    public function refusal($method, $path, array $query = [])
    {
        $method = strtoupper($method);
        $ok = false;
        foreach (isset($this->allow[$method]) ? $this->allow[$method] : [] as $re) {
            $ok = $ok || preg_match($re, $path);
        }
        if (!$ok || strpos($path, '..') !== false) {
            return "$method $path is not something this tool does";
        }
        foreach (array_keys($query) as $k) {
            if (preg_match(self::BANNED_QUERY, (string) $k)) {
                return "the query parameter $k is never sent";
            }
        }
        return '';
    }

    /** CliError unless this user owns the listener on the port (ADR-0019): another account's, unknown, or nothing listening. */
    public function reachable()
    {
        $owner = Host::ownsListener($this->port, $this->uid, $this->tables, [trim(parse_url($this->origin, PHP_URL_HOST), '[]')]);
        $p = $this->port;
        $why = [
            'other' => ["port $p on this machine belongs to another account; not connecting",
                "that is not this user's {$this->name}; never work around this"],
            'unknown' => ["cannot check who owns port $p (/proc/net is not readable); not connecting", 'run the tool on the server itself'],
            'none' => ["{$this->name} is not running (nothing listens on port $p)", 'start it the way it was installed, or run `restart`'],
        ];
        if (isset($why[$owner])) {
            throw new CliError($why[$owner][0], $why[$owner][1]);
        }
    }

    /**
     * One request. Returns ['code', 'body', 'headers']; connection failures throw CliError with the cause. A header given
     * as a Closure is made only once every check passed, just before sending (a device id is stored on first use).
     */
    public function send($method, $path, array $query = [], $body = null, array $headers = [], $timeout = null)
    {
        $refusal = $this->refusal($method, $path, $query);
        if ($refusal !== '') {
            throw new CliError("refused: $refusal; nothing was sent",
                'the media units never delete, never create keys, never change settings');
        }
        $this->reachable();
        foreach ($headers as $i => $h) {
            $headers[$i] = $h instanceof Closure ? $h() : $h;
        }
        $url = $this->origin . $this->prefix . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $t = $timeout !== null ? (int) $timeout : $this->timeout;
        $r = Http::request(strtoupper($method), $url, ['headers' => $headers, 'body' => $body, 'timeout' => $t]);
        if ($r['code'] === 0) {
            $e = $r['error'];
            if (stripos($e, 'timed out') !== false) {
                throw new MediaNoAnswer($this->clean("{$this->name} did not answer within $t s: busy, state unknown (not down)"),
                    'try again in a minute');
            }
            throw new MediaNoAnswer($this->clean("cannot reach {$this->name} on port {$this->port}: $e"), 'check that it is running: `stats`');
        }
        return $r;
    }

    /** send() + status handling + JSON decoding; 204 and empty bodies give []. */
    public function json($method, $path, array $query = [], $body = null, array $headers = [])
    {
        $r = $this->send($method, $path, $query, $body, $headers);
        $this->check($r['code'], "$method $path");
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    /**
     * Throws for a status that is not 2xx: 401 as MediaAuthRejected (the credential is refused), 403 as a plain
     * error (the credential works but may not do this: nothing is marked), 503 as starting up.
     */
    public function check($code, $what)
    {
        if ($code >= 200 && $code < 300) {
            return;
        }
        if ($code === 401) {
            throw new MediaAuthRejected("{$this->name} refused the stored credential (HTTP 401)", 'the user runs `login` in a terminal');
        }
        if ($code === 403) {
            throw new CliError($this->clean("{$this->name} does not allow $what for the stored credential (HTTP 403)"),
                'the credential works but lacks the right (an administrator key or user is needed); nothing was marked');
        }
        if ($code === 503) {
            throw new CliError("{$this->name} answered 503: still starting up, state unknown (not down)", 'try again in a minute');
        }
        throw new CliError($this->clean("{$this->name} answered HTTP $code to $what"),
            $code === 404 ? 'it does not know that item or path' : 'check `logs`');
    }

    private function clean($text)
    {
        return $this->scrub ? str_replace($this->scrub, 'REDACTED', $text) : $text;
    }
}
