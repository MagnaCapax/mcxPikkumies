<?php
/**
 * AskPolicy (ADR-0073, amended 2026-10-19): what happens to hook questions ("approve" answers on pre_tool_call).
 * Hermes runs with approvals.mode "off", which approves every hook question silently (Hermes v0.22.2 tools/approval.py
 * _run_approval_gate), so no question ever leaves this policy: each is dropped (the call runs) or becomes a block with
 * one fixed message (no model text). Applied by hooks/orchestrator.php to the units' answers before they are merged:
 * - a load test or stopping the container daemon runs: neither loses or reveals anything;
 * - every other question goes to one AskReview of all of them together; the call runs unless the review finds it
 *   irreversible and not covered by what the user wrote, and then it is blocked with BLOCK_CONFIRM: the agent says in
 *   one line what it would destroy or send and runs it again after the user's yes, which the review then finds (R1);
 * - the /feedback and /ticket sends and the person's own local hooks are reviewed as a send: only the user's explicit yes clears them;
 * - with no evidence (a messaging gateway, a scheduled run, an agent started by an agent, halt.disabled), no time or no
 *   review answer, a yes cannot be checked: deletes, exposures, sends, mail, the feedback and ticket sends and local hooks are
 *   blocked with BLOCK_UNCHECKED; every other kind runs, since it destroys nothing that has no copy.
 * An error here blocks every question.
 */
require_once __DIR__ . '/AskReview.php';
require_once __DIR__ . '/HaltState.php';
require_once __DIR__ . '/NestedAgent.php';

class AskPolicy
{
    const DROP = ['load', 'stop'];                            // guard-destructive kinds that lose and reveal nothing
    const DISCLOSE = ['send', 'mail', 'exposure'];            // cannot be taken back: R2 is yes without asking the model
    const UNCHECKED_BLOCK = ['delete', 'exposure', 'send', 'mail']; // still blocked when no yes can be checked
    const CONFIRM_UNITS = ['guard/feedback-send', 'guard/ticket-send']; // the operator asked for this confirmation (ADR-0056, ADR-0089)

    const BLOCK_CONFIRM = 'Pikkumies safety: not run. This could destroy or send something that has no copy, and the user\'s'
        . ' own messages do not ask for exactly this. Do not reach the same effect another way. Finish the rest of the task,'
        . ' say to the user in one line what this command would destroy or send, and run the same command again only after'
        . ' they say yes in the chat.';
    const BLOCK_UNCHECKED = 'Pikkumies safety: not run. This could destroy or send something that has no copy, and a yes from'
        . ' the user cannot be checked in this session. Do not reach the same effect another way. Finish the rest of the task,'
        . ' and say to the user in one line what this command would destroy or send, with the exact command, so they can run'
        . ' it themselves if they want it.';

    /** Test seam: a callable($tool, $questions, $evidence, $timeout) returning parsed R answers, instead of the model. */
    public static $reviewer = null;

    /**
     * $results: the directives of every unit (approve ones carry 'unit', and 'local' => true from a local unit).
     * $evidence: the halt snapshot's asked entries (AskReview::evidence). $seconds: time left for a review.
     * Returns [the directives with every approve removed or turned into a block, counts for the hook log].
     */
    public static function apply(array $results, $tool, array $evidence, $seconds)
    {
        $counts = ['blocked' => 0, 'dropped' => 0, 'cleared' => 0, 'ran' => 0];
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
                if (self::route($r) === 'drop') {
                    unset($results[$i]);
                    $counts['dropped']++;
                } else {
                    $review[$i] = $r;
                }
            }
            if (!$review) {
                return [array_values($results), $counts];
            }
            $keep = $evidence && $seconds >= 2 ? self::keepAfterReview($review, $tool, $evidence, min(AskReview::TIMEOUT, $seconds)) : null;
            foreach ($review as $i => $r) {
                if ($keep === false) {
                    unset($results[$i]);
                    $counts['cleared']++;
                } elseif ($keep === true) {
                    $results[$i] = ['action' => 'block', 'message' => self::BLOCK_CONFIRM];
                    $counts['blocked']++;
                } elseif (self::route($r) === 'confirm' || in_array(self::kind($r), self::UNCHECKED_BLOCK, true)) {
                    $results[$i] = ['action' => 'block', 'message' => self::BLOCK_UNCHECKED];
                    $counts['blocked']++;
                } else {
                    unset($results[$i]);
                    $counts['ran']++;
                }
            }
        } catch (\Throwable $e) {
            foreach ($original as $i => $r) {
                if (is_array($r) && isset($r['action']) && $r['action'] === 'approve') {
                    $original[$i] = ['action' => 'block', 'message' => self::BLOCK_UNCHECKED];
                }
            }
            return [array_values($original), ['blocked' => 0, 'dropped' => 0, 'cleared' => 0, 'ran' => 0, 'error' => 1]];
        }
        return [array_values($results), $counts];
    }

    /** 'confirm' (cleared only by the user's explicit yes), 'drop' or 'review' for one approve directive. */
    public static function route(array $approve)
    {
        $unit = isset($approve['unit']) ? (string) $approve['unit'] : '';
        if (!empty($approve['local']) || in_array($unit, self::CONFIRM_UNITS, true)) {
            return 'confirm';
        }
        return in_array(self::kind($approve), self::DROP, true) ? 'drop' : 'review';
    }

    private static function kind(array $approve)
    {
        return isset($approve['kind']) ? (string) $approve['kind'] : '';
    }

    /** One review for all reviewed questions of this call: true blocks them all, false clears them, null: no answer. */
    private static function keepAfterReview(array $review, $tool, array $evidence, $timeout)
    {
        $questions = [];
        $disclose = false;
        foreach ($review as $r) {
            $questions[] = isset($r['message']) ? (string) $r['message'] : 'a Pikkumies hook asks about this call';
            $disclose = $disclose || self::route($r) === 'confirm' || in_array(self::kind($r), self::DISCLOSE, true);
        }
        $answers = self::$reviewer !== null ? call_user_func(self::$reviewer, (string) $tool, $questions, $evidence, $timeout)
            : AskReview::ask((string) $tool, $questions, $evidence, $timeout);
        return is_array($answers) && $answers ? AskReview::keep($answers, $disclose) : null;
    }

    /**
     * The asked entries of this session's halt snapshot, or [] when there is none (halt.disabled, a bad id) or when this
     * agent was started by another agent: its "user" messages were written by that agent (ADR-0020 amendment 2026-10-18).
     */
    public static function evidenceFor($sessionId)
    {
        if (!is_string($sessionId) || !preg_match('/^[A-Za-z0-9._-]{1,128}$/', $sessionId) || NestedAgent::unattended()) {
            return [];
        }
        $state = HaltState::load($sessionId);
        return is_array($state) && isset($state['asked']) && is_array($state['asked']) ? $state['asked'] : [];
    }
}
