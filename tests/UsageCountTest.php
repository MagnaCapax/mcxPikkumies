<?php
/**
 * hooks/trace/usage-count (ADR-0087): every tool call is counted into TelemetryLog's usage-tool,
 * usage-skill and usage-u-<tool> sources, 1 (succeeded) or 0 (failed), through privacy.perfLogging,
 * fails open, and never leaks a command, a path or a session id.
 */
if (!function_exists('ucTree')) {
    /** A throwaway pikkumies tree with lib, config, the hook, the orchestrator, and one fake tool unit. */
    function ucTree($perf = true)
    {
        $tmp = sys_get_temp_dir() . '/pk-usecnt-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'config', 'hooks/trace/usage-count'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/tools/system/telemetry", 0700, true);
        copy("$src/tools/system/telemetry/TelemetryLog.php", "$tmp/pikkumies/tools/system/telemetry/TelemetryLog.php");
        copy("$src/hooks/orchestrator.php", "$tmp/pikkumies/hooks/orchestrator.php");
        @mkdir("$tmp/pikkumies/tools/fakecat/faketool", 0700, true);
        file_put_contents("$tmp/pikkumies/tools/fakecat/faketool/faketool.php", "<?php\n");
        // A real verbs table (security review F-TEL/F1): only "verb-x" and "other-verb" count as verbs; a
        // bogus word recorded by the pre-fix code must become "other" even though this unit has real verbs.
        file_put_contents("$tmp/pikkumies/tools/fakecat/faketool/USAGE.md",
            "# faketool\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| verb-x | | no | x. |\n| other-verb | | no | y. |\n");
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export(['privacy' => ['perfLogging' => $perf]], true) . ";\n");
        return $tmp;
    }

    function ucPayload($tool, array $input, $result, $cwd = '/home/fixtureuser')
    {
        return json_encode(['hook_event_name' => 'post_tool_call', 'tool_name' => $tool, 'tool_input' => $input, 'session_id' => 'sess-usecnt-1',
            'cwd' => $cwd, 'extra' => ['result' => $result, 'tool_call_id' => 'call-9', 'duration_ms' => 42, 'status' => 'ok']]);
    }

    /** source => [record, ...] for every *.jsonl line written under local/state/telemetry/. */
    function ucLines($tree)
    {
        $out = [];
        foreach ((array) glob("$tree/pikkumies/local/state/telemetry/*/*.jsonl") as $f) {
            $source = basename(dirname($f));
            foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
                $out[$source][] = json_decode($line, true);
            }
        }
        return $out;
    }

    function ucRmTree($tree)
    {
        exec('rm -rf ' . escapeshellarg($tree));
    }

    /**
     * Runs the hook with its OWN process cwd and environment set explicitly (security review
     * F-TEL/F2 tests need the hook's process cwd to differ from the payload's claimed `cwd`, which
     * plain runPhp() cannot do: it never passes a cwd or env to proc_open). Returns [exit code, stdout+stderr].
     */
    function ucRunCwd($unit, $payloadJson, $cwd, array $env = [])
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($unit), $spec, $pipes, $cwd, $env + ['PATH' => (string) getenv('PATH')]);
        fwrite($pipes[0], $payloadJson);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out];
    }
}

return [
    'usage-count: a skill_view call counts usage-tool:skill_view and usage-skill:<name>, both 1' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            list($code, $out) = runPhp($unit, [], ucPayload('skill_view', ['name' => 'rtorrent'], ['ok' => true]));
            check($code === 0 && trim($out) === '', 'the unit must print nothing: ' . $out);
            $lines = ucLines($tree);
            check($lines['usage-tool'] === [['ts' => $lines['usage-tool'][0]['ts'], 'skill_view' => 1]], 'usage-tool: ' . json_encode($lines['usage-tool']));
            check($lines['usage-skill'] === [['ts' => $lines['usage-skill'][0]['ts'], 'rtorrent' => 1]], 'usage-skill: ' . json_encode($lines['usage-skill']));
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: a terminal run of a real unit path counts usage-u-<tool>:<verb>, 1 on exit 0 and 0 on exit 1' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        $cmd = "php $tree/pikkumies/tools/fakecat/faketool/faketool.php verb-x";
        try {
            runPhp($unit, [], ucPayload('terminal', ['command' => $cmd], ['output' => 'ok', 'exit_code' => 0]));
            runPhp($unit, [], ucPayload('terminal', ['command' => $cmd], ['output' => '', 'exit_code' => 1]));
            $lines = ucLines($tree);
            $unitLines = array_map(function ($r) { unset($r['ts']); return $r; }, $lines['usage-u-faketool']);
            check($unitLines === [['verb_x' => 1], ['verb_x' => 0]], 'unit lines (verb normalized, - -> _): ' . json_encode($lines['usage-u-faketool']));
            check(count($lines['usage-tool']) === 2 && $lines['usage-tool'][0]['terminal'] === 1 && $lines['usage-tool'][1]['terminal'] === 0,
                'usage-tool must also see both terminal calls: ' . json_encode($lines['usage-tool']));
            check(!isset($lines['usage-skill']), 'a terminal call must never write usage-skill');
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: security review F-TEL/F1 -- a word that is not in the unit\'s own USAGE.md verbs table is never recorded as-is, even when the unit has real verbs' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            foreach (['hunter2passw0rd', 'alice', 'not-a-verb'] as $bogus) {
                runPhp($unit, [], ucPayload('terminal', ['command' => "php $tree/pikkumies/tools/fakecat/faketool/faketool.php $bogus"], ['output' => '', 'exit_code' => 0]));
            }
            $lines = ucLines($tree);
            check(count($lines['usage-u-faketool']) === 3, 'three calls expected: ' . json_encode($lines['usage-u-faketool']));
            foreach ($lines['usage-u-faketool'] as $r) {
                check(isset($r['other']) && $r['other'] === 1 && !isset($r['hunter2passw0rd']) && !isset($r['alice']) && !isset($r['not_a_verb']),
                    'a non-verb word must never be recorded as itself: ' . json_encode($r));
            }
            // A real verb from the same unit's table must still be counted as itself, proving this is a real
            // membership check, not "every word becomes other".
            runPhp($unit, [], ucPayload('terminal', ['command' => "php $tree/pikkumies/tools/fakecat/faketool/faketool.php other-verb"], ['output' => '', 'exit_code' => 0]));
            $lines = ucLines($tree);
            check(end($lines['usage-u-faketool'])['other_verb'] === 1, 'a real verb must still be counted as itself: ' . json_encode(end($lines['usage-u-faketool'])));
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: security review F-TEL/F2 -- a relative unit path is resolved against the tool call\'s own cwd, never this hook\'s; with no cwd on the payload a relative path is not matched at all' => function () {
        $tree = ucTree();
        $lookalike = sys_get_temp_dir() . '/pk-usecnt-f2-' . getmypid() . '-' . mt_rand();
        @mkdir("$lookalike/tools/fakecat/faketool", 0700, true);
        file_put_contents("$lookalike/tools/fakecat/faketool/faketool.php", "<?php // look-alike\n");
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            // The hook's own process cwd is the install root (where "tools/..." would resolve to the REAL
            // unit); the payload's cwd is the look-alike directory. The look-alike must win: nothing counted.
            list($code, $out) = ucRunCwd($unit, ucPayload('terminal', ['command' => 'php tools/fakecat/faketool/faketool.php verb-x'],
                ['output' => '', 'exit_code' => 0], $lookalike), "$tree/pikkumies");
            check($code === 0, 'the hook must still run: ' . $out);
            check(!isset(ucLines($tree)['usage-u-faketool']), 'a relative path resolved against the real cwd must be the look-alike, not the real unit: ' . json_encode(ucLines($tree)));
            // The same relative command, with the payload's cwd now the install root: the real unit, counted.
            ucRunCwd($unit, ucPayload('terminal', ['command' => 'php tools/fakecat/faketool/faketool.php verb-x'],
                ['output' => '', 'exit_code' => 0], "$tree/pikkumies"), $lookalike);
            check(isset(ucLines($tree)['usage-u-faketool']), 'the same relative path, with the real cwd on the payload, must count: ' . json_encode(ucLines($tree)));
            ucRmTree($tree);
            $tree = ucTree();
            $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
            // No cwd on the payload at all: a relative path must never be guessed against the hook's own cwd.
            $pl = json_decode(ucPayload('terminal', ['command' => 'php tools/fakecat/faketool/faketool.php verb-x'], ['output' => '', 'exit_code' => 0]), true);
            unset($pl['cwd']);
            ucRunCwd($unit, json_encode($pl), "$tree/pikkumies");
            check(!isset(ucLines($tree)['usage-u-faketool']), 'no cwd on the payload: a relative path must not be matched: ' . json_encode(ucLines($tree)));
        } finally {
            ucRmTree($tree);
            exec('rm -rf ' . escapeshellarg($lookalike));
        }
    },
    'usage-count: security review F-TEL/F2 -- a leading ~/ in the unit path expands against this process\'s own HOME, the documented invocation form' => function () {
        $tree = ucTree();
        $home = sys_get_temp_dir() . '/pk-usecnt-home-' . getmypid() . '-' . mt_rand();
        mkdir($home, 0700, true);
        symlink("$tree/pikkumies", "$home/pikkumies");
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            list($code, $out) = ucRunCwd($unit, ucPayload('terminal', ['command' => 'php ~/pikkumies/tools/fakecat/faketool/faketool.php verb-x'],
                ['output' => '', 'exit_code' => 0]), $tree, ['HOME' => $home]);
            check($code === 0, 'the hook must still run: ' . $out);
            check(isset(ucLines($tree)['usage-u-faketool']), '~/pikkumies/... must expand against HOME and count as the real unit: ' . json_encode(ucLines($tree)));
        } finally {
            ucRmTree($tree);
            exec('rm -rf ' . escapeshellarg($home));
        }
    },
    'usage-count: security review F-TEL/F4d -- a command that only mentions a unit (echo, or after a non-command word) is never counted as running it' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        $real = "$tree/pikkumies/tools/fakecat/faketool/faketool.php";
        try {
            runPhp($unit, [], ucPayload('terminal', ['command' => "echo 'php $real verb-x'"], ['output' => '', 'exit_code' => 0]));
            check(!isset(ucLines($tree)['usage-u-faketool']), 'an echoed mention must never be counted: ' . json_encode(ucLines($tree)));
            // After && the php call is real again: this must count.
            runPhp($unit, [], ucPayload('terminal', ['command' => "echo hi && php $real verb-x"], ['output' => '', 'exit_code' => 0]));
            check(isset(ucLines($tree)['usage-u-faketool']), 'a real php call after && must still count: ' . json_encode(ucLines($tree)));
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: a terminal run of a non-unit command counts only usage-tool:terminal, never a usage-u-* source' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            runPhp($unit, [], ucPayload('terminal', ['command' => 'ls -la /tmp'], ['output' => 'x', 'exit_code' => 0]));
            $lines = ucLines($tree);
            check(count($lines) === 1 && isset($lines['usage-tool']) && $lines['usage-tool'] === [['ts' => $lines['usage-tool'][0]['ts'], 'terminal' => 1]],
                'only usage-tool must be written: ' . json_encode($lines));
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: a unit look-alike path outside this Pikkumies install is never counted as a unit' => function () {
        $tree = ucTree();
        $outside = sys_get_temp_dir() . '/pk-usecnt-outside-' . getmypid() . '-' . mt_rand();
        @mkdir("$outside/faketool", 0700, true);
        file_put_contents("$outside/faketool/faketool.php", "<?php\n");
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            runPhp($unit, [], ucPayload('terminal', ['command' => "php $outside/faketool/faketool.php verb-x"], ['output' => 'x', 'exit_code' => 0]));
            $lines = ucLines($tree);
            check(count($lines) === 1 && isset($lines['usage-tool']) && !isset($lines['usage-u-faketool']),
                'a look-alike path outside Units::toolsDir() must count only the generic terminal call: ' . json_encode($lines));
        } finally {
            ucRmTree($tree);
            exec('rm -rf ' . escapeshellarg($outside));
        }
    },
    'usage-count: an over-long or odd skill name goes to field "other", never truncated or passed through raw' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            $long = str_repeat('x', 40); // over FIELD_RE's 32-character cap
            runPhp($unit, [], ucPayload('skill_view', ['name' => $long], ['ok' => true]));
            runPhp($unit, [], ucPayload('skill_view', ['name' => 'weird.name:here'], ['ok' => true])); // "." and ":" never normalize
            $lines = ucLines($tree);
            check(count($lines['usage-skill']) === 2 && !isset($lines['usage-skill'][0][$long]) && !isset($lines['usage-skill'][1]['weird.name:here']),
                'the raw name must never appear as a field: ' . json_encode($lines['usage-skill']));
            foreach ($lines['usage-skill'] as $r) {
                check(isset($r['other']) && $r['other'] === 1, 'an unfitting name must become field "other": ' . json_encode($r));
            }
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: privacy.perfLogging off writes nothing at all' => function () {
        $tree = ucTree(false);
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            runPhp($unit, [], ucPayload('skill_view', ['name' => 'rtorrent'], ['ok' => true]));
            runPhp($unit, [], ucPayload('terminal', ['command' => "php $tree/pikkumies/tools/fakecat/faketool/faketool.php verb-x"], ['output' => 'x', 'exit_code' => 0]));
            check(!is_dir("$tree/pikkumies/local/state/telemetry"), 'perfLogging off must create nothing: ' . json_encode(ucLines($tree)));
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: a write that cannot land (its day-file path is taken by a directory) fails open -- the tool call is never blocked -- and counts a TelemetryLog failure' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        try {
            $jammed = "$tree/pikkumies/local/state/telemetry/usage-tool/" . gmdate('Ymd') . '.jsonl';
            mkdir($jammed, 0700, true); // the exact file path the hook would write to is itself a directory: the write cannot land, root or not
            list($code, $out) = runPhp($unit, [], ucPayload('skill_view', ['name' => 'rtorrent'], ['ok' => true]));
            check($code === 0 && trim($out) === '', 'a failed write must still exit 0 and print nothing: code ' . $code . ' out ' . $out);
            check(is_file("$tree/pikkumies/local/state/telemetry/usage-tool/.failures"), 'TelemetryLog::noteFailure must have run for the jammed usage-tool write');
            $skillFile = "$tree/pikkumies/local/state/telemetry/usage-skill/" . gmdate('Ymd') . '.jsonl';
            check(is_file($skillFile) && count(file($skillFile, FILE_IGNORE_NEW_LINES)) === 1,
                'usage-skill is a different source and must still have been written despite usage-tool failing');
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: the orchestrator runs it on post_tool_call, prints no answer, and nothing is counted for an event it does not run on' => function () {
        $tree = ucTree();
        try {
            list(, $out) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_tool_call'], ucPayload('skill_view', ['name' => 'rtorrent'], ['ok' => true]));
            check(trim($out) === '', 'post_tool_call must get no answer: ' . $out);
            $lines = ucLines($tree);
            check(isset($lines['usage-skill']) && count($lines['usage-skill']) === 1, 'the orchestrator must have run the unit: ' . json_encode($lines));
        } finally {
            ucRmTree($tree);
        }
    },
    'usage-count: no command text, path or session id ever appears in a written line' => function () {
        $tree = ucTree();
        $unit = "$tree/pikkumies/hooks/trace/usage-count/usage-count.php";
        $secretPath = "$tree/pikkumies/tools/fakecat/faketool/faketool.php";
        try {
            runPhp($unit, [], ucPayload('terminal', ['command' => "php $secretPath verb-x --token=SECRET123"], ['output' => 'x', 'exit_code' => 0]));
            runPhp($unit, [], ucPayload('skill_view', ['name' => 'rtorrent'], ['ok' => true]));
            foreach ((array) glob("$tree/pikkumies/local/state/telemetry/*/*.jsonl") as $f) {
                $text = (string) file_get_contents($f);
                foreach (['SECRET123', $secretPath, 'sess-usecnt-1', '--token'] as $leak) {
                    check(strpos($text, $leak) === false, "leaked into $f: $leak");
                }
            }
        } finally {
            ucRmTree($tree);
        }
    },
];
