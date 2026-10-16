<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): search first. Until the agent has run the memory search
 * (`tools/memory/pikkumies/pikkumies.php search`, lessons and documentation together), tool calls
 * that act are blocked with the command to run. Reading, asking the user and the search tools
 * themselves are allowed. Partner of hooks/search/search-first/.
 *
 * State: one marker file per session under local/state/search-first/, touched by the search; the
 * pre_llm_call hook touches <marker>.armed when a later turn asks something new, and the gate stays
 * closed until a search is newer than that (files older than 7 days are removed). The agent's user
 * can write these files, so this is a safety prompt for the agent's own habits, not a security
 * boundary (AGENTS.md). Turn it off with searchFirst.disabled.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';

const SEARCH_FIRST_READ_ONLY = ['read_file', 'search_files', 'skill_view', 'skills_list', 'memory', 'clarify', 'todo', 'todo_list', 'session_search', 'read_terminal'];

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || empty($payload['tool_name']) || empty($payload['session_id'])) {
    exit(0); // no opinion
}
$config = Config::load();
if (!empty($config['searchFirst']['disabled'])) {
    exit(0);
}
$tool = (string) $payload['tool_name'];
// Web research is the last tier (ADR-0031): blocked until this turn's search covered tiers 1-3.
// Checked before the "already searched" exit, which would otherwise let the web tools through.
require_once dirname(__DIR__, 3) . '/lib/DocSources.php';
if (in_array($tool, DocSources::WEB_TOOLS, true) && !DocSources::webResearchAllowed((string) $payload['session_id'])) {
    echo json_encode(['action' => 'block', 'message' => DocSources::webGateMessage()]) . "\n";
    exit(0);
}
$dir = Config::localDir() . '/state/search-first';
$marker = $dir . '/' . sha1((string) $payload['session_id']);
// Open once searched in this session, unless the pre_llm_call hook re-armed it for a new question since.
$armed = $marker . '.armed';
if (is_file($marker) && (!is_file($armed) || filemtime($marker) >= filemtime($armed))) {
    exit(0);
}
$command = $tool === 'terminal' && isset($payload['tool_input']['command']) ? (string) $payload['tool_input']['command'] : '';

// The memory unit's script, optionally followed by flags, then the verb (ADR-0018).
const SEARCH_FIRST_TOOL = '#tools/memory/pikkumies/pikkumies\.php["\']?(\s+--?[a-z][a-z-]*)*\s+';
if (preg_match(SEARCH_FIRST_TOOL . 'search\b#', $command)) {
    @mkdir($dir, 0700, true);
    touch($marker);
    foreach ((array) glob($dir . '/*') as $old) {
        if (filemtime($old) < time() - 7 * 86400) {
            @unlink($old);
        }
    }
    exit(0);
}
if (in_array($tool, SEARCH_FIRST_READ_ONLY, true) || preg_match(SEARCH_FIRST_TOOL . '(list|sources)\b#', $command)) {
    exit(0);
}
$tool = Config::root() . '/tools/memory/pikkumies/pikkumies.php';
echo json_encode(['action' => 'block', 'message' => "Pikkumies search first: before acting, search the lessons and documentation for this task:\n"
    . "php $tool search \"<a few technical words>\"\nRead the best hits, then continue. (Reading files and asking the user are allowed meanwhile.)"]) . "\n";
