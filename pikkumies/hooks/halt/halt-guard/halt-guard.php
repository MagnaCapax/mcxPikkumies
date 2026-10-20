<?php
/**
 * Hook unit (pre_verify, run by hooks/orchestrator.php, ADR-0030/ADR-0047): the second half of the
 * halt guard. Reads the snapshot halt-snapshot.php wrote, this turn's command-log lines (ADR-0044)
 * and changed_paths as evidence, and the final_response from the payload, then asks HaltJudge
 * whether the turn is actually finished. A continue verdict keeps the turn going with a fixed
 * sentence; a done verdict asks once for a lesson and an idea; everything else prints nothing, which
 * lets Hermes end the turn. Stamps last_judged_turn on every run so lib/HaltRun.php (the one-shot
 * wrapper) never re-judges a turn this hook already looked at.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/HaltState.php';
require_once dirname(__DIR__, 3) . '/lib/HaltJudge.php';
require_once dirname(__DIR__, 3) . '/lib/Antipatterns.php';

const HALT_GUARD_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
const HALT_GUARD_MAX_ATTEMPT = 3; // mirrors Hermes' own agent.max_verify_nudges default
const HALT_GUARD_BUDGET = 27;     // seconds of hook.md's 30 s timeout this script may spend, so its answer is printed before the kill (H-2)
$haltGuardStart = microtime(true);

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || empty($payload['session_id']) || !preg_match(HALT_GUARD_SESSION_RE, (string) $payload['session_id'])) {
    exit(0);
}
$config = Config::load();
if (!empty($config['halt']['disabled'])) {
    exit(0);
}
$sessionId = (string) $payload['session_id'];
$state = HaltState::load($sessionId);
if ($state === null) {
    exit(0); // halt-snapshot never ran for this session (or halt.disabled was on then): nothing to judge against
}
HaltState::markJudged($sessionId, (int) $state['turn_started']);
$extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
$attempt = isset($extra['attempt']) && is_numeric($extra['attempt']) ? (int) $extra['attempt'] : 0;
if ($attempt >= HALT_GUARD_MAX_ATTEMPT || !empty($state['wrapup_sent'])) {
    exit(0); // Hermes' own nudge cap, re-checked locally, or this turn already got its one wrap-up
}
$changedPaths = isset($extra['changed_paths']) && is_array($extra['changed_paths']) ? $extra['changed_paths'] : [];
$finalResponse = isset($extra['final_response']) ? $extra['final_response'] : '';
$evidence = ['commands' => HaltState::commandsSince(Config::localDir() . '/logs/commands', $sessionId, (int) $state['turn_started']), 'changedPaths' => $changedPaths];
$verdict = HaltJudge::evaluate((string) $state['user_message'], (string) $state['tail'], $evidence, $finalResponse);
// Antipatterns (ADR-0088): a continue/wrap-up message gets the matching lines appended (no extra turn); a verdict
// with no message of its own gets this attempt's one continue instead, marked wrapup_sent like a wrap-up would be.
// H-2: the judge above may already have used most of this script's own hook.md timeout; give the check only what is
// left of the budget (capped at its own ASK_TIMEOUT), and skip it outright under 5 s so its answer, if any, still
// prints before HookSpawn's kill -- never push a slow model past the hook's own deadline and lose the judge's verdict.
$left = (int) floor(HALT_GUARD_BUDGET - (microtime(true) - $haltGuardStart));
$flags = $left >= 5 ? Antipatterns::forTurn($sessionId, $finalResponse, $evidence['commands'], 'hook', $config, null, min(Antipatterns::ASK_TIMEOUT, $left)) : [];
$applied = Antipatterns::apply($verdict, $flags);
$verdict['message'] = $applied['message'];
HaltState::logDecision($sessionId, 'hook', $attempt, $verdict, (int) $config['sessions']['retentionDays'], $attempt >= HALT_GUARD_MAX_ATTEMPT - 1); // the last continue it may send (ADR-0102)
if ($verdict['verdict'] === 'continue') {
    HaltState::bumpContinues($sessionId);
    echo json_encode(['action' => 'continue', 'message' => $verdict['message']], JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($verdict['verdict'] === 'done-wrapup') {
    HaltState::markWrapupSent($sessionId);
    echo json_encode(['action' => 'continue', 'message' => $verdict['message']], JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($applied['resume'] !== null) {
    // The attempt >= 3 / wrapup_sent check at the top of this file already stands guard: this never fires twice for one turn.
    HaltState::markWrapupSent($sessionId);
    echo json_encode(['action' => 'continue', 'message' => $applied['resume']], JSON_UNESCAPED_SLASHES) . "\n";
}
