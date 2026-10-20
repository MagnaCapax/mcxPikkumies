<?php
/**
 * The delete guard's backup check (ADR-0099), run for every rule of kind delete before its question. It reads only the
 * cache backup-safety writes (lib/BackupStatus.php) and never runs a backup tool. Holds the agent once (block), never asks
 * the user:
 * - a removal (rm, unlink, shred, truncate, `: >`, cp /dev/null, package remove; lib/BackupHold.php) while no backup is
 *   known to work: copy first with `backup-safety snap`, then run it again;
 * - a dangerous delete (rm -r, package remove or purge, and every other delete rule: prunes, rclone purge, git clean...):
 *   show the user the table of the 10 latest backups first, whatever the state.
 * The identical command in the same session within 30 minutes goes on, and so does a removal whose every target was
 * snapped in the last 30 minutes. No session id, or a marker that cannot be written: no hold (the question still comes).
 * Removing only files in /tmp, /var/tmp or /dev/shm needs no copy. A safety prompt for the agent, not security (AGENTS.md).
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/HomePath.php';
require_once dirname(__DIR__, 3) . '/lib/FileopsClassify.php';
require_once dirname(__DIR__, 3) . '/lib/BackupStatus.php';
require_once dirname(__DIR__, 3) . '/lib/BackupHold.php';

/** The plain-removal rules of guard-destructive's approve list: the ones a copy protects against. */
const GUARD_BACKUP_REMOVALS = ['deletes or empties files', 'removes software packages and, with purge, their settings'];

/** A block answer, or null to go on to the question. $why: the delete rule's reason. */
function guardBackup($command, $why, array $payload, $localDir, $now)
{
    $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
    $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
    // The terminal's own workdir only: the payload's cwd is Hermes' folder, and a relative path read against it would name the wrong file (ADR-0048).
    $cwd = isset($input['workdir']) && is_string($input['workdir']) ? $input['workdir'] : '';
    $home = rtrim((string) getenv('HOME'), '/');
    $c = BackupHold::classify($command);
    $removal = in_array($why, GUARD_BACKUP_REMOVALS, true);
    $danger = $c['danger'] || !$removal;
    $cache = BackupStatus::read($localDir);
    $state = BackupStatus::state($cache);
    $abs = BackupHold::absolute($c['targets'], $cwd, $home);
    if (!$danger && ($state === 'working' || ($abs !== null && !$c['packages'] && BackupHold::scratch($abs, $home)))) {
        return null;
    }
    if ($state !== 'working' && !$danger && $abs !== null && BackupHold::covered($abs, $c['packages'], BackupHold::recent($localDir, $now), $now)) {
        return null;
    }
    if (BackupHold::marker($localDir, $session, $command, $now) !== 'hold') {
        return null;
    }
    $unit = Config::shown(Config::root() . '/tools/backup/backup-safety/backup-safety.php');
    $msg = 'Pikkumies backup check, once: not run yet.';
    if ($danger) {
        $msg .= " This is a dangerous change ($why). Before running it, show the user this table of the 10 latest backups (when, size, where,"
            . " name) in your reply:\n" . BackupStatus::table(BackupStatus::latest($cache, 10), $now, BackupStatus::custody($cache)) . "\n";
    }
    if ($state !== 'working') {
        $words = array_map('escapeshellarg', $abs !== null ? $abs : $c['targets']);
        $snap = $c['packages'] ? "php $unit snap --package " . escapeshellarg($c['packages'][0]) . (count($c['packages']) > 1 ? ' (one per package)' : '')
            : ($words ? "php $unit snap " . implode(' ', array_slice($words, 0, 8)) : "php $unit snap <the files it removes>");
        $msg .= ' No working backup (' . ($cache === null ? 'not checked yet' : $state) . '). Copy what it removes first: ' . $snap
            . '. A copy is made only when it fits (2 GiB, half the free space); otherwise say so to the user. ' . BackupStatus::suggestion();
    }
    return ['action' => 'block', 'message' => $msg . ' Then run the identical command again: within 30 minutes it goes on. The user is not asked by this check.'];
}

/** " Latest backup: 3 h ago, 1.2 GiB (restic)." for a delete question, or '' when nothing is on record. */
function guardBackupLatest($localDir, $now)
{
    $rows = BackupStatus::latest(BackupStatus::read($localDir), 1);
    return $rows ? ' Latest backup: ' . BackupStatus::age($now - (int) $rows[0]['time']) . ' ago'
        . ($rows[0]['bytes'] !== null ? ', ' . BackupStatus::size($rows[0]['bytes']) : '') . " ({$rows[0]['kind']})." : '';
}
