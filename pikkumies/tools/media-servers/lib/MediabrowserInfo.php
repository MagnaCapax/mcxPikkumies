<?php
/**
 * MediabrowserInfo: `stats` and `logs` for Jellyfin and Emby (MediabrowserServer.php has the other verbs).
 * Field names from the Jellyfin 12.2.0 and Emby 4.1.1.0 specs: SystemInfo, ItemCounts, TaskInfo,
 * EncodingOptions (the hardware setting is HardwareAccelerationType in Jellyfin, HardwareAccelerationMode
 * in Emby, read on live 12.2.0 and 4.10.1.0 servers), LogFile.
 */
class MediabrowserInfo
{
    public static function stats(Mediabrowser $mb, MediabrowserServer $server, array $found)
    {
        $info = $mb->get('/System/Info');
        $libs = $server->libraries(false);
        $streams = $server->sessions();
        $tc = array_values(array_filter($streams, function ($s) {
            return $s['play'] === 'transcode';
        }));
        $counts = $server->maybe('/Items/Counts');
        $enc = $server->maybe('/System/Configuration/encoding');
        $tasks = $server->maybe('/ScheduledTasks');
        return [
            'server' => $mb->set['name'], 'version' => isset($info['Version']) ? (string) $info['Version'] : '', 'server_name' => isset($info['ServerName']) ? (string) $info['ServerName'] : '',
            'found' => $found['how'], 'url' => $found['url'], 'listen' => $found['listen'], 'libraries' => count($libs),
            'items' => isset($counts['ItemCount']) ? (int) $counts['ItemCount'] : null, 'streams' => count($streams), 'transcodes' => count($tc),
            'transcode_bitrate' => array_sum(array_map('intval', array_column($tc, 'bitrate'))),
            'hardware_acceleration' => isset($enc[$mb->set['hw']]) ? $enc[$mb->set['hw']] : null, // the setting as found, nothing more
            'running_tasks' => $tasks === null ? null : count(array_filter($tasks, function ($t) {
                return isset($t['State']) && $t['State'] === 'Running';
            })),
            'pending_restart' => !empty($info['HasPendingRestart']), 'update_available' => !empty($info['HasUpdateAvailable']),
            'pmss_status' => $found['pmss_status'] !== '' ? self::pmssStatus($found['pmss_status']) : null, 'login_from' => $found['login_from'],
        ];
    }

    public static function logs(Mediabrowser $mb, $dir, $lines)
    {
        $files = $dir !== '' ? (array) glob("$dir/*.log") : [];
        if ($files) { // native install: read the newest log from disk, without a 5 MB API download
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            return ['file' => $files[0], 'lines' => MediaLocal::tail($files[0], $lines)];
        }
        $list = $mb->get('/System/Logs');
        usort($list, function ($a, $b) {
            return strcmp((string) $b['DateModified'], (string) $a['DateModified']);
        });
        if (!$list || empty($list[0]['Name'])) {
            throw new CliError("{$mb->set['name']} lists no log files", 'check the server dashboard');
        }
        return ['file' => (string) $list[0]['Name'], 'lines' => MediaLocal::lastLines($mb->text('/System/Logs/Log', ['name' => $list[0]['Name']]), $lines)];
    }

    /** PMSS watchdog state for Jellyfin from ~/.media-stack-status.json (scripts/lib/mediaStackWatchdog.php:84-101). */
    private static function pmssStatus($file)
    {
        $d = json_decode((string) @file_get_contents($file), true);
        return isset($d['apps']['jellyfin']['state']) ? (string) $d['apps']['jellyfin']['state'] : null;
    }
}
