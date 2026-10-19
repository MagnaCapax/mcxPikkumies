<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030/ADR-0047): the first half of the
 * halt guard. Writes this turn's user message and a size-cut transcript tail to
 * local/state/halt/<session>.json, so hooks/halt/halt-guard/ (pre_verify) or lib/HaltRun.php (the
 * one-shot wrapper, for a turn that never reaches pre_verify) has something to judge once the turn
 * is over. Prints nothing: this unit never adds to the prompt. Fails open on any error.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/HaltState.php';
require_once dirname(__DIR__, 3) . '/lib/HaltJudge.php';
require_once dirname(__DIR__, 3) . '/lib/AskReview.php';

const HALT_SNAPSHOT_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || empty($payload['session_id']) || !preg_match(HALT_SNAPSHOT_SESSION_RE, (string) $payload['session_id'])) {
    exit(0); // no usable session id: nothing to key the snapshot by
}
$config = Config::load();
if (!empty($config['halt']['disabled'])) {
    exit(0);
}
$extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
$messages = isset($extra['conversation_history']) && is_array($extra['conversation_history']) ? $extra['conversation_history'] : [];
$userMessage = isset($extra['user_message']) ? $extra['user_message'] : '';
$tail = HaltJudge::tail($messages, $userMessage);
// What the user wrote or saw, for the ask review (ADR-0073); empty outside a person's own session (cron, a subagent).
$platform = isset($extra['platform']) && is_string($extra['platform']) ? $extra['platform'] : '';
HaltState::writeSnapshot((string) $payload['session_id'], HaltJudge::text($userMessage), $tail, AskReview::evidence($messages, $userMessage, $platform));
