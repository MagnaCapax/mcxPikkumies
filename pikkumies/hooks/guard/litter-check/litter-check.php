<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030, ADR-0093): the agent never creates a file or folder in
 * the Pikkumies tree (the clone, ~/pikkumies included) outside local/ and memory/. A terminal command (LitterTargets.php)
 * or a write_file or patch call (its path, and every file a V4A patch names, lib/PatchPaths.php) whose target does not
 * exist yet and lands there, by its real path (lib/TreeLitter.php: every symlink on the way followed), is blocked; the
 * message names this session's scratch folder (lib/Scratch.php). A folder whose new names are unknown (a glob, an
 * extracted archive) counts as soon as the folder itself lands there. Overwriting a file that exists is not this hook's
 * business (config-change and guard-destructive look at those). Fails closed (hook.md). A safety prompt, not security
 * (AGENTS.md): what it cannot see is listed in LitterTargets.php, and the scratch-dir hook's tree check reports it.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/TreeLitter.php';
require_once dirname(__DIR__, 3) . '/lib/Scratch.php';
require_once dirname(__DIR__, 3) . '/lib/PatchPaths.php';
require_once __DIR__ . '/LitterTargets.php';

/** The answer for a pre_tool_call payload, or null. $root: the runtime directory (pikkumies/); $localDir holds the scratch records. */
function litterCheckAnswer(array $payload, $root, $localDir, $home)
{
    $tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
    $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
    $repo = TreeLitter::repo($root);
    if ($repo === '') {
        return null;
    }
    if ($tool === 'write_file' || $tool === 'patch') {
        // Hermes reads a relative file-tool path from where it runs (the payload's cwd), as GuardLocal.php does.
        $base = isset($payload['cwd']) && is_string($payload['cwd']) ? $payload['cwd'] : '';
        $named = isset($input['patch']) && is_scalar($input['patch']) ? PatchPaths::v4a((string) $input['patch']) : [];
        if ($named === null) {
            return ['action' => 'block', 'message' => 'Pikkumies safety: a patch too long or complex to check. Not run.'];
        }
        $entries = [];
        foreach (array_merge(isset($input['path']) && is_scalar($input['path']) ? [(string) $input['path']] : [], $named) as $p) {
            $p = substr($p, 0, 2) === '~/' ? $home . substr($p, 1) : $p;
            $entries[] = [$p, false];
        }
    } elseif ($tool === 'terminal') {
        // A relative path is read from the call's workdir (as fileops-check and publish-check do), never from Hermes' own cwd.
        $base = isset($input['workdir']) && is_string($input['workdir']) ? $input['workdir'] : '';
        $entries = LitterTargets::read(isset($input['command']) && is_string($input['command']) ? $input['command'] : '', $base, $home);
        if ($entries === null) {
            return null; // the shell cannot run it either (an unclosed quote)
        }
    } else {
        return null;
    }
    $hits = [];
    foreach ($entries as $e) {
        $at = TreeLitter::resolve($e[0], $base);
        if ($at !== null && ($e[1] || !$at[1]) && TreeLitter::guarded($at[0], $repo)) {
            $hits[] = Config::shown($at[0]) . ($e[1] ? '/...' : '');
        }
    }
    if (!$hits) {
        return null;
    }
    $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
    $scratch = Scratch::forSession($localDir, $session)[0];
    return ['action' => 'block', 'message' => 'Pikkumies tidy check: not run. ' . implode(', ', array_slice(array_unique($hits), 0, 3))
        . ' would be new in the Pikkumies tree, which holds only Pikkumies and its state in local/ and memory/; updates and the'
        . ' installer write the rest. Small temporary files go to ' . ($scratch !== '' ? "this session's scratch folder $scratch" : 'a private folder made with mktemp -d')
        . '; large ones (downloads, archives, builds) and files for the user go in their own folders. This refusal is the answer: write there instead, do not work around it.'];
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    $answer = is_array($payload) ? litterCheckAnswer($payload, Config::root(), Config::localDir(), rtrim((string) getenv('HOME'), '/'))
        : ['action' => 'block', 'message' => 'Pikkumies safety: the tool call could not be read to check it. Not run; tell the user this safety check is failing.'];
    echo $answer === null ? '' : json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}
