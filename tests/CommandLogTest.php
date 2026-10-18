<?php
/** The command-log hook unit (ADR-0044): every terminal command lands in local/logs/commands/YYYYMMDD.jsonl. */
if (!function_exists('cmdLogTree')) {
    /** A throwaway pikkumies tree with the lib, the orchestrator and the command-log unit only. */
    function cmdLogTree()
    {
        $tmp = sys_get_temp_dir() . '/pk-cmdlog-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'config', 'hooks/trace/command-log'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        copy("$src/hooks/orchestrator.php", "$tmp/pikkumies/hooks/orchestrator.php");
        @mkdir("$tmp/pikkumies/local", 0700, true);
        return $tmp;
    }

    function cmdLogPayload($tool, array $input, $result)
    {
        return json_encode(['hook_event_name' => 'post_tool_call', 'tool_name' => $tool, 'tool_input' => $input, 'session_id' => 'sess-1',
            'cwd' => '/home/u', 'extra' => ['result' => $result, 'tool_call_id' => 'call-9', 'duration_ms' => 42, 'status' => 'ok']]);
    }

    function cmdLogLines($tree)
    {
        $f = "$tree/pikkumies/local/logs/commands/" . gmdate('Ymd') . '.jsonl';
        return is_file($f) ? array_map(function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES)) : [];
    }
}

return [
    'command log: a terminal command is logged with its exit code, secrets removed, never the output; files are private' => function () {
        $tree = cmdLogTree();
        $unit = "$tree/pikkumies/hooks/trace/command-log/command-log.php";
        $cmd = "curl -H 'Authorization: Bearer abc123' https://u:hunter2@example.org/x --password s3cr3t && API_TOKEN=tok999 mysql -uroot -pdbpass db";
        list($code, $out) = runPhp($unit, [], cmdLogPayload('terminal', ['command' => $cmd], json_encode(['output' => "secret output line\n", 'exit_code' => 3, 'error' => null])));
        $lines = cmdLogLines($tree);
        check($code === 0 && trim($out) === '', 'the unit must print nothing: ' . $out);
        check(count($lines) === 1, 'one line per command, got ' . count($lines));
        $l = $lines[0];
        check($l['exit_code'] === 3 && $l['session_id'] === 'sess-1' && $l['tool_call_id'] === 'call-9' && $l['duration_ms'] === 42 && $l['output_bytes'] === 19, 'fields: ' . json_encode($l));
        foreach (['abc123', 'hunter2', 's3cr3t', 'tok999', 'dbpass', 'secret output'] as $leak) {
            check(strpos(json_encode($l), $leak) === false, "leaked into the log: $leak");
        }
        check(strpos($l['command'], 'mysql -uroot -pREDACTED db') !== false && strpos($l['command'], 'curl -H') === 0, 'command kept apart from its secrets: ' . $l['command']);
        check((fileperms("$tree/pikkumies/local/logs/commands") & 0777) === 0700, 'directory not 0700');
        check((fileperms("$tree/pikkumies/local/logs/commands/" . gmdate('Ymd') . '.jsonl') & 0777) === 0600, 'file not 0600');
        runPhp($unit, [], cmdLogPayload('read_file', ['path' => '/etc/hosts', 'command' => 'cat /etc/hosts'], '{}'));
        runPhp($unit, [], cmdLogPayload('terminal', ['command' => 'ls'], 'not json'));
        $lines = cmdLogLines($tree);
        check(count($lines) === 2 && $lines[1]['command'] === 'ls' && $lines[1]['exit_code'] === null, 'a non-shell tool was logged, or a plain-text result broke the line');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'command log: the orchestrator runs it on post_tool_call, prints no answer, and the unit is valid' => function () {
        $tree = cmdLogTree();
        list(, $out) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_tool_call'], cmdLogPayload('terminal', ['command' => 'df -h'], json_encode(['output' => 'x', 'exit_code' => 0])));
        check(trim($out) === '', 'post_tool_call must get no answer: ' . $out);
        $lines = cmdLogLines($tree);
        check(count($lines) === 1 && $lines[0]['command'] === 'df -h' && $lines[0]['exit_code'] === 0, 'orchestrator did not run the unit');
        $units = HookUnits::all(dirname(__DIR__) . '/pikkumies/hooks');
        $mine = array_values(array_filter($units, function ($u) { return $u['name'] === 'command-log'; }));
        check(count($mine) === 1 && $mine[0]['errors'] === [] && $mine[0]['event'] === 'post_tool_call', 'command-log unit invalid: ' . json_encode($mine ? $mine[0]['errors'] : 'missing'));
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'command log: a new day prunes files older than sessions.retentionDays; 0 keeps them forever' => function () {
        $tree = cmdLogTree();
        $dir = "$tree/pikkumies/local/logs/commands";
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/20200101.jsonl", "{}\n");
        $unit = "$tree/pikkumies/hooks/trace/command-log/command-log.php";
        runPhp($unit, [], cmdLogPayload('terminal', ['command' => 'true'], '{"exit_code":0}'));
        check(is_file("$dir/20200101.jsonl"), 'retention 0 must keep old files');
        unlink("$dir/" . gmdate('Ymd') . '.jsonl');
        file_put_contents("$tree/pikkumies/local/config.php", "<?php\nreturn ['sessions' => ['retentionDays' => 30]];\n");
        runPhp($unit, [], cmdLogPayload('terminal', ['command' => 'true'], '{"exit_code":0}'));
        check(!is_file("$dir/20200101.jsonl") && is_file("$dir/" . gmdate('Ymd') . '.jsonl'), 'a file older than 30 days was kept');
        exec('rm -rf ' . escapeshellarg($tree));
    },
];
