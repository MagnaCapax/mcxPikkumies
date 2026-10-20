<?php
/**
 * ADR-0103: inside an agent `pikkumies <tool> ...` runs the tool (lib/Launch.php toolScript, bin/pikkumies `tool`), and the
 * hook orchestrator shows every guard that call in its php form (HookPayload::normalize), with the same resolver.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/HookPayload.php';
require_once dirname(__DIR__) . '/pikkumies/lib/SendGuard.php';
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}

if (!function_exists('toolByNameTerminal')) {
    function toolByNameTerminal($command)
    {
        return ['tool_name' => 'terminal', 'session_id' => 's1', 'tool_input' => ['command' => $command]];
    }

    function toolByNameNorm($command, array $scripts)
    {
        $out = HookPayload::normalize(toolByNameTerminal($command), $scripts);
        return $out === null ? null : $out['tool_input']['command'];
    }

    function toolByNameDepth($value, callable $body)
    {
        $was = getenv('PIKKUMIES_DEPTH');
        try {
            $value === null ? putenv('PIKKUMIES_DEPTH') : putenv("PIKKUMIES_DEPTH=$value");
            return $body();
        } finally {
            $was === false ? putenv('PIKKUMIES_DEPTH') : putenv("PIKKUMIES_DEPTH=$was");
        }
    }
}

return [
    'tool by name: inside an agent a tool unit name runs that tool with the rest of the words' => function () {
        toolByNameDepth('1', function () {
            $real = realpath(dirname(__DIR__) . '/pikkumies/tools/system/system-status/system-status.php');
            $r = Launch::route(['pikkumies', 'system-status', 'health', '--human']);
            check($r === ['tool', [$real, 'health', '--human']], 'tool name at depth 1 did not run the tool: ' . json_encode($r));
            check(Launch::toolScript('system-status') === $real, 'the resolver and the route disagree');
            check(Launch::route(['pikkumies', '--', 'system-status', 'health']) === ['run', ['chat', '--query=system-status health']], '`--` no longer forces a prompt');
            check(Launch::route(['pikkumies', 'why', 'is', 'my', 'disk', 'full']) === ['run', ['chat', '--query=why is my disk full']], 'plain words at depth 1 stopped being a prompt');
            check(Launch::toolScript('../system-status') === '' && Launch::toolScript('') === '', 'a word that names no unit resolved');
        });
        toolByNameDepth(null, function () {
            check(Launch::route(['pikkumies', 'system-status', 'health']) === ['run', ['chat', '--query=system-status health']], 'the user\'s own shell (depth 0) changed');
            check(Launch::toolScript('system-status') === '', 'the resolver answered outside an agent');
        });
    },
    'tool by name: the launcher passes the tool\'s output and exit code through' => function () {
        $out = [];
        $code = 0;
        $bin = escapeshellarg(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        exec('PIKKUMIES_DEPTH=1 ' . escapeshellarg(PHP_BINARY) . " $bin system-status no-such-verb 2>&1", $out, $code);
        $text = implode("\n", $out);
        check($code !== 0 && strpos($text, 'starts a new agent') === false && strpos($text, 'Not starting') === false,
            'the launcher did not run the tool (exit ' . $code . '): ' . substr($text, 0, 300));
        check(json_decode((string) end($out), true) !== null, 'the tool\'s JSON Lines did not come through: ' . substr($text, 0, 300));
    },
    'tool by name: the guards see every launcher form as the php form' => function () {
        $s = ['feedback' => '/p/feedback.php', 'system-status' => '/p/system-status.php', 'feed' => '/p/feed.php'];
        $cases = [
            'pikkumies feedback send ab12' => 'php /p/feedback.php send ab12',
            '~/pikkumies/bin/pikkumies feedback send ab12' => 'php /p/feedback.php send ab12',
            '/home/fixtureuser/.mcxPikkumies/pikkumies/bin/pikkumies system-status health' => 'php /p/system-status.php health',
            'cd /tmp && pikkumies system-status health | head' => 'cd /tmp && php /p/system-status.php health | head',
            'FOO=1 command pikkumies feedback list' => 'FOO=1 command php /p/feedback.php list',
            "bash -c 'pikkumies feedback send ab12'" => "bash -c 'php /p/feedback.php send ab12'",
            'x=$(pikkumies feed list)' => 'x=$(php /p/feed.php list)',
            '"pikkumies" feedback send ab12' => 'php /p/feedback.php send ab12',
            'pikk\\umies feedback send ab12' => 'php /p/feedback.php send ab12',
            "pikkumies 'feedback' send ab12" => 'php /p/feedback.php send ab12',
            "pikkumies \\\nfeedback send ab12" => 'php /p/feedback.php send ab12',
            "pikkumies\tfeedback send ab12" => 'php /p/feedback.php send ab12',
            'pikkumies feedbackx send' => 'pikkumies feedbackx send',
            'pikkumies why is my disk full' => 'pikkumies why is my disk full',
            'xpikkumies feedback send' => 'xpikkumies feedback send',
            'php ~/.mcxPikkumies/pikkumies/tools/system/feedback/feedback.php list' => 'php ~/.mcxPikkumies/pikkumies/tools/system/feedback/feedback.php list',
        ];
        foreach ($cases as $in => $want) {
            $got = toolByNameNorm($in, $s);
            check($got === $want, "normalize($in) = " . json_encode($got) . ", want $want");
        }
        $code = HookPayload::normalize(['tool_name' => 'execute_code', 'tool_input' => ['code' => 'subprocess.run("pikkumies feedback send ab12", shell=True)']], $s);
        check($code['tool_input']['code'] === 'subprocess.run("php /p/feedback.php send ab12", shell=True)', 'execute_code was not rewritten');
        $file = ['tool_name' => 'read_file', 'tool_input' => ['path' => 'pikkumies feedback']];
        check(HookPayload::normalize($file, $s) === $file, 'a file tool call was changed');
    },
    'tool by name: a rewrite that fails is reported, never passed on unrewritten' => function () {
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '1');
            $got = HookPayload::normalize(toolByNameTerminal(str_repeat('a ', 200) . 'pikkumies feedback send ab12'), ['feedback' => '/p/feedback.php']);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
        check($got === null, 'a regex error passed the raw command on: ' . json_encode($got));
    },
    'tool by name: the send guard gives the launcher form the same answer as the php form' => function () {
        toolByNameDepth('1', function () {
            $scripts = Launch::toolScripts();
            check(isset($scripts['feedback']), 'the feedback tool did not resolve');
            $own = $scripts['feedback'];
            $id = str_repeat('a1', 16);
            $ask = function () {
                return 'Send report a1 to Pulsed Media?';
            };
            foreach (["php $own send $id" => "pikkumies feedback send $id", "php $own send $id; ls" => "pikkumies feedback send $id; ls",
                "php $own list" => 'pikkumies feedback list'] as $php => $launcher) {
                $norm = HookPayload::normalize(toolByNameTerminal($launcher), $scripts);
                $a = SendGuard::decide(json_encode(toolByNameTerminal($php)), 'feedback', 'tools/system/feedback/feedback.php', $ask);
                $b = SendGuard::decide(json_encode($norm), 'feedback', 'tools/system/feedback/feedback.php', $ask);
                check($a === $b, "different answers for $launcher: " . json_encode([$a, $b]));
            }
            $raw = SendGuard::decide(json_encode(toolByNameTerminal("php $own send $id")), 'feedback', 'tools/system/feedback/feedback.php', $ask);
            check(is_array($raw) && $raw['action'] !== 'allow', 'the php send form itself is no longer stopped: ' . json_encode($raw));
        });
    },
    'tool by name: through the orchestrator every unit, and the real send guard, sees the launcher form as the php form' => function () {
        $seen = '$p = json_decode(stream_get_contents(STDIN), true); echo json_encode(["action" => "block", "message" => "seen:" . $p["tool_input"]["command"]]);';
        $tree = orchestratorTree(['guard/seen' => ['pre_tool_call', true, 5, $seen]]);
        $full = orchestratorTree();
        try {
            foreach ([$tree, $full] as $t) {
                orchestratorCopy(dirname(__DIR__) . '/pikkumies/tools/system/feedback', "$t/pikkumies/tools/system/feedback");
            }
            $script = realpath("$tree/pikkumies/tools/system/feedback/feedback.php");
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', toolByNameTerminal('pikkumies feedback list'));
            check(is_array($d) && $d['message'] === "seen:php $script list", 'the unit did not see the php form: ' . $raw);
            $own = realpath("$full/pikkumies/tools/system/feedback/feedback.php");
            $id = str_repeat('b2', 16);
            list($viaName, $rawName) = orchestratorRun($full, 'pre_tool_call', toolByNameTerminal("pikkumies feedback send $id"));
            list($viaPhp) = orchestratorRun($full, 'pre_tool_call', toolByNameTerminal("php $own send $id"));
            check(is_array($viaName) && $viaName['action'] === 'block', 'the launcher-form send ran with no answer from the guards: ' . $rawName);
            check($viaName === $viaPhp, 'the guards answered the two forms differently: ' . json_encode([$viaName, $viaPhp]));
        } finally {
            testRmTree($tree);
            testRmTree($full);
        }
    },
    'tool by name: the search gate lets the paired first reading through in its launcher form, and the search opens it' => function () {
        $full = orchestratorTree();
        try {
            foreach (['system/system-status', 'memory/pikkumies'] as $u) {
                orchestratorCopy(dirname(__DIR__) . "/pikkumies/tools/$u", "$full/pikkumies/tools/$u");
            }
            $call = ['tool_name' => 'terminal', 'session_id' => 'sess-sf', 'tool_input' => ['command' => 'pikkumies system-status show']];
            list($d, $raw) = orchestratorRun($full, 'pre_tool_call', $call);
            check(!is_array($d) || strpos((string) $d['message'], 'search first') === false, 'the search gate held the paired reading: ' . $raw);
            $act = ['tool_name' => 'terminal', 'session_id' => 'sess-sf', 'tool_input' => ['command' => 'pikkumies system-status restart-everything']];
            list($d, $raw) = orchestratorRun($full, 'pre_tool_call', $act);
            check(is_array($d) && strpos((string) $d['message'], 'search first') !== false, 'an acting call in the launcher form got past the search gate: ' . $raw);
        } finally {
            testRmTree($full);
        }
    },
];
