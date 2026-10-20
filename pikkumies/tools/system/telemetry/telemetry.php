<?php
/**
 * telemetry: the local-only history TelemetryLog keeps of diagnostic and health runs
 * (docs/adr/0086-local-only-telemetry-history-for-diagnostics-and-health-runs.md). `record` is
 * internal: the hook lines inside system-status, storage and torrent-clients call
 * TelemetryLog::record() directly from PHP (no process to spawn for every run); this verb exists so a
 * person, a script or a test can add or inspect a record by hand. `series` and `list` are the only two
 * verbs the remote `serve` forced command allows for this tool so far (tools/seedbox/services/serve.php);
 * `usage` is read-only the same way but is not on that allow-list (owned by a different unit) -- nothing
 * here sends anything either way. `usage` reads what hooks/trace/usage-count wrote
 * (docs/adr/0087-tool-skill-and-verb-usage-counts-kept-on-this-machine.md): how many times a Hermes
 * tool, a skill or a Pikkumies unit's verb ran.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config'] as $telemetryClass) {
    require_once "$lib/$telemetryClass.php";
}
require_once __DIR__ . '/TelemetryLog.php';
require_once __DIR__ . '/UsageStats.php';

// Test seams: a test may set these before including the file. Nothing is read from the environment.
$telemetryLocalDir = isset($telemetryLocalDir) ? $telemetryLocalDir : null;
$telemetryConfig = isset($telemetryConfig) ? $telemetryConfig : null;

$usage = <<<'TXT'
usage: php telemetry.php <verb> [arguments] [--human]
  record <source> --json <object>                      internal: appends one record now (see USAGE.md)
  series <source> <field> [--days N] [--bucket hour|day]   a time series ready for a chart
  usage [--days N] [--kind tool|skill|unit] [--human]   how many times a tool, skill or unit verb ran
  list                                                  telemetry sources on disk: days, bytes, failures
  prune [--keep-days N]                                 deletes day-files older than N days (default: nothing, kept forever)
See USAGE.md.
TXT;

Cli::run($argv, [
    'record' => function ($args, $opt) use ($telemetryLocalDir, $telemetryConfig) {
        if (!$args) {
            throw new CliError('record needs a source name', 'php telemetry.php record <source> --json \'{"field":1}\'');
        }
        if (!isset($opt['json']) || !is_string($opt['json'])) {
            throw new CliError('record needs --json \'{"field":number,...}\'', 'one JSON object of name: number fields, no text');
        }
        $fields = json_decode($opt['json'], true);
        if (!is_array($fields)) {
            throw new CliError('--json is not a JSON object', 'e.g. --json \'{"used_pct":87.3}\'');
        }
        $written = TelemetryLog::record($args[0], $fields, $telemetryLocalDir, $telemetryConfig);
        return [['source' => $args[0], 'written' => (bool) $written]];
    },
    'series' => function ($args, $opt) use ($telemetryLocalDir) {
        if (count($args) < 2) {
            throw new CliError('series needs a source and a field', 'php telemetry.php series <source> <field> [--days N] [--bucket hour|day]');
        }
        $days = isset($opt['days']) ? (int) $opt['days'] : 7;
        $bucket = isset($opt['bucket']) && $opt['bucket'] === 'day' ? 'day' : 'hour';
        return TelemetryLog::series($args[0], $args[1], $days, $bucket, $telemetryLocalDir);
    },
    'usage' => function ($args, $opt) use ($telemetryLocalDir, $telemetryConfig) {
        $config = $telemetryConfig !== null ? $telemetryConfig : Config::load();
        $off = empty($config['privacy']['perfLogging']);
        $days = isset($opt['days']) ? (int) $opt['days'] : 30;
        $kind = isset($opt['kind']) && in_array($opt['kind'], UsageStats::KINDS, true) ? $opt['kind'] : null;
        if (isset($opt['kind']) && $kind === null) {
            throw new CliError('unknown --kind: ' . Cli::cleanString((string) $opt['kind']), 'kinds: ' . implode(', ', UsageStats::KINDS));
        }
        $records = $off ? [] : UsageStats::compute($days, $kind, $telemetryLocalDir);
        if (!empty($opt['human'])) {
            echo UsageStats::human($records, $days, $kind, $off);
            exit(0);
        }
        return ['records' => $records, 'status' => $off
            ? ['note' => 'privacy.perfLogging is off: nothing is counted; turn it on with pikkumies-configure or `pikkumies install --perf-logging=on`'] : []];
    },
    'list' => function ($args, $opt) use ($telemetryLocalDir) {
        return TelemetryLog::listSources($telemetryLocalDir);
    },
    'prune' => function ($args, $opt) use ($telemetryLocalDir) {
        $keepDays = isset($opt['keep-days']) ? (int) $opt['keep-days'] : 0;
        return [TelemetryLog::prune($keepDays, $telemetryLocalDir)];
    },
], $usage, [], ['json', 'days', 'bucket', 'kind', 'keep-days']);
