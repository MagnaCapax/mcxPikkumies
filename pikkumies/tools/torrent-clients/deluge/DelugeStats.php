<?php
/**
 * DelugeStats: the stats and traffic records. Sources (Deluge 2.0.3): daemon.get_version (core/daemon.py:185-188,
 * registered as "daemon.*" by rpcserver.py:428-436 via daemon.py:147-148), core.get_libtorrent_version :1266,
 * core.get_listen_port :831, core.get_session_status :628-657
 * with the rate keys of SESSION_RATES_MAPPING :101-112 and the totals of DEPR_SESSION_STATUS_KEYS :67-98,
 * core.get_config_values :817; max_download_speed / max_upload_speed are KiB/s, below 0 = no limit
 * (core/preferencesmanager.py:72-73, 328-336).
 */
class DelugeStats
{
    public static function stats(DelugeClient $c, $home)
    {
        $rpc = $c->rpc();
        $counts = array_fill_keys(Torrents::STATES, 0);
        $all = $c->torrents();
        foreach ($all as $t) {
            $counts[$t['state']]++;
        }
        $pids = DelugeRestart::pids('deluged', function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $r = ['client' => 'deluge', 'version' => self::quiet($rpc, 'daemon.get_version'), 'libtorrent' => self::quiet($rpc, 'core.get_libtorrent_version'),
            'pid' => $pids ? $pids[0] : null, 'uptime' => $pids ? self::uptime($pids[0]) : null,
            'listen_port' => self::quiet($rpc, 'core.get_listen_port'), 'torrents' => count($all)];
        $r['by_state'] = $counts;
        $at = $c->endpoint();
        $r['web_port'] = $at['port'];
        $r['found_in'] = $at['how'];
        return $r + self::traffic($rpc);
    }

    public static function traffic(DelugeRpc $rpc)
    {
        $s = (array) $rpc->call('core.get_session_status', [['payload_download_rate', 'payload_upload_rate', 'total_payload_download', 'total_payload_upload']]);
        $limits = (array) $rpc->call('core.get_config_values', [['max_download_speed', 'max_upload_speed']]);
        $limit = function ($k) use ($limits) {
            return isset($limits[$k]) && (float) $limits[$k] > 0 ? (int) round((float) $limits[$k] * 1024) : 0;
        };
        return ['down_rate' => (int) DelugeClient::v($s, 'payload_download_rate'), 'up_rate' => (int) DelugeClient::v($s, 'payload_upload_rate'),
            'down_total' => (int) DelugeClient::v($s, 'total_payload_download'), 'up_total' => (int) DelugeClient::v($s, 'total_payload_upload'),
            'down_limit' => $limit('max_download_speed'), 'up_limit' => $limit('max_upload_speed')];
    }

    /** A value that is nice to have: null when this Deluge does not answer the call. */
    private static function quiet(DelugeRpc $rpc, $method)
    {
        try {
            $v = $rpc->call($method, []);
            return is_scalar($v) ? $v : null;
        } catch (CliError $e) {
            return null;
        }
    }

    /** Seconds since the process started: /proc/<pid>/stat field 22 in clock ticks of 100 per second (USER_HZ). */
    public static function uptime($pid, $proc = '/proc')
    {
        $stat = (string) @file_get_contents("$proc/$pid/stat");
        $up = (float) @file_get_contents("$proc/uptime");
        $p = strrpos($stat, ')');
        $f = $p === false ? [] : preg_split('/\s+/', trim(substr($stat, $p + 1)));
        return isset($f[19]) && $up > 0 ? max(0, (int) ($up - (int) $f[19] / 100)) : null;
    }
}
