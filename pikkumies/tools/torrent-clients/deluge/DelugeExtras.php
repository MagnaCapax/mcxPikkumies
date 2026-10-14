<?php
/**
 * DelugeExtras: the part of DelugeClient behind `show`, recheck, reannounce, limit and `show --trackers`, plus the
 * newer list fields, through the connected DelugeRpc (owner-checked port, localclient login). Deluge 2.0.3 source:
 * deluge/core/core.py, deluge/core/torrent.py.
 */
trait DelugeExtras
{
    /**
     * show: core.get_torrent_status (core.py:758). completed_at, seeding_time and private are not asked here: the list
     * record has them, and a 0 here would hide its null (unfinished) when merged over it.
     */
    public function details($native)
    {
        $s = (array) $this->rpc()->call('core.get_torrent_status', [$native, ['state', 'tracker_host', 'tracker_status', 'num_seeds',
            'num_peers', 'total_seeds', 'total_peers', 'eta', 'total_uploaded', 'total_size', 'num_files']]);
        $n = function ($k) use ($s) {
            return (int) self::v($s, $k);
        };
        return ['client_state' => self::v($s, 'state'), 'tracker' => self::v($s, 'tracker_host'),
            'tracker_status' => self::v($s, 'tracker_status'), 'seeds' => $n('num_seeds'), 'peers' => $n('num_peers'),
            'swarm_seeds' => $n('total_seeds'), 'swarm_peers' => $n('total_peers'), 'eta' => $n('eta'),
            'uploaded_bytes' => $n('total_uploaded'), 'total_size' => $n('total_size'), 'file_count' => $n('num_files')];
    }

    /** List fields: completed_time (0 = not finished), seeding_time (s), private, total_uploaded (torrent.py:1037, 1098, 1122, 1134). */
    public static function listFields(array $s)
    {
        $v = function ($k, $cast) use ($s) {
            $x = self::v($s, $k);
            return $x === null ? null : ($cast === 'bool' ? (bool) $x : (int) $x);
        };
        return ['completed_at' => $v('completed_time', 'int') > 0 ? $v('completed_time', 'int') : null,
            'seeding_time' => $v('seeding_time', 'int'), 'private' => $v('private', 'bool'), 'uploaded' => $v('total_uploaded', 'int')];
    }

    /** core.force_recheck with a list of ids (core.py:885-889); an empty list never reaches it. */
    public function recheck(array $natives)
    {
        $this->rpc()->call('core.force_recheck', [self::ids($natives)]);
    }

    /** core.force_reannounce with a list of ids (core.py:658-662). */
    public function reannounce(array $natives)
    {
        $this->rpc()->call('core.force_reannounce', [self::ids($natives)]);
    }

    /**
     * core.set_config (core.py:816-828) with max_download_speed / max_upload_speed in KiB/s floats, -1.0 = no limit:
     * the unit `traffic` reads back (DelugeStats), the key and value PMSS writes (scripts/lib/user/deluge.php:215-232)
     * and the float form of PMSS etc/seedbox/config/template.deluge.core.conf:39, 44.
     */
    public function setLimits($down, $up)
    {
        $cfg = [];
        foreach (['max_download_speed' => $down, 'max_upload_speed' => $up] as $k => $v) {
            if ($v !== null) {
                $cfg[$k] = $v > 0 ? (float) round($v / 1024, 3) : -1.0;
            }
        }
        $this->rpc()->call('core.set_config', [$cfg]);
        return [];
    }

    /**
     * The `trackers` status key: each entry with `url` and `tier` (torrent.py:562-590, 1104). Deluge gives no status,
     * message or counts per tracker; its one tracker_status is in `show`.
     */
    public function trackers($native)
    {
        $s = (array) $this->rpc()->call('core.get_torrent_status', [$native, ['trackers']]);
        $out = [];
        foreach (isset($s['trackers']) ? (array) $s['trackers'] : [] as $t) {
            if (is_array($t) && isset($t['url'])) {
                $out[] = ['url' => (string) $t['url'], 'tier' => isset($t['tier']) ? $t['tier'] : null,
                    'status' => null, 'message' => null, 'seeds' => null, 'leechers' => null];
            }
        }
        return $out;
    }

    private static function ids(array $natives)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        return array_values($natives);
    }
}
