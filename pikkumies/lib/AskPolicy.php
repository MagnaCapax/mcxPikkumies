<?php
/**
 * AskPolicy (ADR-0073): which hook questions ("approve" answers on pre_tool_call) still reach the user. Applied by
 * hooks/orchestrator.php to the units' answers before they are merged. Routing is deterministic:
 * - a question from the person's own local hooks, or the /feedback send question, is kept as it is;
 * - a load test or stopping the container daemon is dropped (the call runs): neither loses or reveals anything;
 * - every other question, and any kind this table does not know, goes to one AskReview of all of them together,
 *   and is kept only when the review finds the action irreversible and not covered by the user's request.
 * Any doubt keeps the questions: no evidence, no time left, a failed or unparsable review, or an error here.
 */
require_once __DIR__ . '/AskReview.php';
require_once __DIR__ . '/HaltState.php';

class AskPolicy
{
    const DROP = ['load', 'stop'];                      // guard-destructive kinds that lose and reveal nothing
    const DISCLOSE = ['send', 'mail', 'exposure'];      // cannot be taken back: R2 is yes without asking the model
    const KEEP_UNITS = ['guard/feedback-send'];         // the operator asked for this question (ADR-0056)

    /** Test seam: a callable($tool, $questions, $evidence, $timeout) returning parsed R answers, instead of the model. */
    public static $reviewer = null;

    /**
     * $results: the directives of every unit (approve ones carry 'unit', and 'local' => true from a local unit).
     * $evidence: the halt snapshot's asked entries (AskReview::evidence). $seconds: time left for a review.
     * Returns [the directives with the dropped and the cleared approves removed, counts for the hook log].
     */
    public static function apply(array $results, $tool, array $evidence, $seconds)
    {
        $counts = ['kept' => 0, 'dropped' => 0, 'cleared' => 0];
        $original = $results;
        try {
            foreach ($results as $r) {
                if (is_array($r) && isset($r['action'], $r['message']) && $r['action'] === 'block' && is_string($r['message']) && $r['message'] !== '') {
                    return [array_values($results), $counts]; // a block wins the merge anyway: no review, nothing changed
                }
            }
            $review = [];
            foreach ($results as $i => $r) {
                if (!is_array($r) || !isset($r['action']) || $r['action'] !== 'approve') {
                    continue;
                }
                $route = self::route($r);
                if ($route === 'drop') {
                    unset($results[$i]);
                    $counts['dropped']++;
                } elseif ($route === 'review') {
                    $review[$i] = $r;
                } else {
                    $counts['kept']++;
                }
            }
            if (!$review) {
                return [array_values($results), $counts];
            }
            if ($evidence && $seconds >= 2 && !self::keepAfterReview($review, $tool, $evidence, min(AskReview::TIMEOUT, $seconds))) {
                foreach (array_keys($review) as $i) {
                    unset($results[$i]);
                }
                $counts['cleared'] += count($review);
            } else {
                $counts['kept'] += count($review);
            }
        } catch (\Throwable $e) {
            return [array_values($original), ['kept' => 0, 'dropped' => 0, 'cleared' => 0, 'error' => 1]]; // an error keeps every question
        }
        return [array_values($results), $counts];
    }

    /** 'keep', 'drop' or 'review' for one approve directive. */
    public static function route(array $approve)
    {
        $unit = isset($approve['unit']) ? (string) $approve['unit'] : '';
        if (!empty($approve['local']) || in_array($unit, self::KEEP_UNITS, true)) {
            return 'keep';
        }
        $kind = isset($approve['kind']) ? (string) $approve['kind'] : '';
        return in_array($kind, self::DROP, true) ? 'drop' : 'review';
    }

    /** One review for all reviewed questions of this call: true keeps them all. */
    private static function keepAfterReview(array $review, $tool, array $evidence, $timeout)
    {
        $questions = [];
        $disclose = false;
        foreach ($review as $r) {
            $questions[] = isset($r['message']) ? (string) $r['message'] : 'a Pikkumies hook asks about this call';
            $disclose = $disclose || in_array(isset($r['kind']) ? (string) $r['kind'] : '', self::DISCLOSE, true);
        }
        $answers = self::$reviewer !== null ? call_user_func(self::$reviewer, (string) $tool, $questions, $evidence, $timeout)
            : AskReview::ask((string) $tool, $questions, $evidence, $timeout);
        return AskReview::keep(is_array($answers) ? $answers : [], $disclose);
    }

    /** The asked entries of this session's halt snapshot, or [] when there is none (halt.disabled, a bad id). */
    public static function evidenceFor($sessionId)
    {
        if (!is_string($sessionId) || !preg_match('/^[A-Za-z0-9._-]{1,128}$/', $sessionId)) {
            return [];
        }
        $state = HaltState::load($sessionId);
        return is_array($state) && isset($state['asked']) && is_array($state['asked']) ? $state['asked'] : [];
    }
}
