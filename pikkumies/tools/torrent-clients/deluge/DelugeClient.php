<?php
/**
 * DelugeClient: TorrentClient for Deluge 2.x through deluge-web (tools/torrent-clients/README.md).
 * Method names and status keys from the Deluge 2.0.3 source: deluge/core/core.py (methods),
 * deluge/core/torrent.py (status keys :1035-1140, states :51-60 and :632-655). Every core.*
 * method is reachable through /json once the web UI is connected (json_api.py:125-131, 157-160).
 */
class DelugeClient implements TorrentClient
{
    use DelugeExtras; // show details, recheck, reannounce, limits, trackers, the newer list fields

    /** Deluge state words -> the shared ones (torrent.py:51-60, 640-655). Moving has no shared word. */
    const STATES = ['Downloading' => 'downloading', 'Seeding' => 'seeding', 'Paused' => 'paused', 'Checking' => 'checking',
        'Allocating' => 'checking', 'Queued' => 'queued', 'Error' => 'error'];
    const KEYS = ['hash', 'name', 'state', 'progress', 'total_wanted', 'total_done', 'ratio', 'download_payload_rate',
        'upload_payload_rate', 'time_added', 'download_location', 'message', 'tracker_status', 'completed_time', 'seeding_time', 'private',
            'total_uploaded'];

    private $home;
    private $family;
    private $uid;
    private $timeout;
    private $rpc = null;
    private $at = [];
    private $names = [];

    public function __construct($home, $family, $uid, $timeout)
    {
        list($this->home, $this->family, $this->uid, $this->timeout) = [$home, $family, $uid, $timeout];
    }

    /** The connected RPC, located and opened on first use (restart works without it). */
    public function rpc()
    {
        if ($this->rpc === null) {
            $at = DelugeLocate::find($this->home, $this->family, $this->uid);
            $rpc = new DelugeRpc($at['url'], $at['secret'], $this->timeout, $this->uid);
            $rpc->open($at['daemon_port']);
            $this->at = $at;
            $this->rpc = $rpc;
        }
        return $this->rpc;
    }

    public function torrents()
    {
        $all = $this->rpc()->call('core.get_torrents_status', [new stdClass(), self::KEYS]); // core.py:772
        $out = [];
        foreach ((array) $all as $id => $s) {
            $s = (array) $s;
            $msg = isset($s['message']) && $s['message'] !== 'OK' ? (string) $s['message'] : ''; // 'OK' = no message (torrent.py:676-687)
            if ($msg === '' && isset($s['tracker_status']) && preg_match('/^(Error|Warning)/i', (string) $s['tracker_status'])) {
                $msg = (string) $s['tracker_status'];
            }
            $state = isset($s['state']) ? (string) $s['state'] : '';
            $this->names[(string) $id] = isset($s['name']) ? (string) $s['name'] : '';
            $out[] = ['id' => strtolower((string) $id), 'native' => (string) $id, 'name' => $this->names[(string) $id],
                'state' => array_key_exists($state, self::STATES) ? self::STATES[$state] : 'unknown',
                'progress' => round((float) self::v($s, 'progress') / 100, 4), // Deluge reports 0..100 (torrent.py:945-971)
                'size' => (int) self::v($s, 'total_wanted'), 'done' => (int) self::v($s, 'total_done'),
                'ratio' => max(0.0, round((float) self::v($s, 'ratio'), 3)), // -1 means nothing downloaded yet (torrent.py:747-757)
                'down_rate' => (int) self::v($s, 'download_payload_rate'), 'up_rate' => (int) self::v($s, 'upload_payload_rate'),
                'added_at' => (int) self::v($s, 'time_added'), 'label' => '', 'path' => rtrim((string) self::v($s, 'download_location'), '/'),
                'message' => $msg] + self::listFields($s);
        }
        return $out;
    }

    public function files($native)
    {
        return DelugeFiles::one($this->rpc(), $native);
    }

    public function filesOf(array $records)
    {
        return DelugeFiles::many($this->rpc(), $records);
    }

    public function add(array $items, array $opt)
    {
        return DelugeAdd::run($this, $items, $opt);
    }

    public function remove(array $natives, $withData)
    {
        return DelugeRemove::run($this, $natives, (bool) $withData);
    }

    public function pause(array $natives)
    {
        return DelugePause::run($this, 'core.pause_torrents', $natives, true);
    }

    public function resume(array $natives)
    {
        return DelugePause::run($this, 'core.resume_torrents', $natives, false);
    }

    public function stats()
    {
        return DelugeStats::stats($this, $this->home);
    }

    public function traffic()
    {
        return DelugeStats::traffic($this->rpc());
    }

    public function restart()
    {
        return DelugeRestart::run($this, $this->home, $this->family, $this->uid);
    }

    /** Where deluge-web was found: url, port, how (no secret). */
    public function endpoint()
    {
        $this->rpc();
        return ['port' => $this->at['port'], 'how' => $this->at['how']];
    }

    /** Forgets the connection, so the next call logs in again (after a restart). */
    public function reset()
    {
        $this->rpc = null;
    }

    public function name($native)
    {
        return isset($this->names[$native]) ? $this->names[$native] : '';
    }

    public static function v(array $s, $k)
    {
        return isset($s[$k]) ? $s[$k] : null;
    }
}
