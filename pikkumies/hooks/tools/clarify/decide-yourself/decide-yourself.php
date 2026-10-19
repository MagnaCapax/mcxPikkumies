<?php
/**
 * Tool hook (pre_tool_call, clarify only; ADR-0054, ADR-0073): the agent does not ask the user to decide. Every clarify
 * call is blocked with one fixed message; nothing from the call is echoed back.
 */
$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || !isset($payload['tool_name']) || $payload['tool_name'] !== 'clarify') {
    exit(0); // not a clarify call: no opinion
}
echo json_encode(['action' => 'block', 'message' => 'Pikkumies: do not ask the user to decide. Pick the option that fits what the user asked and what you found, do the work, '
    . 'and say in the answer which choice you made. When only the user can give a fact (a name, an address, or a login they type in their own terminal), finish everything else first and say in the answer exactly what is missing.']) . "\n";
exit(0);
