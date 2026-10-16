<?php
/**
 * lib/Http.php and lib/Host.php: edges review found uncovered (2026-10-16) — the curl-missing path, exact refusal
 * text for a bad scheme and an unresolvable host, User-Agent/method pass-through, repeated response headers, the
 * byte cap keeping the real HTTP code, IPv6 bracket spellings of a local address, the 'system' owner-widening
 * Http.php hands to Host::ownsListener(), and a hung backend actually being cut off.
 */

/** A free TCP port on 127.0.0.1. */
if (!function_exists('hhFreePort')) {
    function hhFreePort()
    {
        $srv = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($srv, false), ':'), 1);
        fclose($srv);
        return $port;
    }
}

/** A `php -S` server for /echo (method + User-Agent), /big (300000 bytes) and /slow (2s delay then ok). */
if (!function_exists('hhServer')) {
    function hhServer()
    {
        $dir = testTempDir('pk-hh');
        file_put_contents("$dir/router.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($p === "/echo") { echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "agent" => isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : ""]); exit; }
if ($p === "/big") { echo str_repeat("x", 300000); exit; }
if ($p === "/slow") { usleep(2000000); echo "ok"; exit; }
if ($p === "/headers") { header("Set-Cookie: a=1", false); header("Set-Cookie: b=2", false); header("X-Empty-Value:"); echo "ok"; exit; }
http_response_code(404);
');
        $port = hhFreePort();
        $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/router.php"),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
            if ($s) {
                fclose($s);
                break;
            }
            usleep(100000);
        }
        return [$proc, "http://127.0.0.1:$port", $dir];
    }
}

if (!function_exists('hhServerDone')) {
    function hhServerDone($fx)
    {
        list($proc, , $dir) = $fx;
        proc_terminate($proc);
        proc_close($proc);
        testRmTree($dir);
    }
}

return [
    'http: request() returns the curl-missing error untouched when the curl extension is absent (php -n)' => function () {
        $script = tempnam(sys_get_temp_dir(), 'pk-hh-nocurl');
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/pikkumies/lib/Http.php', true) . ';
echo extension_loaded("curl") ? "curl-still-loaded" : serialize(Http::request("GET", "http://127.0.0.1:1/"));
');
        $cmd = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        unlink($script);
        if ($out === 'curl-still-loaded') {
            skip('curl is built into this PHP, so php -n cannot remove it');
        }
        // serialize(), not json_encode(): php -n also drops a shared json extension (PHP 7.3 in CI).
        check(@unserialize($out) === ['code' => 0, 'body' => '', 'headers' => [], 'error' => 'the PHP curl extension is missing'],
            "curl-missing shape wrong (or php -n did not disable curl here): $out");
    },
    'http: foreignLocalListener refuses a non-http(s) scheme, and an address with no host name, with the exact message' => function () {
        $want = 'not an http or https address with a host name; not connecting';
        foreach (['ftp://127.0.0.1/', 'file:///etc/passwd', 'gopher://127.0.0.1:70/', 'http:///no-host', '', 'not a url at all'] as $url) {
            check(Http::foreignLocalListener($url) === $want, "scheme refusal wrong for '$url': " . Http::foreignLocalListener($url));
        }
    },
    'http: foreignLocalListener refuses an unresolvable .invalid hostname with the exact message' => function () {
        $host = 'pk-test-does-not-exist-' . mt_rand() . '.invalid';
        check(Http::foreignLocalListener("http://$host/") === "cannot resolve $host; not connecting",
            'unresolvable-host refusal wrong: ' . Http::foreignLocalListener("http://$host/"));
    },
    'http: default/custom User-Agent and PUT/DELETE/HEAD methods all pass through unchanged' => function () {
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
        $fx = hhServer();
        try {
            $r = Http::request('GET', $fx[1] . '/echo');
            check(json_decode($r['body'], true)['agent'] === 'pikkumies', 'default User-Agent not sent: ' . $r['body']);
            $r = Http::request('GET', $fx[1] . '/echo', ['agent' => 'my-custom-agent/1']);
            check(json_decode($r['body'], true)['agent'] === 'my-custom-agent/1', 'custom User-Agent not sent: ' . $r['body']);
            foreach (['PUT', 'DELETE', 'HEAD'] as $m) {
                $r = Http::request($m, $fx[1] . '/echo');
                $want = $m === 'HEAD' ? '' : json_decode($r['body'] !== '' ? $r['body'] : '{}', true);
                if ($m !== 'HEAD') {
                    check($want['method'] === $m, "$m was not sent as-is: " . $r['body']);
                } else {
                    check($r['code'] === 200, 'HEAD request failed: ' . $r['error']);
                }
            }
        } finally {
            hhServerDone($fx);
        }
    },
    'http: a response over the byte cap still reports the real curl HTTP status code, not 0' => function () {
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
        $fx = hhServer();
        try {
            $r = Http::request('GET', $fx[1] . '/big', ['max' => 1000]);
            check($r['body'] === '' && strpos($r['error'], 'larger than') !== false, 'size cap not applied: ' . json_encode($r));
            check($r['code'] === 200, 'a size-cap trip reported code 0 instead of the real HTTP status: ' . json_encode($r));
        } finally {
            hhServerDone($fx);
        }
    },
    'http: a timeout shorter than a deliberately slow route aborts with a non-empty error instead of hanging' => function () {
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
        $fx = hhServer();
        try {
            $started = microtime(true);
            $r = Http::request('GET', $fx[1] . '/slow', ['timeout' => 1]);
            $took = microtime(true) - $started;
            check($r['code'] === 0 && $r['error'] !== '', 'a slow backend was not reported as a failure: ' . json_encode($r));
            check($took < 3.0, "a 1s timeout against a 2s route took {$took}s; the agent turn would hang");
        } finally {
            hhServerDone($fx);
        }
    },
    'http: repeated response headers (e.g. two Set-Cookie) collect into a list, not overwrite one another' => function () {
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
        // A real colon-less raw header line cannot be driven through a live curl request: curl's own
        // HTTP parser refuses it ("Header without colon") and aborts the transfer before our
        // CURLOPT_HEADERFUNCTION callback could see it, confirmed this session against a raw TCP
        // fixture. The code's colon-less-line skip (Http.php: `if ($p !== false) {...}`) is therefore
        // defensive dead code against a real curl client; this test covers what curl does deliver.
        $fx = hhServer();
        try {
            $r = Http::request('GET', $fx[1] . '/headers');
            check($r['code'] === 200 && $r['body'] === 'ok', 'request failed: ' . json_encode($r));
            check($r['headers']['set-cookie'] === ['a=1', 'b=2'], 'repeated headers not collected as a list: ' . json_encode($r['headers']));
            check(isset($r['headers']['x-empty-value']) && $r['headers']['x-empty-value'] === [''], 'a header with an empty value was lost: '
                . json_encode($r['headers']));
        } finally {
            hhServerDone($fx);
        }
    },
    'http: IPv6 bracket-notation spellings of a local address are refused for a foreign-owned port like IPv4 spellings' => function () {
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $found = false;
        foreach (explode("\n", (string) @file_get_contents('/proc/net/tcp')) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) > 7 && $f[3] === '0A' && (int) $f[7] !== $uid && preg_match('/^(0{8}|0100007F):/', $f[1])) {
                $p = hexdec(substr($f[1], 9));
                foreach (["http://[::1]:$p/", "http://[::ffff:127.0.0.1]:$p/"] as $url) {
                    check(strpos(Http::foreignLocalListener($url), 'another account') !== false,
                        "IPv6-mapped spelling did not refuse a foreign-owned port: $url -> " . Http::foreignLocalListener($url));
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            skip('no foreign-owned local listener found in /proc/net/tcp on this machine');
        }
    },
    "host: Http's 'system' owner-widening lets uids 0-999 own a local listener, never an arbitrary uid" => function () {
        $table = function ($uid) {
            return "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
                . sprintf("   0: 0100007F:1F90 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 1 1\n", $uid);
        };
        // What Http::foreignLocalListener computes for $owners = 'system' (Http.php: range(0, 999)).
        $systemAllowed = array_merge([424242], range(0, 999));
        check(Host::ownsListener(8080, $systemAllowed, [$table(500), '']) === 'own', "uid 500 must be allowed under 'system' widening");
        check(Host::ownsListener(8080, $systemAllowed, [$table(1000), '']) === 'other', "uid 1000 must NOT be allowed under 'system' widening");
        check(Host::ownsListener(8080, $systemAllowed, [$table(50000), '']) === 'other', 'an arbitrary high uid must never be treated as a system owner');
        // Without 'system', only the caller's own uid (plus any explicit $owners) is allowed.
        check(Host::ownsListener(8080, [424242], [$table(500), '']) === 'other', 'uid 500 must NOT be allowed without the system widening');
    },
];
