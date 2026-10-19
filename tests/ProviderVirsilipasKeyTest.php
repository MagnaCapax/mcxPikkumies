<?php
/**
 * The platform key file ~/.virsilipasKey (ADR-0004 amendment): used when ~/.pikkumiesKey holds no key, never
 * over it, ignored when it is a symlink; and Provider::balance() reads GET {endpoint}/balance, null for any
 * other answer.
 */
function pvBalanceServer($routerBody)
{
    $dir = testTempDir('pk-pvbal');
    file_put_contents("$dir/router.php", "<?php\n$routerBody");
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
    fclose($srv);
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

return [
    'provider: ~/.virsilipasKey is used when ~/.pikkumiesKey has no key, endpoint and key from the same file' => function () {
        $dir = testTempDir('pk-pvkey');
        try {
            $p = ['endpoint' => '', 'model' => '', 'keyFile' => "$dir/.pikkumiesKey", 'virsilipasKeyFile' => "$dir/.virsilipasKey"];
            file_put_contents("$dir/.virsilipasKey", "endpoint=https://proxy.example/v1\nkey=vsk-platform-key-0001\n");
            $r = testWithEnv(['PIKKUMIES_ENDPOINT' => null, 'PIKKUMIES_KEY' => null], function () use ($p) {
                return Provider::resolve(['provider' => $p]);
            });
            check($r['endpoint'] === 'https://proxy.example/v1' && $r['key'] === 'vsk-platform-key-0001', 'platform file not used: ' . json_encode($r));
            check(Provider::keyFile($p)['path'] === "$dir/.virsilipasKey", 'keyFile() must name the file used');
            file_put_contents("$dir/.pikkumiesKey", "endpoint=https://own.example/v1\n"); // an endpoint but no key: not a usable file
            check(Provider::keyFile($p)['path'] === "$dir/.virsilipasKey", 'a key file without a key must not shadow the platform file');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'provider: ~/.pikkumiesKey with a key wins over ~/.virsilipasKey; a symlinked platform file is ignored' => function () {
        $dir = testTempDir('pk-pvkey2');
        try {
            $p = ['endpoint' => '', 'model' => '', 'keyFile' => "$dir/.pikkumiesKey", 'virsilipasKeyFile' => "$dir/.virsilipasKey"];
            file_put_contents("$dir/.pikkumiesKey", "endpoint=https://own.example/v1\nkey=own-key-0001\n");
            file_put_contents("$dir/.virsilipasKey", "endpoint=https://proxy.example/v1\nkey=vsk-platform-key-0001\n");
            $f = Provider::keyFile($p);
            check($f['key'] === 'own-key-0001' && $f['endpoint'] === 'https://own.example/v1', 'the user\'s own key file must win: ' . json_encode($f));
            unlink("$dir/.pikkumiesKey");
            rename("$dir/.virsilipasKey", "$dir/real");
            symlink("$dir/real", "$dir/.virsilipasKey");
            check(Provider::keyFile($p)['key'] === '', 'a symlinked ~/.virsilipasKey must be ignored');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'provider: balance() returns the integer balance from GET /balance, null for 404, a non-integer or no key' => function () {
        list($proc, $base, $dir) = pvBalanceServer('
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$auth = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "";
header("Content-Type: application/json");
if ($p === "/ok/balance" && $auth === "Bearer k1") { echo "{\"balance\": 49999982, \"reserved\": 0}"; return; }
if ($p === "/float/balance") { echo "{\"balance\": 1.5}"; return; }
http_response_code(404); echo "{}";');
        try {
            check(Provider::balance(['endpoint' => "$base/ok", 'key' => 'k1', 'model' => '']) === 49999982, 'balance not read');
            check(Provider::balance(['endpoint' => "$base/ok", 'key' => 'wrong', 'model' => '']) === null, 'a refused key must give null');
            check(Provider::balance(['endpoint' => "$base/none", 'key' => 'k1', 'model' => '']) === null, '404 must give null');
            check(Provider::balance(['endpoint' => "$base/float", 'key' => 'k1', 'model' => '']) === null, 'a non-integer balance must give null');
            check(Provider::balance(['endpoint' => "$base/ok", 'key' => '', 'model' => '']) === null, 'no key must give null without a request');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'provider: resolve() combines a configured endpoint with a key read only from ~/.virsilipasKey (key never comes from local/config.php); PIKKUMIES_ENDPOINT/PIKKUMIES_KEY env still wins over all of it' => function () {
        $dir = testTempDir('pk-pvcombo');
        try {
            $p = ['endpoint' => 'https://config-endpoint.example/v1', 'model' => '', 'keyFile' => "$dir/.pikkumiesKey", 'virsilipasKeyFile' => "$dir/.virsilipasKey"];
            file_put_contents("$dir/.virsilipasKey", "endpoint=https://proxy.example/v1\nkey=vsk-platform-key-0002\n");
            $r = testWithEnv(['PIKKUMIES_ENDPOINT' => null, 'PIKKUMIES_KEY' => null], function () use ($p) {
                return Provider::resolve(['provider' => $p]);
            });
            check($r['endpoint'] === 'https://config-endpoint.example/v1', 'the configured endpoint must win over the key file\'s own endpoint: ' . json_encode($r));
            check($r['key'] === 'vsk-platform-key-0002', 'the key must still come from the virsilipas file when ~/.pikkumiesKey has no key and config has no key field at all: ' . json_encode($r));
            $r2 = testWithEnv(['PIKKUMIES_ENDPOINT' => 'https://env-endpoint.example/v1', 'PIKKUMIES_KEY' => 'env-key-0001'], function () use ($p) {
                return Provider::resolve(['provider' => $p]);
            });
            check($r2['endpoint'] === 'https://env-endpoint.example/v1' && $r2['key'] === 'env-key-0001', 'env vars must win over both the configured endpoint and every key file: ' . json_encode($r2));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: resolve()'s endpoint fallback chain (env -> config -> file) and its key
        // fallback chain (env -> file only, config never supplies a key) are two different chains sharing
        // one $file read; conflating them (e.g. letting a configured endpoint also suppress the file's key)
        // would silently drop a working key whenever only the endpoint, not the key, was ever configured.
    },
];
