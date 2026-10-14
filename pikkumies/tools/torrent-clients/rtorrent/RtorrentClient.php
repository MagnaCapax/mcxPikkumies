<?php
/**
 * RtorrentClient: the TorrentClient of rTorrent over SCGI XML-RPC. Method names, signatures and
 * the target rule are from rTorrent 0.9.8 source (copies in the research notes; file:line in
 * README.md): the first parameter of a call with arguments is the target, "" or the hash exactly
 * as d.hash returns it (upper case, command_download.cc:676; rpc/xmlrpc.cc:136-228).
 */
require_once __DIR__ . '/XmlRpc.php';
require_once __DIR__ . '/Scgi.php';
require_once __DIR__ . '/Locate.php';
require_once __DIR__ . '/Records.php';
require_once __DIR__ . '/Add.php';
require_once __DIR__ . '/Deleter.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/Restart.php';
require_once __DIR__ . '/Extras.php';

class RtorrentClient implements TorrentClient
{
    use RtorrentExtras; // pause, resume, recheck, reannounce, limits, trackers

    public $family;
    public $home;
    public $uid;
    private $scgi = null;
    /** d.directory of each torrent from the last list (RtorrentFiles::many). */
    public $dirs = [];
    private $byNative = [];

    public function __construct($family, $home, $uid)
    {
        $this->family = $family;
        $this->home = rtrim($home, '/');
        $this->uid = (int) $uid;
    }

    /** One XML-RPC call; the endpoint is found on first use, so `restart` works while rtorrent is down. */
    public function call($method, array $params = [], $timeout = 5)
    {
        if ($this->scgi === null) {
            $this->scgi = new RtorrentScgi(RtorrentLocate::address($this->family, $this->home, $this->uid), 5);
        }
        return $timeout === 5 ? $this->scgi->call($method, $params) : (new RtorrentScgi($this->scgi->address, $timeout))->call($method, $params);
    }

    /** The SCGI address in use (unix:///path or tcp://127.0.0.1:port), once a call was made. */
    public function address()
    {
        return $this->scgi === null ? '' : $this->scgi->address;
    }

    public function forget()
    {
        $this->scgi = null;
    }

    public function torrents()
    {
        $rows = $this->call('d.multicall2', array_merge(['', 'main'], RtorrentRecords::FIELDS));
        $this->byNative = [];
        $this->dirs = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && count($row) === count(RtorrentRecords::FIELDS)) {
                $d = array_combine(RtorrentRecords::FIELDS, $row);
                $r = RtorrentRecords::row($d);
                $this->byNative[$r['native']] = $r;
                $this->dirs[$r['native']] = rtrim((string) $d['d.directory='], '/');
            }
        }
        return array_values($this->byNative);
    }

    /** The list record of one torrent, refreshed when it is not known yet. */
    public function record($native)
    {
        if (!isset($this->byNative[$native])) {
            $this->torrents();
        }
        if (!isset($this->byNative[$native])) {
            throw new CliError('torrent ' . strtolower($native) . ' is no longer in rtorrent', 'run `list` again');
        }
        return $this->byNative[$native];
    }

    public function details($native)
    {
        return RtorrentRecords::details($this, $native);
    }

    /** The preview of what RtorrentDeleter would delete (RtorrentFiles::one): refused where the deleter refuses. */
    public function files($native)
    {
        return RtorrentFiles::one($this, $native);
    }

    public function filesOf(array $records)
    {
        return RtorrentFiles::many($this, $records);
    }

    public function add(array $items, array $opt)
    {
        return RtorrentAdd::run($this, $items, $opt);
    }

    public function remove(array $natives, $withData)
    {
        return RtorrentDeleter::remove($this, $natives, (bool) $withData);
    }

    public function stats()
    {
        return RtorrentRecords::stats($this);
    }

    public function traffic()
    {
        return RtorrentRecords::traffic($this);
    }

    public function restart()
    {
        return RtorrentRestart::run($this);
    }
}
