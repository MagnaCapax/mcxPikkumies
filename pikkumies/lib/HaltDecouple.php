<?php
/**
 * HaltDecouple: the halt guard's truth-seeking criterion (ADR-0069), kept apart from HaltJudge's
 * work-completion battery (ADR-0047). One more yes/no question, labelled D1 so it never collides with
 * the numbered Qn questions, asked in the same light-model call. When the answer is "yes" (the final
 * answer dodged a factual question, dismissed a claim with a label, or rested a claim only on
 * authority), the turn continues with one fixed sentence. Same rules as HaltJudge: the model's reply
 * contributes only the yes/no/unsure answer, every message is a PHP constant, and an unsure, missing
 * or unparsable answer leaves the verdict as it was (never block on uncertainty).
 */
require_once __DIR__ . '/HaltJudge.php'; // CONTINUE_RULE and text(); HaltJudge requires this file in turn, and require_once makes the cycle safe

class HaltDecouple
{
    const LABEL = 'D1';

    const QUESTION = 'Did the final answer dodge or blur a factual question the user asked (pointing to sensitivity, controversy or "it depends" instead of giving the facts it has), '
        . 'dismiss a claim with a label such as myth, conspiracy or debunked without saying what was checked, or support a factual claim only by pointing to an authority, '
        . 'an official position or what most people think? Naming the documentation or source for what a product, setting or policy is, '
        . 'an honest "I don\'t know" or "I haven\'t checked" that says what would settle it, a refusal a safety rule requires, '
        . 'leaving a value choice to the user after giving the facts, and a polite tone are not this.';

    const SENTENCE = 'The answer dodged or blurred a factual point, dismissed a claim with a label, or rested a claim on authority instead of evidence: '
        . 'answer from the evidence, plainly, with the source and how sure you are, and say what you have not checked.';

    /** The question as it is listed to the light model, after the numbered Qn questions. */
    public static function promptLine()
    {
        return self::LABEL . '. ' . self::QUESTION;
    }

    /** The D1 answer in a reply: 'yes', 'no', 'unsure', or '' when the reply does not give one. */
    public static function parse($reply)
    {
        return preg_match('/\b' . self::LABEL . '\s*:\s*(yes|no|unsure)\b/i', (string) $reply, $m) ? strtolower($m[1]) : '';
    }

    /**
     * $verdict (HaltJudge::decide's result) with this criterion applied. A failopen verdict stays as it is
     * (the battery itself was unreliable). D1 "yes" turns any other verdict into a continue that starts with
     * SENTENCE: before the work sentences of an existing continue, or with HaltJudge::CONTINUE_RULE alone.
     * Not when $userMessage already starts with SENTENCE: the one-shot wrapper resumes with the message as
     * the new user turn, and the answer to this nudge is not sent back for the same reason twice.
     * The D1 answer is added to 'answers' for the decision log.
     */
    public static function apply(array $verdict, $reply, $userMessage)
    {
        $answer = self::parse($reply);
        if ($verdict['verdict'] === 'failopen') {
            return $verdict;
        }
        if ($answer !== '') {
            $verdict['answers'][self::LABEL] = $answer;
        }
        if ($answer !== 'yes' || strpos(trim(HaltJudge::text($userMessage)), self::SENTENCE) === 0) {
            return $verdict;
        }
        $rest = $verdict['verdict'] === 'continue' ? $verdict['message'] : HaltJudge::CONTINUE_RULE;
        return ['verdict' => 'continue', 'message' => self::SENTENCE . ' ' . $rest, 'answers' => $verdict['answers']];
    }
}
