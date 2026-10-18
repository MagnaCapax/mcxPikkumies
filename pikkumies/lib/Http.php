<?php
/**
 * Http: the one HTTP client of the runtime and the tools (PHP curl; ADR-0012 made curl required).
 * Never follows redirects, verifies TLS, stops reading past a size cap, writes no cookie jar
 * (callers read session cookies from the returned headers), and connects to a port on this machine
 * only when this user owns the listener.
 */
require_once __DIR__ . '/Host.php';

class Http
{
    const MAX = 5242880; // default response cap, bytes

    /**
     * Options: headers (list of "Name: value"), body (string), fields (array name => string|CURLFile:
     * a multipart/form-data request, curl builds it; exclusive with body), timeout (s, default 15),
     * max (bytes), agent (User-Agent), owners (extra uids allowed to own a local listener, or 'system'),
     * discard (true: count the response bytes and keep none, for a throughput test; reaching max or the timeout
     * after the first byte ends the transfer and is not an error).
     * Returns ['code' => int (0 when no response), 'body' => string, 'headers' => [lowercase name => [values]],
     * 'error' => string]; with discard also 'bytes' (received), 'total_time' and 'start_time' (s, curl's own
     * clock: whole transfer, and until the first byte) and 'stopped' ('bytes', 'time' or null when it ended itself);
     * a transfer that broke off after some bytes keeps its HTTP code next to the error.
     */
    public static function request($method, $url, array $o = [])
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'body' => '', 'headers' => [], 'error' => 'the PHP curl extension is missing'];
        }
        if (isset($o['body']) && isset($o['fields'])) {
            return ['code' => 0, 'body' => '', 'headers' => [], 'error' => "'body' and 'fields' are exclusive; pass one or the other"];
        }
        $refusal = self::foreignLocalListener($url, isset($o['owners']) ? $o['owners'] : [], $pin);
        if ($refusal !== '') {
            return ['code' => 0, 'body' => '', 'headers' => [], 'error' => $refusal];
        }
        $max = isset($o['max']) ? (int) $o['max'] : self::MAX;
        $discard = !empty($o['discard']);
        $bytes = 0;
        $stopped = null;
        $body = '';
        $headers = [];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$pin], // connect to the address foreignLocalListener checked
            CURLOPT_TIMEOUT => isset($o['timeout']) ? (int) $o['timeout'] : 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => isset($o['agent']) ? $o['agent'] : 'pikkumies',
            CURLOPT_HTTPHEADER => isset($o['headers']) ? $o['headers'] : [],
            CURLOPT_WRITEFUNCTION => $discard ? function ($ch, $chunk) use (&$bytes, &$stopped, $max) {
                $bytes += strlen($chunk); // counted, never kept: a 512 MiB test costs no memory
                if ($bytes >= $max) {
                    $stopped = 'bytes';
                    return 0; // curl ends the transfer here
                }
                return strlen($chunk);
            } : function ($ch, $chunk) use (&$body, $max) {
                $body .= $chunk;
                return strlen($body) > $max ? 0 : strlen($chunk);
            },
        ];
        if (isset($o['body'])) {
            $opts[CURLOPT_POSTFIELDS] = (string) $o['body'];
        } elseif (isset($o['fields'])) {
            $opts[CURLOPT_POSTFIELDS] = (array) $o['fields']; // curl builds the multipart/form-data body itself
        }
        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, $line) use (&$headers) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $headers[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        };
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($discard) {
            if ($stopped === null && curl_errno($ch) === 28 && $bytes > 0) {
                $stopped = 'time'; // CURLE_OPERATION_TIMEDOUT after data arrived: the time cap, not a failure
            }
            $failed = $ok === false && $stopped === null;
            $r = ['code' => $failed && $bytes === 0 ? 0 : $code, 'body' => '', 'headers' => $headers, 'error' => $failed ? $err : '', 'bytes' => $bytes,
                'total_time' => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3), 'start_time' => round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME), 3),
                'stopped' => $stopped];
            curl_close($ch);
            return $r;
        }
        curl_close($ch);
        if (strlen($body) > $max) {
            return ['code' => $code, 'body' => '', 'headers' => $headers, 'error' => "response larger than $max bytes"];
        }
        return ['code' => $ok === false ? 0 : $code, 'body' => $body, 'headers' => $headers, 'error' => $ok === false ? $err : ''];
    }

    /**
     * Every account on a shared server can reach every local port, so a URL that reaches this
     * machine is used only when the listening socket is this user's own (ADR-0019). Returns '' or
     * the refusal text. $pin receives "host:port:ip" so curl connects to the address checked here
     * and DNS cannot change between the check and the connection. $owners: extra allowed uids;
     * 'system' allows uids below 1000 (root and service accounts, never another customer).
     */
    public static function foreignLocalListener($url, $owners = [], &$pin = null)
    {
        $pin = null;
        $u = parse_url((string) $url);
        $scheme = is_array($u) && isset($u['scheme']) ? strtolower($u['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true) || !isset($u['host']) || $u['host'] === '') {
            return 'not an http or https address with a host name; not connecting';
        }
        $host = strtolower(rtrim(trim($u['host'], '[]'), '.'));
        // gethostbynamel() returns false, not [], when it cannot resolve: (array) false is [false],
        // a one-element truthy array, not an empty one, so the "cannot resolve" check below never
        // fired for this failure. is_array() keeps that check live for a real DNS failure.
        $v4 = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : @gethostbynamel($host);
        $ips = is_array($v4) ? $v4 : [];
        if (!filter_var($host, FILTER_VALIDATE_IP) && function_exists('dns_get_record')) {
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
                if (isset($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (!$ips) {
            return "cannot resolve $host; not connecting";
        }
        $port = isset($u['port']) ? (int) $u['port'] : ($scheme === 'https' ? 443 : 80);
        $pin = $u['host'] . ":$port:" . (strpos($ips[0], ':') !== false ? '[' . $ips[0] . ']' : $ips[0]);
        $mine = [];
        foreach (function_exists('net_get_interfaces') ? (array) @net_get_interfaces() : [] as $iface) {
            foreach (isset($iface['unicast']) ? $iface['unicast'] : [] as $a) {
                if (isset($a['address'])) {
                    $mine[] = strtolower($a['address']);
                }
            }
        }
        $local = strpos($host, 'localhost') !== false
            || preg_match('/^(127\.|0\.|::1$|::$|::ffff:(127\.|0\.))/i', $ips[0])
            || in_array(strtolower($ips[0]), $mine, true);
        if (!$local) {
            return '';
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $allowed = array_merge([$uid], $owners === 'system' ? range(0, 999) : (array) $owners);
        $owner = Host::ownsListener($port, $allowed, null, [$ips[0]]);
        if ($owner === 'other') {
            return "port $port on this machine belongs to another account; not connecting";
        }
        if ($owner === 'unknown') {
            return "cannot check who owns port $port (/proc/net is not readable); not connecting";
        }
        return '';
    }
}
