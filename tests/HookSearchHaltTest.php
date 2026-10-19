<?php
/** The halt guard's own continue and wrap-up messages do not re-arm the search-first gate (ADR-0047 amendment of 2026-10-19). */
require_once __DIR__ . '/hookSearchSupport.php';

return [
    'search-first hook: a halt-guard continue or wrap-up message is a follow-up, not a new question; a near miss still re-arms' => function () {
        $tree = hookSearchTree(['disk-full' => ['Disk full', 'rtorrent stops when the disk is full.']]);
        $search = 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php search "disk full"';
        $marker = "$tree/pikkumies/local/state/search-first/" . sha1('halt');
        $messages = [
            'one continue sentence' => [HaltJudge::CONTINUE_SENTENCES[6] . ' ' . HaltJudge::CONTINUE_RULE, false],
            'two continue sentences' => [HaltJudge::CONTINUE_SENTENCES[3] . ' ' . HaltJudge::CONTINUE_SENTENCES[5] . ' ' . HaltJudge::CONTINUE_RULE, false],
            'the wrap-up' => [HaltJudge::WRAPUP, false],
            'a user message quoting a sentence later on' => ['Why did you say: ' . HaltJudge::CONTINUE_SENTENCES[6], true],
            'a new question' => ['how do I change the rtorrent port number', true],
        ];
        foreach ($messages as $what => list($message, $rearms)) {
            hookSearchGateBlocks($tree, 'halt', 'terminal', $search);
            touch($marker, time() - 10); // the search was an earlier turn
            check(hookSearchRun($tree, hookSearchTurn('halt', $message, false)) === '' || $rearms, "$what printed something");
            check(hookSearchGateBlocks($tree, 'halt', 'write_file') === $rearms, $what . ($rearms ? ' did not re-arm the gate' : ' re-armed the gate'));
            @unlink("$marker.armed");
        }
        exec('rm -rf ' . escapeshellarg($tree));
    },
];
