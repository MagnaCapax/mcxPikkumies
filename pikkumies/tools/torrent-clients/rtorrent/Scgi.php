<?php
/**
 * RtorrentScgi: one XML-RPC call over rTorrent's SCGI socket (a unix socket, or a TCP port on this
 * machine already checked by RtorrentLocate). Framing as rTorrent 0.9.8 requires it: a netstring
 * header whose FIRST field is CONTENT_LENGTH (rpc/scgi_task.cc:139-146), the same bytes PMSS sends
 * (PMSS scripts/lib/rtorrent/scgi/xmlrpc.php:9-13). The reply starts with "Status: 200 OK" headers
 * (scgi_task.cc:234). No answer in time is "busy": the state is unknown, never "down"
 * (a busy rtorrent stops accepting; PMSS watches its accept queue, scripts/lib/rtorrent/scgi/queue.php:46-75).
 */
class RtorrentScgi
{
    const MAX = 67108864; // reply cap, bytes

    /** @var string unix:///path or tcp://127.0.0.1:port */
    public $address;
    private $timeout;

    public function __construct($address, $timeout = 5)
    {
        $this->address = $address;
        $this->timeout = $timeout;
    }

    public function call($method, array $params = [])
    {
        $body = RtorrentXmlRpc::request($method, $params);
        $head = "CONTENT_LENGTH\0" . strlen($body) . "\0SCGI\0" . "1\0";
        $req = strlen($head) . ':' . $head . ',' . $body;
        $s = @stream_socket_client($this->address, $errno, $errstr, $this->timeout);
        if ($s === false) {
            throw $this->connectError((int) $errno, (string) $errstr);
        }
        stream_set_timeout($s, $this->timeout);
        $deadline = microtime(true) + 4 * $this->timeout; // a whole reply, even one arriving slowly
        for ($off = 0; $off < strlen($req);) {
            $n = @fwrite($s, substr($req, $off));
            if ($n === false || $n === 0) {
                fclose($s);
                throw $this->busy($method);
            }
            $off += $n;
        }
        $resp = '';
        while (!feof($s)) {
            $chunk = fread($s, 65536);
            $meta = stream_get_meta_data($s);
            if (!empty($meta['timed_out']) || microtime(true) > $deadline) {
                fclose($s);
                throw $this->busy($method);
            }
            $resp .= (string) $chunk;
            if (strlen($resp) > self::MAX) {
                fclose($s);
                throw new CliError("rtorrent's answer to $method is larger than " . self::MAX . ' bytes', 'ask for less (fewer torrents at a time)');
            }
        }
        fclose($s);
        $p = strpos($resp, "\r\n\r\n");
        if ($p === false || strncmp($resp, 'Status: 200', 11) !== 0) {
            throw new CliError("rtorrent did not answer $method" . ($resp === '' ? ' (the connection closed empty)' : ' with a valid reply'), 'it may be starting or shutting down; try again in a minute');
        }
        return RtorrentXmlRpc::response(substr($resp, $p + 4), $method);
    }

    private function busy($method)
    {
        return new CliError("rtorrent is busy: no answer to $method within {$this->timeout} s (state unknown, not necessarily down)", 'wait a minute and try again; do not restart a busy rtorrent unless it stays silent for several minutes');
    }

    private function connectError($errno, $errstr)
    {
        $where = preg_replace('#^(unix|tcp)://#', '', $this->address);
        if ($errno === 110 || $errno === 11 || stripos($errstr, 'timed out') !== false || stripos($errstr, 'temporarily unavailable') !== false) {
            // ETIMEDOUT, or EAGAIN: a unix socket whose accept queue is full (the busy state PMSS watches for)
            return new CliError("rtorrent is busy: the connection to $where did not open within {$this->timeout} s (state unknown, not necessarily down)", 'wait a minute and try again');
        }
        if ($errno === 111 || stripos($errstr, 'refused') !== false) {
            return new CliError("rtorrent is not running: nothing accepts connections on $where", 'start it with `restart`');
        }
        if ($errno === 2 || stripos($errstr, 'No such file') !== false) {
            return new CliError("rtorrent is not running: $where does not exist", 'start it with `restart`');
        }
        if ($errno === 13 || stripos($errstr, 'denied') !== false) {
            return new CliError("connection to $where refused: permission denied", 'the socket must belong to your own account');
        }
        return new CliError("cannot connect to rtorrent at $where: $errstr", 'check that rtorrent runs (`stats`)');
    }
}
