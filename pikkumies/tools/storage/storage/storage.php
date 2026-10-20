<?php
/**
 * storage: disk space and quota of this account (tools/storage/README.md, README.md here). Three
 * verbs and none of them deletes: `stats` reads the quota without scanning, `topdirs` and
 * `bigfiles` run one bounded scan each. Freeing space goes through the tool that owns the data.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Quota', 'ToolProc', 'HomePath'] as $storageClass) {
    require_once "$lib/$storageClass.php";
}
foreach (['ToolText', 'ToolList'] as $toolLib) {
    require_once dirname(__DIR__) . "/lib/$toolLib.php";
}
require_once __DIR__ . '/StorageStats.php';
require_once __DIR__ . '/StorageScan.php';
require_once dirname(__DIR__, 2) . '/system/telemetry/TelemetryLog.php'; // local-only history of `stats`' own record (docs/adr/0084-...)

$usage = <<<'TXT'
usage: php storage.php <verb> [arguments] [--human]
  stats                                       quota, files and free space; no scan
  topdirs [--path P] [--depth 1-3] [--inodes] [--limit N] [--fresh]
  bigfiles [--path P] [--min-size 1G] [--limit N] [--fresh]
Scans run at the lowest priority, on one filesystem, for at most 300 s, one at a time; results are
cached for 10 minutes (--fresh rescans). Nothing here deletes. See USAGE.md.
TXT;

// Test seam: a test may set this before including the file. Nothing is read from the environment.
$storageLocalDir = isset($storageLocalDir) ? $storageLocalDir : Config::localDir();

Cli::run($argv, [
    'stats' => function ($args, $opt) {
        $record = StorageStats::record(HomePath::home());
        try { // one compact numeric telemetry record per run (docs/adr/0084-...); never changes this verb's own output, never breaks it
            TelemetryLog::record('storage', ['quota_used_bytes' => $record['quota_used_bytes'], 'quota_limit_bytes' => $record['quota_limit_bytes'],
                'quota_used_percent' => $record['quota_used_percent'], 'fs_free_bytes' => $record['fs_free_bytes'], 'fs_full' => !empty($record['fs_full'])]);
        } catch (Throwable $e) {
            TelemetryLog::noteFailure('storage');
        }
        return [$record];
    },
    'topdirs' => function ($args, $opt) use ($storageLocalDir) {
        return (new StorageScan($storageLocalDir))->topdirs($opt);
    },
    'bigfiles' => function ($args, $opt) use ($storageLocalDir) {
        return (new StorageScan($storageLocalDir))->bigfiles($opt);
    },
], $usage, ['inodes', 'fresh'], ['path', 'depth', 'min-size']);
