<?php
/**
 * Hermes shell hook (pre_tool_call): blocks obviously destructive terminal commands and tells
 * the agent to ask the user first; data deletes the user may want (backup prunes, torrent data)
 * go to Hermes' human-approval gate instead (ADR-0020). Reads the hook JSON on stdin, prints a
 * JSON decision on stdout (agent/shell_hooks.py _parse_pre_tool_call).
 *
 * This is a safety prompt for the agent's own mistakes, NOT a security boundary: the user
 * owns this file and Hermes' config. Real limits come from the OS (permissions, no sudo).
 */
$payload = json_decode((string) stream_get_contents(STDIN), true);
// The terminal command, or the code of execute_code (which can run the same programs).
$tool = is_array($payload) && isset($payload['tool_name']) ? $payload['tool_name'] : '';
$field = $tool === 'terminal' ? 'command' : ($tool === 'execute_code' ? 'code' : '');
if ($field === '') {
    exit(0); // no opinion
}
$command = isset($payload['tool_input'][$field]) ? (string) $payload['tool_input'][$field] : '';

$patterns = [
    '/\brm\s+(-[a-z]*r[a-z]*f|-[a-z]*f[a-z]*r)\b/i' => 'recursive force delete',
    '/\bmkfs(\.|\s)/'                              => 'filesystem format',
    '/\bdd\s+[^|]*\bof=\/dev\//'                    => 'raw write to a device',
    '/\b(fdisk|parted|sgdisk|wipefs)\b/'            => 'partition or signature change',
    '/\bchmod\s+(-R\s+)?777\b/'                     => 'world-writable permissions',
    '/:\(\)\s*\{\s*:\|:&\s*\};:/'                   => 'fork bomb',
    '/\bgit\s+push\s+.*--force\b/'                  => 'force push',
];
foreach ($patterns as $re => $why) {
    $hit = preg_match($re, $command);
    if ($hit === false) {
        $why = 'a command too long or complex to check'; // a regex engine error must not let the call through
    }
    if ($hit !== 0) {
        echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why. Not run. Show the user the exact command and ask them to run it themselves if they want it."]) . "\n";
        exit(0);
    }
}

// Deletes the user can ask for, but must confirm (ADR-0020): Hermes asks the user in the chat and
// the model cannot answer for them; cron and other unattended runs are refused (approvals.*_mode).
// rule_key names this exact command: Hermes keys "session"/"always" answers by it and would
// otherwise use the tool name, approving every later delete (hermes_cli/plugins.py).
// Patterns read the whole command (.* and /s): a false match only asks the user, a missed one
// deletes without asking, so nothing may stop at a quote, ";" or "&" inside an argument.
// V: the separator before a subcommand, also in quoted or list form (["rclone", "purge"]).
$v = '[\s"\',\[(]+';
$lh = '(127\.[0-9.]+|localhost|0\.0\.0\.0|\[::1?\])';
$approve = [
    "/\\brclone\\b.*$v(sync|bisync|move|moveto|purge|delete|deletefile|rmdirs?|cleanup|dedupe)\\b/s" => 'rclone can delete or overwrite files',
    "/\\brestic\\b.*$v(forget|prune)\\b/s"                                                  => 'restic removes backup snapshots',
    "/\\bborg\\b.*$v(prune|delete|compact|recreate)\\b/s"                                    => 'borg removes backup archives',
    "/\\bkopia\\b.*{$v}snapshot$v(expire|delete)\\b/s"                                       => 'kopia removes backup snapshots',
    "/\\bduplicacy\\b.*{$v}prune\\b/s"                                                       => 'duplicacy removes backup revisions',
    "#{$lh}[^\\s]*/(api/v2/torrents/delete|[^\\s]*deletefiles=true)#si"                         => 'deletes torrent or media files through a local app',
    "/^(?=.*\\bcurl\\b)(?=.*(-X|--request)\\s*[\"']?DELETE\\b)(?=.*$lh)/si"                  => 'deletes through a local app',
    "#\\.delete\\(\\s*[\"']https?://$lh#i"                                                   => 'deletes through a local app',
    '/delete[-_]local[-_]data/i'                                                              => 'deletes torrent data through a local app',
    '/\.php["\']?\s+.*--(with-data|delete-files)\b/s'                                         => 'deletes data through a Pikkumies tool',
    '#tools/[a-z0-9-]+/[a-z0-9-]+/[a-z0-9-]+\.php["\']?(\s+-\S+)*\s+(prune|forget|purge)\b#'  => 'removes data through a Pikkumies tool',
];
foreach ($approve as $re => $why) {
    $hit = preg_match($re, $command);
    if ($hit === false) {
        $why = 'a command too long or complex to check'; // fail toward asking the user, never allow
    }
    if ($hit !== 0) {
        // The user must see everything they approve: a command too long to show in full is refused.
        if (strlen($command) > 4000) {
            echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why, and the command is too long to show the user in full. Not run. Split it into smaller commands."]) . "\n";
            exit(0);
        }
        $id = substr(sha1($command), 0, 16);
        echo json_encode(['action' => 'approve', 'rule_key' => "pikkumies-delete:$id", 'message' => "Pikkumies: $why, which cannot be undone. Command [$id]: " . $command]) . "\n";
        exit(0);
    }
}
exit(0);
