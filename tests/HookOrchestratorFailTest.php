<?php
/**
 * hooks/orchestrator.php: the edges of reading a unit's answer, its log, and failing closed when a
 * unit's hook.md, a lib file or the orchestrator itself is broken (ADR-0030).
 */
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
if (!function_exists('hookFailAnswer')) {
    /** The last line of $raw that is a JSON object, decoded; null when there is none (crash output can precede it). */
    function hookFailAnswer($raw)
    {
        foreach (array_reverse(explode("\n", (string) $raw)) as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && $d) {
                return $d;
            }
        }
        return null;
    }

    /** Run a shell command line (a Hermes hook entry) with stdin; returns stdout. */
    function hookFailShell($cmd, $stdin)
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return $out;
    }

    /** The outcomes logged today, in run order. */
    function hookFailOutcomes($tree)
    {
        $out = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl'))) as $l) {
            $d = json_decode($l, true);
            $out[] = $d['unit'] . '=' . $d['outcome'];
        }
        return $out;
    }
}

$tool = ['hook_event_name' => 'pre_tool_call', 'tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']];
$turn = ['hook_event_name' => 'pre_llm_call'];
$echo = function ($json) {
    return 'echo ' . var_export($json, true) . ';';
};
return [
    'orchestrator: a Claude-Code decision block beats an action approve in one answer; an approve without a message has no message key' => function () use ($tool, $echo) {
        $tree = orchestratorTree(['fx/mixed' => ['pre_tool_call', false, 5, $echo('{"action":"approve","decision":"block","reason":"cc says no"}')]]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check($d === ['action' => 'block', 'message' => 'cc says no'], 'decision block lost to the approve: ' . $raw);
        } finally {
            testRmTree($tree);
        }
        $tree = orchestratorTree(['fx/ask' => ['pre_tool_call', false, 5, $echo('{"action":"approve","rule_key":"k9","message":"  "}')]]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check($d === null, 'a question of no kind with no yes to check: the call runs (ADR-0073): ' . $raw);
            $merged = HookAnswers::mergePreToolCall([HookAnswers::parse('pre_tool_call', '{"action":"approve","rule_key":"k9","message":"  "}')]);
            check($merged === ['action' => 'approve', 'rule_key' => 'k9'], 'approve without a message: ' . json_encode($merged));
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: pre_llm_call ignores a non-string context and a top-level array, and exit 2 there is no block' => function () use ($turn, $echo) {
        $tree = orchestratorTree([
            'c/a-number' => ['pre_llm_call', false, 5, $echo('{"context":42}')],
            'c/b-list' => ['pre_llm_call', false, 5, $echo('[{"context":"from a list"}]')],
            'c/c-two' => ['pre_llm_call', false, 5, $echo('{"context":"two"}') . ' fwrite(STDERR, "e"); exit(2);'],
            'c/d-ok' => ['pre_llm_call', false, 5, $echo('{"context":"ok"}')],
        ]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_llm_call', $turn);
            check($d === ['context' => "two\n\nok"], 'contexts: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: a failClosed unit printing an unknown JSON object or nothing at all is no opinion, not a block' => function () use ($tool, $echo) {
        $tree = orchestratorTree([
            'fc/a-allow' => ['pre_tool_call', true, 5, $echo('{"action":"allow"}')],
            'fc/b-silent' => ['pre_tool_call', true, 5, 'exit(0);'],
        ]);
        try {
            list(, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check(trim($raw) === '', 'silent failClosed units blocked: ' . $raw);
            check(hookFailOutcomes($tree) === ['fc/a-allow=none', 'fc/b-silent=none'], 'outcomes: ' . json_encode(hookFailOutcomes($tree)));
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: exit 2 takes the message of a JSON block body over stderr, and the default text when there is neither' => function () use ($tool, $echo) {
        $tree = orchestratorTree(['fx/body' => ['pre_tool_call', false, 5, $echo('{"action":"block","message":"from body"}') . ' fwrite(STDERR, "from stderr"); exit(2);']]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check($d === ['action' => 'block', 'message' => 'from body'], 'body message not used: ' . $raw);
        } finally {
            testRmTree($tree);
        }
        $tree = orchestratorTree(['fx/bare' => ['pre_tool_call', false, 5, 'echo "garbage"; exit(2);']]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check($d === ['action' => 'block', 'message' => 'Blocked by shell hook.'], 'default message not used: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: a fail-open unit exiting 7 still has its answer read; empty modifies give no answer' => function () use ($tool, $echo) {
        $tree = orchestratorTree(['fx/seven' => ['pre_tool_call', false, 5, $echo('{"action":"block","message":"stop"}') . ' exit(7);']]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check($d === ['action' => 'block', 'message' => 'stop'] && hookFailOutcomes($tree) === ['fx/seven=block'], 'exit 7 dropped the answer: ' . $raw);
        } finally {
            testRmTree($tree);
        }
        $tree = orchestratorTree(['m/a' => ['pre_tool_call', false, 5, $echo('{"action":"modify","args":{}}')], 'm/b' => ['pre_tool_call', false, 5, $echo('{"decision":"modify","tool_input":{}}')]]);
        try {
            list(, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check(trim($raw) === '', 'empty modifies made an answer: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator log: answer texts never reach it; six old files plus today keep all seven, seven old lose only the oldest' => function () use ($echo) {
        $payload = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'cat PAYLOAD-77']];
        $tree = orchestratorTree([
            'l/a-ask' => ['pre_tool_call', false, 5, $echo('{"action":"approve","message":"ASK-SECRET-1","rule_key":"KEY-SECRET-2"}')],
            'l/b-stop' => ['pre_tool_call', false, 5, $echo('{"action":"block","message":"BLOCK-SECRET-3"}')],
        ]);
        $logs = "$tree/pikkumies/local/logs/hooks";
        try {
            @mkdir($logs, 0700, true);
            for ($i = 1; $i <= 6; $i++) {
                touch(sprintf('%s/202001%02d.jsonl', $logs, $i));
            }
            orchestratorRun($tree, 'pre_tool_call', $payload);
            $log = (string) file_get_contents("$logs/" . gmdate('Ymd') . '.jsonl');
            foreach (['ASK-SECRET-1', 'KEY-SECRET-2', 'BLOCK-SECRET-3', 'PAYLOAD-77'] as $secret) {
                check(strpos($log, $secret) === false, "$secret leaked into the log");
            }
            check(count(glob("$logs/*.jsonl")) === 7 && is_file("$logs/20200101.jsonl"), 'six old files plus today must all stay');
            unlink("$logs/" . gmdate('Ymd') . '.jsonl');
            touch("$logs/20200107.jsonl");
            orchestratorRun($tree, 'pre_tool_call', $payload);
            $names = array_map('basename', glob("$logs/*.jsonl"));
            check(count($names) === 7 && !in_array('20200101.jsonl', $names, true) && in_array('20200102.jsonl', $names, true), 'seven old files: ' . json_encode($names));
            // Today's file already there: not a new day, nothing is pruned whatever lies around.
            touch("$logs/20190101.jsonl");
            orchestratorRun($tree, 'pre_tool_call', $payload);
            check(count(glob("$logs/*.jsonl")) === 8, 'pruned on a day that was not new');
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: a failClosed unit declared for pre_llm_call runs fail open there, and still blocks pre_tool_call as broken' => function () use ($tool, $turn, $echo) {
        $tree = orchestratorTree([
            'bad/ctx-closed' => ['pre_llm_call', true, 5, 'exit(3);'],
            'c/ok' => ['pre_llm_call', false, 5, $echo('{"context":"ok"}')],
        ]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_llm_call', $turn);
            check($d === ['context' => 'ok'], 'a misdeclared unit changed the context answer: ' . $raw);
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'bad/ctx-closed') !== false, 'a broken unit did not block pre_tool_call: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: a malformed or missing hook.md on the guard blocks every tool call instead of dropping it' => function () use ($tool) {
        $good = "---\nname: guard\nevent: pre_tool_call\ndescription: fixture\nfailClosed: true\ntimeout: 5\n---\nfixture\n";
        $cases = [
            'a byte order mark' => "\xEF\xBB\xBF" . $good,
            'a leading blank line' => "\n" . $good,
            'event pre-tool-call' => str_replace('event: pre_tool_call', 'event: pre-tool-call', $good),
            'failClosed yes' => str_replace('failClosed: true', 'failClosed: yes', $good),
            'failClosed True' => str_replace('failClosed: true', 'failClosed: True', $good),
            'no hook.md' => null,
        ];
        foreach ($cases as $what => $md) {
            $tree = orchestratorTree(['g/guard' => ['pre_tool_call', true, 5, 'echo json_encode(["action" => "block", "message" => "guarded"]);']]);
            try {
                $file = "$tree/pikkumies/hooks/g/guard/hook.md";
                $md === null ? unlink($file) : file_put_contents($file, $md);
                list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
                check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'g/guard failed closed') !== false, "$what dropped the guard: $raw");
                check(hookFailOutcomes($tree) === ['g/guard=failed-closed'], "$what not logged as failed-closed: " . json_encode(hookFailOutcomes($tree)));
            } finally {
                testRmTree($tree);
            }
        }
    },
    'orchestrator: a fatal error while finding the units blocks pre_tool_call and prints nothing for pre_llm_call' => function () use ($tool, $turn) {
        $tree = orchestratorTree(['g/guard' => ['pre_tool_call', true, 5, 'exit(0);']]);
        try {
            file_put_contents("$tree/pikkumies/lib/HookUnits.php", "<?php\nclass HookUnits\n{\n    public static function forEvent(\$event)\n    {\n        return hookFixtureNoSuchFunction();\n    }\n}\n");
            list(, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            $d = hookFailAnswer($raw);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'orchestrator failed') !== false, 'a discovery fatal did not block: ' . $raw);
            list(, $raw) = orchestratorRun($tree, 'pre_llm_call', $turn);
            check(hookFailAnswer($raw) === null, 'a discovery fatal on pre_llm_call answered: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: a missing or unparsable lib file blocks pre_tool_call; pre_llm_call stays silent' => function () use ($tool, $turn) {
        $tree = orchestratorTree(['g/guard' => ['pre_tool_call', true, 5, 'exit(0);']]);
        try {
            unlink("$tree/pikkumies/lib/HookUnits.php");
            list(, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            $d = hookFailAnswer($raw);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'orchestrator failed') !== false, 'a missing lib file let the call run: ' . $raw);
            list(, $raw) = orchestratorRun($tree, 'pre_llm_call', $turn);
            check(hookFailAnswer($raw) === null, 'a missing lib file answered pre_llm_call: ' . $raw);
        } finally {
            testRmTree($tree);
        }
        $tree = orchestratorTree(['g/guard' => ['pre_tool_call', true, 5, 'exit(0);']]);
        try {
            file_put_contents("$tree/pikkumies/lib/Config.php", "<?php\nclass Config {\n");
            list(, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            $d = hookFailAnswer($raw);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'orchestrator failed') !== false, 'a parse error in a lib file let the call run: ' . $raw);
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator: through its Hermes command a parse error in the orchestrator prints text, and a healthy run prints only the answer' => function () use ($tool) {
        $tree = orchestratorTree(['g/guard' => ['pre_tool_call', true, 5, 'echo json_encode(["action" => "block", "message" => "guarded"]);']]);
        try {
            $cmd = HermesConfig::hookEntries("$tree/pikkumies/hooks")['pre_tool_call']['command'];
            $out = hookFailShell($cmd, json_encode($tool));
            check(json_decode($out, true) === ['action' => 'block', 'message' => 'guarded'], 'healthy run through the Hermes command: ' . $out);
            file_put_contents("$tree/pikkumies/hooks/orchestrator.php", "\n}", FILE_APPEND);
            $out = hookFailShell($cmd, json_encode($tool));
            // Hermes fails closed on stdout that is not a JSON object; empty stdout would let the call run.
            check(trim($out) !== '' && !is_array(json_decode(trim($out), true)) && stripos($out, 'parse error') !== false, 'a broken orchestrator printed nothing: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
];
