<?php
/**
 * The credits line's test harness (ADR-0040): a php -S fixture that answers like the platform API, and a runner for
 * the real `credits.php line` with the $creditsConfig and $creditsStateDir seams, so the checkout's local/ is never used.
 */
require_once dirname(__DIR__, 3) . '/pikkumies/tools/system/credits/CreditsLine.php';

const CREDITS_LINE_KEY = 'vsk-S3CRET-line-oracle-41d2e8';

/**
 * Routes, as /<prefix>/<route>: v (normal), refund (a balance below zero after a refund), merr (models answers 500),
 * slowm (models sleeps 4 s), err (500), slow (sleeps 4 s), none or a wrong key (404). Returns [proc, base, dir].
 */
function creditsLineServer()
{
    $dir = testTempDir('pk-cline');
    $day = 86400;
    $today = (int) (floor(time() / $day) * $day);
    $usage = ['balance' => 4700000, 'days' => 400, 'since' => $today - 399 * $day,
        'daily' => [
            ['day' => gmdate('Y-m-d', $today - 3 * $day), 'credits' => 1000],   // before the grant's day: not in the pot
            ['day' => gmdate('Y-m-d', $today - 2 * $day), 'credits' => 200000], // the grant's day
            ['day' => gmdate('Y-m-d', $today - $day), 'credits' => 65000],
            ['day' => gmdate('Y-m-d', $today), 'credits' => 35000],
        ],
        'grants' => [['grant_id' => 'g1', 'credits' => 5000000, 'created_at' => $today - 2 * $day + 3600, 'expires_at' => null]]];
    // 1M granted, 50k spent, then a 1.2M refund: the balance is -250k and the pot (-250k + 50k) is below zero.
    $refund = ['balance' => -250000, 'days' => 400, 'since' => $today - 399 * $day,
        'daily' => [['day' => gmdate('Y-m-d', $today - 2 * $day), 'credits' => 50000]],
        'grants' => [['grant_id' => 'r1', 'credits' => -1200000, 'created_at' => $today - $day, 'expires_at' => null],
            ['grant_id' => 'g1', 'credits' => 1000000, 'created_at' => $today - 2 * $day, 'expires_at' => null]]];
    $models = ['object' => 'list', 'data' => [
        ['id' => 'default', 'credits_per_million_tokens' => ['input' => 1000000, 'cached_input' => 250000, 'output' => 4000000]],
        ['id' => 'second', 'credits_per_million_tokens' => ['input' => 500000, 'cached_input' => 125000, 'output' => 2000000]],
    ]];
    file_put_contents("$dir/usage.json", json_encode($usage));
    file_put_contents("$dir/refund-usage.json", json_encode($refund));
    file_put_contents("$dir/models.json", json_encode($models));
    file_put_contents("$dir/router.php", '<?php
list(, $prefix, $route) = explode("/", parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)) + [null, "", ""];
$auth = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "";
file_put_contents(__DIR__ . "/requests.log", $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);
header("Content-Type: application/json");
if ($prefix === "slow" || ($prefix === "slowm" && $route === "models")) { sleep(4); echo "{}"; return; }
if ($auth !== ' . var_export('Bearer ' . CREDITS_LINE_KEY, true) . ' || $prefix === "none" || !in_array($route, ["usage", "models"], true)) { http_response_code(404); echo json_encode(["error" => "not found " . $auth]); return; }
if ($prefix === "err" || ($prefix === "merr" && $route === "models")) { http_response_code(500); echo "{}"; return; }
readfile(is_file(__DIR__ . "/$prefix-$route.json") ? __DIR__ . "/$prefix-$route.json" : __DIR__ . "/$route.json");');
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

/** Runs `credits.php line ...` with a fixture HOME holding $key ('' = no key), state dir $dir/state, LANG=$lang. Returns [code, stdout, stderr]. */
function creditsLineRun($dir, $endpoint, array $args, $lang = 'C.UTF-8', $model = '', $key = CREDITS_LINE_KEY)
{
    return creditsToolRun($dir, $endpoint, array_merge(['line'], $args), $lang, $model, $key);
}

/** Runs `credits.php <args>` (the verb first) the way creditsLineRun does. Returns [code, stdout, stderr]. */
function creditsToolRun($dir, $endpoint, array $args, $lang = 'C.UTF-8', $model = '', $key = CREDITS_LINE_KEY)
{
    $home = "$dir/home";
    @mkdir($home, 0700, true);
    @unlink("$home/.virsilipasKey");
    if ($key !== '') {
        file_put_contents("$home/.virsilipasKey", "endpoint=$endpoint\nkey=$key\n");
        chmod("$home/.virsilipasKey", 0600);
    }
    $code = "<?php\n";
    foreach (['HOME' => $home, 'PIKKUMIES_KEY' => null, 'PIKKUMIES_ENDPOINT' => null, 'LC_ALL' => null, 'LC_CTYPE' => null, 'LANG' => $lang] as $k => $v) {
        $code .= 'putenv(' . var_export($v === null ? $k : "$k=$v", true) . ");\n";
    }
    $config = ['provider' => ['endpoint' => $key !== '' ? '' : $endpoint, 'model' => $model, 'keyFile' => "$home/.pikkumiesKey", 'virsilipasKeyFile' => "$home/.virsilipasKey"]];
    $code .= '$creditsConfig = ' . var_export($config, true) . ";\n\$creditsStateDir = " . var_export("$dir/state", true) . ";\n";
    $code .= 'require ' . var_export(dirname(__DIR__, 3) . '/pikkumies/tools/system/credits/credits.php', true) . ";\n";
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

function creditsLineRequests($dir)
{
    return is_file("$dir/requests.log") ? array_values(array_filter(explode("\n", (string) file_get_contents("$dir/requests.log")), 'strlen')) : [];
}

/** Moves a cache file's `at` by $seconds (negative: older, as if that much time had passed; positive: in the future). */
function creditsLineShift($dir, $name, $seconds)
{
    $c = json_decode((string) file_get_contents("$dir/state/$name"), true);
    $c['at'] += $seconds;
    file_put_contents("$dir/state/$name", json_encode($c));
}

/** Starts the fixture, runs $fn($base, $dir), always stops it and removes the directory. */
function creditsLineWithServer(callable $fn)
{
    list($proc, $base, $dir) = creditsLineServer();
    try {
        $fn($base, $dir);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        testRmTree($dir);
    }
}
