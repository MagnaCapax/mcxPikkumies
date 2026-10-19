<?php
/**
 * ADR-0054 (review S3, N7): a local unit runs only as generate recorded it at the last start, when the user was told in
 * SOUL.md; a unit written or edited mid-session waits for the next start and is named meanwhile. A huge local hook.md is
 * a skipped unit, never a reason to block.
 */
if (!function_exists('localHookUnit')) {
    require __DIR__ . '/LocalHooksTest.php';
}

$lsS = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls'], 'session_id' => 's'];
$sayLS = function (array $answer) {
    return 'echo ' . var_export(json_encode($answer), true) . ';';
};
return [
    'local hooks start: a unit written mid-session does not run until the next start, and is named as waiting' => function () use ($lsS, $sayLS) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        localHookStart($tree); // the session starts with no local hooks
        $dir = localHookUnit($tree, 'mine/stop', 'pre_tool_call', false, 5, $sayLS(['action' => 'block', 'message' => 'local ran']));
        try {
            list(, $raw) = localHookRun($tree, 'pre_tool_call', $lsS);
            check(trim($raw) === '', 'a unit written after the start ran: ' . $raw);
            check(in_array('local/mine/stop', array_keys(LocalHooks::scan("$tree/pikkumies")['pending']), true), 'not pending');
            check(strpos(localHookTurn($tree), 'the local hook local/mine/stop is new or changed since the last start; it runs from the next start') !== false, 'the waiting unit was not named');
            check(strpos(localHookStart($tree), 'hooks/mine/stop') !== false, 'the next start did not tell the user');
            list($d) = localHookRun($tree, 'pre_tool_call', $lsS);
            check(is_array($d) && $d['message'] === 'local ran', 'a recorded unit did not run after the start');
            file_put_contents("$dir/stop.php", "<?php\necho json_encode(['action' => 'block', 'message' => 'edited ran']);\n");
            list(, $raw) = localHookRun($tree, 'pre_tool_call', $lsS);
            check(trim($raw) === '', 'a unit edited mid-session ran in its new form: ' . $raw);
            localHookStart($tree);
            list($d) = localHookRun($tree, 'pre_tool_call', $lsS);
            check(is_array($d) && $d['message'] === 'edited ran', 'the edited unit did not run after the next start');
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks start: the record is read only as this account\'s plain file; a symlinked record runs nothing' => function () use ($lsS, $sayLS) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        localHookUnit($tree, 'mine/stop', 'pre_tool_call', false, 5, $sayLS(['action' => 'block', 'message' => 'local ran']));
        localHookStart($tree);
        $record = "$tree/pikkumies/local/" . LocalHooks::RECORD;
        rename($record, "$tree/record.json");
        symlink("$tree/record.json", $record);
        try {
            list(, $raw) = localHookRun($tree, 'pre_tool_call', $lsS);
            check(trim($raw) === '', 'a symlinked record let a unit run: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'local hooks start: a hook.md over 64 KiB is a skipped local unit (read with a cap), never a block' => function () use ($lsS) {
        $tree = orchestratorTree(['s/ok' => ['pre_tool_call', false, 5, 'exit(0);']]);
        $dir = localHookUnit($tree, 'mine/huge', 'pre_tool_call', false, 5, 'exit(0);');
        file_put_contents("$dir/hook.md", str_repeat('x', HookUnits::MD_MAX + 1), FILE_APPEND);
        localHookStart($tree);
        try {
            list(, $raw) = localHookRun($tree, 'pre_tool_call', $lsS);
            check(trim($raw) === '', 'a huge local hook.md blocked: ' . $raw);
            check(strpos(localHookTurn($tree), 'local/mine/huge: hook.md is larger than 65536 bytes') !== false, 'the huge unit was not named');
        } finally {
            testRmTree($tree);
        }
    },
];
