<?php
/**
 * ADR-0073 amendment 2026-10-19 (approvals off): Hermes' approval prompt never shows. approvals.mode is pinned "off",
 * which also approves every hook "approve" answer silently (Hermes v0.22.2 tools/approval.py _run_approval_gate), so a
 * question that survives the ask policy becomes a block with one fixed refusal that reports and never asks (ADR-0101); a re-run passes when the
 * review finds the user's yes. Without evidence or a review answer only deletes, exposures, sends and mail stay blocked.
 * What a real light model answers is not tested here.
 */
if (!function_exists('askApply')) {
    require __DIR__ . '/AskPolicyTest.php';
}
if (!function_exists('askOrchTree')) {
    require __DIR__ . '/AskPolicyOrchestratorTest.php';
}
if (!function_exists('fileopsTree')) {
    require __DIR__ . '/FileopsCheckTest.php';
}

if (!function_exists('cfgHookTree')) {
    require __DIR__ . '/ConfigChangeHookTest.php';
}

if (!function_exists('offConst')) {
    /** AskPolicy's one refusal (ADR-0101): $unchecked picks the reason; '' on a tree without it (so the test fails, not the runner). */
    function offConst($unchecked)
    {
        return method_exists('AskPolicy', 'refused') ? AskPolicy::refused($unchecked) : '';
    }

    /** True when a refusal text asks nothing and hands nothing over (ADR-0101). */
    function offReportsOnly($msg)
    {
        return $msg !== '' && stripos($msg, 'not run') !== false && preg_match('/\byes\b|\bask\b|run it themselves|so they can|tell the user the command/i', $msg) === 0
            && strpos($msg, 'list this step as skipped') !== false && strpos($msg, 'do not give the command to the user') !== false;
    }
}

$yes = [['role' => 'user', 'text' => 'clean up my home'], ['role' => 'agent', 'text' => 'This deletes ~/old for good, and there is no copy. Go ahead?'], ['role' => 'user', 'text' => 'yes']];
return [
    'approvals off: both config layers pin approvals.mode "off" and no smart guardian is configured' => function () {
        $prov = ['endpoint' => 'e', 'model' => 'm', 'key' => 'k'];
        foreach ([true, false] as $pinsOnly) {
            $y = HermesConfig::renderYaml($prov, ['/a'], [], false, 'light', $pinsOnly);
            check(strpos($y, "approvals:\n  mode: \"off\"\n") !== false, 'approvals.mode is not pinned off (pinsOnly ' . var_export($pinsOnly, true) . '): ' . $y);
            check(strpos($y, 'smart') === false && strpos($y, "  approval:\n") === false, 'no smart mode and no auxiliary.approval: ' . $y);
        }
        check(!isset(HermesInstall::CONFIG_FACTS['auxiliary.approval']), 'auxiliary.approval is no longer written, so it is no fact');
        check(HermesInstall::CONFIG_FACTS['approvals.mode'][1] === '_VALID_MODES = ("manual", "smart", "off")', 'the fact must name "off": a Hermes without it is refused');
    },
    'approvals off: a question the review keeps becomes a block with the fixed message, never an approve' => function () {
        $msg = offConst(false);
        check(offReportsOnly($msg) && strpos($msg, AskPolicy::WHY_UNCOVERED) !== false, 'the refusal must report, never ask or hand the command over: ' . $msg);
        foreach (['delete', 'schedule', null] as $kind) {
            list($out, $counts) = askApply([askApprove($kind), askApprove('load')], ['R1' => 'no', 'R2' => 'yes', 'R3' => 'no']);
            check($out === [['action' => 'block', 'message' => $msg]] && $counts['blocked'] === 1, var_export($kind, true) . ': ' . json_encode([$out, $counts]));
        }
        list($out) = askApply([askApprove('delete'), askApprove('mail')], new Exception('boom'));
        foreach ($out as $d) {
            check($d['action'] === 'block', 'an error in the policy must block, never pass an approve on: ' . json_encode($out));
        }
    },
    'approvals off: through the orchestrator a kept delete is a block with the fixed message' => function () {
        $dir = testTempDir('pk-askoff');
        list($proc, $port) = hookSearchFakeProvider($dir);
        $del = ['action' => 'approve', 'message' => 'Pikkumies: deletes or empties files, which cannot be undone. Command [1]: rm -r ~/old', 'rule_key' => 'pikkumies-delete:1', 'kind' => 'delete'];
        $tree = askOrchTree(['guard/del' => $del], $port);
        $call = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -r ~/old'], 'session_id' => 'sess-off'];
        try {
            askOrchSnapshot($tree, 'sess-off', [['role' => 'user', 'text' => 'what uses my space?']]);
            file_put_contents("$dir/reply", "R1: no\nR2: yes\nR3: no");
            list($d, $raw) = orchestratorRun($tree, 'pre_tool_call', $call);
            check($d === ['action' => 'block', 'message' => offConst(false)], 'expected the fixed confirm block: ' . $raw);
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
            testRmTree($dir);
        }
    },
    'approvals off: a re-run whose evidence holds the user\'s yes passes; the review is told a yes to a named action counts' => function () use ($yes) {
        list($out, $counts, $calls) = askApply([askApprove('delete')], ['R1' => 'yes', 'R2' => 'yes', 'R3' => 'no'], $yes);
        check($out === [] && $counts['cleared'] === 1 && $calls === 1, 'cleared: ' . json_encode([$out, $counts, $calls]));
        $p = AskReview::prompt('terminal', ['Pikkumies: deletes ~/old'], $yes);
        check(strpos($p[1]['content'], 'A plain yes counts') !== false, 'R1 must say that a yes to the agent\'s named action counts: ' . $p[1]['content']);
    },
    'approvals off: with no evidence or no review answer, only deletes, exposures, sends and mail stay blocked' => function () use ($yes) {
        $unchecked = offConst(true);
        check(offReportsOnly($unchecked) && strpos($unchecked, AskPolicy::WHY_UNCHECKED) !== false && $unchecked !== offConst(false), 'the unchecked refusal: ' . $unchecked);
        foreach ([[[], 8, $yes], [$yes, 1, ['R1' => 'yes']], [$yes, 8, []]] as $case) { // no evidence; no time; an empty review
            list($evidence, $seconds, $answers) = $case;
            foreach (['schedule', 'local', 'brand-new', null] as $kind) {
                list($out, $counts) = askApply([askApprove($kind)], $answers, $evidence, $seconds);
                check($out === [] && $counts['ran'] === 1, var_export($kind, true) . ' must run: ' . json_encode([$out, $counts]));
            }
            foreach (['delete', 'exposure', 'send', 'mail'] as $kind) {
                list($out) = askApply([askApprove($kind)], $answers, $evidence, $seconds);
                check($out === [['action' => 'block', 'message' => $unchecked]], "$kind must stay blocked: " . json_encode($out));
            }
            list($out) = askApply([askApprove(null, ['local' => true])], $answers, $evidence, $seconds);
            check($out === [['action' => 'block', 'message' => $unchecked]], 'a local unit\'s question cannot be cleared without the user\'s yes: ' . json_encode($out));
        }
    },
    'approvals off: the feedback send and a local unit\'s question block until the review finds the user\'s explicit yes' => function () use ($yes) {
        $send = askApprove(null, ['unit' => 'guard/feedback-send', 'message' => 'Pikkumies: send this feedback?']);
        $local = askApprove(null, ['local' => true, 'unit' => 'my/hook']);
        foreach ([$send, $local] as $q) {
            list($out, , $calls) = askApply([$q], ['R1' => 'unsure', 'R2' => 'no', 'R3' => 'no'], [['role' => 'user', 'text' => 'thanks']]);
            check($out === [['action' => 'block', 'message' => offConst(false)]] && $calls === 1, 'an R2 "no" alone must not clear it: ' . json_encode($out));
            list($out) = askApply([$q], ['R1' => 'yes', 'R2' => 'no', 'R3' => 'no'], $yes);
            check($out === [], 'the user\'s yes must clear it: ' . json_encode($out));
        }
        list($out) = askApply([$send], ['R1' => 'yes', 'R2' => 'no', 'R3' => 'no'], []);
        check($out === [['action' => 'block', 'message' => offConst(true)]], 'no evidence: the send stays blocked: ' . json_encode($out));
    },
    'approvals off: an mv that replaces a file is a delete, so it stays blocked where no yes can be checked' => function () {
        $tree = fileopsTree();
        try {
            $home = "$tree/home";
            $d = fileopsAnswer($tree, fileopsPayload("mv $home/data/a $home/data/b"));
            check(is_array($d) && $d['action'] === 'approve' && isset($d['kind']) && $d['kind'] === 'delete', 'mv over a file must carry kind delete: ' . json_encode($d));
            $d = fileopsAnswer($tree, fileopsPayload("chmod -R 700 $home"));
            check(is_array($d) && $d['action'] === 'approve' && !isset($d['kind']), 'a permission change is not a delete: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
    'approvals off: a shorter session retention deletes history, so it is a delete; another asked setting is not' => function () {
        $tree = cfgHookTree(['root' => ['disabled' => true]]);
        try {
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set sessions.retentionDays 30')]);
            check(is_array($d) && $d['action'] === 'approve' && isset($d['kind']) && $d['kind'] === 'delete', 'retention: ' . json_encode($d));
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set provider.model m-1')]);
            check(is_array($d) && $d['action'] === 'approve' && !isset($d['kind']), 'model: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
    'approvals off: in an agent started by an agent, a "yes" in its session is not the user\'s, so a delete stays blocked' => function () use ($yes) {
        $dir = testTempDir('pk-askoff');
        list($proc, $port) = hookSearchFakeProvider($dir);
        $del = ['action' => 'approve', 'message' => 'Pikkumies: deletes or empties files, which cannot be undone. Command [1]: rm -r ~/old', 'rule_key' => 'pikkumies-delete:1', 'kind' => 'delete'];
        $tree = askOrchTree(['guard/del' => $del], $port);
        $call = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -r ~/old'], 'session_id' => 'sess-nest'];
        try {
            askOrchSnapshot($tree, 'sess-nest', $yes);
            file_put_contents("$dir/reply", "R1: yes\nR2: yes\nR3: no");
            list($d, $raw) = testWithEnv(['PIKKUMIES_DEPTH' => '2'], function () use ($tree, $call) {
                return orchestratorRun($tree, 'pre_tool_call', $call);
            });
            check($d === ['action' => 'block', 'message' => offConst(true)] && hookSearchRequests($dir) === [], 'depth 2: no evidence, no review: ' . $raw);
            list($d, $raw) = testWithEnv(['PIKKUMIES_DEPTH' => '1'], function () use ($tree, $call) {
                return orchestratorRun($tree, 'pre_tool_call', $call);
            });
            check($d === null, 'depth 1: the user\'s yes clears it: ' . $raw);
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
            testRmTree($dir);
        }
    },
    'approvals off: Hermes\' other questions are pinned off too: slash-command and MCP reload confirms, the model switch, instruction files' => function () {
        $prov = ['endpoint' => 'e', 'model' => 'm', 'key' => 'k'];
        foreach ([true, false] as $pinsOnly) {
            $y = HermesConfig::renderYaml($prov, ['/a'], [], false, 'light', $pinsOnly);
            check(strpos($y, "approvals:\n  mode: \"off\"\n  destructive_slash_confirm: false\n  mcp_reload_confirm: false\n") !== false, 'slash confirms: ' . $y);
            check(strpos($y, "  switch_context_confirm_tokens: 0\n") !== false && strpos($y, "  protected_instruction_files: false\n") !== false, 'model switch, instruction files: ' . $y);
        }
        foreach (['approvals.destructive_slash_confirm', 'approvals.mcp_reload_confirm', 'model.switch_context_confirm_tokens', 'security.protected_instruction_files'] as $key) {
            check(isset(HermesInstall::CONFIG_FACTS[$key]), "$key must be a fact, so a Hermes without it is refused");
        }
    },
    'approvals off: `pikkumies run update` passes --yes to Hermes; the search-first gate no longer offers asking the user' => function () {
        $root = dirname(__DIR__);
        $bin = (string) file_get_contents("$root/pikkumies/bin/pikkumies");
        check(strpos($bin, "array_splice(\$passArgs, 1, 0, ['--yes'])") !== false && strpos($bin, "array_intersect(['--yes', '-y'], \$passArgs)") !== false, 'run update without --yes');
        $gate = (string) file_get_contents("$root/pikkumies/hooks/search/search-first-gate/search-first-gate.php");
        check(strpos($gate, 'asking the user') === false, 'the search-first gate still suggests asking the user');
    },
];
