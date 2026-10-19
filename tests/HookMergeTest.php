<?php
/**
 * ADR-0054: two or more approves become one question holding every unit's question unaltered, whatever their order,
 * keyed pikkumies-multi:<sha1 of the sorted keys>, and blocked when together they pass HookUnits::APPROVE_SHOWN; and
 * lib/HookPayload.php reads a call exactly as the guard's own inline code did before it moved there. Through the
 * orchestrator no question is printed (ADR-0073: approvals are off, the ask policy blocks or lets the call run), so
 * the merge is tested on HookAnswers directly and the orchestrator for what the ask policy makes of the questions.
 */
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
if (!function_exists('hookMergeOldInline')) {
    /** The guard's extraction as it was inline before ADR-0054 (guard-destructive.php, 796f2e0, lines 18-44). */
    function hookMergeOldInline($payload)
    {
        $tool = is_array($payload) && isset($payload['tool_name']) ? $payload['tool_name'] : '';
        $input = is_array($payload) && isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        $field = $tool === 'terminal' ? 'command' : ($tool === 'execute_code' ? 'code' : '');
        if ($tool === 'process_manage' && isset($input['action']) && in_array($input['action'], ['write', 'submit'], true)) {
            $field = 'data';
        }
        $fileTool = in_array($tool, ['read_file', 'write_file', 'patch', 'search_files'], true);
        $scheduleTool = $tool === 'cronjob_manage';
        if ($field === '' && !$fileTool && !$scheduleTool) {
            return ['', '', []];
        }
        if ($fileTool) {
            $command = (isset($input['path']) ? (string) $input['path'] : '') . ' ' . (isset($input['pattern']) ? (string) $input['pattern'] : '');
        } elseif ($scheduleTool) {
            $command = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } else {
            $command = isset($input[$field]) ? (string) $input[$field] : '';
        }
        $bare = str_replace(['"', "'", '\\'], '', $command);
        $kind = $fileTool ? 'file' : ($scheduleTool ? 'schedule' : 'command');
        return [$kind, $command, array_values(array_unique([$command, $bare, preg_replace('#/(\./)*/*#', '/', $bare)]))];
    }
}

$sayHM = function (array $answer) {
    return 'echo ' . var_export(json_encode($answer), true) . ';';
};
return [
    'hook merge: a tool hook approving next to a guarded delete cannot let the delete through; the merged key is new and order-free' => function () use ($sayHM) {
        $keys = [];
        foreach (['aaa', 'zzz'] as $name) {
            $tree = orchestratorTree();
            $dir = "$tree/pikkumies/hooks/tools/terminal/$name";
            mkdir($dir, 0700, true);
            file_put_contents("$dir/hook.md", "---\nname: $name\nevent: pre_tool_call\ndescription: fixture\nfailClosed: false\ntimeout: 5\n---\nx\n");
            file_put_contents("$dir/README.md", "# $name\n");
            file_put_contents("$dir/$name.php", "<?php\n" . $sayHM(['action' => 'approve', 'message' => 'tool asks', 'rule_key' => 'k']));
            try {
                list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm f']]);
                check($d === ['action' => 'block', 'message' => AskPolicy::BLOCK_UNCHECKED], "$name: the delete must stay blocked (no session): $raw");
            } finally {
                testRmTree($tree);
            }
        }
        $guard = ['action' => 'approve', 'message' => 'Pikkumies: deletes or empties files', 'rule_key' => 'pikkumies-delete:1'];
        $tool = ['action' => 'approve', 'message' => 'tool asks', 'rule_key' => 'k'];
        foreach ([[$tool, $guard], [$guard, $tool]] as $pair) {
            $d = HookAnswers::mergePreToolCall($pair);
            check(strpos($d['message'], 'deletes or empties files') !== false && strpos($d['message'], 'tool asks') !== false, 'the guard\'s question was replaced: ' . json_encode($d));
            check($d['rule_key'] !== 'k' && strpos($d['rule_key'], 'pikkumies-multi:') === 0, 'rule_key ' . $d['rule_key']);
            $keys[] = $d['rule_key'];
        }
        check($keys[0] === $keys[1], 'the merged key depends on which unit sorts first: ' . json_encode($keys));
    },
    'hook merge: merged questions up to ' . HookUnits::APPROVE_SHOWN . ' characters are asked, one more is blocked' => function () {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        $fixed = strlen('Pikkumies: 2 checks ask about this call. (1) ') + strlen(' (2) b');
        foreach ([HookUnits::APPROVE_SHOWN => 'approve', HookUnits::APPROVE_SHOWN + 1 => 'block'] as $total => $want) {
            $d = HookAnswers::mergePreToolCall([
                ['action' => 'approve', 'message' => str_repeat('a', $total - $fixed), 'rule_key' => 'k1', 'unit' => 'a/one'],
                ['action' => 'approve', 'message' => 'b', 'unit' => 'a/two'],
            ]);
            check(is_array($d) && $d['action'] === $want, "$total characters: expected $want: " . json_encode($d));
            check($want === 'block' || strlen($d['message']) === $total, 'length arithmetic off: ' . strlen($d['message']));
        }
    },
    'hook merge: the shipped feedback-send question, with no yes to check, is a block through every shipped unit (approvals off)' => function () {
        if (!function_exists('feedbackTree')) {
            require __DIR__ . '/FeedbackTest.php';
        }
        $tree = feedbackTree();
        try {
            orchestratorCopy(dirname(__DIR__) . '/pikkumies/hooks', "$tree/pikkumies/hooks");
            $rec = FeedbackDraft::create('the tool times out', '', [], 1792300000, "$tree/pikkumies/local/state/feedback", ['s-1', 'Magnets']);
            $cmd = "php $tree/pikkumies/tools/system/feedback/feedback.php send {$rec['id']}";
            $payload = ['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]; // no session: search-first-gate stays out
            $direct = testWithEnv(['HOME' => $tree], function () use ($tree, $cmd) {
                return feedbackHook($tree, 'terminal', ['command' => $cmd]);
            });
            list($d, $raw) = testWithEnv(['HOME' => $tree], function () use ($tree, $payload) {
                return orchestratorRun($tree, 'pre_tool_call', $payload);
            });
            check(is_array($direct) && $direct['action'] === 'approve' && $direct['rule_key'] === 'pikkumies-send:' . $rec['id'], 'the unit itself still asks: ' . json_encode($direct));
            check($d === ['action' => 'block', 'message' => AskPolicy::BLOCK_UNCHECKED], 'with no session nobody\'s yes can clear the send, and Hermes must never get an approve: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'hook merge: HookPayload::read is the guard\'s old inline extraction, byte for byte' => function () {
        $corpus = [
            ['tool_name' => 'terminal', 'tool_input' => ['command' => 'r"m" -rf //home/./x']],
            ['tool_name' => 'terminal', 'tool_input' => []], ['tool_name' => 'terminal'],
            ['tool_name' => 'execute_code', 'tool_input' => ['code' => "import os; os.remove('a')"]],
            ['tool_name' => 'process_manage', 'tool_input' => ['action' => 'write', 'data' => 'rm x']],
            ['tool_name' => 'process_manage', 'tool_input' => ['action' => 'submit', 'data' => "'q'"]],
            ['tool_name' => 'process_manage', 'tool_input' => ['action' => 'kill', 'data' => 'rm x']],
            ['tool_name' => 'read_file', 'tool_input' => ['path' => '~/pikkumies/local/secrets/x']],
            ['tool_name' => 'search_files', 'tool_input' => ['path' => '/h', 'pattern' => 'vsk-']],
            ['tool_name' => 'write_file', 'tool_input' => ['path' => 'a', 'content' => 'b']], ['tool_name' => 'patch', 'tool_input' => 'not an array'],
            ['tool_name' => 'cronjob_manage', 'tool_input' => ['script' => 'x.sh', 'name' => 'é']],
            ['tool_name' => 'web_search', 'tool_input' => ['query' => 'rm']], ['tool_name' => ['odd']], [],
        ];
        foreach ($corpus as $p) {
            check(HookPayload::read($p) === hookMergeOldInline($p), 'differs for ' . json_encode($p) . ': ' . json_encode(HookPayload::read($p)));
        }
    },
    'hook merge: an unknown SSH host alone runs where no yes can be checked; next to a delete, allowed or not, the delete blocks (ADR-0068, ADR-0073)' => function () {
        $tree = orchestratorTree();
        try {
            $cmd = 'rsync -a --delete d/ unknown.example:d/ # intent: mirror the reports folder';
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]); // no session: search-first-gate stays out
            check($d === ['action' => 'block', 'message' => AskPolicy::BLOCK_UNCHECKED], 'the delete must block: ' . $raw);
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ssh unknown.example uptime # intent: check the load']]);
            check($d === null, 'an unknown host alone is not a delete or a send: it runs: ' . $raw);
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['ssh' => ['allow' => ['unknown.example']]], true) . ";\n");
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]); // no session: search-first-gate stays out
            check($d === ['action' => 'block', 'message' => AskPolicy::BLOCK_UNCHECKED], 'only the delete blocks: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
];
