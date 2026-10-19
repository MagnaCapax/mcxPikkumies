<?php
/**
 * lib/HaltJudge.php (ADR-0047 amendment of 2026-10-19): an offer is not unfinished work.
 * What these tests can and cannot show: the judge's answers come from a light model, so whether a
 * real model now answers a how-to question differently is not tested here (only a live rerun of the
 * question suite shows that). Test 1 checks the wording the model reads; test 2 pins the decision
 * table, which this change did not touch (it passes on the code before the change too); test 3 checks
 * the closing rule of every continue message, which is fixed PHP text.
 */
if (!function_exists('haltOfferAnswers')) {
    /** A full Q1..Q8 answer set: everything 'no' except the overrides. */
    function haltOfferAnswers(array $over)
    {
        return array_merge(['Q1' => 'no', 'Q2' => 'no', 'Q3' => 'no', 'Q4' => 'no', 'Q5' => 'no', 'Q6' => 'no', 'Q7' => 'no', 'Q8' => 'no'], $over);
    }

    /** Question $n exactly as the light model reads it in the prompt. */
    function haltOfferQuestion($n)
    {
        $p = HaltJudge::prompt('u', 't', 'e', 'a');
        check(preg_match('/^' . $n . '\. (.*)$/m', $p[1]['content'], $m) === 1, "question $n missing from the prompt");
        return $m[1];
    }
}

return [
    'HaltJudge offer (wording only, not model behaviour): how-to is no request, a polite request is one, an unrequested change waits on the user, a change the requested work needs counts as asked' => function () {
        $want = [
            1 => ['how something works or what to do'],
            2 => ['A polite request to do something ("can you check ...", "please restart ...") is a request', 'how it could be done ("how do I ...?", "can you tell me how ...?", "can you explain ...?") is not'],
            4 => ['Offering a change the user did not ask for is not such a step', 'a change the requested work needs counts as asked for'],
            6 => ['next steps of the requested work'],
            7 => ['config, services or data that they did not ask for', 'a change the requested work needs counts as asked for', 'a polite request to do something'],
        ];
        foreach ($want as $n => $phrases) {
            foreach ($phrases as $phrase) {
                check(stripos(haltOfferQuestion($n), $phrase) !== false, "Q$n must say \"$phrase\": " . haltOfferQuestion($n));
            }
        }
    },
    'HaltJudge decision-table pin (unchanged by this change, passes before it too): chat and waiting end the turn, asked-for work left undone continues' => function () {
        $chat = HaltJudge::decide(haltOfferAnswers(['Q1' => 'yes', 'Q7' => 'yes']), false);
        check($chat['verdict'] === 'chat' && $chat['message'] === null, 'Q1 yes, Q2 no must be chat: ' . json_encode($chat));
        $waiting = HaltJudge::decide(haltOfferAnswers(['Q2' => 'yes', 'Q4' => 'yes', 'Q6' => 'yes', 'Q7' => 'yes']), false);
        check($waiting['verdict'] === 'waiting' && $waiting['message'] === null, 'Q7 yes must win over the continue triggers: ' . json_encode($waiting));
        $undone = HaltJudge::decide(haltOfferAnswers(['Q2' => 'yes', 'Q3' => 'no']), false);
        check($undone['verdict'] === 'continue' && strpos($undone['message'], HaltJudge::CONTINUE_SENTENCES[3]) === 0, 'Q2 yes, Q3 no must continue: ' . json_encode($undone));
        $deferred = HaltJudge::decide(haltOfferAnswers(['Q2' => 'yes', 'Q3' => 'yes', 'Q4' => 'yes']), false);
        check($deferred['verdict'] === 'continue' && strpos($deferred['message'], HaltJudge::CONTINUE_SENTENCES[4]) === 0, 'a deferred requested step must continue: ' . json_encode($deferred));
    },
    'HaltJudge offer: every continue message ends with the rule that keeps the agent inside the request' => function () {
        foreach ([['Q3' => 'no'], ['Q3' => 'yes', 'Q4' => 'yes'], ['Q3' => 'yes', 'Q5' => 'yes'], ['Q3' => 'yes', 'Q6' => 'yes']] as $over) {
            $v = HaltJudge::decide(haltOfferAnswers(['Q2' => 'yes'] + $over), false);
            check($v['verdict'] === 'continue', 'expected a continue: ' . json_encode($v));
            check(substr($v['message'], -strlen(HaltJudge::CONTINUE_RULE)) === HaltJudge::CONTINUE_RULE
                && strpos(HaltJudge::CONTINUE_RULE, 'what the user asked for') !== false && strpos(HaltJudge::CONTINUE_RULE, 'beyond that request') !== false,
                'the continue message must end with the in-request rule: ' . $v['message']);
        }
    },
];
