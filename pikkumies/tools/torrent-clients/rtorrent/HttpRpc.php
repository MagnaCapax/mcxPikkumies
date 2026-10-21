<?php
/**
 * RtorrentHttpRpc: one XML-RPC call to an rTorrent whose RPC a third-party seedbox publishes over HTTPS with basic auth
 * (docs/adr/0105-third-party-seedbox-stats-through-the-clients-own-rpc.md). The same public shape as RtorrentScgi
 * (`address`, `call`) and the same codec (RtorrentXmlRpc), so RtorrentClient builds the same records from it.
 * Refused before any request: a URL that is not https, that carries user:password@, whose port is not 443 or that holds
 * anything but a plain host and path; a host that is not the service's own host, or that the person's SSH lists refuse
 * (Services::refuseListed); and, with an auth handle, a secret whose pin does not allow that host and path (`secrets
 * set|rotate <handle> --host <host>[/path/]`, Secrets::pinAllows). A pin mismatch never suggests re-pinning to the address
 * the service record names: the record is agent-writable, the pin is what the user typed. A reply must start as XML and
 * hold no NUL byte; it is parsed without libxml's huge-document allowance and its fault text is capped. The secret value
 * comes from the store at each request and goes only into the Authorization header; no error carries it, the header or a
 * URL's userinfo. Http::request does the request: TLS verified, redirects never followed, a local port used only when
 * this account owns it.
 */
$rpcLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'Config', 'SshLists', 'Http', 'SecretFile', 'SecretsCache', 'Secrets', 'Services'] as $rpcClass) {
    require_once "$rpcLib/$rpcClass.php";
}
require_once __DIR__ . '/XmlRpc.php';
require_once __DIR__ . '/Scgi.php';

class RtorrentHttpRpc
{
    /** A plain https URL: a host name, port 443 at most, a path of plain characters; no userinfo, query, fragment or %-encoding. */
    const URL_RE = '#^https://([A-Za-z0-9][A-Za-z0-9.-]{0,252})(:443)?(/[A-Za-z0-9._~/+=,:-]*)?\z#i';

    /** @var string the https URL (never with user:password@) */
    public $address;
    private $host;
    private $user;
    private $auth;
    private $timeout;
    private $requester;

    /**
     * $url: clients.rtorrent.url; $serviceHost: the service's own host; $user: the basic-auth user; $auth: "secret:<handle>"
     * or null (no Authorization header); $requester: fn($method, $url, $options) returning Http::request's shape (tests).
     */
    public function __construct($url, $serviceHost, $user, $auth, $timeout = 15, $requester = null)
    {
        $url = (string) $url;
        if (strncasecmp($url, 'https://', 8) !== 0) {
            throw new CliError('the rtorrent RPC address is not https: the login would travel in clear; nothing was sent',
                'use the https address the provider documents (clients.rtorrent.url)');
        }
        if (strpos($url, '@') !== false) {
            throw new CliError('the rtorrent RPC address carries a user or a login (user:...@); nothing was sent',
                'give the address without them; the user goes in clients.rtorrent.user and the login in the encrypted store (auth: secret:<handle>)');
        }
        if (!preg_match(self::URL_RE, $url, $m)) {
            throw new CliError(preg_match('#^https://[^/]*:(?!443(/|\z))#i', $url) ? 'the rtorrent RPC address names a port other than 443; nothing was sent'
                : 'the rtorrent RPC address is not a plain https://host/path (no query, fragment, %-encoding, space or backslash); nothing was sent',
                'use the https address the provider documents, on the default port');
        }
        $host = SshLists::host($m[1]);
        $own = SshLists::host((string) $serviceHost);
        if ($host === null || $own === null || $host !== $own) {
            throw new CliError('the rtorrent RPC host ' . ($host !== null ? $host : '(unreadable)') . " is not the service's own host "
                . ($own !== null ? $own : '(unreadable)') . '; nothing was sent', 'a stored login goes only to the box it belongs to: fix clients.rtorrent.url or the service host');
        }
        $path = Secrets::cleanPath(isset($m[3]) ? $m[3] : '');
        if ($path === null) {
            throw new CliError('the rtorrent RPC address has an empty, "." or ".." path part (it could reach another path than it names); nothing was sent',
                'use the path the provider documents, written plainly');
        }
        Services::refuseListed($host);
        $user = (string) $user;
        if ($auth !== null && ($user === '' || preg_match('/[:\x00-\x20\x7f]/', $user))) {
            throw new CliError('the rtorrent RPC user is empty or holds a colon, space or control character; nothing was sent', 'set clients.rtorrent.user to the login name the provider documents');
        }
        if ($auth !== null) {
            $handle = Secrets::handle(Services::secretRef($auth, 'clients.rtorrent.auth')); // never echoes a raw value
            $pin = Secrets::pin($handle);
            if ($pin === null) {
                throw new CliError("secret $handle is not pinned to a host; nothing was sent",
                    "the user pins it in their own terminal with `secrets rotate $handle --host <host>/<path>/`, typing the box's address as they know it"
                    . ' (not one read from the services list); a pin changes only together with a new value');
            }
            if (!Secrets::pinAllows($pin, $host, $path)) {
                // No re-pin command here: the record is agent-writable, so its address must never become the user's next pin.
                throw new CliError("secret $handle is pinned to " . Secrets::pinText($pin) . ", but this service's record now names $host" . rtrim($path, '/')
                    . '; nothing was sent', 'the service record names another box or path than the one the user pinned: check it with `services show`.'
                    . ' A new pin is right only if the user moved the box themself, and then they type the address they know, never one from this record');
            }
            $auth = $handle;
        }
        $this->address = $url;
        $this->host = $host;
        $this->user = $user;
        $this->auth = $auth;
        $this->timeout = (int) $timeout;
        $this->requester = $requester !== null ? $requester : ['Http', 'request'];
    }

    public function call($method, array $params = [])
    {
        $body = RtorrentXmlRpc::request($method, $params);
        $headers = ['Content-Type: text/xml'];
        $hide = [];
        if ($this->auth !== null) {
            $value = Secrets::get($this->auth); // at request time: never kept on the object
            $pair = base64_encode($this->user . ':' . $value);
            $headers[] = 'Authorization: Basic ' . $pair;
            $hide = [$value, $pair, rawurlencode($value)];
            $value = null;
        }
        $r = call_user_func($this->requester, 'POST', $this->address, ['headers' => $headers, 'body' => $body, 'timeout' => $this->timeout,
            'max' => RtorrentScgi::MAX]);
        $headers = null;
        $code = is_array($r) && isset($r['code']) ? (int) $r['code'] : 0;
        $error = is_array($r) && isset($r['error']) ? self::scrub((string) $r['error'], $hide) : 'no answer';
        if ($code === 0) {
            if (stripos($error, 'timed out') !== false) {
                throw new CliError("rtorrent at {$this->host} did not answer $method within {$this->timeout} s (state unknown, not necessarily down)", 'try again in a minute');
            }
            throw new CliError("cannot reach rtorrent at {$this->host}: $error", 'check the address and the network; the provider may be down');
        }
        if ($error !== '') {
            throw new CliError("rtorrent's answer to $method from {$this->host} was cut off: $error", 'ask for less (fewer torrents at a time)');
        }
        if ($code === 401 || $code === 403) {
            throw new CliError("rtorrent at {$this->host} refused the login for $method (HTTP $code)",
                'check clients.rtorrent.user; a wrong or changed login: the user replaces the stored value with `secrets rotate <handle>` in their own terminal');
        }
        if ($code !== 200) {
            throw new CliError("rtorrent at {$this->host} answered $method with HTTP $code", 'check clients.rtorrent.url against the provider\'s documentation (redirects are not followed)');
        }
        $reply = isset($r['body']) ? (string) $r['body'] : '';
        if (strpos($reply, "\0") !== false || preg_match('/^(\xEF\xBB\xBF)?\s*<(\?xml|methodResponse)/', $reply) !== 1) {
            // a NUL (UTF-16 and the like, which hides a DOCTYPE from the codec's check) or anything but an XML-RPC reply
            throw new CliError("rtorrent at {$this->host} answered $method with something that is not an XML-RPC reply", 'check clients.rtorrent.url: it must be the RPC address, not the web interface');
        }
        try {
            return RtorrentXmlRpc::response($reply, $method, true);
        } catch (CliError $e) {
            throw new CliError(self::scrub($e->getMessage(), $hide), self::scrub((string) $e->hint, $hide));
        }
    }

    /** $text with every needle (the secret and its encoded forms) replaced: a provider's reply never echoes them to the model. */
    private static function scrub($text, array $needles)
    {
        foreach ($needles as $n) {
            if ((string) $n !== '') {
                $text = str_replace((string) $n, '[REDACTED]', $text);
            }
        }
        return $text;
    }
}
