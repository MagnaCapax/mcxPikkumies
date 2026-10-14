<?php
/**
 * borg: archives of the user's files in their own borg repository (tools/backup/README.md,
 * README.md here). The verbs are shared with restic (../lib/Backup.php); the repository password is
 * typed once by the user at a terminal (`login`); borg reads it from a file, never as a value.
 */
require_once dirname(__DIR__) . '/lib/Backup.php';
require_once __DIR__ . '/BorgRepo.php';
require_once __DIR__ . '/BorgTool.php';

$usage = <<<'TXT'
usage: php borg.php <verb> [arguments] [--human]
  login --repo <repository>          the user types the repository password at a terminal
  list [--path P] [--limit N]         archives, newest first
  show <id> [--files [path]]          one archive (by name); --files lists its files
  show --prune-plan --keep-daily N... what prune would remove, and the --expect value
  stats                               version, compact support, repository, archives, borgmatic configs
  check [--read-data]
  backup <path>...                    a new archive <host>-<UTC time>Z of paths inside the home
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

$borgStore = new BackupStore('borg', $backupSecretDir, $backupLocalDir);
Backup::run($argv, [
    'store' => $borgStore,
    'script' => __FILE__,
    'mountinfo' => $backupMountinfo,
    'procRoot' => $backupProcRoot,
    'uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
    'make' => function () use ($borgStore) {
        return new BorgTool($borgStore, __FILE__);
    },
], $usage);
