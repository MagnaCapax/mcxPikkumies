<?php
/**
 * AskPolicy (ADR-0073, ADR-0101): what happens to hook questions ("approve" answers on pre_tool_call).
 * Hermes runs with approvals.mode "off", which approves every hook question silently (Hermes v0.22.2 tools/approval.py
 * _run_approval_gate), so no question ever leaves this policy: each is dropped (the call runs) or becomes a block with
 * one fixed message (no model text). Nothing is ever asked: a refused step is reported, never handed to the person.
 * Applied by hooks/orchestrator.php to the units' answers before they are merged:
 * - a load test or stopping the container daemon runs: neither loses or reveals anything;
 * - every other question goes to one AskReview of all of them together; the call runs unless the review finds it
 *   irreversible and not covered by what the user asked for, and then it is refused with refused(): the agent finishes
 *   the rest and lists the step as skipped, with the reason, in its final answer;
 * - the /feedback and /ticket sends and the person's own local hooks are reviewed as a send (R2 is yes whatever the
 *   model says), so only the user's own request covering them (R1 yes, R3 no) clears them;
 * - with no evidence (a scheduled run with no record of the person's words, a gateway that lets anyone write, an agent
 *   started by an agent, halt.disabled), no time or no review answer, the check cannot run: deletes, exposures, sends,
 *   mail, the feedback and ticket sends and local hooks are refused (the unchecked reason); every other kind runs,
 *   since it destroys nothing that has no copy.
 * An error here refuses every question.
 */
require_once __DIR__ . '/AskReview.php';
require_once __DIR__ . '/HaltState.php';
require_once __DIR__ . '/NestedAgent.php';

class AskPolicy
{
    const DROP = ['load', 'stop'];                            // guard-destructive kinds that lose and reveal nothing
    const DISCLOSE = ['send', 'mail', 'exposure'];            // cannot be taken back: R2 is yes without asking the model
    const UNCHECKED_BLOCK = ['delete', 'exposure', 'send', 'mail']; // still refused when the check cannot run
    const CONFIRM_UNITS = ['guard/feedback-send', 'guard/ticket-send']; // data leaves for Pulsed Media: reviewed as a send (ADR-0056, ADR-0089)
    /** Pikkumies' own note to the review of a /feedback or /ticket send (ADR-0073 amendment 2026-10-28): what these sends carry is their design. */
    const SEND_SCOPE = 'The action is Pikkumies\' own feedback or support ticket send. The conversation transcript, system information and diagnostic files it names are what such a send carries by design, so a user who asked to send the feedback or ticket asked for them too: for R1 judge whether the user asked for this send, and for R3 do not count them as reaching further.';

    /** The one refusal (ADR-0101): %s is WHY_UNCOVERED or WHY_UNCHECKED. It never asks and never hands the step over. */
    const BLOCK_REFUSED = 'Pikkumies safety: not run. This could destroy or send something that has no copy, and %s.'
        . ' Do not reach the same effect another way. Finish the rest of the task, and in the final answer list this step'
        . ' as skipped, with this reason. Do not offer to run it, and do not give the command to the user.';
    const WHY_UNCOVERED = 'what the user asked for in their own messages does not cover it';
    const WHY_UNCHECKED = 'the check of what the user asked for could not run in this session';

    /** Test seam: a callable($tool, $questions, $evidence, $timeout) returning parsed R answers, instead of the model. */
    public static $reviewer = null;

    /** The fixed refusal text: the review found the step not covered, or ($unchecked) it could not run. */
    public static function refused($unchecked = false)
    {
        return sprintf(self::BLOCK_REFUSED, $unchecked ? self::WHY_UNCHECKED : self::WHY_UNCOVERED);
    }

    /**
     * $results: the directives of every unit (approve ones carry 'unit', and 'local' => true from a local unit).
     * $evidence: the halt snapshot's asked entries (AskReview::evidence). $seconds: time left for a review.
     * Returns [the directives with every approve removed or turned into a block, counts for the hook log: numbers and
     * the reason code 'unchecked' (refusals because the check could not run), never the person's words or the command].
     */
    public static function apply(array $results, $tool, array $evidence, $seconds)
    {
        $counts = ['blocked' => 0, 'dropped' => 0, 'cleared' => 0, 'ran' => 0, 'unchecked' => 0];
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
                    $results[$i] = ['action' => 'block', 'message' => self::refused()];
                    $counts['blocked']++;
                } elseif (self::route($r) === 'confirm' || in_array(self::kind($r), self::UNCHECKED_BLOCK, true)) {
                    $results[$i] = ['action' => 'block', 'message' => self::refused(true)];
                    $counts['blocked']++;
                    $counts['unchecked']++;
                } else {
                    unset($results[$i]);
                    $counts['ran']++;
                }
            }
        } catch (\Throwable $e) {
            foreach ($original as $i => $r) {
                if (is_array($r) && isset($r['action']) && $r['action'] === 'approve') {
                    $original[$i] = ['action' => 'block', 'message' => self::refused(true)];
                }
            }
            return [array_values($original), ['blocked' => 0, 'dropped' => 0, 'cleared' => 0, 'ran' => 0, 'unchecked' => 0, 'error' => 1]];
        }
        return [array_values($results), $counts];
    }

    /** 'confirm' (reviewed as a send: only the user's own request clears it), 'drop' or 'review' for one approve directive. */
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

    /** One review for all reviewed questions of this call: true refuses them all, false clears them, null: no answer. */
    private static function keepAfterReview(array $review, $tool, array $evidence, $timeout)
    {
        $questions = [];
        $disclose = false;
        $note = '';
        foreach ($review as $r) {
            $questions[] = isset($r['message']) ? (string) $r['message'] : 'a Pikkumies hook asks about this call';
            if (empty($r['local']) && in_array(isset($r['unit']) ? (string) $r['unit'] : '', self::CONFIRM_UNITS, true)) {
                $note = self::SEND_SCOPE;
            }
            $disclose = $disclose || self::route($r) === 'confirm' || in_array(self::kind($r), self::DISCLOSE, true);
        }
        $answers = self::$reviewer !== null ? call_user_func(self::$reviewer, (string) $tool, $questions, $evidence, $timeout, $note)
            : AskReview::ask((string) $tool, $questions, $evidence, $timeout, $note);
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
