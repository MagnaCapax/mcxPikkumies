<?php
/**
 * Hook unit (post_api_request, run by hooks/orchestrator.php, ADR-0030): the context the last model request used and the
 * model's window, for the tmux pane in TMUX_PANE (lib/SessionCounters.php, ADR-0061). Hermes puts them under `extra` for a
 * shell hook (Hermes v0.22.2 agent/shell_hooks.py _payload_fields; agent/turn_response_intake.py
 * _fire_post_api_request_hook: usage.prompt_tokens, context_length, platform). A delegated agent's request (platform
 * "subagent", tools/delegate_tool.py) is left out of the pane, so that figure stays the main agent's.
 * Every request, a delegated agent's too, also adds its tokens and one request to the run file of PIKKUMIES_RUN, the key an
 * interactive start exports (ADR-0096). Prints nothing; Hermes ignores the answer. A failure to write never fails the request.
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
    $run = (string) getenv('PIKKUMIES_RUN');
    $inPane = preg_match(SessionCounters::PANE_RE, $pane) === 1;
    $inRun = preg_match(SessionCounters::RUN_RE, $run) === 1;
    if ($inPane || $inRun) { // outside tmux and without a run key: nothing to read or write
        $payload = json_decode((string) stream_get_contents(STDIN), true);
        $payload = is_array($payload) ? $payload : [];
        $ctx = $inPane ? sessionContextOf($payload) : null;
        if ($ctx !== null) {
            SessionCounters::update(Config::localDir(), $pane, [], $ctx);
        }
        $tokens = $inRun ? SessionCounters::requestTokens($payload) : null;
        if ($tokens !== null) {
            SessionCounters::updateRun(Config::localDir(), $run, ['tokens' => $tokens, 'reqs' => 1]);
        }
    }
}
