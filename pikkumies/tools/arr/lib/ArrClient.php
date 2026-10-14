<?php
/**
 * ArrClient: every API call of an *ARR unit. The key goes only in the X-Api-Key header, never in the
 * URL (where proxy logs keep it), never on a command line, never in output: any text that came back
 * from the app has the key replaced before it is shown. Calls go through lib/Http.php (owner check of
 * the local port, no redirects, pinned address). Failures are named in the order of tools/arr/README.md
 * "When it cannot reach the app" (steps 5-12; steps 1-4 are ArrLocate's). An answer Http could not read
 * whole (over the size cap) or an empty GET answer is an error, never an empty list.
 */
class ArrClient
{
    const LIST_MAX = 16777216; // bytes read for a whole-library list (Arr::all); Http::MAX for the rest

    private $where;
    private $relocate;
    private $timeout;
    private $api;

    /** $where from ArrLocate::find; $relocate: fn() => a fresh $where (config.xml read again); $api: v3 or v1. */
    public function __construct(array $where, callable $relocate, $api, $timeout = 15)
    {
        $this->api = $api;
        $this->where = $where;
        $this->relocate = $relocate;
        $this->timeout = $timeout;
    }

    public function where()
    {
        $w = $this->where;
        unset($w['key']);
        return $w;
    }

    /**
     * GET/POST/PUT/DELETE an API path ('movie/5'); $query values: bool -> true|false, list -> repeated.
     * $o: accept (codes returned as [code, data]), missing (the 404 text), raw, timeout, max. Returns decoded JSON.
     */
    public function call($method, $path, array $query = [], $body = null, array $o = [])
    {
        $r = $this->send($method, $path, $query, $body, $o);
        if ($r['code'] === 401) {
            $this->where = call_user_func($this->relocate); // the key may have changed: read config.xml once more
            $r = $this->send($method, $path, $query, $body, $o);
        }
        $code = $r['code'];
        $port = $this->where['port'];
        $cfg = $this->where['config'];
        if ($code === 0) {
            $this->unreachable($r['error']);
        }
        if ($r['error'] !== '') {
            throw new CliError("port $port answered HTTP $code, but the answer could not be read whole ({$r['error']}); nothing was used",
                'too much for one call: use the app\'s own UI for this, or ask for less');
        }
        if (!empty($o['accept']) && in_array($code, $o['accept'], true)) {
            return ['code' => $code, 'data' => json_decode($r['body'], true)];
        }
        if ($code >= 300 && $code < 400) {
            $base = $this->where['urlBase'] === '' ? '(empty)' : $this->where['urlBase'];
            throw new CliError("the app answered HTTP $code: wrong base path (UrlBase $base from $cfg)",
                'check <UrlBase> in config.xml; the tool never follows redirects');
        }
        if ($code === 401) {
            throw new CliError("the app rejected the ApiKey from $cfg (HTTP 401)",
                'the app may run from another data folder than this config.xml; check which one it was started with');
        }
        if ($code === 404 && !empty($o['missing'])) {
            throw new CliError($o['missing'], 'ids come from `list`');
        }
        if ($code >= 400) {
            $msg = $this->message($r['body']);
            $what = ($code < 500 ? 'the app refused the request' : 'the app failed') . " (HTTP $code)";
            throw new CliError($what . ($msg !== '' ? ": $msg" : ''),
                $code < 500 ? 'fix what the message says and try again' : 'see the app\'s own log (System, Logs); try again later');
        }
        $data = json_decode($r['body'], true);
        $empty = $r['body'] === '' && $method === 'GET'; // the API always answers a GET with JSON
        if ($empty || ($r['body'] !== '' && $data === null && trim($r['body']) !== 'null')) {
            throw new CliError("port $port answered, but not with the app's API (HTTP $code, " . ($empty ? 'empty' : 'not JSON') . ')',
                "check <Port> and <UrlBase> in $cfg");
        }
        return empty($o['raw']) ? ArrRecords::scrub($data) : $data;
    }

    private function send($method, $path, array $query, $body, array $o)
    {
        $w = $this->where;
        $url = 'http://127.0.0.1:' . $w['port'] . $w['urlBase'] . '/api/' . $this->api . '/' . ltrim($path, '/');
        $q = [];
        foreach ($query as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $one) {
                $q[] = rawurlencode($k) . '=' . rawurlencode(is_bool($one) ? ($one ? 'true' : 'false') : (string) $one);
            }
        }
        $headers = ['X-Api-Key: ' . $w['key'], 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $opts = ['headers' => $headers, 'timeout' => isset($o['timeout']) ? $o['timeout'] : $this->timeout,
            'body' => $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES)];
        if (isset($o['max'])) {
            $opts['max'] = (int) $o['max'];
        }
        return Http::request($method, $url . ($q ? '?' . implode('&', $q) : ''), $opts);
    }

    /** Steps 5-7: another account's port, not running, busy. */
    private function unreachable($error)
    {
        $w = $this->where;
        if (preg_match('/another account|who owns|not connecting/', $error)) {
            throw new CliError($this->redact($error), "the port in {$w['config']} is not this user's {$w['app']}; never work around that");
        }
        if (preg_match('/timed out|timeout/i', $error)) {
            throw new CliError("no answer from port {$w['port']} within {$this->timeout} s: busy, state unknown (not down)",
                'try again in a minute; do not restart it for this alone');
        }
        $pmss = $w['source'] === 'pmss';
        $state = $pmss ? ' (tmux session: ' . ArrShell::tmuxState($w['app']) . ')' : '';
        throw new CliError("not running: nothing answers on port {$w['port']}$state",
            $pmss ? 'start it with `restart`, or in the PMSS panel' : 'start it the way the provider documents (or `restart`)');
    }

    /** The app's own message from an error body: ErrorModel {message} or a list of {errorMessage} (RadarrErrorPipeline.cs:33-52). */
    public function message($body)
    {
        $d = json_decode((string) $body, true);
        $parts = [];
        if (is_array($d) && isset($d['message']) && is_string($d['message'])) {
            $parts[] = $d['message'];
        } elseif (is_array($d)) {
            foreach ($d as $e) {
                if (is_array($e) && isset($e['errorMessage'])) {
                    $parts[] = (isset($e['propertyName']) ? $e['propertyName'] . ': ' : '') . $e['errorMessage'];
                }
            }
        } else {
            $parts[] = substr(trim(strip_tags((string) $body)), 0, 300);
        }
        return $this->redact(substr(implode('; ', array_filter($parts, 'strlen')), 0, 600));
    }

    public function redact($s)
    {
        $key = isset($this->where['key']) ? (string) $this->where['key'] : '';
        return $key === '' ? (string) $s : str_ireplace($key, 'REDACTED', (string) $s);
    }
}
