<?php
/**
 * Hermes shell hook (pre_tool_call): search first. Until the agent has run
 * `docs.php search` in this session (it searches lessons and documentation together), tool calls
 * that act are blocked with the command to run. Reading, asking the user and the search tools
 * themselves are allowed. Partner of hooks/pre_llm_call/search-first.php.
 *
 * State: one marker file per session under local/state/search-first/ (markers older than 7 days
 * are removed). The agent's user can write that file, so this is a safety prompt for the agent's
 * own habits, not a security boundary (AGENTS.md). Turn it off with searchFirst.disabled.
 */
require_once dirname(__DIR__, 2) . '/lib/Config.php';

const SEARCH_FIRST_READ_ONLY = ['read_file', 'search_files', 'skill_view', 'skills_list', 'memory', 'clarify', 'todo', 'todo_list', 'session_search', 'read_terminal'];

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || empty($payload['tool_name']) || empty($payload['session_id'])) {
    exit(0); // no opinion
}
$config = Config::load();
if (!empty($config['searchFirst']['disabled'])) {
    exit(0);
}
$dir = Config::localDir() . '/state/search-first';
$marker = $dir . '/' . sha1((string) $payload['session_id']);
if (is_file($marker)) {
    exit(0);
}
$tool = (string) $payload['tool_name'];
$command = $tool === 'terminal' && isset($payload['tool_input']['command']) ? (string) $payload['tool_input']['command'] : '';

if (preg_match('#tools/docs/docs\.php["\']?\s+search\b#', $command)) {
    @mkdir($dir, 0700, true);
    touch($marker);
    foreach ((array) glob($dir . '/*') as $old) {
        if (filemtime($old) < time() - 7 * 86400) {
            @unlink($old);
        }
    }
    exit(0);
}
if (in_array($tool, SEARCH_FIRST_READ_ONLY, true) || preg_match('#tools/(docs/docs|memory/memory)\.php["\']?\s+(search|read|sources)\b#', $command)) {
    exit(0);
}
$docs = Config::root() . '/tools/docs/docs.php';
echo json_encode(['action' => 'block', 'message' => "Pikkumies search first: before acting, search the lessons and documentation for this task:\n"
    . "php $docs search \"<a few technical words>\"\nRead the best hits, then continue. (Reading files and asking the user are allowed meanwhile.)"]) . "\n";
