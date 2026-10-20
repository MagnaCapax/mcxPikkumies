<?php
/**
 * TransmissionClient: TorrentClient for Transmission over its (pre-4.1) JSON RPC
 * (tools/torrent-clients/README.md). Field and method names from rpc-spec.md on the 4.0.6 tag
 * (fetched 2026-10-20: https://github.com/transmission/transmission/blob/4.0.6/docs/rpc-spec.md),
 * which PMSS's curated 4.1.3 and 4.0.5 both still answer to (see TransmissionRpc for the full
 * version citation). The per-torrent field mapping lives in TransmissionRecords.php.
 */
class TransmissionClient implements TorrentClient
{
    use TransmissionExtras;

    const FIELDS = ['id', 'hashString', 'name', 'status', 'error', 'errorString', 'isStalled', 'percentDone',
        'totalSize', 'sizeWhenDone', 'leftUntilDone', 'uploadRatio', 'rateDownload', 'rateUpload', 'addedDate',
        'downloadDir', 'labels', 'doneDate', 'secondsSeeding', 'isPrivate', 'uploadedEver'];

    private $home;
    private $family;
    private $uid;
    private $timeout;
    private $secretDir;
    private $rpc = null;
    private $at = [];
    private $names = [];

    public function __construct($home, $family, $uid, $timeout, $secretDir = null)
    {
        $this->home = $home;
        $this->family = $family;
        $this->uid = $uid;
        $this->timeout = $timeout;
        $this->secretDir = $secretDir !== null ? $secretDir : Config::localDir() . '/secrets';
    }

    /** The connected RPC, located on first use (restart works without it). When authentication is
     * required, the stored `login` credential is sent as Basic auth -- never a reason to refuse or to
     * suggest turning authentication off (TransmissionLocate never does either). */
    public function rpc()
    {
        if ($this->rpc === null) {
            $at = TransmissionLocate::find($this->home, $this->family, $this->uid);
            $this->at = $at;
            $username = '';
            $secret = null;
            if (!empty($at['auth']['required'])) {
                $username = $at['auth']['username'];
                $secret = new TransmissionSecret($this->secretDir, $this->uid);
                $secret->usable(); // throws now (a clear refusal) rather than on the first real call
            }
            $this->rpc = new TransmissionRpc($at['url'], $at['unix_socket'], $this->timeout, $username, $secret);
        }
        return $this->rpc;
    }

    public function torrents()
    {
        $args = $this->rpc()->call('torrent-get', ['fields' => self::FIELDS]);
        $out = [];
        foreach ((array) (isset($args['torrents']) ? $args['torrents'] : []) as $t) {
            $t = (array) $t;
            $hash = isset($t['hashString']) ? (string) $t['hashString'] : '';
            if ($hash === '') {
                continue; // a torrent Transmission could not describe fully (should not happen)
            }
            $name = isset($t['name']) ? (string) $t['name'] : '';
            $this->names[$hash] = $name;
            $out[] = TransmissionRecords::one($hash, $t, $name);
        }
        return $out;
    }

    public function files($native)
    {
        $args = $this->rpc()->call('torrent-get', ['ids' => [$native], 'fields' => ['downloadDir', 'files']]);
        $t = isset($args['torrents'][0]) ? (array) $args['torrents'][0] : null;
        return $t === null ? [] : TransmissionRecords::paths($native, $t);
    }

    public function filesOf(array $records)
    {
        return TransmissionRecords::filesOf($this->rpc(), $records);
    }

    public function add(array $items, array $opt)
    {
        return TransmissionAdd::run($this, $items, $opt);
    }

    public function remove(array $natives, $withData)
    {
        return TransmissionRemove::run($this, $natives, (bool) $withData);
    }

    public function pause(array $natives)
    {
        return TransmissionPause::run($this, 'torrent-stop', $natives, true);
    }

    public function resume(array $natives)
    {
        return TransmissionPause::run($this, 'torrent-start', $natives, false);
    }

    public function stats()
    {
        return TransmissionStats::stats($this, $this->home, $this->uid);
    }

    public function traffic()
    {
        return TransmissionStats::traffic($this->rpc());
    }

    public function restart()
    {
        return TransmissionRestart::run($this, $this->home, $this->family, $this->uid);
    }

    /** Where this account's Transmission RPC was found: unix_socket/port and how (no secret). */
    public function endpoint()
    {
        $this->rpc();
        return $this->at;
    }

    /** Forgets the connection, so the next call locates and connects again (after a restart). */
    public function reset()
    {
        $this->rpc = null;
    }

    public function name($native)
    {
        return isset($this->names[$native]) ? $this->names[$native] : '';
    }
}
