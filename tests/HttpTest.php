<?php
/** lib/Http.php against a local `php -S` server: no redirects followed, size cap, headers sent. */
function httpFixtureServer()
{
    $dir = sys_get_temp_dir() . '/pk-http-' . getmypid();
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/router.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($p === "/redirect") { header("Location: http://example.invalid/"); http_response_code(302); exit; }
if ($p === "/big") { echo str_repeat("x", 300000); exit; }
if ($p === "/echo") { echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "auth" => isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "", "body" => file_get_contents("php://input")]); exit; }
http_response_code(404);
');
    $port = 20000 + getmypid() % 20000;
    $proc = proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/router.php"), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
    return [$proc, "http://127.0.0.1:$port"];
}

return [
    'http: redirects are not followed, size cap and headers hold' => function () {
        if (!function_exists('curl_init')) {
            return; // CI installs curl; a machine without it cannot run the tools either
        }
        list($proc, $base) = httpFixtureServer();
        try {
            $r = Http::request('GET', "$base/redirect");
            check($r['code'] === 302 && $r['error'] === '', 'redirect followed or failed: ' . json_encode($r));
            check($r['headers']['location'] === ['http://example.invalid/'], 'response headers not returned');
            $r = Http::request('GET', "$base/big", ['max' => 1000]);
            check($r['body'] === '' && strpos($r['error'], 'larger than') !== false, 'size cap not applied');
            $r = Http::request('POST', "$base/echo", ['headers' => ['Authorization: Bearer t'], 'body' => 'a=1']);
            $d = json_decode($r['body'], true);
            check($r['code'] === 200 && $d['method'] === 'POST' && $d['auth'] === 'Bearer t' && $d['body'] === 'a=1', 'request not sent as asked: ' . $r['body']);
            $r = Http::request('GET', 'http://127.0.0.1:1/');
            check($r['code'] === 0 && $r['error'] !== '', 'connection failure not reported');
            $port = (int) parse_url($base, PHP_URL_PORT);
            foreach (["http://127.1:$port/echo", "http://2130706433:$port/echo", "HTTP://LOCALHOST.:$port/echo"] as $url) {
                $r = Http::request('GET', $url);
                check($r['code'] === 200, "own listener not reached through $url: " . $r['error']);
            }
            foreach (['file:///etc/passwd', "http:/127.0.0.1:$port/", "http://127.0.0.1:0$port/", 'gopher://127.0.0.1:70/', 'http://0x7f000001.invalid./'] as $url) {
                $r = Http::request('GET', $url);
                check($r['code'] === 0 && $r['error'] !== '', "unchecked address was used: $url");
            }
            // A listener owned by another account (e.g. sshd as root), reached through any spelling.
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            foreach (explode("\n", (string) @file_get_contents('/proc/net/tcp')) as $line) {
                $f = preg_split('/\s+/', trim($line));
                if (count($f) > 7 && $f[3] === '0A' && (int) $f[7] !== $uid && preg_match('/^(0{8}|0100007F):/', $f[1])) {
                    $p = hexdec(substr($f[1], 9));
                    foreach (["http://127.0.0.1:$p/", "http://127.1:$p/", "http://0:$p/", "http://localhost.:$p/"] as $url) {
                        check(strpos(Http::foreignLocalListener($url), 'another account') !== false, "foreign listener not refused: $url");
                    }
                    check(Http::foreignLocalListener("http://127.0.0.1:$p/", [(int) $f[7]]) === '', 'an allowed owner was refused');
                    break;
                }
            }
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    },
];
