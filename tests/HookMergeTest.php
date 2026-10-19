<?php
/**
 * ADR-0054: two or more approves become one question holding every unit's question unaltered, whatever their order,
 * keyed pikkumies-multi:<sha1 of the sorted keys>, and blocked when together they pass HookUnits::APPROVE_SHOWN; and
 * lib/HookPayload.php reads a call exactly as the guard's own inline code did before it moved there.
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
    'hook merge: a tool hook approving next to a guarded delete keeps the guard\'s question and command; the key is new and order-free' => function () use ($sayHM) {
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
                $id = substr(sha1('rm f'), 0, 16);
                check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], "deletes or empties files, which cannot be undone. Command [$id]: rm f") !== false
                    && strpos($d['message'], 'tool asks') !== false, "$name: the guard's question was replaced: $raw");
                check($d['rule_key'] !== 'k' && strpos($d['rule_key'], 'pikkumies-multi:') === 0, "$name: rule_key " . $d['rule_key']);
                $keys[] = $d['rule_key'];
            } finally {
                testRmTree($tree);
            }
        }
        check($keys[0] === $keys[1], 'the merged key depends on which unit sorts first: ' . json_encode($keys));
    },
    'hook merge: merged questions up to ' . HookUnits::APPROVE_SHOWN . ' characters are asked, one more is blocked' => function () use ($sayHM) {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        $fixed = strlen('Pikkumies: 2 checks ask about this call. (1) ') + strlen(' (2) b');
        foreach ([HookUnits::APPROVE_SHOWN => 'approve', HookUnits::APPROVE_SHOWN + 1 => 'block'] as $total => $want) {
            $tree = orchestratorTree([
                'a/one' => ['pre_tool_call', false, 5, $sayHM(['action' => 'approve', 'message' => str_repeat('a', $total - $fixed), 'rule_key' => 'k1'])],
                'a/two' => ['pre_tool_call', false, 5, $sayHM(['action' => 'approve', 'message' => 'b'])],
            ]);
            try {
                list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']]);
                check(is_array($d) && $d['action'] === $want, "$total characters: expected $want: $raw");
                check($want === 'block' || strlen($d['message']) === $total, 'length arithmetic off: ' . strlen($d['message']));
            } finally {
                testRmTree($tree);
            }
        }
    },
    'hook merge: the shipped feedback-send question still reaches the user alone and unaltered through every shipped unit' => function () {
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
            check(is_array($direct) && is_array($d) && ksort($direct) && ksort($d) && $direct['action'] === 'approve' && $d === $direct, 'the orchestrator changed the feedback question: ' . $raw);
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
];
