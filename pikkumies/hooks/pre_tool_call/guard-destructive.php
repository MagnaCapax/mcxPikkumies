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
    '#local/secrets\b#'                             => 'a command on stored passwords (local/secrets/); what the agent reads goes to the model',
    '/\brclone\b.*\bconfig\s+(show|dump)\b/s' => 'rclone config show/dump prints cloud credentials, and what the agent reads goes to the model',
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
    "/^(?=.*\\bcurl\\b)(?=.*(-X|--request)[\\s=]*[\"']?DELETE\\b)(?=.*$lh)/si"                => 'deletes through a local app',
    "#\\.delete\\(\\s*[fbru]*[\"']https?://$lh#i"                                              => 'deletes through a local app',
    "#\\.request\\(\\s*[\"']DELETE[\"']\\s*,\\s*[fbru]*[\"']https?://$lh#i"                        => 'deletes through a local app',
    // *arr items deleted by HTTP from anywhere (also the public proxy path): library items and their files.
    '#^(?=.*(-X|--request|\\.delete\\(|["\']DELETE["\']))(?=.*/api/v[0-9]+/(queue|movie|series|artist|author|book|episodefile|moviefile|trackfile|bookfile)\\b)#si' => 'deletes media or downloads through an *arr app',
    '#["\']name["\']\\s*:\\s*["\'](DeleteSeriesFiles|ResetApiKey|ApplicationUpdate)["\']#i'      => 'runs an *arr command that deletes files, resets the API key or replaces the app',
    // Media servers from anywhere (also the public proxy path): Jellyfin/Emby DELETE /Items[/{id}] deletes "from the
    // library and filesystem" (Jellyfin 12.2 and Emby 4.1 specs), Plex DELETE /library/metadata/{id}; /Auth/Keys/{key} revokes a key.
    '#^(?=.*((-X|--request)[\\s=]*["\']?DELETE\\b|\\.delete\\(|["\']DELETE["\']))(?=.*/(Items|library/metadata|Auth/Keys)(/|\\?|\\b))#si' => 'deletes media files or an API key through a media server',
    '/delete[-_]local[-_]data/i'                                                              => 'deletes torrent data through a local app',
    // The tool convention anywhere in the command: `php $Q remove <id> --with-data` names no .php file.
    '/(^|[\s"\',\[(])--(with-data|delete-files)\b/'                                          => 'deletes data through a Pikkumies tool',
    "/\\bphp\\b.*{$v}(prune|forget|purge)\\b/s"                                                => 'removes data through a Pikkumies tool',
    '/\bcore\.remove_torrents?\b/'                                                            => 'removes torrents, and with remove_data their files, through Deluge',
    '/\bexecute[a-z0-9.]*\b.*\brm\b/s'                                                         => 'runs rm through rTorrent',
    // Plain file deletes: Hermes' own list catches only rm -r and rm /, and its default "smart" mode lets a
    // second model approve them; the user decides about their files (ADR-0020). rm -rf stays blocked above.
    '/(^|[\s;&|(`"\'])(rm|unlink|shred|truncate)\s/'                                           => 'deletes or empties files',
    '/\bfind\b.*\s-(delete|exec(dir)?\s+(\S*\/)?(rm|unlink|shred)\b)/s'                        => 'deletes the files find matches',
    '/\bos\.(remove|unlink|rmdir|removedirs)\(|\bshutil\.rmtree\(|\.unlink\(|\.rmdir\(/'          => 'deletes files from code',
    // rclone's remote-control API (the WebUI's rcd runs for every rclone-enabled PMSS account) and its HTTP form.
    '#\brclone\b.*\brc\b.*\boperations/(purge|delete|deletefile|rmdirs?|cleanup)\b#s'          => 'rclone deletes files through its remote control',
    "#{$lh}[^\\s]*/(operations/(purge|delete|deletefile|rmdirs?|cleanup)|sync/(sync|bisync|move))\\b#" => 'rclone deletes or overwrites files through its remote control',
    '/\bborgmatic\b(?!.*\s(list|info|check|rlist|rinfo|--help|--version)\b)/s'                 => 'borgmatic runs prune and compact by default',
    "/\\brestic\\b.*{$v}(key{$v}remove|repair{$v}snapshots.*--forget|restore)\\b/s"            => 'restic removes a key, rewrites snapshots, or restores over existing files',
    "/\\bborg\\b.*{$v}(check.*--repair|extract)\\b/s"                                      => 'borg repairs the repository or extracts over existing files',
    "/\\bkopia\\b.*{$v}(snapshot{$v}restore|restore)\\b|\\bduplicacy\\b.*-overwrite\\b/s"   => 'a restore that can overwrite existing files',
    '/\b(rsync|lftp)\b.*--(delete[a-z-]*|remove-source-files|Remove-source-files)\b/s'          => 'deletes files on one side of a transfer',
    '/\brclone\b.*\bserve\b(?!.*--addr[=\s]+["\']?(127\.|localhost|\[::1\]|unix:))/s'         => 'rclone serve without a loopback --addr publishes files to the network',
    '/(^|[\s"\',\[(])--replace\b/'                                                             => 'replaces existing files through a Pikkumies tool',
    // Re-running the media installer can delete an app's settings and users (PMSS install-media-stack.sh
    // asks on stdin, which a pipe can answer); stopping or starting one app is safe.
    '/install-media-stack\.sh(?![^;&|\n]*--(stop-app|start-app|help)\b)/' => 'runs the media installer, which can delete app settings and data',
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
