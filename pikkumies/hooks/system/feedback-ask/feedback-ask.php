<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030): the opt-in occasional feedback line (ADR-0056,
 * amendments 2026-10-19 and 2026-10-20). Off unless feedback.ask is on; FeedbackAsk::due decides from the run's counters
 * (PIKKUMIES_RUN), FeedbackAsk::claim takes the run's one ask and the shared budget before the sentence is printed, so a
 * failed write is silence. No network, no model call, nothing sent. Fails open.
 */
require_once dirname(__DIR__, 3) . '/tools/system/feedback/FeedbackAsk.php';

$payload = json_decode((string) stream_get_contents(STDIN), true);
$now = time();
if (!is_array($payload) || !FeedbackAsk::due(Config::load(), $payload, $now)
    || !FeedbackAsk::claim($now, FeedbackAsk::runId(), FeedbackAsk::SNOOZE_ANSWERED_DAYS)) {
    exit(0);
}
echo json_encode(['context' => FeedbackAsk::SENTENCE], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
