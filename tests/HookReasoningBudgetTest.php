<?php
/**
 * lib/HookLlm.php, lib/AskReview.php (ADR-0073 amendment 2026-10-28): a hook's model call leaves room for the light
 * model's reasoning on top of the answer, and the ask review has time to finish one answer.
 */
return [
    'hook model calls: every caller\'s answer size gets the reasoning allowance on top, in one place' => function () {
        foreach ([40, 60, 80, 120, 300] as $answer) {
            $body = HookLlm::body('light-model', [['role' => 'user', 'content' => 'x']], $answer);
            check($body['max_tokens'] === $answer + HookLlm::REASONING_ALLOWANCE, "answer $answer: max_tokens {$body['max_tokens']}");
            check($body['model'] === 'light-model' && $body['temperature'] === 0 && $body['messages'][0]['content'] === 'x', 'the rest of the body is unchanged');
        }
    },
    'hook model calls: the allowance covers a reasoning light model with room to spare' => function () {
        // Measured 2026-10-28 on the platform's light model (Virsilipas default-instant, 7 days): 67.1 reasoning tokens per
        // request, and 386 of 404 calls capped at 40 tokens spent all 40 on reasoning, so the ask review got no answer.
        check(HookLlm::REASONING_ALLOWANCE >= 5 * 67, 'allowance ' . HookLlm::REASONING_ALLOWANCE);
        check(AskReview::MAX_TOKENS + HookLlm::REASONING_ALLOWANCE > 40 + 67, 'the review\'s three lines fit after the reasoning');
    },
    'ask review: the model call has time for one answer from a reasoning light model' => function () {
        // Same measurement: a mean of 6161 ms to the whole (non-streamed) answer; 8 s refused most sends as unchecked.
        check(AskReview::TIMEOUT >= 3 * 6, 'review timeout ' . AskReview::TIMEOUT . ' s');
        $entries = HermesConfig::hookEntries();
        check(isset($entries['pre_tool_call']) && $entries['pre_tool_call']['timeout'] >= 2 + AskReview::TIMEOUT,
            'the pre_tool_call hook entry leaves the review its time');
    },
];
