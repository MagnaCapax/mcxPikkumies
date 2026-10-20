<?php
/**
 * hooks/halt/halt-snapshot/ (pre_llm_call), hooks/halt/halt-guard/ (pre_verify) and the orchestrator's
 * pre_verify merge path (ADR-0047). Each test builds its own throwaway pikkumies/{lib,hooks/halt}
 * tree so nothing here touches this checkout's real local/.
 */
if (!function_exists('hookUnitsProvider')) {
    require __DIR__ . '/HookUnitsTest.php';
}
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
if (!function_exists('haltRunFakeModel')) {
    require __DIR__ . '/haltRunSupport.php'; // its indexed replies.json fixture, for the judge call and the antipatterns call in one invocation
}
if (!function_exists('haltHookTree')) {
    /** A throwaway tree with lib/ and the two halt hook units; $providerPort wires local/config.php at a fake endpoint, 0 = none. */
    function haltHookTree($providerPort = 0)
    {
        $tmp = sys_get_temp_dir() . '/pk-halthook-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tmp/pikkumies/lib", 0700, true);
        foreach ((array) glob("$src/lib/*.php") as $f) {
            copy($f, "$tmp/pikkumies/lib/" . basename($f));
        }
        foreach (['halt-snapshot', 'halt-guard'] as $u) {
            @mkdir("$tmp/pikkumies/hooks/halt/$u", 0700, true);
            foreach ((array) glob("$src/hooks/halt/$u/*.*") as $f) {
                copy($f, "$tmp/pikkumies/hooks/halt/$u/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/local", 0700, true);
        if ($providerPort > 0) {
            file_put_contents("$tmp/key", "key=k\n");
            file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export(['provider' =>
                ['endpoint' => "http://127.0.0.1:$providerPort/v1", 'model' => 'm', 'keyFile' => "$tmp/key"]], true) . ";\n");
        }
        return $tmp;
    }

    /** haltHookTree() plus doctrine/antipatterns.md and the real review/antipattern-check unit (ADR-0088), so halt-guard.php's Antipatterns::forTurn() call finds the shared unit. */
    function haltHookTreeWithAntipatterns($providerPort)
    {
        $tmp = haltHookTree($providerPort);
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tmp/pikkumies/doctrine", 0700, true);
        copy("$src/doctrine/antipatterns.md", "$tmp/pikkumies/doctrine/antipatterns.md");
        @mkdir("$tmp/pikkumies/hooks/review/antipattern-check", 0700, true);
        foreach ((array) glob("$src/hooks/review/antipattern-check/*.*") as $f) {
            copy($f, "$tmp/pikkumies/hooks/review/antipattern-check/" . basename($f));
        }
        return $tmp;
    }

    /** The per-session halt state file path, mirroring HaltState::file() without loading the class. */
    function haltStateFile($tree, $sessionId)
    {
        return "$tree/pikkumies/local/state/halt/" . sha1($sessionId) . '.json';
    }

    /** Seeds a state file matching HaltState's own shape; $over replaces individual keys. */
    function haltSeed($tree, $sessionId, array $over = [])
    {
        $state = array_merge(['user_message' => 'do the thing', 'tail' => '[user] do the thing', 'turn_started' => time(),
            'continues' => 0, 'wrapup_sent' => false, 'last_judged_turn' => 0], $over);
        $file = haltStateFile($tree, $sessionId);
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, json_encode($state));
        chmod($file, 0600);
        return $state;
    }

    /** One command-log line (ADR-0044 shape) for $sessionId at $ts. */
    function haltCommandLog($tree, $sessionId, $ts, $command, $exitCode)
    {
        $dir = "$tree/pikkumies/local/logs/commands";
        @mkdir($dir, 0700, true);
        $file = $dir . '/' . gmdate('Ymd', $ts) . '.jsonl';
        file_put_contents($file, json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z', $ts), 'session_id' => $sessionId, 'command' => $command, 'exit_code' => $exitCode]) . "\n", FILE_APPEND);
    }

    /** Runs a halt hook unit; [decoded answer or null, raw stdout]. */
    function haltHookRun($tree, $unit, array $payload)
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/halt/$unit/$unit.php", [], json_encode($payload));
        return [json_decode($out, true), $out];
    }

    function haltDecisionLogLines($tree)
    {
        $f = "$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl';
        return is_file($f) ? array_values(array_filter(array_map(function ($l) {
            return json_decode($l, true);
        }, explode("\n", file_get_contents($f))))) : [];
    }
}

$continueReply = "Q1: no\nQ2: yes\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no"; // Q3 no -> continue
$doneReply = "Q1: no\nQ2: yes\nQ3: yes\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no"; // work carried out, nothing left -> wrap-up
$chatReply = "Q1: yes\nQ2: no\nQ3: no\nQ4: no\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no"; // chat, no request -> allow with no message (Antipatterns test below)

return [
    'halt-snapshot: writes {user_message, tail, turn_started, continues:0, wrapup_sent:false}, dir 0700, file 0600, prints nothing' => function () {
        $tree = haltHookTree();
        try {
            $payload = ['hook_event_name' => 'pre_llm_call', 'session_id' => 'sess-1', 'extra' => [
                'user_message' => 'please fix the thing', 'is_first_turn' => true,
                'conversation_history' => [['role' => 'assistant', 'content' => 'earlier turn']]]];
            list(, $out) = runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], json_encode($payload));
            check(trim($out) === '', 'halt-snapshot must print nothing: ' . $out);
            $file = haltStateFile($tree, 'sess-1');
            check(is_file($file), 'snapshot file not written');
            check((fileperms(dirname($file)) & 0777) === 0700, 'state dir must be 0700');
            check((fileperms($file) & 0777) === 0600, 'state file must be 0600');
            $state = json_decode(file_get_contents($file), true);
            check($state['user_message'] === 'please fix the thing', 'user_message not saved: ' . json_encode($state));
            check(strpos($state['tail'], '[assistant] earlier turn') !== false && strpos($state['tail'], '[user] please fix the thing') !== false, 'tail missing turn content: ' . $state['tail']);
            check($state['continues'] === 0 && $state['wrapup_sent'] === false && $state['last_judged_turn'] === 0, 'fresh-turn fields not reset: ' . json_encode($state));
            check(is_int($state['turn_started']) && $state['turn_started'] > 0, 'turn_started not set');
        } finally {
            testRmTree($tree);
        }
    },
    'halt-snapshot: an invalid or missing session id writes nothing; halt.disabled writes nothing' => function () {
        $tree = haltHookTree();
        try {
            $bad = ['hook_event_name' => 'pre_llm_call', 'session_id' => 'bad id! .. /', 'extra' => ['user_message' => 'x']];
            runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], json_encode($bad));
            check(!is_dir("$tree/pikkumies/local/state"), 'an invalid session id must not create local/state at all');
            $missing = ['hook_event_name' => 'pre_llm_call', 'extra' => ['user_message' => 'x']];
            runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], json_encode($missing));
            check(!is_dir("$tree/pikkumies/local/state"), 'a missing session id must not create local/state at all');
            file_put_contents("$tree/pikkumies/local/config.php", "<?php\nreturn ['halt' => ['disabled' => true]];\n");
            $ok = ['hook_event_name' => 'pre_llm_call', 'session_id' => 'sess-off', 'extra' => ['user_message' => 'x']];
            runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], json_encode($ok));
            check(!is_file(haltStateFile($tree, 'sess-off')), 'halt.disabled must stop the snapshot being written');
        } finally {
            testRmTree($tree);
        }
    },
    'halt-snapshot: a new session deletes snapshots older than two days and keeps recent ones' => function () {
        $tree = haltHookTree();
        try {
            $payload = function ($sid) {
                return json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => $sid, 'extra' => ['user_message' => 'x']]);
            };
            runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], $payload('old-sess'));
            runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], $payload('recent-sess'));
            $old = haltStateFile($tree, 'old-sess');
            $recent = haltStateFile($tree, 'recent-sess');
            touch($old, time() - 3 * 86400);
            touch($recent, time() - 86400);
            runPhp("$tree/pikkumies/hooks/halt/halt-snapshot/halt-snapshot.php", [], $payload('new-sess'));
            check(!is_file($old), 'a snapshot untouched for three days must be pruned when a new session starts');
            check(is_file($recent) && is_file(haltStateFile($tree, 'new-sess')), 'recent and new snapshots must stay');
        } finally {
            testRmTree($tree);
        }
    },
    'halt-guard: an invalid session id, or one with no snapshot, prints nothing' => function () {
        $tree = haltHookTree();
        try {
            list($d1, $raw1) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'bad id!', 'extra' => ['attempt' => 0]]);
            check($d1 === null && trim($raw1) === '', 'invalid session id must print nothing: ' . $raw1);
            list($d2, $raw2) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-none', 'extra' => ['attempt' => 0]]);
            check($d2 === null && trim($raw2) === '', 'no snapshot for this session must print nothing: ' . $raw2);
        } finally {
            testRmTree($tree);
        }
    },
    'halt-guard: refuses at attempt >= 3 without ever asking the model' => function () use ($continueReply) {
        $fake = sys_get_temp_dir() . '/pk-halthook-fake-' . getmypid() . '-' . mt_rand();
        @mkdir($fake, 0700, true);
        list($proc, $port) = hookUnitsProvider($fake);
        $tree = haltHookTree($port);
        try {
            file_put_contents("$fake/reply", $continueReply);
            haltSeed($tree, 'sess-cap', []);
            list($d, $raw) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-cap', 'extra' => ['attempt' => 3, 'final_response' => 'done']]);
            check($d === null && trim($raw) === '', 'attempt 3 must print nothing: ' . $raw);
            check(count(hookSearchRequests($fake)) === 0, 'the model must never be asked once the local attempt cap is hit');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
        }
    },
    'halt-guard: a continuing turn answers {action:continue,message:<fixed sentences>}, bumps continues, stamps last_judged_turn' => function () use ($continueReply) {
        $fake = sys_get_temp_dir() . '/pk-halthook-fake-' . getmypid() . '-' . mt_rand();
        @mkdir($fake, 0700, true);
        list($proc, $port) = hookUnitsProvider($fake);
        $tree = haltHookTree($port);
        try {
            file_put_contents("$fake/reply", $continueReply);
            $seeded = haltSeed($tree, 'sess-cont', []);
            list($d, $raw) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-cont', 'extra' => ['attempt' => 0, 'final_response' => 'partial answer']]);
            check(is_array($d) && $d['action'] === 'continue' && strpos($d['message'], HaltJudge::CONTINUE_SENTENCES[3]) !== false, 'continue answer: ' . $raw);
            $state = json_decode(file_get_contents(haltStateFile($tree, 'sess-cont')), true);
            check($state['continues'] === 1, 'continues must be bumped: ' . json_encode($state));
            check($state['last_judged_turn'] === $seeded['turn_started'], 'last_judged_turn must be stamped: ' . json_encode($state));
            check($state['wrapup_sent'] === false, 'a continue must not mark wrapup_sent');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
        }
    },
    'halt-guard: a finished turn wraps up exactly once; a second call for the same turn stays silent and never re-asks the model' => function () use ($doneReply) {
        $fake = sys_get_temp_dir() . '/pk-halthook-fake-' . getmypid() . '-' . mt_rand();
        @mkdir($fake, 0700, true);
        list($proc, $port) = hookUnitsProvider($fake);
        $tree = haltHookTree($port);
        try {
            file_put_contents("$fake/reply", $doneReply);
            haltSeed($tree, 'sess-done', []);
            list($d1, $raw1) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-done', 'extra' => ['attempt' => 0, 'final_response' => 'all done']]);
            check(is_array($d1) && $d1['action'] === 'continue' && $d1['message'] === HaltJudge::WRAPUP, 'first wrap-up answer: ' . $raw1);
            $afterFirst = json_decode(file_get_contents(haltStateFile($tree, 'sess-done')), true);
            check($afterFirst['wrapup_sent'] === true, 'wrapup_sent must be set after the one wrap-up');
            list($d2, $raw2) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-done', 'extra' => ['attempt' => 1, 'final_response' => 'still done']]);
            check($d2 === null && trim($raw2) === '', 'a second call this turn must stay silent: ' . $raw2);
            check(count(hookSearchRequests($fake)) === 1, 'the second call must never re-ask the model: ' . count(hookSearchRequests($fake)) . ' requests');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
        }
    },
    'halt-guard: the decision log never carries the transcript, the final answer, or the model\'s free text' => function () use ($continueReply) {
        $fake = sys_get_temp_dir() . '/pk-halthook-fake-' . getmypid() . '-' . mt_rand();
        @mkdir($fake, 0700, true);
        list($proc, $port) = hookUnitsProvider($fake);
        $tree = haltHookTree($port);
        try {
            file_put_contents("$fake/reply", $continueReply . "\nSECRET-INJECTED-TEXT");
            haltSeed($tree, 'sess-log', ['tail' => '[user] SECRET-TRANSCRIPT-MARKER']);
            list($d, $rawAnswer) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-log', 'extra' => ['attempt' => 0, 'final_response' => 'SECRET-FINAL-ANSWER-MARKER']]);
            check(is_array($d) && strpos($d['message'], 'SECRET-INJECTED-TEXT') === false, 'the hook\'s own continue message must never carry the model\'s free text: ' . $rawAnswer);
            $lines = haltDecisionLogLines($tree);
            check(count($lines) === 1, 'expected exactly one decision log line: ' . json_encode($lines));
            $raw = file_get_contents("$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl');
            foreach (['SECRET-TRANSCRIPT-MARKER', 'SECRET-FINAL-ANSWER-MARKER', 'SECRET-INJECTED-TEXT'] as $secret) {
                check(strpos($raw, $secret) === false, "$secret leaked into the decision log: $raw");
            }
            check($lines[0]['source'] === 'hook' && $lines[0]['verdict'] === 'continue' && $lines[0]['questions']['Q3'] === 'no', 'decision log fields: ' . json_encode($lines[0]));
            check((fileperms(dirname("$tree/pikkumies/local/logs/halt-guard/x")) & 0777) === 0700, 'log dir must be 0700');
            check((fileperms("$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl') & 0777) === 0600, 'log file must be 0600');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
        }
    },
    'halt-guard: evidence is filtered to this session_id and to commands since turn_started' => function () use ($continueReply) {
        $fake = sys_get_temp_dir() . '/pk-halthook-fake-' . getmypid() . '-' . mt_rand();
        @mkdir($fake, 0700, true);
        list($proc, $port) = hookUnitsProvider($fake);
        $tree = haltHookTree($port);
        try {
            file_put_contents("$fake/reply", $continueReply);
            $now = time();
            $seeded = haltSeed($tree, 'sess-ev', ['turn_started' => $now]);
            haltCommandLog($tree, 'sess-ev', $now - 3600, 'ls before the turn', 0); // too old: must not be evidence
            haltCommandLog($tree, 'other-session', $now, 'rm -rf /other', 0);       // another session: must not be evidence
            haltCommandLog($tree, 'sess-ev', $now, 'php tool.php fix --now', 1);    // this turn: must be evidence
            haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-ev', 'extra' => ['attempt' => 0, 'final_response' => '']]);
            $requests = hookSearchRequests($fake);
            check(count($requests) === 1, 'exactly one model call expected: ' . json_encode($requests));
            $prompt = $requests[0]['body']['messages'][1]['content'];
            check(strpos($prompt, 'fix --now') !== false, 'the in-window same-session command must be in the evidence: ' . $prompt);
            check(strpos($prompt, 'ls before the turn') === false, 'a command before turn_started leaked into the evidence');
            check(strpos($prompt, 'rm -rf /other') === false, 'another session\'s command leaked into the evidence');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
        }
    },
    'orchestrator: pre_verify\'s first continue answer wins; a Claude-Code decision/reason dialect also continues; no opinion gives no answer' => function () {
        $say = function (array $a) {
            return 'echo ' . var_export(json_encode($a), true) . ';';
        };
        $turn = ['hook_event_name' => 'pre_verify'];
        $tree = orchestratorTree(['h/a-silent' => ['pre_verify', false, 5, 'exit(0);'], 'h/b-first' => ['pre_verify', false, 5, $say(['action' => 'continue', 'message' => 'first wins'])], 'h/c-second' => ['pre_verify', false, 5, $say(['action' => 'continue', 'message' => 'second'])]]);
        try {
            list($d) = orchestratorRun($tree, 'pre_verify', $turn);
            check($d === ['action' => 'continue', 'message' => 'first wins'], 'first continue must win in name order: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
        $tree2 = orchestratorTree(['h/d-cc' => ['pre_verify', false, 5, $say(['decision' => 'block', 'reason' => 'cc says continue'])]]);
        try {
            list($d2) = orchestratorRun($tree2, 'pre_verify', $turn);
            check($d2 === ['action' => 'continue', 'message' => 'cc says continue'], 'Claude-Code dialect must also mean continue: ' . json_encode($d2));
        } finally {
            testRmTree($tree2);
        }
        $tree3 = orchestratorTree(['h/e-none' => ['pre_verify', false, 5, $say(['action' => 'allow'])]]);
        try {
            list(, $raw3) = orchestratorRun($tree3, 'pre_verify', $turn);
            check(trim($raw3) === '', 'an irrelevant action must be no opinion, not an answer: ' . $raw3);
        } finally {
            testRmTree($tree3);
        }
    },
    // Antipatterns (ADR-0088): halt-guard.php calls Antipatterns::forTurn()/apply() right after HaltJudge::evaluate(). The
    // attempt>=3 refusal (never asking the model) is unchanged and already covered above ("refuses at attempt >= 3").
    'halt-guard + Antipatterns: a chat verdict (no message of its own) with a flagged id answers one continue carrying resumeMessage(), marking wrapup_sent so a second attempt this turn stays silent' => function () use ($chatReply) {
        $fakeModel = sys_get_temp_dir() . '/pk-halthook-ap-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = haltHookTreeWithAntipatterns($port);
        try {
            $flagged = 'the agent replied: load of 40.02 is basically pegging every one of the four cpu cores to the max';
            file_put_contents("$fakeModel/replies.json", json_encode([$chatReply, 'load-is-not-cpu | pegging every one of the four cpu cores']));
            haltSeed($tree, 'sess-ap-chat', []);
            list($d, $raw) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-ap-chat', 'extra' => ['attempt' => 0, 'final_response' => $flagged]]);
            check(is_array($d) && $d['action'] === 'continue' && $d['message'] === Antipatterns::resumeMessage(['load-is-not-cpu']), 'answer: ' . $raw);
            $state = json_decode(file_get_contents(haltStateFile($tree, 'sess-ap-chat')), true);
            check($state['wrapup_sent'] === true, 'the one-shot resume must mark wrapup_sent, reusing the wrap-up slot: ' . json_encode($state));
            list($d2, $raw2) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => 'sess-ap-chat', 'extra' => ['attempt' => 1, 'final_response' => $flagged]]);
            check($d2 === null && trim($raw2) === '', 'a second attempt this turn must stay silent once wrapup_sent is set: ' . $raw2);
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 2, 'exactly 2 model requests total (judge + antipatterns); the silent second attempt must ask neither');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
];
