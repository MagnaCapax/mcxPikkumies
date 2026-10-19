<?php
/**
 * hooks/orchestrator.php (ADR-0030): a unit's own genuine PHP fatal error — not a deliberate exit()
 * or echoed garbage, both already covered by tests/HookOrchestratorFailTest.php — must still fail
 * closed with a reason, and its outcome in local/logs/hooks/*.jsonl must be distinguishable from a
 * quiet "no opinion" exit, even though the crash leaves stdout empty (D165). That file is owned by
 * another builder's batch, so this fatal-error edge lives in its own file; it reuses
 * tests/OrchestratorTest.php's fixture helpers the same way HookOrchestratorFailTest.php does.
 */
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}

/** The outcomes this test's own tree logged today, in run order, as "unit=outcome". */
function hookCrashOutcomes($tree)
{
    $out = [];
    foreach (array_filter(explode("\n", (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl'))) as $l) {
        $d = json_decode($l, true);
        $out[] = $d['unit'] . '=' . $d['outcome'];
    }
    return $out;
}

$tool = ['hook_event_name' => 'pre_tool_call', 'tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']];

return [
    'orchestrator crash: a failClosed unit\'s own genuine PHP fatal error blocks with a reason, logged distinctly from a silent exit' => function () use ($tool) {
        // No echo, no exit(): a real "call to undefined function" fatal, the way a broken unit script
        // (not the orchestrator's own code, already covered by HookOrchestratorFailTest.php's
        // discovery-fatal cases) would actually crash in production.
        $tree = orchestratorTree(['fx/crash' => ['pre_tool_call', true, 5, 'pkTestUndefinedFunctionForCrashFixture();']]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'fx/crash failed closed') !== false,
                'a genuine fatal error in a failClosed unit must block with its own reason, not run the call: ' . $raw);
            $outcomes = hookCrashOutcomes($tree);
            check(count($outcomes) === 1, 'expected exactly one logged outcome: ' . json_encode($outcomes));
            check($outcomes[0] !== 'fx/crash=none', 'a crash must never log the same outcome as a quiet, intentional no-opinion exit: ' . json_encode($outcomes));
            check(strpos($outcomes[0], 'fx/crash=') === 0, 'outcome must still name the crashed unit: ' . json_encode($outcomes));
        } finally {
            testRmTree($tree);
        }
    },
    'orchestrator crash: the SAME fatal error in a fail-open unit never blocks, and is still logged (not silently dropped)' => function () use ($tool) {
        $tree = orchestratorTree(['fx/crash-open' => ['pre_tool_call', false, 5, 'pkTestUndefinedFunctionForCrashFixture();']]);
        try {
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $tool);
            check($d === null, 'a fail-open unit\'s own crash must give no opinion, never block: ' . $raw);
            $outcomes = hookCrashOutcomes($tree);
            check(count($outcomes) === 1 && strpos($outcomes[0], 'fx/crash-open=') === 0, 'a fail-open crash must still be logged, not dropped: ' . json_encode($outcomes));
        } finally {
            testRmTree($tree);
        }
    },
];
