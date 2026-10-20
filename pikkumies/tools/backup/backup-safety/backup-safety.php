<?php
/**
 * backup-safety: whether this account's backups work, and a copy of exactly the files a change is about to remove or
 * rewrite (README.md here, ADR-0099). `status` reads (or with --refresh rebuilds) the cache the hooks read; `snap` copies.
 */
$safetyLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'ToolProc', 'HomePath', 'Quota', 'Detect', 'SecretFile', 'CronTick', 'Update', 'SshLists', 'FileopsClassify',
    'BackupStatus', 'BackupHold', 'LocalTmp'] as $safetyClass) {
    require_once "$safetyLib/$safetyClass.php";
}
require_once dirname(__DIR__) . '/lib/Backup.php';
foreach (['restic/ResticRepo', 'restic/ResticPrune', 'restic/ResticTool', 'borg/BorgRepo', 'borg/BorgPrune', 'borg/BorgTool', 'remote/RemotePipe',
    'remote/RemoteDest', 'remote/RemoteOps'] as $safetyClass) {
    require_once dirname(__DIR__) . "/$safetyClass.php";
}
require_once __DIR__ . '/BackupFacts.php';
require_once __DIR__ . '/BackupSnap.php';

$usage = <<<'TXT'
usage: php backup-safety.php <verb> [arguments] [--human]
  status [--refresh] [--latest N]     the state of every backup method and the N latest backups (default 10)
  snap <path>... | --package <name>   copy exactly these files to ~/backups/<UTC time>/ (or /backups/ with root)
Output: JSON Lines; --human for people. See USAGE.md.
TXT;

// Test seams: a test may set these before including this file. Nothing is read from the environment.
$safetyLocalDir = isset($safetyLocalDir) ? $safetyLocalDir : Config::localDir();
$safetyNow = isset($safetyNow) ? $safetyNow : time();

Cli::run($argv, [
    'status' => function ($args, $opt) use ($safetyLocalDir, $safetyNow) {
        $n = isset($opt['latest']) ? (string) $opt['latest'] : '10';
        if (!preg_match('/^[1-9][0-9]?$/', $n)) {
            throw new CliError('--latest takes a number from 1 to 99', 'e.g. --latest 10');
        }
        $cache = BackupStatus::read($safetyLocalDir);
        if (!empty($opt['refresh'])) {
            $home = HomePath::home();
            $root = Detect::rootAccess(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
            // Twice: without the repository checks first, so the hooks have a status even when a slow check runs out of time.
            foreach ([false, true] as $verify) {
                $cache = BackupStatus::judge(BackupFacts::gather($safetyLocalDir, $home, $root, $cache, $safetyNow, $verify), $safetyNow)
                    + ['root_via' => !empty($root['available']) ? $root['via'] : 'none'];
                if (!BackupStatus::write($safetyLocalDir, $cache)) {
                    throw new CliError('cannot write ' . BackupStatus::file($safetyLocalDir), 'check that pikkumies/local is writable by this account');
                }
            }
        }
        $records = [['record' => 'status', 'state' => BackupStatus::state($cache), 'checked_at' => is_array($cache) ? $cache['checked_at'] : null]];
        $methods = is_array($cache) && isset($cache['methods']) && is_array($cache['methods']) ? $cache['methods'] : [];
        foreach ($methods as $m) {
            unset($m['backups']);
            $records[] = ['record' => 'method'] + $m;
        }
        $rows = BackupStatus::latest($cache, (int) $n);
        foreach ($rows as $r) {
            $records[] = ['record' => 'backup'] + $r;
        }
        $state = BackupStatus::state($cache);
        $custody = BackupStatus::custody($cache);
        $extra = ['state' => $state, 'table' => BackupStatus::table($rows, $safetyNow, $custody)] + BackupStatus::links()
            + ($custody !== '' ? ['custody' => $custody] : []);
        if ($cache === null) {
            $extra['note'] = 'not checked yet: run status --refresh';
        }
        if ($state !== 'working') {
            $extra['suggestion'] = BackupStatus::suggestion();
        }
        return ['records' => $records, 'status' => $extra];
    },
    'snap' => function ($args, $opt) use ($safetyLocalDir, $safetyNow) {
        return BackupSnap::run($args, $opt, HomePath::home(), $safetyLocalDir, $safetyNow);
    },
], $usage, ['refresh'], ['latest', 'package']);
