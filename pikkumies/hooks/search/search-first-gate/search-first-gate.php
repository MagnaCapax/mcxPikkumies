<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): search first. Until the agent has run the memory search
 * (`tools/memory/pikkumies/pikkumies.php search`, lessons and documentation together), tool calls
 * that act are blocked with the command to run, and so is reading or searching the downloaded documentation
 * (local/docs-cache, the wiki and kb units; ADR-0043: lessons first). Other reading, asking the user and the memory unit
 * are allowed. Partner of hooks/search/search-first/.
 *
 * State: one marker file per session under local/state/search-first/, touched by the search; the
 * pre_llm_call hook touches <marker>.armed when a later turn asks something new, and the gate stays
 * closed until a search is newer than that (files older than 7 days are removed). The agent's user
 * can write these files, so this is a safety prompt for the agent's own habits, not a security
 * boundary (AGENTS.md). Turn it off with searchFirst.disabled.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';

const SEARCH_FIRST_MAX_COMMAND = 4096; // bytes; a longer terminal command is never a plain call
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

/**
 * True when $command is one plain call of the tool at $path with one of $verbs (ADR-0038): an optional
 * `cd <dir> &&` in front, then optional `php`, the script, flags and the verb. The command is read the way
 * the shell quotes it: single-quoted text is literal and may hold anything; outside it a backslash or a
 * command substitution ($( ` ${) anywhere, the cd argument and double quotes included, is no match, and so
 * is anything chained after the call (; & | a redirect, a newline). So `echo <search command>; rm -rf x`
 * neither opens the gate nor passes as a read. One output suffix may follow, since it changes what is shown,
 * never what runs: `2>&1` or `2>/dev/null`, then or instead one pipe into `head` or `tail` (bare, `-n N` or `-N`, N at
 * least 1). A command longer than SEARCH_FIRST_MAX_COMMAND is no match.
 * The script path may start with ./, ~/pikkumies/, $HOME/pikkumies/, /home/<name>/pikkumies/ or this install's
 * directory, not any other directory.
 */
function searchFirstPlainCall($command, $path, $verbs)
{
    if (strlen($command) > SEARCH_FIRST_MAX_COMMAND) {
        return false; // a real search is short; a padded command must not cost the hook its timeout (it fails open)
    }
    $bare = '';
    $quote = '';
    for ($i = 0, $n = strlen($command); $i < $n; $i++) {
        $ch = $command[$i];
        if ($quote === "'") {
            $quote = $ch === "'" ? '' : $quote;
            $bare .= $ch === "'" ? "'" : '';
            continue;
        }
        if ($ch === '\\' || $ch === '`' || ($ch === '$' && in_array(substr($command, $i + 1, 1), ['(', '{'], true))) {
            return false;
        }
        if ($quote === '"' && $ch !== '"') {
            continue;
        }
        $quote = $quote === '"' ? '' : ($ch === "'" || $ch === '"' ? $ch : '');
        $bare .= $ch;
    }
    if ($quote !== '') {
        return false; // unterminated quote
    }
    $bare = preg_replace('#^\s*cd\s+[^\s;&|<>]+\s*&&\s*#', '', $bare, 1);
    // The output suffix, only at the end and joined by spaces or tabs only: a newline, or anything after the suffix, stays in
    // $bare and fails the check below. A line count of 0 would show nothing, so N is at least 1.
    $bare = preg_replace('#([ \t]+2>(&1|/dev/null))?([ \t]*\|[ \t]*(head|tail)([ \t]+-n[ \t]*0*[1-9][0-9]*|[ \t]+-0*[1-9][0-9]*)?)?[ \t]*$#D', '', $bare, 1);
    if (preg_match('#[;&|<>\r\n\x0B\x0C]#', $bare)) { // vertical tab and form feed: \s below would read them as spaces, the shell does not
        return false;
    }
    $dirs = '\./|(~|\$HOME|/home/[^/\s]+)/pikkumies/|' . preg_quote(Config::root() . '/', '#');
    return (bool) preg_match('#^\s*(php\s+)?(' . $dirs . ')?' . $path . '(\s+--?[a-z][a-z-]*)*\s+(' . $verbs . ')(\s|$)#', $bare);
}

// The memory unit's script, optionally followed by flags, then the verb (ADR-0018).
const SEARCH_FIRST_TOOL = 'tools/memory/pikkumies/pikkumies\.php';
if (searchFirstPlainCall($command, SEARCH_FIRST_TOOL, 'search')) {
    @mkdir($dir, 0700, true);
    touch($marker);
    foreach ((array) glob($dir . '/*') as $old) {
        if (filemtime($old) < time() - 7 * 86400) {
            @unlink($old);
        }
    }
    exit(0);
}
// Lessons before documentation (ADR-0043): reading the downloaded copies, or searching them with the wiki and kb
// units, waits for the memory search, which searches the lessons and the documentation together.
$path = isset($payload['tool_input']['path']) ? str_replace('\\', '/', (string) $payload['tool_input']['path']) : '';
$docs = (in_array($tool, ['read_file', 'search_files'], true) && preg_match('#(^|/)docs-cache(/|$)#', $path))
    || searchFirstPlainCall($command, 'tools/documentation/wiki/wiki\.php', 'search|list') || searchFirstPlainCall($command, 'tools/documentation/kb/kb\.php', 'search|list');
if (!$docs && (in_array($tool, SEARCH_FIRST_READ_ONLY, true) || searchFirstPlainCall($command, SEARCH_FIRST_TOOL, 'list|sources'))) {
    exit(0);
}
$tool = Config::root() . '/tools/memory/pikkumies/pikkumies.php';
// A memory search the gate did not count (chained, redirected into a file, piped elsewhere, another path): say so, or the agent retries the same shape.
$notCounted = preg_match('#pikkumies\.php(\s+--?[a-z][a-z-]*)*\s+search(\s|$)#', $command)
    ? "This memory search was not counted. Run it plain, on its own, with the path below: nothing may follow it but 2>&1 or 2>/dev/null first, then one | head or | tail (bare, -n N or -N).\n" : '';
echo json_encode(['action' => 'block', 'message' => $notCounted . "Pikkumies search first: " . ($docs ? 'lessons before documentation' : 'before acting')
    . ", search the lessons and documentation for this task:\n"
    . "php $tool search \"<a few technical words>\"\nRead the best hits, then continue. (Reading other files and asking the user are allowed meanwhile.)"]) . "\n";
