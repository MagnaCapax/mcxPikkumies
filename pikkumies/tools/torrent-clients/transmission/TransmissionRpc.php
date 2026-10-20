<?php
/**
 * TransmissionRpc: the old (pre-4.1) bespoke JSON RPC that Transmission 4 still answers to, deprecated
 * but not removed. Verified against the `main` branch docs/rpc-spec.md top-of-file notice (fetched
 * 2026-10-20): "Transmission 4.1.0 ... converted all RPC strings to snake_case. The old bespoke RPC
 * protocol, and the old mix of kebab-case and camelCase strings, are still supported in Transmission 4
 * but are deprecated" -- and against the kebab/camelCase method and field names themselves, read from
 * the 4.0.6 tag's docs/rpc-spec.md (fetched 2026-10-20: https://github.com/transmission/transmission/
 * blob/4.0.6/docs/rpc-spec.md). PMSS curates Transmission 4.1.3 (default) and 4.0.5 (legacy) (PMSS
 * development/PMSS/docs/adr/0108-transmission-socket-integration.md); only the pre-4.1 protocol works
 * on both 4.1.3 and the legacy 4.0.5, so this unit never sends the newer JSON-RPC 2.0/snake_case
 * protocol that 4.1.0 introduced.
 *
 * Request/response shape (rpc-spec.md section 2): {"method", "arguments", "tag"} in,
 * {"result", "arguments", "tag"} out, `result` is the string "success" or an error message.
 * CSRF handshake (2.3.1): a 409 carries the right `X-Transmission-Session-Id` in its own response
 * headers; the same request is resent once with it. HTTP Basic auth (2.3.3), when `$username` is
 * given, is sent with every request, reading the current credential from `$secret` each time (so a
 * `login` run meanwhile is picked up without rebuilding this object) -- TransmissionLocate never
 * refuses just because authentication is required; TransmissionClient supplies both here instead.
 */
class TransmissionRpc
{
    private $url;
    private $unixSocket;
    private $timeout;
    private $username;
    private $secret;
    private $sessionId = '';
    private $tag = 0;

    /** $username/$secret: sent as HTTP Basic auth on every request when $secret is not null (a
     * TransmissionSecret; its usable() throws when nothing is stored, its reject() marks a 401). */
    public function __construct($url, $unixSocket, $timeout, $username = '', TransmissionSecret $secret = null)
    {
        $this->url = $url;
        $this->unixSocket = (string) $unixSocket;
        $this->timeout = $timeout;
        $this->username = (string) $username;
        $this->secret = $secret;
    }

    /** The `arguments` of a successful reply; CliError naming the cause otherwise. $retried (internal):
     * one 409 handshake retry only, never a loop. */
    public function call($method, array $arguments = [], $retried = false)
    {
        $headers = ['Content-Type: application/json'];
        if ($this->sessionId !== '') {
            $headers[] = 'X-Transmission-Session-Id: ' . $this->sessionId;
        }
        if ($this->secret !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->secret->usable());
        }
        $body = json_encode(['method' => $method, 'arguments' => (object) $arguments, 'tag' => ++$this->tag],
            JSON_UNESCAPED_SLASHES);
        $opt = ['headers' => $headers, 'body' => $body, 'timeout' => $this->timeout];
        if ($this->unixSocket !== '') {
            $opt['unix_socket'] = $this->unixSocket;
        }
        $r = Http::request('POST', $this->url, $opt);
        if ($r['code'] === 0) {
            throw self::transportError($r['error']);
        }
        if ($r['code'] === 401) {
            if ($this->secret !== null) {
                $this->secret->reject(); // never tried again until the user runs `login`
            }
            throw new CliError('Transmission refused the RPC credential (401); it is marked rejected and will not be tried again',
                'the user runs `php ' . dirname(__FILE__) . '/transmission.php login` again with the current rpc-password setting');
        }
        if ($r['code'] === 409) {
            $id = isset($r['headers']['x-transmission-session-id'][0]) ? (string) $r['headers']['x-transmission-session-id'][0] : '';
            if ($id === '' || $retried) {
                throw new CliError('Transmission refused the request (409) and gave no usable session id', 'try again; the daemon may be restarting');
            }
            $this->sessionId = $id;
            return $this->call($method, $arguments, true);
        }
        $reply = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($reply) || !array_key_exists('result', $reply)) {
            throw new CliError("Transmission answered HTTP {$r['code']} without a usable reply to $method",
                'is the socket or port in settings.json really transmission-daemon\'s RPC?');
        }
        if ($reply['result'] !== 'success') {
            throw new CliError('Transmission refused ' . $method . ': ' . (string) $reply['result']);
        }
        return isset($reply['arguments']) && is_array($reply['arguments']) ? $reply['arguments'] : [];
    }

    /** Http's error text -> busy (timeout: unknown, not down), refused, or another account's socket/port. */
    public static function transportError($error)
    {
        if (stripos($error, 'timed out') !== false) {
            return new CliError('Transmission is busy: no answer in time (state unknown, not necessarily down)', 'try again in a minute');
        }
        if (stripos($error, 'another account') !== false || stripos($error, "not this account's own") !== false) {
            return new CliError($error, 'the socket or port is not this user\'s Transmission; check settings.json');
        }
        if (stripos($error, 'refused') !== false || stripos($error, 'connect') !== false) {
            return new CliError('Transmission refused the connection', 'it may be starting or stopping; try again in a minute');
        }
        return new CliError('cannot reach Transmission: ' . $error);
    }
}
