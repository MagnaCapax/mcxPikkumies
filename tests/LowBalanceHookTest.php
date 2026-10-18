<?php
/**
 * hooks/system/low-balance (ADR-0050): once per session, from the credits line's fresh cache only, with the one
 * "is it low" test (CreditsFormat::isLow); silent above the threshold, on a stale or missing cache, when disabled or
 * without a usable session id; never a request. Runs the real hook in a throwaway tree so local/ is never the checkout's.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsFormat.php';

const LOW_BALANCE_TEST_KEY = 'vsk-S3CRET-lowbal-oracle-5c1e';

if (!function_exists('lowBalanceTree')) {
    /** A pikkumies tree with lib, the credits unit and the hook, a key file for $endpoint, and $config merged into local/config.php. */
    function lowBalanceTree($endpoint, array $config = [])
    {
        $tmp = testTempDir('pk-lowbal');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/system/credits', 'hooks/system/low-balance'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/home", 0700, true);
        file_put_contents("$tmp/home/.pikkumiesKey", "endpoint=$endpoint\nkey=" . LOW_BALANCE_TEST_KEY . "\n");
        chmod("$tmp/home/.pikkumiesKey", 0600);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        $config = array_replace_recursive(['provider' => ['endpoint' => '', 'model' => '', 'keyFile' => "$tmp/home/.pikkumiesKey", 'virsilipasKeyFile' => "$tmp/home/.none"]], $config);
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        return $tmp;
    }

    /** Writes the line's cache as CreditsLine does: $age seconds old, for $endpoint and the test key, model ''. */
    function lowBalanceCache($tree, $endpoint, array $record, $age = 30)
    {
        @mkdir("$tree/pikkumies/local/state/credits", 0700, true);
        file_put_contents("$tree/pikkumies/local/state/credits/line.json", json_encode(['at' => time() - $age, 'endpoint' => $endpoint,
            'key_sha256' => substr(hash('sha256', LOW_BALANCE_TEST_KEY), 0, 12), 'model' => '', 'record' => $record]));
    }

    /** Runs the hook with a clean environment; returns [context or '', seconds taken, raw stdout]. */
    function lowBalanceRun($tree, $session = 's-1')
    {
        $env = ['HOME' => "$tree/home", 'PATH' => (string) getenv('PATH')];
        $t = microtime(true);
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/pikkumies/hooks/system/low-balance/low-balance.php"),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fwrite($pipes[0], json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => $session, 'extra' => ['user_message' => 'hi']]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $d = json_decode($out, true);
        return [is_array($d) && isset($d['context']) ? $d['context'] : (trim($out) === '' ? '' : 'UNPARSED: ' . $out), microtime(true) - $t, $out];
    }

    function lowBalanceRecord($pct, $tokens, $balance = 350000)
    {
        return ['balance' => $balance, 'pot' => 5000000, 'pct_left' => $pct, 'tokens_min' => $tokens, 'model' => 'default', 'today_credits' => 1000, 'approx' => ['pot' => false]];
    }
}

return [
    'low-balance: isLow is under 10% of the grant or under 200k tokens; a missing figure never counts' => function () {
        check(CreditsFormat::isLow(lowBalanceRecord(9.9, null)) && !CreditsFormat::isLow(lowBalanceRecord(10, null)) && !CreditsFormat::isLow(lowBalanceRecord(94, 1175000)), 'pct boundary');
        check(CreditsFormat::isLow(lowBalanceRecord(null, 199999)) && !CreditsFormat::isLow(lowBalanceRecord(null, 200000)), 'tokens boundary');
        check(!CreditsFormat::isLow(lowBalanceRecord(null, null)) && CreditsFormat::isLow(lowBalanceRecord(0.0, 0, -5)), 'no figures / below zero');
    },
    'low-balance: silent above the threshold, on a stale or missing cache, for another endpoint, when disabled or with a bad session id' => function () {
        $ep = 'http://127.0.0.1:9/v1';
        $tree = lowBalanceTree($ep);
        try {
            check(lowBalanceRun($tree)[0] === '', 'no cache must be silent');
            lowBalanceCache($tree, $ep, lowBalanceRecord(94, 1175000));
            check(lowBalanceRun($tree)[0] === '', 'plenty left must be silent');
            lowBalanceCache($tree, $ep, lowBalanceRecord(5, 50000), 601);
            check(lowBalanceRun($tree)[0] === '', 'a cache over 10 minutes old must be silent');
            lowBalanceCache($tree, 'http://127.0.0.1:9/other', lowBalanceRecord(5, 50000));
            check(lowBalanceRun($tree)[0] === '', 'a cache for another endpoint must be silent');
            lowBalanceCache($tree, $ep, lowBalanceRecord(5, 50000));
            foreach (['', '../x', str_repeat('a', 129), 'a b'] as $bad) {
                check(lowBalanceRun($tree, $bad)[0] === '', "session id '$bad' must be silent");
            }
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['provider' => ['keyFile' => "$tree/home/.pikkumiesKey"], 'lowBalance' => ['disabled' => true]], true) . ";\n");
            check(lowBalanceRun($tree)[0] === '', 'lowBalance.disabled must be silent');
        } finally {
            testRmTree($tree);
        }
    },
    'low-balance: below the threshold it speaks once per session, with the numbers, and again for a new session' => function () {
        $ep = 'http://127.0.0.1:' . testFreePort() . '/v1'; // a closed port: any request would fail, none is made
        $tree = lowBalanceTree($ep);
        try {
            lowBalanceCache($tree, $ep, lowBalanceRecord(7, 87500));
            list($ctx, $took) = lowBalanceRun($tree, 'sess-A');
            check($ctx === 'AI credits are low (350k credits left, 7% of the newest grant, at least 87.5k tokens): prefer the cheapest steps that do the job, and tell the user once that credits are running low.', 'context: ' . $ctx);
            check($took < 1.5, sprintf('the hook took %.2f s', $took));
            check(lowBalanceRun($tree, 'sess-A')[0] === '', 'the second turn of a session must be silent');
            check(lowBalanceRun($tree, 'sess-B')[0] !== '', 'another session must be told');
            lowBalanceCache($tree, $ep, lowBalanceRecord(null, 1000));
            check(lowBalanceRun($tree, 'sess-C')[0] === 'AI credits are low (350k credits left, at least 1k tokens): prefer the cheapest steps that do the job, and tell the user once that credits are running low.', 'tokens only');
            check(count(glob("$tree/pikkumies/local/state/low-balance/*")) === 3 && (fileperms("$tree/pikkumies/local/state/low-balance") & 0777) === 0700, 'markers');
        } finally {
            testRmTree($tree);
        }
    },
    'low-balance: never makes a request, also on a stale cache: a listener that never answers gets no connection and no delay' => function () {
        $port = testFreePort();
        $server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
        check($server !== false, "listener: $errstr");
        $ep = "http://127.0.0.1:$port/v1";
        $tree = lowBalanceTree($ep);
        try {
            foreach ([[lowBalanceRecord(5, 50000), 30, 's1'], [lowBalanceRecord(5, 50000), 3600, 's2'], [lowBalanceRecord(94, 1175000), 30, 's3']] as $case) {
                lowBalanceCache($tree, $ep, $case[0], $case[1]);
                list(, $took) = lowBalanceRun($tree, $case[2]);
                check($took < 1.5, sprintf('%s: the hook took %.2f s', $case[2], $took));
            }
            @unlink("$tree/pikkumies/local/state/credits/line.json");
            lowBalanceRun($tree, 's4');
            $conn = @stream_socket_accept($server, 0.2);
            check($conn === false, 'the hook connected to the AI endpoint');
        } finally {
            fclose($server);
            testRmTree($tree);
        }
    },
];
