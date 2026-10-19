<?php
/**
 * Tool hooks (ADR-0054): hooks/tools/<tool>/<hook>/ runs only for calls of its tool (lib/HookPayload.php selects),
 * a tool hook's modify is ignored, a post_tool_call context waits as a hook note for the next turn, and two approves
 * become one question that keeps the delete guard's words.
 */
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
if (!function_exists('hookFailOutcomes')) {
    require __DIR__ . '/HookOrchestratorFailTest.php';
}
if (!function_exists('toolHooksTree')) {
    /** orchestratorTree($units) plus the tool unit tools/torrent-clients/rtorrent, so a hook may name it. */
    function toolHooksTree($units)
    {
        $tree = orchestratorTree($units);
        @mkdir("$tree/pikkumies/tools/torrent-clients/rtorrent", 0700, true);
        file_put_contents("$tree/pikkumies/tools/torrent-clients/rtorrent/skill.md", "---\nname: rtorrent\ndescription: x\n---\n");
        return $tree;
    }

    /** The orchestrator's answer for a terminal command (with optional extra payload fields). */
    function toolHooksAsk($tree, $command, array $extra = [], $tool = 'terminal')
    {
        $field = $tool === 'execute_code' ? 'code' : 'command';
        list($d) = orchestratorRun($tree, 'pre_tool_call', array_merge(['tool_name' => $tool, 'tool_input' => [$field => $command]], $extra));
        return $d;
    }
}

$sayTH = function (array $answer) {
    return 'echo ' . var_export(json_encode($answer), true) . ';';
};
return [
    'tool hooks: a Hermes-tool hook runs only on that tool_name' => function () use ($sayTH) {
        $tree = toolHooksTree(['tools/write_file/ask' => ['pre_tool_call', false, 5, $sayTH(['action' => 'block', 'message' => 'wf'])]]);
        try {
            check(toolHooksAsk($tree, 'ls') === null, 'a write_file hook ran on terminal');
            list($d) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'write_file', 'tool_input' => ['path' => '/tmp/x']]);
            check(is_array($d) && $d['message'] === 'wf', 'a write_file hook did not run on write_file');
        } finally {
            testRmTree($tree);
        }
    },
    'tool hooks: a Pikkumies-unit hook runs on every way of calling the unit, and on nothing else' => function () use ($sayTH) {
        $tree = toolHooksTree(['tools/rtorrent/stop' => ['pre_tool_call', false, 5, $sayTH(['action' => 'block', 'message' => 'rt'])]]);
        $unit = '/home/u/pikkumies/tools/torrent-clients/rtorrent';
        try {
            foreach (["php $unit/rtorrent.php list", 'php rtorrent.php list 2>&1', 'php rtorrent.php list | head -5',
                "cd $unit && php ./rtorrent.php list", 'php "rtorrent.php" list', "ls $unit", 'php $R remove x --with-data',
                'php "$R" list', 'php ${R} list'] as $cmd) {
                $d = toolHooksAsk($tree, $cmd);
                check(is_array($d) && $d['message'] === 'rt', "not selected: $cmd");
            }
            $d = toolHooksAsk($tree, 'import subprocess; subprocess.run(["php", "' . $unit . '/rtorrent.php", "list"])', [], 'execute_code');
            check(is_array($d) && $d['message'] === 'rt', 'not selected from execute_code');
            check(is_array(toolHooksAsk($tree, 'php x.php', ['cwd' => "$unit/"])), 'not selected by a cwd inside the unit');
            foreach (['ls -la', 'php deluge.php list', 'php myrtorrent.php list', 'php /x/tools/media/rtorrent2/a.php'] as $cmd) {
                check(toolHooksAsk($tree, $cmd, ['cwd' => '/home/u']) === null, "selected for another call: $cmd");
            }
        } finally {
            testRmTree($tree);
        }
    },
    'tool hooks: a tool hook\'s modify is ignored and logged; one in the wrong place, event or tool is a broken unit' => function () use ($sayTH) {
        $tree = toolHooksTree(['tools/terminal/mod' => ['pre_tool_call', false, 5, $sayTH(['action' => 'modify', 'args' => ['command' => 'rm x']])]]);
        try {
            check(toolHooksAsk($tree, 'ls') === null, 'a tool hook changed the call');
            check(in_array('tools/terminal/mod=modify-ignored', hookFailOutcomes($tree), true), 'modify-ignored not logged: ' . json_encode(hookFailOutcomes($tree)));
        } finally {
            testRmTree($tree);
        }
        $tree = toolHooksTree([
            'tools/rtorrent/fine' => ['post_tool_call', false, 5, 'exit(0);'], 'tools/terminal/fine2' => ['pre_tool_call', false, 5, 'exit(0);'],
            'tools/nosuch/x' => ['pre_tool_call', false, 5, 'exit(0);'], 'tools/terminal/turn' => ['pre_llm_call', false, 5, 'exit(0);'],
            'tools/rtorrent/sub/deep' => ['pre_tool_call', false, 5, 'exit(0);'],
        ]);
        try {
            $e = [];
            foreach (HookUnits::all("$tree/pikkumies/hooks") as $u) {
                $e[$u['category'] . '/' . $u['name']] = implode('; ', $u['errors']);
            }
            check($e['tools/rtorrent/fine'] === '' && $e['tools/terminal/fine2'] === '', 'good tool hooks refused: ' . json_encode($e));
            check(strpos($e['tools/nosuch/x'], 'neither a Pikkumies tool unit nor a Hermes tool') !== false, 'unknown tool accepted');
            check(strpos($e['tools/terminal/turn'], 'pre_tool_call or post_tool_call') !== false, 'pre_llm_call tool hook accepted');
            check(strpos($e['tools/rtorrent/sub/deep'], 'hooks/tools/<tool>/<hook>/') !== false, 'a tool hook one level too deep accepted');
            list($d) = orchestratorRun($tree, 'pre_tool_call', ['tool_name' => 'read_file', 'tool_input' => ['path' => 'x']]);
            check(is_array($d) && $d['action'] === 'block', 'a broken shipped tool hook must block every tool call (ADR-0030)');
        } finally {
            testRmTree($tree);
        }
    },
    'tool hooks: the Hermes tool list is Hermes v0.22.2\'s, and every name in it is also reserved for units' => function () {
        $tools = explode(' ', HookUnits::HERMES_TOOLS);
        $reserved = explode(' ', Units::RESERVED_NAMES);
        check(count($tools) === 45 && count(array_unique($tools)) === 45, 'v0.22.2 registers 45 literal tool names besides example_tool: ' . count($tools));
        foreach (['browser_vault', 'set_theme', 'setup_beat', 'terminal', 'write_file'] as $name) {
            check(in_array($name, $tools, true) && in_array($name, $reserved, true), "$name missing");
        }
        foreach (['apply_layout', 'browser_vault_enter_code', 'browser_vault_fill', 'browser_vault_list', 'browser_vault_save_login', 'browser_vault_unlock'] as $gone) {
            check(!in_array($gone, $tools, true) && !in_array($gone, $reserved, true), "$gone is no longer a Hermes tool in v0.22.2");
        }
        check(array_diff($tools, $reserved) === [], 'tool names not reserved: ' . implode(', ', array_diff($tools, $reserved)));
    },
    'tool hooks: a post_tool_call context waits for the next turn of that session, once; no session keeps nothing' => function () use ($sayTH) {
        $tree = toolHooksTree(['tools/terminal/note' => ['post_tool_call', false, 5, $sayTH(['context' => 'disk was full'])]]);
        try {
            list(, $raw) = orchestratorRun($tree, 'post_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'df'], 'session_id' => 's1']);
            check(trim($raw) === '', 'post_tool_call printed an answer: ' . $raw);
            $file = "$tree/pikkumies/local/state/hook-notes/" . sha1('s1') . '.jsonl';
            check(is_file($file) && (fileperms($file) & 0777) === 0600, 'note file missing or not 0600');
            orchestratorRun($tree, 'post_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'df'], 'session_id' => '']);
            list($d) = orchestratorRun($tree, 'pre_llm_call', ['session_id' => 's1']);
            check(is_array($d) && substr_count($d['context'], '- hook tools/terminal/note wrote: disk was full') === 1, 'note not drained into the turn: ' . json_encode($d));
            list(, $raw) = orchestratorRun($tree, 'pre_llm_call', ['session_id' => 's1']);
            check(strpos($raw, 'disk was full') === false, 'a note was given twice');
            list(, $raw) = orchestratorRun($tree, 'pre_llm_call', ['session_id' => 's2']);
            check(strpos($raw, 'disk was full') === false, 'a note reached another session');
        } finally {
            testRmTree($tree);
        }
    },
];
