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
require_once dirname(__DIR__, 3) . '/lib/CronAuth.php';
require_once dirname(__DIR__, 3) . '/lib/HookLog.php';

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
// What the user wrote or saw, for the ask review (ADR-0073, ADR-0101): a person's own session, or a gateway turn from a
// sender on the gateway's explicit allowlist; in a scheduled run, what the person asked when the job was made.
$platform = isset($extra['platform']) && is_string($extra['platform']) ? $extra['platform'] : '';
$sender = isset($extra['sender_id']) && is_scalar($extra['sender_id']) ? (string) $extra['sender_id'] : '';
$asked = AskReview::evidence($messages, $userMessage, $platform, $sender);
if (!$asked && $platform === 'cron') {
    $asked = CronAuth::lookup(HaltJudge::text($userMessage));
    HookLog::add('pre_llm_call', 'cron-auth', 0, $asked ? 'hit' : 'miss'); // a reason code only, never the words
}
HaltState::writeSnapshot((string) $payload['session_id'], HaltJudge::text($userMessage), $tail, $asked);
