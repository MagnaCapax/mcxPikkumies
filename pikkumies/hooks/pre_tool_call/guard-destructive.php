<?php
/**
 * Hermes shell hook (pre_tool_call): asks for approval before obviously destructive
 * terminal commands. Reads the hook JSON on stdin, prints a JSON decision on stdout.
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
        // Hermes-canonical escalation to the human-approval gate (hooks.md, shell hook stdout schema).
        echo json_encode(['action' => 'approve', 'message' => "Pikkumies safety: $why. Confirm before this runs.", 'rule_key' => 'pikkumies:destructive']) . "\n";
        exit(0);
    }
}
exit(0);
