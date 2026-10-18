<?php
/**
 * MailTransport: the one mail connection of the email units (ADR-0053), PHP curl like lib/Http.php. TLS only and always
 * verified: imaps 993, pop3s 995, smtps 465, or smtp 587 with STARTTLS required (CURLUSESSL_ALL: a server that offers no
 * TLS fails before any login is sent). No port 25, no plaintext fallback, no CA file or skip-verify from any config.
 * A port on this machine is used only when this account owns the listener (Http::foreignLocalListener, ADR-0019), and
 * curl is pinned to the address checked. Every read is capped in the write callback (CURLOPT_MAXFILESIZE does not apply
 * to mail): at the cap the callback returns 0 and curl stops. One curl handle per object, so the IMAP connection and its
 * selected folder are reused between calls.
 */
class MailTransport
{
    const PORTS = [993 => 'imaps', 995 => 'pop3s', 465 => 'smtps', 587 => 'smtp'];
    const MAX = 52428800; // default read cap, bytes

    /** @var callable|null test seam: fn(array $request) => ['errno' => int, 'error' => string, 'body' => string]; never set outside tests */
    public static $fake = null;
    /** @var array test seam: [port => scheme] also allowed, for a fake server on 127.0.0.1; never set outside tests */
    public static $extraPorts = [];

    private $scheme;
    private $host;
    private $port;
    private $user;
    private $secret;
    private $pin = null;
    private $ch = null;

    public function __construct($scheme, $host, $port, $user, $secret)
    {
        self::endpoint($scheme, $host, $port);
        if (!is_string($user) || $user === '' || preg_match('/[\x00-\x1F\x7F]/', $user . $secret)) {
            throw new CliError('the stored login has an empty user name or a control character', 'run login again');
        }
        list($this->scheme, $this->host, $this->port, $this->user, $this->secret) = [$scheme, strtolower($host), (int) $port, $user, (string) $secret];
    }

    /** Refuses anything but a TLS mail port with its scheme and a plain host name or address; '-' first is never an option. */
    public static function endpoint($scheme, $host, $port)
    {
        $ports = self::PORTS + self::$extraPorts;
        if (!isset($ports[(int) $port]) || $ports[(int) $port] !== $scheme) {
            throw new CliError("port $port is not a TLS mail port for $scheme", 'IMAP uses 993, POP 995, SMTP 465 or 587 (STARTTLS); 25, 110 and 143 are never used');
        }
        if (!is_string($host) || strlen($host) > 253 || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $host) || strpos($host, '..') !== false) {
            throw new CliError('not a mail server host name: ' . Cli::cleanString((string) $host), 'type the server name, e.g. imap.example.org');
        }
    }

    /** The curl options of one request ($r: url, custom, nobody, from, rcpt, upload). Public so tests can check TLS. */
    public function options(array $r, callable $write)
    {
        $proto = ['imaps' => CURLPROTO_IMAPS, 'pop3s' => CURLPROTO_POP3S, 'smtps' => CURLPROTO_SMTPS, 'smtp' => CURLPROTO_SMTP];
        $o = [
            CURLOPT_URL => $r['url'],
            CURLOPT_PROTOCOLS => $proto[$this->scheme],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // TLS 1.2 as the minimum on libcurl 7.54 and newer, which negotiates a newer version when both sides have
            // it; on older libcurl (the ADR names 7.52) this setting means exactly TLS 1.2.
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_USERNAME => $this->user,
            CURLOPT_PASSWORD => $this->secret,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_LOW_SPEED_LIMIT => 512,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($write) {
                return $write($chunk);
            },
        ];
        if ($this->pin !== null) {
            $o[CURLOPT_RESOLVE] = [$this->pin];
        }
        if (isset($r['custom'])) {
            $o[CURLOPT_CUSTOMREQUEST] = $r['custom'];
        }
        if (!empty($r['nobody'])) {
            $o[CURLOPT_NOBODY] = true;
        }
        if (isset($r['upload'])) {
            $data = (string) $r['upload'];
            $pos = 0; // an offset, so each call copies only its own piece (up to 20 MiB in pieces of about 16 KiB)
            $o += [CURLOPT_MAIL_FROM => '<' . $r['from'] . '>', CURLOPT_MAIL_RCPT => array_map(function ($a) {
                return "<$a>";
            }, $r['rcpt']), CURLOPT_UPLOAD => true, CURLOPT_INFILESIZE => strlen($data),
                CURLOPT_READFUNCTION => function ($ch, $fd, $n) use ($data, &$pos) {
                    $out = (string) substr($data, $pos, $n);
                    $pos += strlen($out);
                    return $out;
                }];
        }
        return $o;
    }

    /**
     * One request. $path: the URL path after the host, already encoded by the caller (MailImap::url); custom: a
     * command the caller built and escaped. Options: max (bytes), sink (callable($chunk): the bytes go there
     * instead of being returned; false from it stops the transfer). Returns the body (or the byte count with a
     * sink); throws CliError.
     */
    public function request($path, array $r = [])
    {
        foreach (['custom', 'from'] as $k) {
            if (isset($r[$k]) && preg_match('/[\x00-\x1F\x7F]/', (string) $r[$k])) {
                throw new CliError("a control character in the $k; nothing sent", 'this is a bug in the tool');
            }
        }
        foreach (isset($r['rcpt']) ? $r['rcpt'] : [] as $a) {
            if (preg_match('/[\x00-\x20\x7F<>]/', (string) $a)) {
                throw new CliError('a control character or <> in a recipient; nothing sent', 'check the addresses');
            }
        }
        $r['url'] = $this->scheme . '://' . $this->host . ':' . $this->port . $path;
        $max = isset($r['max']) ? (int) $r['max'] : self::MAX;
        $sink = isset($r['sink']) ? $r['sink'] : null;
        $body = '';
        $bytes = 0;
        $over = false;
        $write = function ($chunk) use (&$body, &$bytes, &$over, $max, $sink) {
            $bytes += strlen($chunk);
            if ($bytes > $max) {
                $over = true;
                return 0; // curl ends the transfer here
            }
            if ($sink !== null) {
                return call_user_func($sink, $chunk) === false ? 0 : strlen($chunk);
            }
            $body .= $chunk;
            return strlen($chunk);
        };
        if (self::$fake !== null) {
            $req = array_diff_key($r, ['sink' => 1, 'max' => 1]) + ['scheme' => $this->scheme, 'user' => $this->user];
            $res = call_user_func(self::$fake, $req) + ['errno' => 0, 'error' => '', 'body' => ''];
            foreach (str_split((string) $res['body'], 8192) as $chunk) {
                if ($chunk !== '' && $write($chunk) !== strlen($chunk)) {
                    $res = ['errno' => 23, 'error' => 'write error'];
                    break;
                }
            }
            $errno = (int) $res['errno'];
            $error = (string) $res['error'];
        } else {
            list($errno, $error) = $this->perform($r, $write);
        }
        if ($over) {
            throw new CliError("the answer is larger than $max bytes; stopped", 'nothing larger is read; ask for a smaller message or a part of it');
        }
        if ($errno !== 0) {
            throw self::error($errno, $error);
        }
        return $sink !== null ? $bytes : $body;
    }

    private function perform(array $r, callable $write)
    {
        if (!function_exists('curl_init')) {
            return [2, 'the PHP curl extension is missing'];
        }
        if ($this->pin === null) {
            $refusal = Http::foreignLocalListener("https://{$this->host}:{$this->port}/", [], $pin);
            if ($refusal !== '') {
                throw new CliError($refusal, 'check the server name stored by login');
            }
            $this->pin = $pin;
        }
        if ($this->ch === null) {
            $this->ch = curl_init();
        } else {
            curl_reset($this->ch); // options cleared, the open connection kept
        }
        curl_setopt_array($this->ch, $this->options($r, $write));
        $ok = curl_exec($this->ch);
        return $ok === false ? [curl_errno($this->ch), curl_error($this->ch)] : [0, ''];
    }

    /** Ends the connection (POP3: QUIT, which makes DELE final); the next request opens a new one. */
    public function close()
    {
        if ($this->ch !== null && PHP_VERSION_ID < 80000) {
            curl_close($this->ch);
        }
        $this->ch = null; // PHP 8 frees the handle, and curl closes the connection, when the last reference goes
    }

    /** A curl error number as a CliError the user can act on; TLS problems are never retried without TLS. */
    public static function error($errno, $error)
    {
        $tls = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];
        if ($errno === 64) {
            return new CliError('the server offers no TLS; no login was sent', 'use the TLS port (993, 995, 465 or 587); plaintext is never used');
        }
        if (in_array($errno, $tls, true)) {
            return new CliError("TLS failed: $error", 'check the server name; the certificate must be valid for it, and plaintext is never tried instead');
        }
        $known = [6 => ['cannot resolve the server name', 'check the server name stored by login'],
            7 => ['cannot connect to the server', 'check the server name and port, or try again later'],
            28 => ['the server took too long', 'try again later'],
            67 => ['the server refused the login', 'run login again; many providers need an app password for mail clients'],
            78 => ['no such message or folder', 'list the folder again; the uid may be gone'],
            21 => ['the server refused the command', 'check the folder name and uid; the server said no']];
        if (isset($known[$errno])) {
            return new CliError($known[$errno][0] . ($error !== '' ? " ($error)" : ''), $known[$errno][1]);
        }
        return new CliError("mail transfer failed: curl error $errno" . ($error !== '' ? " ($error)" : ''), 'try again; if it repeats, check the server settings with login');
    }
}
