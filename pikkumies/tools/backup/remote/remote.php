<?php
/**
 * backup remote: encrypted archives of this account pushed to another machine over ssh (README.md here, ADR-0049):
 * tar | pigz | gpg --symmetric | ssh to a receiver that a restricted key can reach and nothing else.
 */
$remoteLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'ToolProc', 'HomePath', 'SecretFile', 'CronTick', 'Update', 'SshLists'] as $remoteClass) {
    require_once "$remoteLib/$remoteClass.php";
}
require_once dirname(__DIR__, 2) . '/storage/lib/ToolText.php';
foreach (['RemotePipe', 'RemoteDest', 'RemoteSetup', 'RemoteRun', 'RemoteOps'] as $remoteClass) {
    require_once __DIR__ . "/$remoteClass.php";
}

$usage = <<<'TXT'
usage: php remote.php <verb> [arguments] [--human]
  setup <host> <user> [--port N] [--keep-days 30] [--fingerprint SHA256:...]
                                      the login comes only from PIKKUMIES_REMOTE_PASSWORD='...' before the command
  run --mode meta|full [--allow-large] one encrypted archive to the backup machine
  list                                archives on the backup machine, newest first
  restore <name> --target <new-dir>   into a new or empty directory inside the home
  stats                               destination, schedule, last run, passphrase_saved_by_user
  schedule --daily HH:MM --mode meta|full [--allow-large] | --off
  passphrase [--confirm-saved]        the user's own terminal only
Output: JSON Lines; --human for people. See USAGE.md.
TXT;

// Test seams: a test may set these before including this file. Nothing is read from the environment.
$remoteLocalDir = isset($remoteLocalDir) ? $remoteLocalDir : Config::localDir();
$remoteMountinfo = isset($remoteMountinfo) ? $remoteMountinfo : '/proc/self/mountinfo';
$remoteLocalDir = realpath($remoteLocalDir) ?: $remoteLocalDir;
Update::$keptDir = $remoteLocalDir . '/secrets/cron';
$remoteDest = new RemoteDest($remoteLocalDir);

Cli::run($argv, [
    'setup' => function ($args, $opt) use ($remoteDest) {
        return RemoteSetup::run($remoteDest, $args, $opt, __DIR__ . '/askpass.php', __DIR__ . '/recv.sh');
    },
    'run' => function ($args, $opt) use ($remoteDest, $remoteLocalDir) {
        return RemoteRun::run($remoteDest, $opt, HomePath::home(), $remoteLocalDir);
    },
    'list' => function ($args, $opt) use ($remoteDest) {
        return RemoteOps::listing($remoteDest);
    },
    'restore' => function ($args, $opt) use ($remoteDest, $remoteMountinfo) {
        return RemoteOps::restore($remoteDest, $args, $opt, HomePath::home(), $remoteMountinfo);
    },
    'stats' => function ($args, $opt) use ($remoteDest) {
        return RemoteOps::stats($remoteDest, Update::$keptDir);
    },
    'schedule' => function ($args, $opt) use ($remoteDest, $remoteLocalDir) {
        return RemoteOps::schedule($remoteDest, $opt, __FILE__, $remoteLocalDir);
    },
    'passphrase' => function ($args, $opt) use ($remoteDest) {
        return RemoteOps::passphrase($remoteDest, $opt);
    },
], $usage, ['allow-large', 'off', 'confirm-saved'], ['port', 'keep-days', 'fingerprint', 'mode', 'target', 'daily']);
