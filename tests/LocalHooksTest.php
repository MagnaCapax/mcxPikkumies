<?php
/**
 * Local hooks (ADR-0054): the user's units in local/hooks/ run after the shipped ones, always fail open, never change a
 * call, are started only while the entry timeout leaves room, and anything skipped is named to the agent. local/ must
 * be this account's and closed to others; a symlinked unit or file is ignored.
 */
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
if (!function_exists('hookFailOutcomes')) {
    require __DIR__ . '/HookOrchestratorFailTest.php';
}
if (!function_exists('localHookUnit')) {
    /** local/hooks/<id>/ in $tree with hook.md, README.md and <name>.php ($body after <?php). */
    function localHookUnit($tree, $id, $event, $failClosed, $timeout, $body)
    {
        $dir = "$tree/pikkumies/local/hooks/$id";
        $name = basename($id);
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/hook.md", "---\nname: $name\nevent: $event\ndescription: local fixture\nfailClosed: " . ($failClosed ? 'true' : 'false') . "\ntimeout: $timeout\n---\nx\n");
        file_put_contents("$dir/README.md", "# $name\n");
        file_put_contents("$dir/$name.php", "<?php\n$body\n");
        return $dir;
    }

    /** The orchestrator run as Hermes runs it, with the entry timeout as the last argument: [answer, raw]. */
    function localHookRun($tree, $event, array $payload, $budget = '30')
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/orchestrator.php", [$event, $budget], json_encode($payload));
        return [json_decode($out, true), $out];
    }

    /** A Pikkumies start: generate records the local units as they are now, so they run from here (ADR-0054). */
    function localHookStart($tree)
    {
        return LocalHooks::noteChanges("$tree/pikkumies");
    }

    /** The pre_llm_call context for session s (what the agent reads on its next turn). */
    function localHookTurn($tree)
    {
        list($d) = localHookRun($tree, 'pre_llm_call', ['session_id' => 's']);
        return is_array($d) && isset($d['context']) ? $d['context'] : '';
    }
}

$ls = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls'], 'session_id' => 's'];
$sayLH = function (array $answer) {
    return 'echo ' . var_export(json_encode($answer), true) . ';';
};
return [
    'local hooks: run after every shipped unit; a local approve next to a guarded rm blocks the call when no yes can be checked' => function () use ($sayLH) {
        $tree = orchestratorTree();
        localHookUnit($tree, 'mine/aaa', 'pre_tool_call', false, 5, $sayLH(['action' => 'approve', 'message' => 'local asks', 'rule_key' => 'lk']));
        localHookStart($tree);
        try {
            list($d, $raw) = localHookRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm f']]);
            check($d === ['action' => 'block', 'message' => AskPolicy::refused(true)], 'no session: the delete and the local question must block (ADR-0073): ' . $raw);
            $log = hookFailOutcomes($tree);
            $policy = array_pop($log);
            check(end($log) === 'local/mine/aaa=approve' && count($log) > 1 && strpos($policy, 'ask-policy=') === 0, 'the local unit did not run last, under local/, before the ask policy: ' . json_encode($log));
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks: a local unit that crashes fails open even with failClosed, and is named on the next turn' => function () use ($ls) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        localHookUnit($tree, 'mine/crash', 'pre_tool_call', true, 5, 'undefined_function_xyz();');
        localHookUnit($tree, 'mine/garbage', 'pre_tool_call', true, 5, 'echo "not json";');
        localHookStart($tree);
        try {
            list($d, $raw) = localHookRun($tree, 'pre_tool_call', $ls);
            check(trim($raw) === '', 'a failing local unit blocked: ' . $raw);
            $turn = localHookTurn($tree);
            check(strpos($turn, 'Pikkumies: the local hook local/mine/crash failed (exit-255) and was skipped') !== false, 'the crash was not named: ' . $turn);
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks: a symlinked unit or file is ignored with a notice; a broken unit is skipped, never blocks' => function () use ($ls, $sayLH) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        $real = localHookUnit($tree, 'elsewhere/stop', 'pre_tool_call', false, 5, $sayLH(['action' => 'block', 'message' => 'ran']));
        rename($real, "$tree/stop");
        rmdir(dirname($real));
        symlink("$tree/stop", "$tree/pikkumies/local/hooks/mine");
        @mkdir("$tree/pikkumies/local/hooks/cat", 0700, true);
        symlink("$tree/stop", "$tree/pikkumies/local/hooks/cat/stop");
        $dir = localHookUnit($tree, 'other/filelink', 'pre_tool_call', false, 5, 'exit(0);');
        unlink("$dir/filelink.php");
        symlink("$tree/stop/stop.php", "$dir/filelink.php");
        @mkdir("$tree/pikkumies/local/hooks/other/empty", 0700, true);
        localHookStart($tree);
        try {
            list(, $raw) = localHookRun($tree, 'pre_tool_call', $ls);
            check(trim($raw) === '', 'a symlinked or broken local unit ran or blocked: ' . $raw);
            $turn = localHookTurn($tree);
            foreach (['local/mine: a symlinked directory', 'local/cat/stop: a symlinked directory', 'local/other/filelink: filelink.php is a symlink', 'local/other/empty: hook.md'] as $want) {
                check(strpos($turn, "skipped the local hook $want") !== false, "not named: $want in $turn");
            }
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks: a group-writable dir inside local/ is fine; local/ open to group or others in any way turns every local hook off and says so' => function () use ($ls, $sayLH) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        $dir = localHookUnit($tree, 'mine/stop', 'pre_tool_call', false, 5, $sayLH(['action' => 'block', 'message' => 'ran']));
        chmod(dirname($dir), 0775); // umask 002 on user-private-group systems makes every new dir like this
        localHookStart($tree);
        try {
            list($d) = localHookRun($tree, 'pre_tool_call', $ls);
            check(is_array($d) && $d['message'] === 'ran', 'a 0775 dir inside local/ turned the unit off');
            foreach ([0770, 0750, 0705] as $mode) {
                chmod("$tree/pikkumies/local", $mode);
                list(, $raw) = localHookRun($tree, 'pre_tool_call', $ls);
                check(trim($raw) === '', sprintf('a local unit ran while local/ is %o: ', $mode) . $raw);
            }
            check(strpos(localHookTurn($tree), 'local hooks are off because') !== false, 'the agent was not told local hooks are off');
            check(strpos(LocalHooks::noteChanges("$tree/pikkumies"), 'local hooks are off') !== false, 'the start notice does not say local hooks are off');
        } finally {
            chmod("$tree/pikkumies/local", 0700);
            testRmTree($tree);
        }
    },
    'local hooks: a local modify is ignored; a unit whose timeout no longer fits the entry is not started and is named' => function () use ($ls, $sayLH) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        localHookUnit($tree, 'mine/mod', 'pre_tool_call', false, 5, $sayLH(['action' => 'modify', 'args' => ['command' => 'rm x']]));
        localHookUnit($tree, 'mine/slow', 'pre_tool_call', false, 10, $sayLH(['action' => 'block', 'message' => 'slow ran']));
        localHookStart($tree);
        try {
            list(, $raw) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['pre_tool_call', (string) (9 + AskReview::TIMEOUT)], json_encode($ls)); // the ask review's time is kept free of local units (ADR-0073)
            check(trim($raw) === '', 'a local modify or an over-budget unit acted: ' . $raw);
            $log = hookFailOutcomes($tree);
            check(in_array('local/mine/mod=modify-ignored', $log, true) && in_array('local/mine/slow=not-started', $log, true), 'log: ' . json_encode($log));
            check(strpos(localHookTurn($tree), 'local/mine/slow was not started') !== false, 'the skipped unit was not named');
            list(, $raw) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['pre_tool_call', (string) (22 + AskReview::TIMEOUT)], json_encode($ls));
            check(strpos($raw, 'slow ran') !== false, 'a unit that fits was not started: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks: the Hermes entries count local units, pass the budget, and give a local-only event its entry' => function () {
        $tree = orchestratorTree(['g/guard' => ['pre_tool_call', true, 10, 'exit(0);']]);
        localHookUnit($tree, 'mine/check', 'pre_tool_call', false, 5, 'exit(0);');
        localHookUnit($tree, 'mine/verify', 'pre_verify', false, 4, 'exit(0);');
        try {
            $e = HermesConfig::hookEntries("$tree/pikkumies/hooks");
            check(array_keys($e) === ['pre_tool_call', 'pre_verify'], 'entries: ' . json_encode(array_keys($e)));
            $ptc = 17 + AskReview::TIMEOUT; // guard 10 s + local check 5 s + 2, plus the ask review's own time
            check($e['pre_tool_call']['timeout'] === $ptc && substr($e['pre_tool_call']['command'], -strlen(" pre_tool_call $ptc")) === " pre_tool_call $ptc", 'pre_tool_call: ' . json_encode($e['pre_tool_call']));
            check($e['pre_verify']['timeout'] === 6 && $e['pre_verify']['failClosed'] === false, 'pre_verify: ' . json_encode($e['pre_verify']));
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks: generate names new and changed local hooks once, in the one local-changes notice' => function () {
        $tree = orchestratorTree([]);
        $root = "$tree/pikkumies";
        $dir = localHookUnit($tree, 'mine/check', 'pre_tool_call', false, 5, 'exit(0);');
        $notice = "$root/local/" . LocalHooks::NOTICE;
        try {
            $first = LocalHooks::noteChanges($root);
            check($first === '1 local hook new or changed since the last start: hooks/mine/check; they run from this start', 'first start: ' . $first);
            check(trim((string) file_get_contents($notice)) === $first && (fileperms("$root/local/" . LocalHooks::RECORD) & 0777) === 0600, 'notice or record not written');
            $notices = HermesConfig::notices("$root/local/notices");
            check(count($notices) === 1 && $notices[0]['from'] === 'local-changes', 'SOUL.md would not show it: ' . json_encode($notices));
            check(LocalHooks::noteChanges($root) === '' && !file_exists($notice), 'an unchanged start still notes a change');
            file_put_contents("$dir/check.php", "<?php\nexit(0); // edited\n");
            check(strpos(LocalHooks::noteChanges($root), 'hooks/mine/check') !== false, 'an edited unit was not noted');
        } finally {
            testRmTree($tree);
        }
    },
];
