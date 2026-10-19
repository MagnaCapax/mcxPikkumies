<?php
/** The search-first gate counts a search followed only by an output suffix, and names a search it did not count (ADR-0038 addendum of 2026-10-19). */
require_once __DIR__ . '/hookSearchSupport.php';

return [
    'search-first gate: a search with only an output suffix (2>&1, 2>/dev/null, | head, | tail) opens it; anything else after it does not, and the block says the search was not counted (ADR-0038 amendment)' => function () {
        $tree = hookSearchTree(['disk-full' => ['Disk full', 'rtorrent stops when the disk is full.']]);
        $tool = '~/pikkumies/tools/memory/pikkumies/pikkumies.php';
        $search = "php $tool search \"disk full\"";
        $opening = [' 2>&1', ' 2>/dev/null', ' | head', ' | head -n 20', ' |head -n20', ' | head -50', ' | tail', ' | tail -n 5', ' | tail -5', ' 2>&1 | head -50', ' 2>/dev/null | tail -n 3'];
        foreach ($opening as $i => $suffix) {
            check(!hookSearchGateBlocks($tree, "sfx$i", 'terminal', $search . $suffix) && !hookSearchGateBlocks($tree, "sfx$i", 'write_file'), 'a search with a harmless suffix did not open the gate: ' . $suffix);
        }
        $closed = [' 2>&1; rm -rf /tmp/x', ' && rm -rf /tmp/x', ' | head; rm -rf /tmp/x', ' | head && rm -rf /tmp/x', ' | sh', ' | head | sh', ' 2>&1 | sh', ' > /tmp/out', ' | head > /tmp/out',
            ' 2>&1 > /tmp/out', ' 2> /tmp/err', ' | tee /tmp/out', ' | head -c 100', ' | tail -f', ' | head -n', ' || head', ' & | head', " | head\nrm -rf /tmp/x", " 2>&1\nrm -rf /tmp/x", " | head -5\n", ' 2>&1 | head -50 | sh', ' | head -n 0', ' | tail -0', ' | head -n 00', ' | head -20 2>&1'];
        $gate = "$tree/pikkumies/hooks/search/search-first-gate/search-first-gate.php";
        foreach ($closed as $i => $suffix) {
            $p = ['hook_event_name' => 'pre_tool_call', 'session_id' => "cl$i", 'tool_name' => 'terminal', 'tool_input' => ['command' => $search . $suffix]];
            list(, $out) = runPhp($gate, [], json_encode($p));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'This memory search was not counted') === 0 && strpos($d['message'], '2>&1 or 2>/dev/null first, then one | head or | tail') !== false, 'not blocked, or no "not counted" note: ' . json_encode($suffix) . ' -> ' . $out);
            check(hookSearchGateBlocks($tree, "cl$i", 'write_file'), 'opened the gate: ' . json_encode($suffix));
        }
        // The note names the search verb only: a save whose title holds the word "search" is blocked without it.
        list(, $out) = runPhp($gate, [], json_encode(['hook_event_name' => 'pre_tool_call', 'session_id' => 'savetitle', 'tool_name' => 'terminal',
            'tool_input' => ['command' => "php $tool save --topic disk --title \"how to search\" --body-file /tmp/x"]]));
        check(strpos($out, 'not counted') === false && strpos($out, 'Pikkumies search first') !== false, 'a save mentioning "search" got the note: ' . $out);
        // A padded command stays blocked and is answered quickly: the hook has a 10 s timeout and fails open.
        $t = microtime(true);
        $padded = "php $tool search x" . str_repeat(' ', 60000) . 'x; rm -rf /tmp/x';
        check(hookSearchGateBlocks($tree, 'padded', 'terminal', $padded) && hookSearchGateBlocks($tree, 'padded', 'write_file'), 'a padded chained command opened the gate');
        check(microtime(true) - $t < 3, 'a padded command took ' . round(microtime(true) - $t, 2) . ' s');
        check(!hookSearchGateBlocks($tree, 'longok', 'terminal', "php $tool search \"" . str_repeat('a', 4000) . '"'), 'a long plain search under the cap was blocked');
        // A block for a command with no memory search in it keeps the plain message.
        list(, $out) = runPhp($gate, [], json_encode(['hook_event_name' => 'pre_tool_call', 'session_id' => 'plainblock', 'tool_name' => 'terminal', 'tool_input' => ['command' => 'df -h']]));
        check(strpos($out, 'not counted') === false && strpos($out, 'Pikkumies search first') !== false, 'a block with no search in the command must not mention one: ' . $out);
        exec('rm -rf ' . escapeshellarg($tree));
    },
];
