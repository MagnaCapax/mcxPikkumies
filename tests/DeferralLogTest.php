<?php
/**
 * ADR-0102: every deferral the halt guard catches is logged as a fixed class, antipattern id and note of at most 75
 * words, never transcript or model text; the clarify block is logged too; every continue and the clarify block end
 * with the method sentence; final_attempt marks the last continue.
 */
if (!function_exists('haltHookTree')) {
    require __DIR__ . '/HaltHookTest.php';
}
require_once dirname(__DIR__) . '/pikkumies/lib/Antipatterns.php';

if (!function_exists('deferralAnswers')) {
    function deferralAnswers(array $over)
    {
        return array_merge(['Q1' => 'no', 'Q2' => 'yes', 'Q3' => 'yes', 'Q4' => 'no', 'Q5' => 'no', 'Q6' => 'no', 'Q7' => 'no', 'Q8' => 'no', 'Q9' => 'no'], $over);
    }
}

return [
    'deferral log: each continuing question maps to its fixed class and antipattern id; notes are fixed and at most 75 words' => function () {
        $byQ = [3 => ['Q3' => 'no'], 4 => ['Q4' => 'yes'], 5 => ['Q5' => 'yes'], 6 => ['Q6' => 'yes'], 9 => ['Q9' => 'yes']];
        $ids = array_keys(Antipatterns::entries());
        foreach ($byQ as $n => $over) {
            $v = HaltJudge::decide(deferralAnswers($over), false);
            $d = HaltJudge::deferrals($v);
            check($v['verdict'] === 'continue' && count($d) === 1 && $d[0]['class'] === HaltJudge::DEFERRAL_CLASSES[$n][0], "Q$n: " . json_encode([$v['verdict'], $d]));
            check(in_array($d[0]['slug'], $ids, true), "Q$n: the slug {$d[0]['slug']} must be an antipatterns.md id");
            check(str_word_count($d[0]['note']) <= 75, "Q$n: the note has more than 75 words");
        }
        $v = HaltJudge::decide(deferralAnswers(['Q3' => 'no', 'Q6' => 'yes', 'Q9' => 'yes']), false);
        check(array_column(HaltJudge::deferrals($v), 'class') === ['work-not-done', 'listed-own-next-steps', 'offered-or-handed-back'], 'several at once, in question order');
        check(HaltJudge::deferrals(HaltJudge::decide(deferralAnswers(['Q7' => 'yes', 'Q3' => 'no']), false)) === [], 'waiting on the user is not a deferral');
        check(HaltJudge::deferrals(HaltJudge::decide(deferralAnswers([]), false)) === [], 'a finished turn is not a deferral');
        check(HaltJudge::deferrals(['verdict' => 'failopen', 'answers' => []]) === [], 'fail open: none');
        $v = HaltJudge::decide(deferralAnswers(['Q1' => 'yes', 'Q2' => 'no', 'Q9' => 'yes']), false);
        check(array_column(HaltJudge::deferrals($v), 'class') === ['offered-or-handed-back'], 'a how-to answer that only offered: ' . json_encode(HaltJudge::deferrals($v)));
    },
    'deferral log: every continue ends with the method sentence, and the new antipattern entry is there' => function () {
        $v = HaltJudge::decide(deferralAnswers(['Q4' => 'yes']), false);
        check(substr($v['message'], -strlen(HaltJudge::METHOD)) === HaltJudge::METHOD, 'continue: ' . $v['message']);
        foreach (['investigate', 'attack your plan', 'iterate', 'do it'] as $w) {
            check(strpos(HaltJudge::METHOD, $w) !== false, "the method sentence must say '$w'");
        }
        $byId = Antipatterns::entries();
        check(isset($byId['asked-permission-for-the-task']) && strpos($byId['asked-permission-for-the-task']['do'], 'investigate') !== false, 'antipatterns.md entry asked-permission-for-the-task');
    },
    'deferral log: the halt guard writes class, slug, note and final_attempt, never the transcript, the answer or the model text' => function () {
        $fake = sys_get_temp_dir() . '/pk-deferral-fake-' . getmypid() . '-' . mt_rand();
        @mkdir($fake, 0700, true);
        list($proc, $port) = hookUnitsProvider($fake);
        $tree = haltHookTree($port);
        try {
            file_put_contents("$fake/reply", "Q1: no\nQ2: yes\nQ3: yes\nQ4: yes\nQ5: no\nQ6: no\nQ7: no\nQ8: no\nQ9: no\nCANARY-MODEL-TEXT");
            foreach ([0 => false, 2 => true] as $attempt => $final) {
                haltSeed($tree, "sess-def-$attempt", ['tail' => '[user] CANARY-TRANSCRIPT', 'user_message' => 'CANARY-USER fix my web ui']);
                list($d) = haltHookRun($tree, 'halt-guard', ['hook_event_name' => 'pre_verify', 'session_id' => "sess-def-$attempt", 'extra' => ['attempt' => $attempt, 'final_response' => 'Shall I restart it? CANARY-ANSWER']]);
                check(is_array($d) && $d['action'] === 'continue' && strpos($d['message'], HaltJudge::METHOD) !== false, "attempt $attempt: " . json_encode($d));
            }
            $lines = haltDecisionLogLines($tree);
            check(count($lines) === 2, 'two decision lines: ' . json_encode($lines));
            foreach ($lines as $i => $l) {
                check(isset($l['deferral'][0]) && $l['deferral'][0]['class'] === 'asked-permission-for-the-task' && $l['deferral'][0]['slug'] === 'asked-permission-for-the-task', 'class and slug: ' . json_encode($l));
                check($l['final_attempt'] === ($i === 1), 'final_attempt only on attempt 2: ' . json_encode($l));
            }
            $raw = (string) file_get_contents("$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl');
            foreach (['CANARY-MODEL-TEXT', 'CANARY-TRANSCRIPT', 'CANARY-USER', 'CANARY-ANSWER'] as $c) {
                check(strpos($raw, $c) === false, "$c leaked into the deferral log");
            }
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fake);
        }
    },
    'deferral log: a blocked clarify call is logged as asked-user-to-decide, without the question, and its block ends with the method sentence' => function () {
        $tree = haltHookTree();
        $src = dirname(__DIR__) . '/pikkumies/hooks/tools/clarify/decide-yourself';
        @mkdir("$tree/pikkumies/hooks/tools/clarify/decide-yourself", 0700, true);
        copy("$src/decide-yourself.php", "$tree/pikkumies/hooks/tools/clarify/decide-yourself/decide-yourself.php");
        try {
            list(, $out) = runPhp("$tree/pikkumies/hooks/tools/clarify/decide-yourself/decide-yourself.php", [], json_encode(['tool_name' => 'clarify', 'session_id' => 'sess-clar', 'tool_input' => ['question' => 'CANARY-QUESTION which one?', 'choices' => ['a', 'b']]]));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block' && substr($d['message'], -strlen(HaltJudge::METHOD)) === HaltJudge::METHOD, 'clarify block: ' . $out);
            $lines = haltDecisionLogLines($tree);
            check(count($lines) === 1 && $lines[0]['source'] === 'clarify' && $lines[0]['deferral'][0]['class'] === 'asked-user-to-decide' && $lines[0]['deferral'][0]['slug'] === 'asking-what-you-can-check', 'clarify line: ' . json_encode($lines));
            check(str_word_count($lines[0]['deferral'][0]['note']) <= 75, 'note length');
            check(strpos((string) file_get_contents("$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl'), 'CANARY-QUESTION') === false, 'the question must never be logged');
            file_put_contents("$tree/pikkumies/local/config.php", "<?php\nreturn ['halt' => ['disabled' => true]];\n");
            runPhp("$tree/pikkumies/hooks/tools/clarify/decide-yourself/decide-yourself.php", [], json_encode(['tool_name' => 'clarify', 'session_id' => 'sess-clar2', 'tool_input' => ['question' => 'x']]));
            check(count(haltDecisionLogLines($tree)) === 1, 'halt.disabled: nothing more is logged');
        } finally {
            testRmTree($tree);
        }
    },
];
