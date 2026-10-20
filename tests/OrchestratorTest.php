<?php
/** hooks/orchestrator.php: runs the hook units of one event and merges their answers the way Hermes does (ADR-0030). */
if (!function_exists('orchestratorTree')) {
    function orchestratorCopy($src, $dst)
    {
        @mkdir($dst, 0700, true);
        foreach ((array) scandir($src) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$src/$f") ? orchestratorCopy("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
            }
        }
    }

    /**
     * A throwaway pikkumies tree with lib/ and the orchestrator, so nothing touches this checkout's local/.
     * $units null = the shipped hook units; else [category/name => [event, failClosed, timeout, php body]].
     */
    function orchestratorTree($units = null)
    {
        $tmp = sys_get_temp_dir() . '/pk-orch-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        orchestratorCopy("$src/lib", "$tmp/pikkumies/lib");
        @mkdir("$tmp/pikkumies/local", 0700, true);
        if ($units === null) {
            orchestratorCopy("$src/hooks", "$tmp/pikkumies/hooks");
            return $tmp;
        }
        @mkdir("$tmp/pikkumies/hooks", 0700, true);
        copy("$src/hooks/orchestrator.php", "$tmp/pikkumies/hooks/orchestrator.php");
        foreach ($units as $id => $u) {
            $dir = "$tmp/pikkumies/hooks/$id";
            $name = basename($id);
            @mkdir($dir, 0700, true);
            file_put_contents("$dir/hook.md", "---\nname: $name\nevent: {$u[0]}\ndescription: fixture\nfailClosed: " . ($u[1] ? 'true' : 'false') . "\ntimeout: {$u[2]}\n---\nfixture\n");
            file_put_contents("$dir/README.md", "# $name\n");
            file_put_contents("$dir/$name.php", "<?php\n" . $u[3] . "\n");
        }
        return $tmp;
    }

    /** Run the orchestrator for $event with $payload; returns [decoded answer or null, raw stdout]. */
    function orchestratorRun($tree, $event, array $payload)
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/orchestrator.php", [$event], json_encode($payload));
        return [json_decode($out, true), $out];
    }
}

$terminal = ['hook_event_name' => 'pre_tool_call', 'tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']];
$say = function (array $answer) {
    return 'echo ' . var_export(json_encode($answer), true) . ';';
};
return [
    'orchestrator: the shipped guard still blocks, asks and stays silent through the orchestrator' => function () {
        $tree = orchestratorTree();
        list($d) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'mkfs.ext4 /home/x.img']]);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'filesystem format') !== false, 'mkfs not blocked through the orchestrator');
        list($d) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rclone sync ~/a remote:a']]);
        // No session, so no yes can be checked: the ask policy blocks the delete (ADR-0073); approvals are off, so it never asks.
        check($d === ['action' => 'block', 'message' => AskPolicy::BLOCK_UNCHECKED], 'delete not blocked through the orchestrator: ' . json_encode($d));
        list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls -la']]);
        check(trim($raw) === '', 'harmless command got an answer: ' . $raw);
        // memory-check holds the first write of a file in a session once (ADR-0081); then the search-first gate runs: with a
        // session and no search yet, the acting call is blocked.
        list($d) = orchestratorRun($tree, 'pre_tool_call', ['session_id' => 's', 'tool_name' => 'write_file', 'tool_input' => ['path' => 'x']]);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'Pikkumies memory check') === 0, 'memory-check not run by the orchestrator: ' . json_encode($d));
        list($d) = orchestratorRun($tree, 'pre_tool_call', ['session_id' => 's', 'tool_name' => 'write_file', 'tool_input' => ['path' => 'x']]);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'search first') !== false, 'gate not run by the orchestrator');
        list(, $raw) = orchestratorRun($tree, 'no_such_event', ['tool_name' => 'terminal']);
        check(trim($raw) === '', 'an event without units must print nothing');
        $log = (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl');
        $lines = array_values(array_filter(explode("\n", $log)));
        // Units run in name order: guard/config-change and guard/feedback-send (no opinion on rm -rf), guard/fileops-check, guard/guard-destructive (the block), guard/litter-check (ADR-0093),
        // guard/publish-check, search/memory-check, search/search-first-gate, guard/ssh-connect, guard/ticket-send (ADR-0048, ADR-0059, ADR-0068, ADR-0081, ADR-0083,
        // ADR-0089): 10 units, 5 calls; the blocked
        // delete adds one ask-policy line (ADR-0073).
        $config = json_decode($lines[0], true);
        $send = json_decode($lines[1], true);
        $first = json_decode($lines[2], true);
        $guard = json_decode($lines[3], true);
        // 51 = 10 units x 5 calls + the one ask-policy line of the blocked delete.
        check(count($lines) === 51 && $config['unit'] === 'guard/config-change' && $config['outcome'] === 'none' && $send['unit'] === 'guard/feedback-send' && $send['outcome'] === 'none'
            && $first['unit'] === 'guard/fileops-check' && $first['outcome'] === 'none'
            && $guard['event'] === 'pre_tool_call' && $guard['unit'] === 'guard/guard-destructive' && $guard['outcome'] === 'block'
            && is_int($guard['ms']) && isset($guard['ts']), 'one log line per unit run: ' . $log);
        check(strpos($log, 'rm -rf') === false && strpos($log, 'rclone') === false, 'the payload must not be logged');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'orchestrator: a failClosed unit that crashes, times out, prints garbage or is broken blocks; a fail-open one is ignored' => function () use ($terminal) {
        $cases = [
            'crash' => 'undefined_function_xyz();',
            'timeout' => 'sleep(5);',
            'garbage' => 'echo "not json";',
            'list' => 'echo "[]";',
            'exit' => 'exit(1);',
        ];
        foreach ($cases as $what => $body) {
            $tree = orchestratorTree(["fx/closed-$what" => ['pre_tool_call', true, 1, $body]]);
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $terminal);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'fx/closed-' . $what . ' failed closed') !== false, "failClosed $what did not block: $raw");
            exec('rm -rf ' . escapeshellarg($tree));
            $tree = orchestratorTree(["fx/open-$what" => ['pre_tool_call', false, 1, $body]]);
            list(, $raw) = orchestratorRun($tree, 'pre_tool_call', $terminal);
            check(trim($raw) === '', "fail-open $what was not ignored: $raw");
            exec('rm -rf ' . escapeshellarg($tree));
        }
        // A broken unit that declares failClosed still blocks instead of being skipped.
        $tree = orchestratorTree(['fx/broken' => ['pre_tool_call', true, 0, 'exit(0);']]);
        list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'timeout must be') !== false, 'broken failClosed unit skipped: ' . $raw);
        exec('rm -rf ' . escapeshellarg($tree));
        // A fail-open failure next to a working unit leaves that unit's answer.
        $tree = orchestratorTree(['fx/a-crash' => ['pre_tool_call', false, 1, 'undefined_function_xyz();'], 'fx/b-stop' => ['pre_tool_call', false, 1, 'echo json_encode(["action" => "block", "message" => "stop"]);']]);
        list($d) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        check($d === ['action' => 'block', 'message' => 'stop'], 'a fail-open crash hid the next unit');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'orchestrator: block beats approve whatever the order; two approves become one question; modifies merge in order' => function () use ($terminal, $say) {
        $tree = orchestratorTree([
            'aa/a-approve' => ['pre_tool_call', false, 5, $say(['action' => 'approve', 'message' => 'ask first', 'rule_key' => 'k1'])],
            'zz/z-block' => ['pre_tool_call', false, 5, $say(['action' => 'block', 'message' => 'no'])],
        ]);
        list($d) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        check($d === ['action' => 'block', 'message' => 'no'], 'block must beat an earlier approve: ' . json_encode($d));
        exec('rm -rf ' . escapeshellarg($tree));
        $tree = orchestratorTree([
            'x/b-second' => ['pre_tool_call', false, 5, $say(['action' => 'approve', 'message' => 'second', 'rule_key' => 'k2'])],
            'y/a-first' => ['pre_tool_call', false, 5, $say(['action' => 'approve', 'message' => ' first ', 'rule_key' => ' k1 '])],
            'y/c-noise' => ['pre_tool_call', false, 5, $say(['action' => 'allow', 'message' => 'irrelevant'])],
        ]);
        list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        // ADR-0073: questions of no known kind run where no yes can be checked (no session); approvals are off, so none is printed.
        check($d === null, 'two questions of no kind, no session: the call runs: ' . $raw);
        // ADR-0054, the merge itself: every question is shown, in run order, trimmed; the key covers both.
        $d = HookAnswers::mergePreToolCall([['action' => 'approve', 'message' => 'first', 'rule_key' => ' k1 '], ['action' => 'approve', 'message' => 'second', 'rule_key' => 'k2'], ['action' => 'allow', 'message' => 'irrelevant']]);
        check($d === ['action' => 'approve', 'message' => 'Pikkumies: 2 checks ask about this call. (1) first (2) second', 'rule_key' => 'pikkumies-multi:' . substr(sha1("k1\nk2"), 0, 16)], 'two approves must merge into one question: ' . json_encode($d));
        exec('rm -rf ' . escapeshellarg($tree));
        $tree = orchestratorTree([
            'm/a-mod' => ['pre_tool_call', false, 5, $say(['action' => 'modify', 'args' => ['a' => 1, 'b' => 1]])],
            'm/b-mod' => ['pre_tool_call', false, 5, $say(['decision' => 'modify', 'tool_input' => ['b' => 2]])],
            'm/c-empty' => ['pre_tool_call', false, 5, $say(['action' => 'modify', 'args' => []])],
        ]);
        list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        check($d === ['action' => 'modify', 'args' => ['a' => 1, 'b' => 2]], 'modify args must shallow-merge in order: ' . $raw);
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'orchestrator: exit code 2 and a block without a message block, as Hermes shell hooks do' => function () use ($terminal, $say) {
        $tree = orchestratorTree(['fx/two' => ['pre_tool_call', false, 5, 'fwrite(STDERR, "stopped by fx"); exit(2);']]);
        list($d) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        check($d === ['action' => 'block', 'message' => 'stopped by fx'], 'exit 2 must block with the stderr text: ' . json_encode($d));
        exec('rm -rf ' . escapeshellarg($tree));
        // agent/shell_hooks.py _block_message gives a message-less block the default text, so it blocks.
        $tree = orchestratorTree(['fx/bare' => ['pre_tool_call', false, 5, $say(['action' => 'block'])]]);
        list($d) = orchestratorRun($tree, 'pre_tool_call', $terminal);
        check($d === ['action' => 'block', 'message' => 'Blocked by shell hook.'], 'bare block: ' . json_encode($d));
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'orchestrator: pre_llm_call contexts are joined by a blank line and capped at 9000 characters' => function () use ($say) {
        $tree = orchestratorTree([
            'c/a-one' => ['pre_llm_call', false, 5, $say(['context' => 'one'])],
            'c/b-blank' => ['pre_llm_call', false, 5, $say(['context' => '   '])],
            'c/c-two' => ['pre_llm_call', false, 5, $say(['context' => 'two'])],
        ]);
        list($d) = orchestratorRun($tree, 'pre_llm_call', ['hook_event_name' => 'pre_llm_call']);
        check($d === ['context' => "one\n\ntwo"], 'contexts not joined: ' . json_encode($d));
        exec('rm -rf ' . escapeshellarg($tree));
        $tree = orchestratorTree(['c/big' => ['pre_llm_call', false, 5, 'echo json_encode(["context" => str_repeat("ä", 12000)]);']]);
        list($d) = orchestratorRun($tree, 'pre_llm_call', ['hook_event_name' => 'pre_llm_call']);
        check(is_array($d) && mb_strlen($d['context'], 'UTF-8') === 9000, 'context not capped at 9000 characters');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'orchestrator: a unit that ignores a large stdin does not hang; the log keeps 7 daily files' => function () use ($say) {
        $tree = orchestratorTree(['fx/quick' => ['pre_tool_call', true, 5, $say(['action' => 'block', 'message' => 'm'])]]);
        $logs = "$tree/pikkumies/local/logs/hooks";
        @mkdir($logs, 0700, true);
        for ($i = 1; $i <= 9; $i++) {
            touch(sprintf('%s/202001%02d.jsonl', $logs, $i));
        }
        $start = microtime(true);
        list($d) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => str_repeat('x', 3000000)]]);
        check($d === ['action' => 'block', 'message' => 'm'] && microtime(true) - $start < 4, 'large stdin hung or lost the answer');
        $files = glob("$logs/*.jsonl");
        check(count($files) === 7 && in_array("$logs/" . gmdate('Ymd') . '.jsonl', $files, true), 'log not pruned to 7 files: ' . json_encode(array_map('basename', $files)));
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'orchestrator: the Hermes config gets one entry per event; fail_closed when any unit of it is' => function () {
        $tree = orchestratorTree([
            'g/guard' => ['pre_tool_call', true, 10, 'exit(0);'],
            'g/other' => ['pre_tool_call', false, 7, 'exit(0);'],
            's/ctx' => ['pre_llm_call', false, 3, 'exit(0);'],
        ]);
        $e = HermesConfig::hookEntries("$tree/pikkumies/hooks");
        check(array_keys($e) === ['pre_llm_call', 'pre_tool_call'], 'entries: ' . json_encode($e));
        check($e['pre_tool_call'] === ['command' => PHP_BINARY . " -d display_errors=stdout -d error_reporting=4437 $tree/pikkumies/hooks/orchestrator.php pre_tool_call 27", 'failClosed' => true, 'timeout' => 27], 'pre_tool_call entry: ' . json_encode($e['pre_tool_call'])); // 10 + 7 + 2, plus AskReview::TIMEOUT (8) for the ask review (ADR-0073)
        check($e['pre_llm_call']['failClosed'] === false && $e['pre_llm_call']['timeout'] === 5, 'pre_llm_call entry: ' . json_encode($e['pre_llm_call']));
        $y = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a'], $e);
        check(substr_count($y, '    - command: ') === 2 && substr_count($y, 'fail_closed: true') === 1 && strpos($y, "hooks_auto_accept: true\n") !== false, 'yaml: ' . $y);
        exec('rm -rf ' . escapeshellarg($tree));
        // A unit with problems is reported by HookUnits; failClosed outside pre_tool_call is refused.
        $tree = orchestratorTree(['g/bad' => ['pre_llm_call', true, 5, 'exit(0);']]);
        $all = HookUnits::all("$tree/pikkumies/hooks");
        check(count($all) === 1 && $all[0]['errors'], 'failClosed on pre_llm_call accepted');
        exec('rm -rf ' . escapeshellarg($tree));
    },
];
