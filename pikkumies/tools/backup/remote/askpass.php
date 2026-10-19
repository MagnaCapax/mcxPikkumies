#!/usr/bin/env php
<?php
// SSH_ASKPASS helper of `backup remote setup` (ADR-0049). ssh runs it with its prompt as the one argument; it prints the
// one-time remote login from PIKKUMIES_REMOTE_PASSWORD, which setup puts only into that ssh process's environment
// (never an argv, never a file). It answers only a login prompt: a host-key question or a key passphrase gets nothing.
$prompt = isset($argv[1]) ? (string) $argv[1] : '';
$secret = getenv('PIKKUMIES_REMOTE_PASSWORD');
if ($secret === false || $secret === '' || !preg_match('/password/i', $prompt) || preg_match('/passphrase|yes\/no|fingerprint/i', $prompt)) {
    exit(1);
}
echo $secret, "\n";
