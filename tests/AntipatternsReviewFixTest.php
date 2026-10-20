<?php
/**
 * Independent review fixes (2026-10-20): H-1 (daily ledger, both callers), H-2 (halt-guard time budget), M1
 * (per-command-line cut), M2 (antipatterns.resume tier polarity), L3 (UTF-8-safe HookLlm encode), L4 (stamp
 * re-check under forTurn()'s own lock), L8 (background unit answers {"skip":true} with no reply), L9 (array-shaped
 * final answer read as text, not cast to "Array"), and the scheduled (cron_) session skip for both callers.
 * Each case was confirmed to fail against the pre-review code (git history / the delivered L.patch) before this
 * file was finalised, per the coordinator's "every fix needs a test that fails on your old code".
 */
if (!function_exists('haltHookTreeWithAntipatterns')) {
    require __DIR__ . '/HaltHookTest.php'; // haltHookTreeWithAntipatterns(), haltSeed(), haltStateFile()
}
if (!function_exists('apcTree')) {
    require __DIR__ . '/AntipatternCheckUnitTest.php'; // apcTree(), apcRun(), apcReplyRecord(), apcToolRecord()
}

if (!function_exists('arfModel')) {
    /** A fake OpenAI-compatible endpoint: replies.json[request index] (last entry repeats), optional per-request sleep; requests.jsonl records len/json_ok. */
    function arfModel($dir, array $replies, $sleepSeconds = 0)
    {
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/replies.json", json_encode($replies));
        file_put_contents("$dir/sleep.txt", (string) $sleepSeconds);
        file_put_contents("$dir/router.php", '<?php
$d = __DIR__;
$body = (string) file_get_contents("php://input");
$n = is_file("$d/requests.jsonl") ? count(array_filter(explode("\n", (string) file_get_contents("$d/requests.jsonl")), "strlen")) : 0;
file_put_contents("$d/requests.jsonl", json_encode(["n" => $n, "len" => strlen($body), "json_ok" => json_decode($body, true) !== null]) . "\n", FILE_APPEND);
$s = (int) @file_get_contents("$d/sleep.txt");
if ($s > 0) { sleep($s); }
$replies = json_decode((string) @file_get_contents("$d/replies.json"), true);
$reply = is_array($replies) && isset($replies[$n]) ? $replies[$n] : (is_array($replies) && $replies ? end($replies) : "");
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
                    return [$p, $port];
                }
                usleep(100000);
            }
            proc_terminate($p);
        }
        throw new Exception('fake model did not start');
    }

    function arfRequests($dir)
    {
        return array_values(array_filter(array_map(function ($l) {
            return json_decode($l, true);
        }, explode("\n", (string) @file_get_contents("$dir/requests.jsonl")))));
    }

    /** lib/ + doctrine/antipatterns.md + the real review/antipattern-check unit (no halt hooks): for forTurn()-only tests. */
    function arfTree($modelPort, array $config = [])
    {
        $tree = sys_get_temp_dir() . '/arf-tree-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tree/pikkumies/lib", 0700, true);
        foreach ((array) glob("$src/lib/*.php") as $f) {
            copy($f, "$tree/pikkumies/lib/" . basename($f));
        }
        @mkdir("$tree/pikkumies/doctrine", 0700, true);
        copy("$src/doctrine/antipatterns.md", "$tree/pikkumies/doctrine/antipatterns.md");
        @mkdir("$tree/pikkumies/hooks/review/antipattern-check", 0700, true);
        foreach ((array) glob("$src/hooks/review/antipattern-check/*.*") as $f) {
            copy($f, "$tree/pikkumies/hooks/review/antipattern-check/" . basename($f));
        }
        @mkdir("$tree/pikkumies/local", 0700, true);
        file_put_contents("$tree/key", "key=k\n");
        $cfg = array_replace_recursive(['provider' => ['endpoint' => "http://127.0.0.1:$modelPort/v1", 'model' => 'm', 'keyFile' => "$tree/key"]], $config);
        file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export($cfg, true) . ";\n");
        return $tree;
    }

    /** Runs $code with the tree's own lib/ loaded (so Config::root() is the tree's); returns stdout. */
    function arfPhp($tree, $code)
    {
        $script = "$tree/arf-" . mt_rand() . '.php';
        file_put_contents($script, '<?php foreach (glob(' . var_export("$tree/pikkumies/lib/*.php", true) . ') as $l) { require_once $l; } ' . $code);
        list(, $out) = runPhp($script, []);
        return $out;
    }

    function arfDown(array $pm, array $dirs)
    {
        proc_terminate($pm[0], 9);
        proc_close($pm[0]);
        foreach ($dirs as $d) {
            testRmTree($d);
        }
    }
}

return [
    'H-1: the default daily cap matches ADR-0088 (48/day, all three callers share it)' => function () {
        check(Antipatterns::DAILY === 48, 'default cap drifted: ' . Antipatterns::DAILY);
        check(Antipatterns::UNIT === 'antipattern-check', 'the ledger/dailyCalls key must be the bare unit name, not category/name: ' . Antipatterns::UNIT);
    },
    'H-1: forTurn() reserves against the shared ledger before calling the model; hooks.dailyCalls.antipattern-check bounds total calls across many sessions' => function () {
        $m = sys_get_temp_dir() . '/arf-h1a-' . mt_rand();
        $pm = arfModel($m, ['none']);
        $tree = arfTree($pm[1], ['hooks' => ['dailyCalls' => ['antipattern-check' => 2]]]);
        try {
            $out = arfPhp($tree, 'for ($i = 0; $i < 5; $i++) { Antipatterns::forTurn("s-$i", "a plain final answer of some words", [], "oneshot"); } '
                . 'echo HookLedger::used(Config::localDir(), "antipattern-check");');
            $n = count(arfRequests($m));
            $log = (string) @file_get_contents("$tree/pikkumies/local/logs/antipatterns/" . gmdate('Ymd') . '.jsonl');
            echo "      [H-1a] requests=$n ledger_used=" . trim($out) . " skipped:daily-cap=" . substr_count($log, 'skipped:daily-cap') . "\n";
            check($n === 2, "the cap (2) must bound the calls across 5 different sessions, got $n");
            check(trim($out) === '2', "HookLedger::used must read back 2: $out");
            check(substr_count($log, 'skipped:daily-cap') === 3, 'expected 3 refusals logged as skipped:daily-cap: ' . $log);
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'H-1: the background unit also reserves against the shared ledger; a spent cap asks the model neither time and emits nothing' => function () {
        $m = sys_get_temp_dir() . '/arf-h1b-' . mt_rand();
        $pm = arfModel($m, ['load-is-not-cpu | pegging every one of the four cpu cores']);
        $tree = apcTree($pm[1], ['hooks' => ['dailyCalls' => ['antipattern-check' => 1]]]);
        try {
            $records = [apcReplyRecord('load is pegging every one of the four cpu cores')];
            list($d1, $o1) = apcRun($tree, $records);
            list($d2, $o2) = apcRun($tree, $records);
            $n = count(arfRequests($m));
            echo "      [H-1b] requests=$n first=" . json_encode($d1) . " second=" . json_encode(trim($o2)) . "\n";
            check($n === 1 && is_array($d1) && $d1['context'] !== '' && $d2 === null && trim($o2) === '', "the async unit's own cap was not enforced: n=$n o2=$o2");
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'H-1 (scheduled sessions): forTurn() skips a cron_-prefixed session before any model call, the same as goal-review.php skips one' => function () {
        $m = sys_get_temp_dir() . '/arf-h1c-' . mt_rand();
        $pm = arfModel($m, ['load-is-not-cpu | pegging every one of the four cpu cores']);
        $tree = arfTree($pm[1]);
        try {
            $out = arfPhp($tree, 'echo json_encode(Antipatterns::forTurn("cron_daily123", "the agent said: load of 40 is pegging every one of the four cpu cores", [], "oneshot"));');
            $n = count(arfRequests($m));
            echo "      [H-1c] out=" . trim($out) . " requests=$n\n";
            check(trim($out) === '[]' && $n === 0, "a scheduled session must never reach the model: out=$out n=$n");
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'H-1 (scheduled sessions): the background unit skips a scheduled run too (worker.php\'s own "scheduled" flag), logging skipped:scheduled' => function () {
        $m = sys_get_temp_dir() . '/arf-h1d-' . mt_rand();
        $pm = arfModel($m, ['load-is-not-cpu | pegging every one of the four cpu cores']);
        $tree = apcTree($pm[1]);
        try {
            $in = json_encode(['session' => sha1('cron_x'), 'scheduled' => true,
                'records' => [['kind' => 'reply', 'text' => 'load is pegging every one of the four cpu cores']], 'state' => new stdClass()]);
            list(, $out) = runPhp("$tree/pikkumies/hooks/review/antipattern-check/antipattern-check.php", [], $in);
            $n = count(arfRequests($m));
            $log = (string) @file_get_contents("$tree/pikkumies/local/logs/antipatterns/" . gmdate('Ymd') . '.jsonl');
            echo "      [H-1d] out=" . json_encode(trim($out)) . " requests=$n log=" . trim($log) . "\n";
            check(trim($out) === '' && $n === 0 && strpos($log, 'skipped:scheduled') !== false, "a scheduled window must skip before any call: out=$out n=$n log=$log");
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'H-2: halt-guard spends only its remaining time budget on the check, so a slow model no longer costs the judge its own continue' => function () {
        $continueReply = "Q1: no\nQ2: yes\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no"; // Q3 no -> continue
        $m = sys_get_temp_dir() . '/arf-h2-' . mt_rand();
        $pm = arfModel($m, [$continueReply, 'none'], 16); // the judge call AND the check both hit this 16s/answer model
        $tree = haltHookTreeWithAntipatterns($pm[1]);
        try {
            haltSeed($tree, 'sess-h2', []);
            $stdin = json_encode(['hook_event_name' => 'pre_verify', 'session_id' => 'sess-h2', 'extra' => ['attempt' => 0, 'final_response' => 'done, I think']]);
            $t = microtime(true);
            $r = HookSpawn::run("$tree/pikkumies/hooks/halt/halt-guard/halt-guard.php", $stdin, 30); // hook.md's own timeout
            $secs = round(microtime(true) - $t, 1);
            echo "      [H-2] timedOut=" . var_export($r['timedOut'], true) . " secs=$secs stdout=" . json_encode(trim($r['stdout'])) . "\n";
            check(!$r['timedOut'] && strpos($r['stdout'], '"continue"') !== false, "the judge's continue must survive a slow model, not be killed at 30s: timedOut=" . var_export($r['timedOut'], true) . " secs=$secs stdout=" . trim($r['stdout']));
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'M1: commandText() cuts each command line to 300 characters, keeping only the last COMMANDS_MAX lines' => function () {
        $long = str_repeat('x', 4000);
        $lines = array_fill(0, 45, $long); // more than COMMANDS_MAX (40)
        $out = Antipatterns::commandText($lines);
        $got = explode("\n", $out);
        check(count($got) === Antipatterns::COMMANDS_MAX, 'expected exactly COMMANDS_MAX lines kept: ' . count($got));
        foreach ($got as $l) {
            check(strlen($l) === Antipatterns::COMMAND_CHARS, 'each kept line must be cut to COMMAND_CHARS: ' . strlen($l));
        }
    },
    'M1: forTurn()\'s request body stays bounded even with many long commands (review finding H4)' => function () {
        $m = sys_get_temp_dir() . '/arf-m1-' . mt_rand();
        $pm = arfModel($m, ['none']);
        $tree = arfTree($pm[1]);
        try {
            $cmds = [];
            for ($i = 0; $i < 60; $i++) {
                $cmds[] = ['command' => str_repeat('x', 4000)];
            }
            arfPhp($tree, '$c = ' . var_export($cmds, true) . '; Antipatterns::forTurn("s-size", str_repeat("y", 20000), $c, "oneshot");');
            $req = arfRequests($m);
            echo "      [M1] body len=" . (isset($req[0]) ? $req[0]['len'] : 'none') . "\n";
            check(isset($req[0]) && $req[0]['len'] < 40000, 'expected a bounded request body (<40KB), got ' . (isset($req[0]) ? $req[0]['len'] : 'none'));
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'M2: antipatterns.resume tier is positive-sense -- turning it off needs the person, turning it back on is free' => function () {
        check(ConfigureTiers::classify('antipatterns.resume', true, false) === 'person', 'turning the resume off must need the person');
        check(ConfigureTiers::classify('antipatterns.resume', false, true) === 'free', 'turning the resume back on must be free');
    },
    'L3: a multibyte tail cut landing mid-character no longer empties the HookLlm request body' => function () {
        $m = sys_get_temp_dir() . '/arf-l3-' . mt_rand();
        $pm = arfModel($m, ['none']);
        $tree = arfTree($pm[1]);
        try {
            $t = str_repeat('ä', 4001) . 'a'; // substr(-8000) on this UTF-8 string cuts on an odd byte offset, inside a 2-byte character
            arfPhp($tree, 'Antipatterns::forTurn("s-utf", ' . var_export($t, true) . ', [], "oneshot");');
            $req = arfRequests($m);
            echo "      [L3] " . json_encode($req) . "\n";
            check(isset($req[0]) && $req[0]['json_ok'] === true && $req[0]['len'] > 0, 'expected a valid, non-empty JSON body, got ' . json_encode($req));
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
    'L4: forTurn() re-stamps "now" under its own lock, so a colliding worker\'s lock-free again() during the model call does not survive into the final stamp' => function () {
        $m = sys_get_temp_dir() . '/arf-l4-' . mt_rand();
        $pm = arfModel($m, ['none'], 3); // answers after a 3s sleep: a window to simulate the collision mid-call
        $tree = arfTree($pm[1]);
        $p = null;
        try {
            $hooksDir = "$tree/pikkumies/hooks";
            $localDir = "$tree/pikkumies/local";
            $u = HookInterval::on([], $hooksDir)['review/antipattern-check'];
            $hash = sha1('s-race');
            $script = "$tree/bg-" . mt_rand() . '.php';
            file_put_contents($script, '<?php foreach (glob(' . var_export("$tree/pikkumies/lib/*.php", true) . ') as $l) { require_once $l; } '
                . 'Antipatterns::forTurn("s-race", "a plain final answer", [], "oneshot");');
            $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            usleep(900000); // let it claim the lock and reach the (sleeping) model call
            HookRun::again($localDir, $u, $hash); // simulates hooks/worker.php finding the run busy: this call takes no lock of its own
            for ($i = 0; $i < 100 && proc_get_status($p)['running']; $i++) {
                usleep(100000);
            }
            proc_close($p);
            $p = null;
            $finalStamp = (int) @file_get_contents(HookAsync::path($localDir, $u, $hash, '.stamp'));
            $age = time() - $finalStamp;
            echo "      [L4] final_stamp_age=$age (fixed: near 0; clobbered: near 270)\n";
            check($age <= 10, "the final stamp must be fresh (age<=10s), not the collision's clobbered value (~270s old): age=$age");
        } finally {
            if ($p !== null) {
                proc_terminate($p, 9);
            }
            arfDown($pm, [$m, $tree]);
        }
    },
    'L8: the background unit with no reply record answers {"skip":true}, so tool-only records are retried, not lost' => function () {
        $tree = apcTree(0);
        try {
            list($d, $raw) = apcRun($tree, [apcToolRecord('terminal', ['command' => 'uptime'])]);
            echo "      [L8] " . json_encode($raw) . "\n";
            check($d === ['skip' => true], 'expected exactly {"skip":true}, got ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'L9: an array-shaped final answer (multimodal content) is read as text, never cast to the literal string "Array"' => function () {
        $m = sys_get_temp_dir() . '/arf-l9-' . mt_rand();
        $pm = arfModel($m, ['load-is-not-cpu | pegging every one of the four cpu cores']);
        $tree = arfTree($pm[1]);
        try {
            $out = arfPhp($tree, '$ids = Antipatterns::forTurn("s-arr", [["type" => "text", "text" => "the agent said: load of 40 is basically pegging every one of the four cpu cores to the max"]], [], "oneshot"); echo json_encode($ids);');
            echo "      [L9] ids=" . trim($out) . "\n";
            check(trim($out) === '["load-is-not-cpu"]', 'an array-shaped final answer must still ground correctly (a bare (string) cast would read "Array" and ground nothing): got ' . $out);
        } finally {
            arfDown($pm, [$m, $tree]);
        }
    },
];
