<?php
/**
 * TorrentsTelemetry: one compact numeric telemetry record per `stats` run, one shared place for
 * every client (docs/adr/0086-local-only-telemetry-history-for-diagnostics-and-health-runs.md).
 * Torrents::run's `stats` verb calls record(); it never changes that verb's own output and never
 * breaks it. Source is `torrent-<client>`, the client's own class name with "Client" dropped and
 * lower-cased (RtorrentClient -> torrent-rtorrent), so a new client unit gets its own source for free.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 2) . '/system/telemetry/TelemetryLog.php';

class TorrentsTelemetry
{
    public static function record($client, array $stats)
    {
        $source = 'torrent-' . strtolower((string) preg_replace('/Client$/', '', get_class($client)));
        try {
            $fields = ['torrents' => isset($stats['torrents']) ? $stats['torrents'] : null,
                'down_rate' => isset($stats['down_rate']) ? $stats['down_rate'] : null, 'up_rate' => isset($stats['up_rate']) ? $stats['up_rate'] : null,
                'quota_used_bytes' => isset($stats['quota_used_bytes']) ? $stats['quota_used_bytes'] : null,
                'quota_free_bytes' => isset($stats['quota_free_bytes']) ? $stats['quota_free_bytes'] : null];
            foreach (Torrents::STATES as $state) {
                $fields[$state] = isset($stats['by_state'][$state]) ? $stats['by_state'][$state] : 0;
            }
            TelemetryLog::record($source, $fields);
        } catch (Throwable $e) {
            TelemetryLog::noteFailure($source);
        }
    }
}
