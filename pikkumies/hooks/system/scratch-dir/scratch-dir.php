<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030, ADR-0093). Every turn it looks up this session's
 * scratch folder (lib/Scratch.php: /tmp/pk-<uid>/<32 hex>, 0700 inside a 0700 parent, one per session) and says nothing while it is there.
 * When the folder is new (the session's first turn, or the old one is gone, as after a reboot empties a tmpfs /tmp) it:
 * - tells the agent the path in one line;
 * - removes this account's scratch folders untouched for Scratch::KEEP_DAYS, logging only the counts (lib/HookLog.php);
 * - lists what git sees in the Pikkumies tree as neither tracked nor ignored (lib/TreeLitter.php), the leftovers the
 *   litter-check hook could not see, and never deletes them.
 * Fails open; a prompt, not a limit (AGENTS.md).
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';
require_once dirname(__DIR__, 3) . '/lib/HookLog.php';
require_once dirname(__DIR__, 3) . '/lib/Scratch.php';
require_once dirname(__DIR__, 3) . '/lib/TreeLitter.php';

/** The answer for a pre_llm_call payload, or null. $root: the runtime directory (pikkumies/); $localDir its local/. */
function scratchDirAnswer(array $payload, $root, $localDir)
{
    $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
    list($path, $new) = Scratch::forSession($localDir, $session);
    if (!$new) {
        return null;
    }
    list($removed, $failed) = Scratch::prune($localDir);
    if ($removed + $failed > 0) {
        HookLog::add('pre_llm_call', 'system/scratch-dir', 0, 'gc ' . json_encode(['removed' => $removed, 'failed' => $failed]));
    }
    $say = "Scratch folder for this session: $path (private; removed after " . Scratch::KEEP_DAYS . ' days unused). Temporary files go there,'
        . ' never in a working directory or under ~/pikkumies; small files only: downloads, archives, builds and anything large go in your home.';
    $stray = TreeLitter::stray($root);
    if ($stray) {
        $say .= "\n" . TreeLitter::line($stray, TreeLitter::repo($root)) . '. If you made them, move them out; never delete what you did not make.';
    }
    return ['context' => $say];
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    $answer = is_array($payload) ? scratchDirAnswer($payload, Config::root(), Config::localDir()) : null;
    echo $answer === null ? '' : json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}
