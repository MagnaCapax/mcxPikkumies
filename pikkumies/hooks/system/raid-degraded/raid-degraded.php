<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030): when root is available and /proc/mdstat shows an
 * array degraded, rebuilding or inactive, the turn gets one sentence naming it and the mdraid status verb (ADR-0095).
 * Once per session per state change. Reads one file; never runs a program. Fails open; a prompt, not a limit.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/tools/server-admin/mdraid/MdRun.php';
require_once dirname(__DIR__, 3) . '/tools/server-admin/mdraid/MdState.php';

const RAID_DEGRADED_MARKER_DAYS = 7;

$payload = json_decode((string) stream_get_contents(STDIN), true);
$session = is_array($payload) && isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $session)) {
    exit(0);
}
$config = Config::load();
$host = json_decode((string) @file_get_contents(Config::localDir() . '/host.json'), true);
if (!empty($config['root']['disabled']) || !is_array($host) || empty($host['root']['available'])) {
    exit(0); // the agent cannot act on an array without root; a missing cache is silence, never a sudo probe
}
$problems = [];
foreach (MdState::parseMdstat((string) MdRun::read('/proc/mdstat')) as $md => $a) {
    if (!$a['active']) {
        $problems[] = "$md is inactive";
    } elseif ($a['action'] === 'recovery') {
        $problems[] = "$md is rebuilding (" . $a['progress'] . '%)';
    } elseif (strpos($a['map'], '_') !== false) {
        $problems[] = "$md is degraded ([{$a['map']}])";
    }
}
$state = implode('; ', $problems);
$dir = Config::localDir() . '/state/raid-degraded';
if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) {
    exit(0);
}
$marker = "$dir/" . sha1($session);
$told = is_file($marker) ? (string) @file_get_contents($marker) : '';
if ($state === $told) {
    exit(0);
}
@file_put_contents($marker, $state);
foreach ((array) glob("$dir/*") as $old) {
    if (@filemtime($old) < time() - RAID_DEGRADED_MARKER_DAYS * 86400) {
        @unlink($old);
    }
}
if ($state !== '') {
    echo json_encode(['context' => "Software RAID needs attention: $state. Run mdraid status (server-admin/mdraid) before touching those disks, and follow doctrine/SOP/recover-a-degraded-or-failed-array.md."],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
