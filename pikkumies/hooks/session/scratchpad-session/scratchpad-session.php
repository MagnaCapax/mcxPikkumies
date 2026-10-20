<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030/ADR-0090): records the live
 * session id to local/state/scratchpad/current, the one place a session id crosses into a tool call
 * (tools carry none of their own; lib/HaltRun.php, lib/HookFeed.php and hooks/orchestrator.php are the
 * only places that have one). Prints nothing. Fails open on any error.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Scratchpad.php';

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (is_array($payload) && !empty($payload['session_id'])) {
    Scratchpad::recordCurrent($payload['session_id']);
}
