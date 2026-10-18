<?php
/**
 * tools/system/credits `line` (ADR-0040): the credits line for the start banner and the `pikkumies tmux` status line.
 * The arithmetic and the look are tested on CreditsFormat directly; fetching, caching and silence run the real
 * script against a local php -S fixture, with the $creditsConfig and $creditsStateDir seams, so the checkout's real
 * local/ is never read or written.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsLine.php';

const CREDITS_LINE_KEY = 'vsk-S3CRET-line-oracle-41d2e8';

/** A php -S fixture: /v/usage and /v/models answer like the platform proxy, /none/* is 404, /slow/* sleeps 4 s. Returns [proc, base, dir]. */
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
    $models = ['object' => 'list', 'data' => [
        ['id' => 'default', 'credits_per_million_tokens' => ['input' => 1000000, 'cached_input' => 250000, 'output' => 4000000]],
        ['id' => 'second', 'credits_per_million_tokens' => ['input' => 500000, 'cached_input' => 125000, 'output' => 2000000]],
    ]];
    file_put_contents("$dir/usage.json", json_encode($usage));
    file_put_contents("$dir/models.json", json_encode($models));
    file_put_contents("$dir/router.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$auth = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "";
file_put_contents(__DIR__ . "/requests.log", $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);
header("Content-Type: application/json");
if (strpos($p, "/slow/") === 0) { sleep(4); echo "{}"; return; }
if ($auth === ' . var_export('Bearer ' . CREDITS_LINE_KEY, true) . ' && $p === "/v/usage") { readfile(__DIR__ . "/usage.json"); return; }
if ($auth === ' . var_export('Bearer ' . CREDITS_LINE_KEY, true) . ' && $p === "/v/models") { readfile(__DIR__ . "/models.json"); return; }
http_response_code(404); echo json_encode(["error" => "not found " . $auth]);');
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

/** Runs `credits.php line ...` with a fixture HOME, the state dir $dir/state, LANG=$lang and no LC_*. Returns [code, stdout, stderr]. */
function creditsLineRun($dir, $endpoint, array $args, $lang = 'C.UTF-8', $model = '', $withKey = true)
{
    $home = "$dir/home";
    @mkdir($home, 0700, true);
    @unlink("$home/.virsilipasKey");
    if ($withKey) {
        file_put_contents("$home/.virsilipasKey", "endpoint=$endpoint\nkey=" . CREDITS_LINE_KEY . "\n");
        chmod("$home/.virsilipasKey", 0600);
    }
    $code = "<?php\n";
    foreach (['HOME' => $home, 'PIKKUMIES_KEY' => null, 'PIKKUMIES_ENDPOINT' => null, 'LC_ALL' => null, 'LC_CTYPE' => null, 'LANG' => $lang] as $k => $v) {
        $code .= 'putenv(' . var_export($v === null ? $k : "$k=$v", true) . ");\n";
    }
    $config = ['provider' => ['endpoint' => $withKey ? '' : $endpoint, 'model' => $model, 'keyFile' => "$home/.pikkumiesKey", 'virsilipasKeyFile' => "$home/.virsilipasKey"]];
    $code .= '$creditsConfig = ' . var_export($config, true) . ";\n\$creditsStateDir = " . var_export("$dir/state", true) . ";\n";
    $code .= 'require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/system/credits/credits.php', true) . ";\n";
    file_put_contents("$dir/run.php", $code);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/run.php") . ' line';
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

return [
    'credits line: prints one line from usage and model rates; --jsonl gives the record; ASCII under a non-UTF-8 locale or --ascii' => function () {
        list($proc, $base, $dir) = creditsLineServer();
        try {
            list($code, $out, $err) = creditsLineRun($dir, "$base/v", []);
            check($code === 0 && $err === '', "line failed ($code): $out $err");
            check($out === "💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≈1.2M–4.7M tokens · today 35k\n", 'line: ' . $out);
            check(creditsLineRequests($dir) === ['/v/usage?days=400&grants=1', '/v/models'], 'requests: ' . json_encode(creditsLineRequests($dir)));
            list(, $out) = creditsLineRun($dir, "$base/v", ['--jsonl']);
            $r = json_decode(trim($out), true);
            check($r === ['balance' => 4700000, 'pot' => 5000000, 'pct_left' => 94, 'tokens_low' => 1175000, 'tokens_high' => 4700000, 'model' => 'default', 'today_credits' => 35000, 'approx' => ['tokens' => true, 'pot' => false]], 'record: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/v", [], 'C');
            check($out === "cr 4.7M credits [#########-] 94% left | ~1.2M-4.7M tokens | today 35k\n", 'LANG=C must give ASCII: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/v", ['--ascii']);
            check($out === "cr 4.7M credits [#########-] 94% left | ~1.2M-4.7M tokens | today 35k\n", '--ascii: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/v", ['--tmux']);
            check($out === "4.7M credits #[fg=green,bg=black]▰▰▰▰▰▰▰▰▰▱#[default] 94% left · ≈1.2M–4.7M tokens · today 35k\n", '--tmux: ' . $out);
            testRmTree("$dir/state");
            list(, $out) = creditsLineRun($dir, "$base/v", [], 'C.UTF-8', 'second');
            check(strpos($out, '≈2.4M–9.4M tokens') !== false, 'the configured model\'s rates must be used: ' . $out);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits line: a second call inside 60 s makes no request; an older line asks usage again but keeps the day-old rates' => function () {
        list($proc, $base, $dir) = creditsLineServer();
        try {
            creditsLineRun($dir, "$base/v", []);
            check(count(creditsLineRequests($dir)) === 2, 'first call: ' . json_encode(creditsLineRequests($dir)));
            list($code, $out) = creditsLineRun($dir, "$base/v", []);
            check($code === 0 && strpos($out, '4.7M credits') !== false, 'cached line: ' . $out);
            check(count(creditsLineRequests($dir)) === 2, 'a second call inside 60 s must make no request: ' . json_encode(creditsLineRequests($dir)));
            $line = json_decode((string) file_get_contents("$dir/state/line.json"), true);
            $line['at'] -= 61;
            file_put_contents("$dir/state/line.json", json_encode($line));
            creditsLineRun($dir, "$base/v", []);
            check(array_slice(creditsLineRequests($dir), 2) === ['/v/usage?days=400&grants=1'], 'after 60 s only usage is asked again: ' . json_encode(creditsLineRequests($dir)));
            check((fileperms("$dir/state") & 0777) === 0700, 'state dir must be 0700: ' . decoct(fileperms("$dir/state") & 0777));
            foreach (glob("$dir/state/*") as $f) {
                check((fileperms($f) & 0777) === 0600, basename($f) . ' must be 0600');
                check(strpos((string) file_get_contents($f), 'S3CRET') === false, basename($f) . ' holds the key');
            }
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits line: an endpoint without a usage route (404) prints nothing, exits 0 and is not asked again for a day' => function () {
        list($proc, $base, $dir) = creditsLineServer();
        try {
            list($code, $out, $err) = creditsLineRun($dir, "$base/none", []);
            check($code === 0 && $out === '' && $err === '', "404 must be silent ($code): $out $err");
            check(creditsLineRequests($dir) === ['/none/usage?days=400&grants=1'], 'one request: ' . json_encode(creditsLineRequests($dir)));
            list($code, $out) = creditsLineRun($dir, "$base/none", ['--tmux']);
            check($code === 0 && $out === '' && count(creditsLineRequests($dir)) === 1, 'the 404 must be remembered: ' . json_encode(creditsLineRequests($dir)));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits line: no key means no request and no output; a hanging endpoint gives up after 2 s, silently' => function () {
        list($proc, $base, $dir) = creditsLineServer();
        try {
            list($code, $out, $err) = creditsLineRun($dir, "$base/v", [], 'C.UTF-8', '', false);
            check($code === 0 && $out === '' && $err === '', "no key must be silent ($code): $out $err");
            check(creditsLineRequests($dir) === [], 'no request without a key: ' . json_encode(creditsLineRequests($dir)));
            $t = microtime(true);
            list($code, $out) = creditsLineRun($dir, "$base/slow", []);
            $took = microtime(true) - $t;
            check($code === 0 && $out === '' && $took < 3.5, sprintf('a hanging endpoint must time out silently in about 2 s (took %.1f s): %s', $took, $out));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
        }
    },
    'credits line: the tmux plan sets the status line on the pikkumies session only, never with -g, and bin wires it and the banner' => function () {
        $cmd = "'php' 'pikkumies'";
        $status = "'php' 'credits.php' line --tmux";
        $plan = Launch::tmuxPlan('2', null, false, $cmd, $status);
        check(count($plan) === 6 && $plan[0][1] === 'new-session' && $plan[4] === ['tmux', 'select-window', '-t', '=pikkumies:pm2'], 'plan: ' . json_encode($plan));
        check(array_slice($plan, 1, 3) === [
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-right', "#($status) %H:%M"],
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-right-length', '90'],
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-interval', '60'],
        ], 'status steps: ' . json_encode($plan));
        foreach ($plan as $step) {
            check(!in_array('-g', $step, true), 'no step may set a global option: ' . json_encode($step));
        }
        check(count(Launch::tmuxPlan('1', ['pm1'], true, $cmd, $status)) === 5, 'an existing session gets the status steps too');
        check(count(Launch::tmuxPlan('1', ['pm1'], true, $cmd)) === 2, 'without a status command the plan is unchanged');
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, "/tools/system/credits/credits.php') . ' line --tmux'") !== false, 'bin must pass `credits.php line --tmux` to the tmux plan');
        check(preg_match("/if \\(Launch::isTty\\(\\) && Launch::isSession\\(\\\$passArgs\\)\\) \\{\n\s+require_once [^\n]*CreditsLine\\.php';\n(.*?)passthru\\(escapeshellarg\\(hermesBin/s", $bin, $m) === 1, 'the banner must be printed on a terminal session, before Hermes starts');
        check(strpos($m[1], 'CreditsLine::text(') !== false && strpos($m[1], 'exec(') === false, 'the banner is a library call, not a subprocess');
    },
];
