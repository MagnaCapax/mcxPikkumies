<?php
/**
 * The hook units hooks/trace/session-counters (post_tool_call) and hooks/trace/session-context (post_api_request)
 * (ADR-0061): diff lines counted by hand, what each payload adds, Hermes' `extra` fields read and a delegated agent's
 * request left out, and both units run through the orchestrator with and without TMUX_PANE.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/trace/session-counters/session-counters.php';
require_once dirname(__DIR__) . '/pikkumies/hooks/trace/session-context/session-context.php';

if (!function_exists('sessionHooksTree')) {
    /** A throwaway tree with the lib, the orchestrator and the two units only. */
    function sessionHooksTree()
    {
        $tmp = testTempDir('pk-sesshooks');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'config', 'hooks/trace/session-counters', 'hooks/trace/session-context'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        copy("$src/hooks/orchestrator.php", "$tmp/pikkumies/hooks/orchestrator.php");
        @mkdir("$tmp/pikkumies/local", 0700, true);
        return $tmp;
    }
}

return [
    'session-counters: diff lines are counted inside hunks only; file headers never count, a removed "--" line does' => function () {
        $one = "--- a/x.php\n+++ b/x.php\n@@ -1,3 +1,4 @@\n keep\n-old\n+new\n+added\n keep\n";
        check(sessionCountersDiffLines($one) === [2, 1], 'one hunk: ' . json_encode(sessionCountersDiffLines($one)));
        $dashes = "--- a/s.sql\n+++ b/s.sql\n@@ -2,2 +2,1 @@\n--- a comment line\n kept\n";
        check(sessionCountersDiffLines($dashes) === [0, 1], 'a removed "-- comment" line reads as "---" and must count as one removal: ' . json_encode(sessionCountersDiffLines($dashes)));
        $two = $one . "--- a/y\n+++ b/y\n@@ -5 +5 @@\n-a\n+b\n\\ No newline at end of file\n";
        check(sessionCountersDiffLines($two) === [3, 2], 'two files, a one-line hunk header, a no-newline marker: ' . json_encode(sessionCountersDiffLines($two)));
        check(sessionCountersDiffLines('') === [0, 0] && sessionCountersDiffLines("+++ b/x\n+not in a hunk\n") === [0, 0], 'no hunk, no lines');
    },
    'session-counters: every call counts once; lines from a patch diff or written content; a failed or unreadable result adds none' => function () {
        $patch = function ($result) {
            return ['tool_name' => 'patch', 'tool_input' => [], 'extra' => ['result' => $result]];
        };
        check(sessionCountersDelta($patch(json_encode(['success' => true, 'diff' => "@@ -1 +1,2 @@\n-a\n+b\n+c\n"]))) === ['tools' => 1, 'added' => 2, 'removed' => 1], 'patch');
        check(sessionCountersDelta($patch(json_encode(['success' => false, 'diff' => "@@ -1 +1 @@\n-a\n+b\n", 'error' => 'stale']))) === ['tools' => 1, 'added' => 0, 'removed' => 0], 'failed patch');
        check(sessionCountersDelta($patch('{"success": true, "diff": "@@ -1 +1 @@\n-a\n+b ... [truncated')) === ['tools' => 1, 'added' => 0, 'removed' => 0], 'a result cut to size');
        $write = function ($content) {
            return ['tool_name' => 'write_file', 'tool_input' => ['path' => 'x', 'content' => $content], 'extra' => ['result' => '{"bytes_written": 3}']];
        };
        check(sessionCountersDelta($write("a\nb\n"))['added'] === 2 && sessionCountersDelta($write("a\nb"))['added'] === 2 && sessionCountersDelta($write(''))['added'] === 0, 'write_file lines, with and without a final newline');
        check(sessionCountersDelta(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls'], 'extra' => ['result' => '{"output":"+x\n-y"}']]) === ['tools' => 1, 'added' => 0, 'removed' => 0], 'other tools count the call only');
        check(sessionCountersDelta([]) === ['tools' => 1, 'added' => 0, 'removed' => 0], 'an empty payload is still a call');
    },
    'session-context: prompt tokens and the window from extra; a delegated agent\'s request or one without usage is ignored' => function () {
        $main = ['extra' => ['platform' => 'cli', 'usage' => ['prompt_tokens' => 48211, 'input_tokens' => 1200], 'context_length' => 1000000]];
        check(sessionContextOf($main) === ['ctx' => 48211, 'ctxMax' => 1000000], 'main agent: ' . json_encode(sessionContextOf($main)));
        check(sessionContextOf(['extra' => ['platform' => 'subagent'] + $main['extra']]) === null, 'a delegated agent must not overwrite the figure');
        check(sessionContextOf(['extra' => ['platform' => 'cli', 'usage' => null, 'context_length' => 5]]) === null, 'no usage: nothing');
        check(sessionContextOf(['platform' => 'cli', 'usage' => ['prompt_tokens' => 9], 'context_length' => 9]) === null, 'Hermes sends these under extra; top-level keys are not read');
        check(sessionContextOf(['extra' => ['usage' => ['prompt_tokens' => 7]]]) === ['ctx' => 7, 'ctxMax' => 0], 'no window: 0');
    },
    'session hooks: through the orchestrator they count for the pane in TMUX_PANE, print nothing, and write nothing outside tmux' => function () {
        $tree = sessionHooksTree();
        $was = getenv('TMUX_PANE');
        $local = "$tree/pikkumies/local";
        $tool = json_encode(['hook_event_name' => 'post_tool_call', 'tool_name' => 'patch', 'tool_input' => [], 'session_id' => 's',
            'extra' => ['result' => json_encode(['success' => true, 'diff' => "@@ -1 +1,2 @@\n-a\n+b\n+c\n"])]]);
        $api = json_encode(['hook_event_name' => 'post_api_request', 'session_id' => 's', 'extra' => ['platform' => 'cli', 'usage' => ['prompt_tokens' => 1234], 'context_length' => 200000]]);
        try {
            putenv('TMUX_PANE'); // outside tmux, even when these tests run inside it
            SessionCounters::reset($local, '%7');
            list(, $out) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_tool_call'], $tool);
            check(trim($out) === '' && SessionCounters::read($local, '%7')['tools'] === 0, 'without TMUX_PANE nothing may be counted: ' . $out);
            putenv('TMUX_PANE=%7');
            list(, $out1) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_tool_call'], $tool);
            list(, $out2) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_api_request'], $api);
            $c = SessionCounters::read($local, '%7');
            check(trim($out1 . $out2) === '', 'observers print nothing: ' . $out1 . $out2);
            check($c['tools'] === 1 && $c['added'] === 2 && $c['removed'] === 1 && $c['ctx'] === 1234 && $c['ctxMax'] === 200000, 'counted for %7: ' . json_encode($c));
            putenv('TMUX_PANE=%8');
            runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_tool_call'], $tool);
            check(!is_file(SessionCounters::file($local, '%8')), 'a pane where pikkumies never started gets no file');
            $units = [];
            foreach (HookUnits::all("$tree/pikkumies/hooks") as $u) {
                $units[$u['name']] = [$u['event'], $u['errors']];
            }
            check($units === ['session-context' => ['post_api_request', []], 'session-counters' => ['post_tool_call', []]], 'both units valid on their events: ' . json_encode($units));
        } finally {
            putenv($was === false ? 'TMUX_PANE' : "TMUX_PANE=$was");
            testRmTree($tree);
        }
    },
    'session-context run: total_tokens when an integer in 0..10M, else prompt + output; a delegated agent counts too; nothing usable is null' => function () {
        $u = function (array $usage, $platform = 'cli') {
            return ['extra' => ['platform' => $platform, 'usage' => $usage]];
        };
        check(SessionCounters::requestTokens($u(['prompt_tokens' => 48000, 'output_tokens' => 500, 'total_tokens' => 48500])) === 48500, 'total_tokens');
        check(SessionCounters::requestTokens($u(['total_tokens' => 900], 'subagent')) === 900, 'a delegated agent\'s request counts for the run');
        check(SessionCounters::requestTokens($u(['total_tokens' => 10000001, 'prompt_tokens' => 70, 'output_tokens' => 5])) === 75, 'out of range total: prompt + output');
        check(SessionCounters::requestTokens($u(['total_tokens' => '900', 'prompt_tokens' => 70])) === 70, 'a string total is not an integer; output missing counts 0');
        check(SessionCounters::requestTokens($u(['total_tokens' => -1, 'output_tokens' => 12])) === 12, 'negative total ignored');
        check(SessionCounters::requestTokens($u(['total_tokens' => 0])) === 0, 'zero is a count');
        check(SessionCounters::requestTokens($u(['input_tokens' => 9])) === null && SessionCounters::requestTokens(['extra' => ['usage' => null]]) === null && SessionCounters::requestTokens([]) === null, 'nothing usable: null');
        check(SessionCounters::requestTokens(['usage' => ['total_tokens' => 9]]) === null, 'Hermes sends usage under extra; top level is not read');
    },
    'session-context run: through the orchestrator every request adds to the run of PIKKUMIES_RUN, with or without tmux; the pane figures stay as before' => function () {
        $tree = sessionHooksTree();
        $local = "$tree/pikkumies/local";
        $run = '00112233445566ff';
        $api = function ($platform, $total) {
            return json_encode(['hook_event_name' => 'post_api_request', 'session_id' => 's',
                'extra' => ['platform' => $platform, 'usage' => ['prompt_tokens' => 1000, 'output_tokens' => 20, 'total_tokens' => $total], 'context_length' => 200000]]);
        };
        try {
            SessionCounters::resetRun($local, $run, 100);
            SessionCounters::reset($local, '%7');
            testWithEnv(['TMUX_PANE' => null, 'PIKKUMIES_RUN' => $run], function () use ($tree, $api) {
                list(, $out) = runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_api_request'], $api('cli', 1020));
                check(trim($out) === '', 'prints nothing: ' . $out);
                runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_api_request'], $api('subagent', 300));
            });
            $c = SessionCounters::readRun($local, $run);
            check($c['tokens'] === 1320 && $c['reqs'] === 2 && $c['since'] === 100, 'outside tmux both requests count, the delegated one too: ' . json_encode($c));
            check(SessionCounters::read($local, '%7')['ctx'] === 0, 'outside tmux the pane is untouched');
            testWithEnv(['TMUX_PANE' => '%7', 'PIKKUMIES_RUN' => $run], function () use ($tree, $api) {
                runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_api_request'], $api('subagent', 50));
                runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_api_request'], $api('cli', 1020));
            });
            $p = SessionCounters::read($local, '%7');
            check(SessionCounters::readRun($local, $run)['tokens'] === 2390 && $p['ctx'] === 1000 && $p['ctxMax'] === 200000, 'run and pane both counted: ' . json_encode([$p, SessionCounters::readRun($local, $run)]));
            foreach (['nothex0123456789', '', 'ffffffffffffffff'] as $other) { // invalid, unset, a key no start zeroed
                testWithEnv(['TMUX_PANE' => null, 'PIKKUMIES_RUN' => $other === '' ? null : $other], function () use ($tree, $api) {
                    runPhp("$tree/pikkumies/hooks/orchestrator.php", ['post_api_request'], $api('cli', 5));
                });
            }
            check(SessionCounters::readRun($local, $run)['tokens'] === 2390 && SessionCounters::readRun($local, 'ffffffffffffffff') === null, 'other keys write nothing');
            check(count((array) glob(SessionCounters::dir($local) . '/run-*.json')) === 1, 'no other run file appeared');
        } finally {
            testRmTree($tree);
        }
    },
];
