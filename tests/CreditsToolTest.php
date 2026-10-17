<?php
/**
 * tools/system/credits: `balance` answers "how many AI credits do I have left?" so the agent never reads the key
 * (origin: in a live test the agent grepped the home for its own key and misread `window`, the last 60 seconds, as a
 * month). Runs the real script against a local php -S fixture, with a fixture HOME and the $creditsConfig seam, so the
 * checkout's real local/config.php and key files are never read.
 */
const CREDITS_ORACLE_KEY = 'vsk-S3CRET-credits-oracle-7f3a9c';

/** A php -S fixture for GET .../balance; it logs every request path to $dir/requests.log. Returns [proc, base url, dir]. */
function creditsBalanceServer()
{
    $dir = testTempDir('pk-credits');
    $key = var_export('Bearer ' . CREDITS_ORACLE_KEY, true);
    file_put_contents("$dir/router.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$auth = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "";
file_put_contents(__DIR__ . "/requests.log", $p . "\n", FILE_APPEND);
header("Content-Type: application/json");
if ($p === "/ok/balance" && $auth === ' . $key . ') {
    echo "{\"balance\": 49999961, \"reserved\": 120, \"in_flight\": 1, \"limits\": {\"max_concurrent\": 5, \"requests_per_minute\": null, \"credits_per_minute\": null}, \"window\": {\"requests\": 1, \"credits\": 39}, \"bonus_pct\": 0, \"weight\": 707}";
    return;
}
if ($p === "/refused/balance") { http_response_code(401); echo json_encode(["error" => "bad key " . $auth]); return; }
if ($p === "/echo/balance") { http_response_code(500); echo json_encode(["error" => "upstream saw " . $auth]); return; }
http_response_code(404); echo "{\"error\": \"not found " . addslashes($auth) . "\"}";');
    $port = testFreePort();
    $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/router.php"),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
    return [$proc, "http://127.0.0.1:$port", $dir];
}

/**
 * Runs credits.php with HOME=$home, PIKKUMIES_* unset unless $env sets them, and the given provider endpoint.
 * Returns [exit code, stdout, stderr].
 */
function creditsRun($dir, $home, $endpoint, array $args, array $env = [])
{
    $env += ['HOME' => $home, 'PIKKUMIES_KEY' => null, 'PIKKUMIES_ENDPOINT' => null];
    $code = "<?php\n";
    foreach ($env as $k => $v) {
        $code .= 'putenv(' . var_export($v === null ? $k : "$k=$v", true) . ");\n";
    }
    $config = ['provider' => ['endpoint' => $endpoint, 'model' => '', 'keyFile' => "$home/.pikkumiesKey", 'virsilipasKeyFile' => "$home/.virsilipasKey"]];
    $code .= '$creditsConfig = ' . var_export($config, true) . ";\n";
    $code .= 'require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/system/credits/credits.php', true) . ";\n";
    file_put_contents("$dir/run.php", $code);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/run.php");
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

/** A fixture HOME holding ~/.virsilipasKey with $endpoint and the oracle key. */
function creditsHome($dir, $endpoint)
{
    $home = "$dir/home";
    @mkdir($home, 0700, true);
    file_put_contents("$home/.virsilipasKey", "endpoint=$endpoint\nkey=" . CREDITS_ORACLE_KEY . "\n");
    return $home;
}

function creditsRequests($dir)
{
    return is_file("$dir/requests.log") ? array_values(array_filter(explode("\n", (string) file_get_contents("$dir/requests.log")), 'strlen')) : [];
}

return [
    'credits: balance prints one record with the API fields, `window` renamed to last_minute, key_from the file and never the key' => function () {
        list($proc, $base, $dir) = creditsBalanceServer();
        try {
            $home = creditsHome($dir, "$base/ok");
            list($code, $out, $err) = creditsRun($dir, $home, '', ['balance']);
            $lines = cliLines($out);
            check($code === 0 && count($lines) === 2 && $lines[1]['ok'] === true, "balance failed ($code): $out $err");
            $r = $lines[0];
            $want = [
                'balance' => 49999961, 'reserved' => 120, 'in_flight' => 1,
                'limits' => ['max_concurrent' => 5, 'requests_per_minute' => null, 'credits_per_minute' => null],
                'last_minute' => ['requests' => 1, 'credits' => 39],
                'key_from' => "$home/.virsilipasKey", 'endpoint_host' => '127.0.0.1',
            ];
            check($r === $want, 'record: ' . json_encode($r));
            check(!array_key_exists('window', $r), '`window` must be renamed to last_minute (it is the last 60 seconds)');
            check(creditsRequests($dir) === ['/ok/balance'], 'exactly one GET /balance expected: ' . json_encode(creditsRequests($dir)));
            list($code, $out) = creditsRun($dir, $home, '', ['balance'], ['PIKKUMIES_KEY' => CREDITS_ORACLE_KEY]);
            check($code === 0 && cliLines($out)[0]['key_from'] === 'PIKKUMIES_KEY', 'a key from the environment must be named PIKKUMIES_KEY: ' . $out);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits: an endpoint without a balance route (404) is a CliError that says where a key comes from' => function () {
        list($proc, $base, $dir) = creditsBalanceServer();
        try {
            $home = creditsHome($dir, "$base/none");
            list($code, $out) = creditsRun($dir, $home, '', ['balance']);
            $s = cliLines($out);
            $s = end($s);
            check($code === 1 && $s['ok'] === false && strpos($s['error'], 'no balance route') !== false, "404 must be a CliError ($code): $out");
            foreach (['~/.virsilipasKey', 'Virsilipas AI key', 'AI credits', '~/.pikkumiesKey'] as $where) {
                check(strpos($s['hint'], $where) !== false, "the 404 hint must name $where: " . $s['hint']);
            }
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits: no key at all is a CliError naming the key locations, and no request is made' => function () {
        list($proc, $base, $dir) = creditsBalanceServer();
        try {
            $home = "$dir/home";
            mkdir($home, 0700, true);
            file_put_contents("$home/.pikkumiesKey", "endpoint=$base/ok\n"); // an endpoint but no key
            list($code, $out) = creditsRun($dir, $home, "$base/ok", ['balance']);
            $s = cliLines($out);
            $s = end($s);
            check($code === 1 && $s['ok'] === false && strpos($s['error'], 'no AI key') !== false, "no key must be a CliError ($code): $out");
            check(strpos($s['hint'], '~/.virsilipasKey') !== false && strpos($s['hint'], 'Virsilipas AI key') !== false && strpos($s['hint'], '~/.pikkumiesKey') !== false, 'hint: ' . $s['hint']);
            check(creditsRequests($dir) === [], 'no request may be made without a key: ' . json_encode(creditsRequests($dir)));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits: the key value never appears in stdout, stderr, error or hint, whatever the endpoint answers' => function () {
        list($proc, $base, $dir) = creditsBalanceServer();
        try {
            $seen = 0;
            foreach (['ok', 'none', 'refused', 'echo'] as $route) {
                foreach ([[], ['--human']] as $flags) {
                    $home = creditsHome($dir, "$base/$route");
                    list($code, $out, $err) = creditsRun($dir, $home, '', array_merge(['balance'], $flags));
                    check(trim($out . $err) !== '', "$route: no output at all");
                    foreach (['S3CRET', CREDITS_ORACLE_KEY] as $needle) {
                        check(strpos($out . $err, $needle) === false, "$route " . implode(' ', $flags) . ": the key leaked: $out $err");
                    }
                    $seen++;
                }
            }
            // The fixture echoes the Authorization header in its error bodies, so the oracle could have leaked there.
            check($seen === 8 && count(creditsRequests($dir)) === 8, 'every route must have been asked: ' . json_encode(creditsRequests($dir)));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
];
