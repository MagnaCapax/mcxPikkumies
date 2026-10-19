<?php
/**
 * UserTurn: what a user message is, read the same way by the search-first hook and the goal review (ADR-0067, ADR-0071).
 * substantive(): asks something new, 4 or more content words or a question mark, not a slash command. ownResume():
 * Pikkumies' own continue or wrap-up text (the halt guard's fixed sentences, ADR-0047), which reaches Hermes as a user
 * message but is the same task going on: matched as an exact prefix of the fixed PHP constants, never the model's words.
 */
require_once __DIR__ . '/HaltJudge.php';

class UserTurn
{
    // Words that do not make a follow-up a new question ("ok thanks", "yes do it").
    const FILLER = ['ok', 'okay', 'thanks', 'thank', 'you', 'yes', 'no', 'yeah', 'yep', 'please', 'sure', 'fine', 'good', 'great', 'nice',
        'cool', 'done', 'the', 'a', 'an', 'and', 'or', 'it', 'that', 'this', 'is', 'to', 'do', 'go', 'kiitos', 'joo', 'kyllä', 'ei', 'selvä', 'hyvä', 'tee', 'se'];

    /** The lower-case words of a message (letters and digits). */
    public static function words($message)
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', strtolower((string) $message), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? $words : [];
    }

    public static function substantive($message)
    {
        $message = trim((string) $message);
        if ($message === '' || $message[0] === '/' || self::ownResume($message)) {
            return false;
        }
        return count(array_diff(self::words($message), self::FILLER)) >= 4 || strpos($message, '?') !== false;
    }

    public static function ownResume($message)
    {
        foreach (array_merge(array_values(HaltJudge::CONTINUE_SENTENCES), [HaltJudge::WRAPUP, HaltDecouple::SENTENCE]) as $fixed) {
            if (strpos((string) $message, $fixed) === 0) {
                return true;
            }
        }
        return false;
    }
}
