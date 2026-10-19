<?php
/**
 * Question-suite run 3 (2026-10-19): borg-prune-keep-daily-weekly and restic-forget-needs-prune read local/docs-cache
 * with no memory search before, which ADR-0043 says the search-first gate blocks. Their exact call sequences, payloads as
 * Hermes sends them (agent/shell_hooks.py: hook_event_name, tool_name, tool_input, session_id, cwd, extra), replayed
 * through the shipped orchestrator and units: the gate blocks every one. So the miss is not in the gate's matcher or the
 * orchestrator; it lies between Hermes and the hook, or in the live account's state. The question suite now keeps each
 * question's hook-log lines (hook-log/<target>/<id>.jsonl) so the next live run shows which it was (ADR-0039).
 */
require_once __DIR__ . '/OrchestratorTest.php'; // orchestratorTree(), orchestratorRun()

if (!function_exists('replayCall')) {
    function replayCall($tree, $session, $tool, array $input)
    {
        list($d) = orchestratorRun($tree, 'pre_tool_call', ['hook_event_name' => 'pre_tool_call', 'tool_name' => $tool, 'tool_input' => $input,
            'session_id' => $session, 'cwd' => "$tree/home", 'extra' => []]);
        return is_array($d) && isset($d['action']) ? $d['action'] . ': ' . (isset($d['message']) ? $d['message'] : '') : 'none';
    }
}

return [
    'search-first gate: the run-3 borg and restic docs reads before any search are blocked through the orchestrator (ADR-0043)' => function () {
        $tree = orchestratorTree();
        try {
            $cache = "$tree/pikkumies/local/docs-cache";
            foreach (['borg' => [['skill_view', ['name' => 'borg']], ['read_file', ['path' => "$cache/borg-docs/en-stable-usage-prune.md"]]],
                'restic' => [['skill_view', ['name' => 'restic']], ['read_file', ['path' => "$cache/restic-docs/en-stable-077-troubleshooting.md"]],
                    ['search_files', ['pattern' => 'prune', 'path' => "$cache/restic-docs", 'output_mode' => 'files_only']],
                    ['read_file', ['path' => "$cache/pm-wiki/restic-s3-backup-to-your-storage-box-on-pmss.md"]]]] as $q => $calls) {
                $session = "20261019_0047_$q";
                orchestratorRun($tree, 'pre_llm_call', ['hook_event_name' => 'pre_llm_call', 'session_id' => $session,
                    'extra' => ['is_first_turn' => true, 'user_message' => "How do I prune old $q backups?"]]);
                foreach ($calls as $c) {
                    $got = replayCall($tree, $session, $c[0], $c[1]);
                    if ($c[0] === 'skill_view') {
                        check($got === 'none', "$q: skill_view only reads and must be let through, got $got");
                    } else {
                        check(strpos($got, 'block: ') === 0 && strpos($got, 'lessons before documentation') !== false, "$q: {$c[0]} of the docs cache before a search was not blocked: $got");
                    }
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
];
