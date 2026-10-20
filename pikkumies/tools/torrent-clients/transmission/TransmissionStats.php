<?php
/**
 * TransmissionStats: the stats and traffic records. Sources: rpc-spec.md on the 4.0.6 tag (fetched
 * 2026-10-20), section 4: `session-get` for `version` and `peer-port` (4.1), `session-stats` for the
 * torrent counts and current speed (4.2: `downloadSpeed`/`uploadSpeed`, `cumulative-stats`), and
 * `speed-limit-down(-enabled)` / `speed-limit-up(-enabled)` for the global limits (KBps; 0 when the
 * matching *-enabled flag is off).
 */
class TransmissionStats
{
    public static function stats(TransmissionClient $c, $home, $uid)
    {
        $rpc = $c->rpc();
        $counts = array_fill_keys(Torrents::STATES, 0);
        $all = $c->torrents();
        foreach ($all as $t) {
            $counts[$t['state']]++;
        }
        $pids = OwnProcs::pids([TransmissionLocate::PROCESS], $uid);
        $session = self::quiet($rpc, 'session-get', ['fields' => ['version', 'peer-port', 'rpc-version-semver']]);
        $r = ['client' => 'transmission', 'version' => is_array($session) && isset($session['version']) ? $session['version'] : null,
            'rpc_version' => is_array($session) && isset($session['rpc-version-semver']) ? $session['rpc-version-semver'] : null,
            'pid' => $pids ? $pids[0] : null, 'uptime' => $pids ? self::uptime($pids[0]) : null,
            'listen_port' => is_array($session) && isset($session['peer-port']) ? (int) $session['peer-port'] : null,
            'torrents' => count($all)];
        $r['by_state'] = array_filter($counts); // only states that have torrents, as every unit (../README.md)
        $at = $c->endpoint();
        $r['rpc_endpoint'] = $at['unix_socket'] !== '' ? 'unix:' . $at['unix_socket'] : 'tcp:' . $at['port'];
        $r['found_in'] = $at['how'];
        return $r + self::traffic($rpc);
    }

    public static function traffic(TransmissionRpc $rpc)
    {
        $s = self::quiet($rpc, 'session-stats', []);
        $limits = self::quiet($rpc, 'session-get', ['fields' => ['speed-limit-down', 'speed-limit-up',
            'speed-limit-down-enabled', 'speed-limit-up-enabled']]);
        $limit = function ($enabledKey, $valueKey) use ($limits) {
            if (!is_array($limits) || empty($limits[$enabledKey])) {
                return 0;
            }
            return isset($limits[$valueKey]) && (float) $limits[$valueKey] > 0 ? (int) round((float) $limits[$valueKey] * 1024) : 0;
        };
        $cum = is_array($s) && isset($s['cumulative-stats']) ? (array) $s['cumulative-stats'] : [];
        return ['down_rate' => is_array($s) ? (int) self::v($s, 'downloadSpeed') : 0,
            'up_rate' => is_array($s) ? (int) self::v($s, 'uploadSpeed') : 0,
            'down_total' => (int) self::v($cum, 'downloadedBytes'), 'up_total' => (int) self::v($cum, 'uploadedBytes'),
            'down_limit' => $limit('speed-limit-down-enabled', 'speed-limit-down'),
            'up_limit' => $limit('speed-limit-up-enabled', 'speed-limit-up')];
    }

    private static function v(array $s, $k)
    {
        return isset($s[$k]) ? $s[$k] : null;
    }

    /** A call that is nice to have: null when it fails, instead of aborting stats/traffic entirely. */
    private static function quiet(TransmissionRpc $rpc, $method, array $args)
    {
        try {
            $v = $rpc->call($method, $args);
            return is_array($v) ? $v : null;
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
