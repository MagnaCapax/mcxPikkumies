<?php
/**
 * Hook unit (post_api_request, run by hooks/orchestrator.php, ADR-0030): the context the last model request used and the
 * model's window, for the tmux pane in TMUX_PANE (lib/SessionCounters.php, ADR-0061). Hermes puts them under `extra` for a
 * shell hook (Hermes v0.22.2 agent/shell_hooks.py _payload_fields; agent/turn_response_intake.py
 * _fire_post_api_request_hook: usage.prompt_tokens, context_length, platform). A delegated agent's request (platform
 * "subagent", tools/delegate_tool.py) is left out, so the figure stays the main agent's. Prints nothing; Hermes ignores
 * the answer. A failure to write never fails the request.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/SessionCounters.php';

/** ['ctx' => used tokens, 'ctxMax' => window or 0] from a post_api_request payload, or null when it says nothing usable. */
function sessionContextOf(array $p)
{
    $x = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $u = isset($x['usage']) && is_array($x['usage']) ? $x['usage'] : [];
    if ((isset($x['platform']) ? (string) $x['platform'] : '') === 'subagent' || !isset($u['prompt_tokens']) || !is_numeric($u['prompt_tokens'])) {
        return null;
    }
    return ['ctx' => (int) $u['prompt_tokens'], 'ctxMax' => isset($x['context_length']) && is_numeric($x['context_length']) ? (int) $x['context_length'] : 0];
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $pane = (string) getenv('TMUX_PANE');
    if (preg_match(SessionCounters::PANE_RE, $pane)) { // outside tmux: nothing to read or write
        $payload = json_decode((string) stream_get_contents(STDIN), true);
        $ctx = sessionContextOf(is_array($payload) ? $payload : []);
        if ($ctx !== null) {
            SessionCounters::update(Config::localDir(), $pane, [], $ctx);
        }
    }
}
