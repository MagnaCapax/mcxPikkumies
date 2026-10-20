<?php
/**
 * hooks/orchestrator.php with the ask policy (ADR-0073), end to end against a fake model endpoint: a reviewed question
 * clears only on a clear review of what the user wrote, and otherwise blocks the call (approvals are off, so no
 * question is ever printed); no snapshot and a load test are decided without a model call, the feedback send clears
 * only on the user's yes. What a real light model answers is not tested here.
 */
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
if (!function_exists('hookSearchFakeProvider')) {
    require __DIR__ . '/hookSearchSupport.php';
}
if (!function_exists('hookFailOutcomes')) {
    require __DIR__ . '/HookOrchestratorFailTest.php';
}

if (!function_exists('askOrchTree')) {
    /** A tree whose units echo the given approves, wired to the fake endpoint on $port; returns the tree path. */
    function askOrchTree(array $approves, $port)
    {
        $units = [];
        foreach ($approves as $id => $a) {
            $units[$id] = ['pre_tool_call', false, 5, 'echo ' . var_export(json_encode($a), true) . ';'];
        }
        $tree = orchestratorTree($units);
        $local = "$tree/pikkumies/local";
        file_put_contents("$tree/key", "key=test-key-1\n");
        file_put_contents("$local/config.php", '<?php return ' . var_export(['provider' => ['endpoint' => "http://127.0.0.1:$port/v1", 'model' => 'acme/main', 'keyFile' => "$tree/key"]], true) . ";\n");
        file_put_contents("$local/llm.json", json_encode(['main' => 'acme/main', 'light' => 'acme/light', 'decidedAt' => time()]));
        return $tree;
    }

    /** The halt snapshot the review reads its evidence from, as halt-snapshot writes it. */
    function askOrchSnapshot($tree, $session, array $asked)
    {
        @mkdir("$tree/pikkumies/local/state/halt", 0700, true);
        file_put_contents("$tree/pikkumies/local/state/halt/" . sha1($session) . '.json', json_encode(['user_message' => 'm', 'tail' => 't', 'asked' => $asked,
            'turn_started' => time(), 'continues' => 0, 'wrapup_sent' => false, 'last_judged_turn' => 0]));
    }
}

return [
    'orchestrator ask policy: a clear review of the user\'s own words clears a delete question; a doubtful one or no snapshot blocks it' => function () {
        $dir = testTempDir('pk-askorch');
        list($proc, $port) = hookSearchFakeProvider($dir);
        $del = ['action' => 'approve', 'message' => 'Pikkumies: deletes or empties files, which cannot be undone. Command [1]: rm -r ~/old', 'rule_key' => 'pikkumies-delete:1', 'kind' => 'delete'];
        $tree = askOrchTree(['guard/del' => $del], $port);
        $call = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -r ~/old'], 'session_id' => 'sess-1'];
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $call);
            check($d === ['action' => 'block', 'message' => AskPolicy::refused(true)] && hookSearchRequests($dir) === [], 'no snapshot: the delete must be blocked without a model call: ' . $raw);
            askOrchSnapshot($tree, 'sess-1', [['role' => 'user', 'text' => 'delete the old folder in my home']]);
            file_put_contents("$dir/reply", "R1: yes\nR2: yes\nR3: no");
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $call);
            check($d === null, 'a clear review must let the call run: ' . $raw);
            $req = hookSearchRequests($dir);
            check(count($req) === 1 && $req[0]['body']['model'] === 'acme/light' && strpos($req[0]['body']['messages'][1]['content'], '[user wrote] delete the old folder in my home') !== false, 'one review with the light model: ' . json_encode($req));
            check(in_array('ask-policy={"blocked":0,"dropped":0,"cleared":1,"ran":0,"unchecked":0}', hookFailOutcomes($tree), true), 'the clearing must be logged: ' . json_encode(hookFailOutcomes($tree)));
            file_put_contents("$dir/reply", "R1: unsure\nR2: yes\nR3: no");
            list($d) = orchestratorRun($tree, 'pre_tool_call', $call);
            check($d === ['action' => 'block', 'message' => AskPolicy::refused()], 'a doubtful review must block until the user says yes');
            file_put_contents("$dir/reply", "Sure, go ahead!");
            list($d) = orchestratorRun($tree, 'pre_tool_call', $call);
            check($d === ['action' => 'block', 'message' => AskPolicy::refused(true)], 'an unparsable review must block a delete');
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
            testRmTree($dir);
        }
    },
    'orchestrator ask policy: a load test runs unasked without a model call; the feedback send blocks until the user\'s yes' => function () {
        $dir = testTempDir('pk-askorch');
        list($proc, $port) = hookSearchFakeProvider($dir);
        file_put_contents("$dir/reply", "R1: unsure\nR2: no\nR3: no");
        $load = ['action' => 'approve', 'message' => 'Pikkumies: a benchmark or load test. Command [2]: fio', 'rule_key' => 'pikkumies-load:2', 'kind' => 'load'];
        $send = ['action' => 'approve', 'message' => 'Pikkumies: send this feedback?', 'rule_key' => 'pikkumies-feedback:3', 'kind' => 'send'];
        $call = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'fio'], 'session_id' => 'sess-2'];
        $loadTree = askOrchTree(['guard/perf' => $load], $port);
        $sendTree = askOrchTree(['guard/feedback-send' => $send], $port);
        try {
            askOrchSnapshot($loadTree, 'sess-2', [['role' => 'user', 'text' => 'x']]);
            askOrchSnapshot($sendTree, 'sess-2', [['role' => 'user', 'text' => 'send my feedback']]);
            list($d, $raw) = orchestratorRun($loadTree, 'pre_tool_call', $call);
            check($d === null, 'a load test must run unasked: ' . $raw);
            check(hookSearchRequests($dir) === [], 'a load test must not call the model');
            list($d, $raw) = orchestratorRun($sendTree, 'pre_tool_call', $call);
            check($d === ['action' => 'block', 'message' => AskPolicy::refused()], 'the model\'s R2 "no" must not clear the feedback send: ' . $raw);
            askOrchSnapshot($sendTree, 'sess-2', [['role' => 'user', 'text' => 'send my feedback'], ['role' => 'agent', 'text' => 'This sends the draft to Pulsed Media. Send it?'], ['role' => 'user', 'text' => 'yes']]);
            file_put_contents("$dir/reply", "R1: yes\nR2: no\nR3: no");
            list($d, $raw) = orchestratorRun($sendTree, 'pre_tool_call', $call);
            check($d === null, 'the user\'s yes must let the send run: ' . $raw);
        } finally {
            proc_terminate($proc);
            testRmTree($loadTree);
            testRmTree($sendTree);
            testRmTree($dir);
        }
    },
];
