<?php
/**
 * restic: snapshots of the user's files in their own restic repository (tools/backup/README.md,
 * README.md here). The verbs are shared with borg (../lib/Backup.php); the repository password is
 * typed once by the user at a terminal (`login`) and handed to restic as a file, never as a value.
 */
require_once dirname(__DIR__) . '/lib/Backup.php';
require_once __DIR__ . '/ResticRepo.php';
require_once __DIR__ . '/ResticTool.php';

$usage = <<<'TXT'
usage: php restic.php <verb> [arguments] [--human]
  login --repo <repository>          the user types the repository password at a terminal
  list [--path P] [--limit N]         snapshots, newest first
  show <id> [--files [path]]          one snapshot; --files lists its files
  show --prune-plan --keep-daily N... what prune would remove, and the --expect value
  stats                               version, repository, snapshots, locks
  check [--read-data | --read-data-subset 5%]
  backup <path>...                    a new snapshot of paths inside the home
  restore <id> --target <new-dir> [--include /path]
  prune --keep-daily N... --expect <n>
Keep options: --keep-last --keep-hourly --keep-daily --keep-weekly --keep-monthly --keep-yearly --keep-within 30d
Output: JSON Lines; --human for people. See USAGE.md.
TXT;

// Test seams: a test may set these before including this file. Nothing is read from the environment.
$backupSecretDir = isset($backupSecretDir) ? $backupSecretDir : Config::localDir() . '/secrets';
$backupLocalDir = isset($backupLocalDir) ? $backupLocalDir : Config::localDir();
$backupMountinfo = isset($backupMountinfo) ? $backupMountinfo : '/proc/self/mountinfo';
$backupProcRoot = isset($backupProcRoot) ? $backupProcRoot : '/proc';

$resticStore = new BackupStore('restic', $backupSecretDir, $backupLocalDir);
Backup::run($argv, [
    'store' => $resticStore,
    'script' => __FILE__,
    'mountinfo' => $backupMountinfo,
    'procRoot' => $backupProcRoot,
    'uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
    'make' => function () use ($resticStore) {
        return new ResticTool($resticStore, __FILE__);
    },
], $usage);
