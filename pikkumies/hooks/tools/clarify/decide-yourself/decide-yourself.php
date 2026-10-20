<?php
/**
 * Tool hook (pre_tool_call, clarify only; ADR-0054, ADR-0073, ADR-0102): the agent does not ask the user to decide. Every
 * clarify call is blocked with one fixed message that ends with the method sentence (investigate, attack the plan,
 * iterate, do it); nothing from the call is echoed back. The block goes to the deferral log as one fixed entry (class
 * asked-user-to-decide), never the question or the choices; off with halt.disabled like the rest of that log.
 */
require_once dirname(__DIR__, 4) . '/lib/Config.php';
require_once dirname(__DIR__, 4) . '/lib/HaltState.php';
require_once dirname(__DIR__, 4) . '/lib/HaltJudge.php';

const DECIDE_YOURSELF_NOTE = 'The agent tried to ask the user to choose between options it could weigh itself from the request and what it found.';

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || !isset($payload['tool_name']) || $payload['tool_name'] !== 'clarify') {
    exit(0); // not a clarify call: no opinion
}
echo json_encode(['action' => 'block', 'message' => 'Pikkumies: do not ask the user to decide. Pick the option that fits what the user asked and what you found, do the work, '
    . 'and say in the answer which choice you made. When only the user can give a fact (a name, an address, or a login they type in their own terminal), finish everything else first and say in the answer exactly what is missing. '
    . HaltJudge::METHOD]) . "\n";
$session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
try {
    $config = Config::load();
    if ($session !== '' && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $session) && empty($config['halt']['disabled'])) {
        HaltState::logDecision($session, 'clarify', 0, ['verdict' => 'blocked', 'answers' => [],
            'deferral' => [['class' => 'asked-user-to-decide', 'slug' => 'asking-what-you-can-check', 'note' => DECIDE_YOURSELF_NOTE]]],
            isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0);
    }
} catch (\Throwable $e) {
    // the block above is the answer; a log that cannot be written changes nothing
}
exit(0);
