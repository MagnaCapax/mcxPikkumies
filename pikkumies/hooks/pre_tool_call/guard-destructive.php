<?php
/**
 * Hermes shell hook (pre_tool_call): blocks obviously destructive terminal commands and tells
 * the agent to ask the user first. Reads the hook JSON on stdin, prints a JSON decision on stdout.
 * A pre_tool_call shell hook can only block: any other action is ignored and the call runs
 * (agent/shell_hooks.py _parse_response, Hermes 0.19.0). The user can run the command themselves.
 *
 * This is a safety prompt for the agent's own mistakes, NOT a security boundary: the user
 * owns this file and Hermes' config. Real limits come from the OS (permissions, no sudo).
 */
$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload) || !isset($payload['tool_name']) || $payload['tool_name'] !== 'terminal') {
    exit(0); // no opinion
}
$command = isset($payload['tool_input']['command']) ? (string) $payload['tool_input']['command'] : '';

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
    if (preg_match($re, $command)) {
        echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why. Not run. Show the user the exact command and ask them to run it themselves if they want it."]) . "\n";
        exit(0);
    }
}
exit(0);
