<?php
/** Shared by the HaltRun tests: a throwaway lib/ tree, a fake hermes and a fake light model (see tests/HaltRunTest.php). */
if (!function_exists('haltRunTree')) {
    /** A throwaway tree with a copy of lib/ (HaltRun's own dependency chain) and local/config.php pointed at the fake model. */
    function haltRunTree($modelPort)
    {
        $tmp = sys_get_temp_dir() . '/pk-haltrun-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies/lib';
        @mkdir("$tmp/pikkumies/lib", 0700, true);
        foreach ((array) glob("$src/*.php") as $f) {
            copy($f, "$tmp/pikkumies/lib/" . basename($f));
        }
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/key", "key=k\n");
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export(['provider' =>
            ['endpoint' => "http://127.0.0.1:$modelPort/v1", 'model' => 'm', 'keyFile' => "$tmp/key"]], true) . ";\n");
        return $tmp;
    }

    /**
     * A fixture "hermes": refuses --usage-file after chat (exit 2, as Hermes v0.22.2 does); reads --query-file (a path, or "-"
     * for stdin) into the logged call's "query_file_text" and, like Hermes, exits 2 when --query is given too or the text is
     * empty; prints plan.json[call index] "output" (last entry repeats) plus a stream-json result event with its "session_id",
     * exits its "code". Every call is logged to calls.jsonl.
     */
    function haltRunFakeHermes($tree)
    {
        $path = "$tree/fake-hermes";
        $body = '#!' . PHP_BINARY . "\n<?php\n"
            . '$dir = getenv("PK_FAKE_HERMES_DIR");' . "\n"
            . '$callsFile = "$dir/calls.jsonl";' . "\n"
            . '$n = is_file($callsFile) ? count(array_filter(explode("\n", (string) file_get_contents($callsFile)), "strlen")) : 0;' . "\n"
            . '$plan = json_decode((string) @file_get_contents("$dir/plan.json"), true);' . "\n"
            . '$plan = is_array($plan) ? $plan : [];' . "\n"
            . '$step = isset($plan[$n]) ? $plan[$n] : ($plan ? end($plan) : ["output" => "", "code" => 0, "session_id" => "fakesess"]);' . "\n"
            . '$qf = null; $hasQuery = false;' . "\n"
            . 'foreach (array_slice($argv, 1) as $k => $a) { if (strpos($a, "--query-file=") === 0) { $qf = substr($a, 13); } elseif ($a === "--query-file") { $qf = (string) $argv[$k + 2]; } elseif (strpos($a, "--query=") === 0) { $hasQuery = true; } }' . "\n"
            . '$qfText = $qf === null ? null : ($qf === "-" ? stream_get_contents(STDIN) : (string) @file_get_contents($qf));' . "\n"
            . 'file_put_contents($callsFile, json_encode(["argv" => array_slice($argv, 1), "query_file_text" => $qfText]) . "\n", FILE_APPEND);' . "\n"
            . 'if ($qf !== null && $hasQuery) { fwrite(STDERR, "hermes chat: error: argument --query-file: not allowed with argument -q/--query\n"); exit(2); }' . "\n"
            . 'if ($qf !== null && trim($qfText) === "") { fwrite(STDERR, "Error: --query-file $qf is empty\n"); exit(2); }' . "\n"
            // Like Hermes v0.22.2: after the `chat` subcommand an option chat does not define is an argparse error.
            . '$chatAt = array_search("chat", $argv, true);' . "\n"
            . 'if ($chatAt !== false) {' . "\n"
            . '    foreach (array_slice($argv, $chatAt + 1) as $a) {' . "\n"
            . '        if (strpos($a, "--usage-file") === 0) { fwrite(STDERR, "hermes: error: unrecognized arguments: $a\n"); exit(2); }' . "\n"
            . '    }' . "\n"
            . '}' . "\n"
            . 'echo isset($step["output"]) ? $step["output"] : "";' . "\n"
            // A stream-json run ends with a result event that carries the session id.
            . 'if (!empty($step["session_id"])) { echo "\n" . json_encode(["type" => "result", "session_id" => $step["session_id"], "exit_code" => 0]) . "\n"; }' . "\n"
            . 'exit(isset($step["code"]) ? (int) $step["code"] : 0);' . "\n";
        file_put_contents($path, $body);
        chmod($path, 0755);
        return $path;
    }

    /** A fake OpenAI-compatible endpoint whose reply is replies.json[request index] (last entry repeats); requests.jsonl counts calls. */
    function haltRunFakeModel($dir)
    {
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/router.php", '<?php
$d = __DIR__;
$n = is_file("$d/requests.jsonl") ? count(array_filter(explode("\n", (string) file_get_contents("$d/requests.jsonl")), "strlen")) : 0;
file_put_contents("$d/requests.jsonl", json_encode(["n" => $n]) . "\n", FILE_APPEND);
$replies = json_decode((string) @file_get_contents("$d/replies.json"), true);
$replies = is_array($replies) ? $replies : [];
$reply = isset($replies[$n]) ? $replies[$n] : ($replies ? end($replies) : "");
header("Content-Type: application/json");
echo json_encode(["choices" => [["message" => ["role" => "assistant", "content" => $reply]]]]);
');
        for ($try = 0; $try < 5; $try++) {
            $port = testFreePort();
            $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port router.php",
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($s) {
                    fclose($s);
                    if (proc_get_status($p)['running']) {
                        return [$p, $port];
                    }
                    break;
                }
                usleep(100000);
            }
            proc_terminate($p);
        }
        throw new Exception('fake model did not start');
    }

    /** Seeds the halt-snapshot HaltRun::run() will read, optionally pre-marking it as already judged, then runs HaltRun::run() in a subprocess with $stdin. [code, stdout]. */
    function haltRunDrive($tree, $hermesBin, $sessionId, $alreadyJudged = false, array $passArgs = ['chat', '--query=please fix the thing'], $stdin = '')
    {
        $script = "$tree/driver-" . mt_rand() . '.php';
        $body = "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltState.php", true) . ";\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n"
            . 'HaltState::writeSnapshot(' . var_export($sessionId, true) . ", 'please fix the thing', '[user] please fix the thing');\n";
        if ($alreadyJudged) {
            $body .= '$s = HaltState::load(' . var_export($sessionId, true) . ');' . "\n"
                . 'HaltState::markJudged(' . var_export($sessionId, true) . ", \$s['turn_started']);\n";
        }
        $body .= '$code = HaltRun::run(' . var_export($hermesBin, true) . ', ' . var_export($passArgs, true) . ");\n"
            . 'fwrite(STDOUT, "\nHALT_EXIT:" . $code . "\n");' . "\n";
        file_put_contents($script, $body);
        list(, $out) = runPhp($script, [], $stdin);
        return [(int) (preg_match('/HALT_EXIT:(-?\d+)/', $out, $m) ? $m[1] : -999), $out];
    }

    function haltRunCalls($tree)
    {
        return is_file("$tree/calls.jsonl") ? count(array_filter(explode("\n", file_get_contents("$tree/calls.jsonl")), 'strlen')) : 0;
    }
}

if (!function_exists('haltRunReplies')) {
    /** The light model's answer sets the tests script: [continue (Q3 no), done, chat]. */
    function haltRunReplies()
    {
        return ["Q1: no\nQ2: yes\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no", "Q1: no\nQ2: yes\nQ3: yes\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no", "Q1: yes\nQ2: no\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no"];
    }
}
