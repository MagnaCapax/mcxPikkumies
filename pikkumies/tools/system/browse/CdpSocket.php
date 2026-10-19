<?php
/**
 * CdpSocket: the smallest WebSocket client the guard needs for the browser's DevTools endpoint (RFC 6455): one
 * ws:// connection to a loopback address, masked text frames out, unmasked frames in, ping answered, close noticed.
 * No TLS, no extensions, no compression: Chromium's DevTools endpoint offers none of them on ws://. The frame codec
 * is static so tests can run it without a socket.
 */
class CdpSocket
{
    const MAX = 67108864; // bytes in one message; DevTools messages carrying a large request body stay well below this

    private $s;
    private $buf = '';
    private $parts = '';
    public $closed = false;

    /** Opens $url (ws://127.0.0.1:<port>/devtools/browser/<id> or another loopback address); throws on failure. */
    public function __construct($url, $timeout = 5)
    {
        $u = parse_url($url);
        if (!is_array($u) || !isset($u['scheme'], $u['host'], $u['port']) || $u['scheme'] !== 'ws') {
            throw new Exception('not a ws:// address with a port');
        }
        $s = @stream_socket_client('tcp://' . $u['host'] . ':' . $u['port'], $errno, $errstr, $timeout);
        if ($s === false) {
            throw new Exception("cannot connect to the browser's DevTools port: $errstr");
        }
        $key = base64_encode(random_bytes(16));
        $path = (isset($u['path']) ? $u['path'] : '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        fwrite($s, "GET $path HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($s, $timeout);
        $head = '';
        while (strpos($head, "\r\n\r\n") === false && strlen($head) < 16384) {
            $c = fread($s, 1);
            if ($c === '' || $c === false) {
                throw new Exception('the browser closed the connection during the WebSocket handshake');
            }
            $head .= $c;
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('#^HTTP/1\.1 101 #', $head) || stripos($head, "\r\nSec-WebSocket-Accept: $accept\r\n") === false) {
            throw new Exception('the browser refused the WebSocket handshake');
        }
        stream_set_blocking($s, false);
        $this->s = $s;
    }

    public function stream()
    {
        return $this->s;
    }

    public function send(array $msg)
    {
        $data = self::frame(json_encode($msg, JSON_UNESCAPED_SLASHES), 0x1);
        for ($off = 0; $off < strlen($data) && !$this->closed;) {
            $n = @fwrite($this->s, substr($data, $off));
            if ($n === false || ($n === 0 && feof($this->s))) {
                $this->closed = true;
            } elseif ($n === 0) {
                usleep(1000);
            }
            $off += (int) $n;
        }
    }

    /** Reads what has arrived; returns the complete messages, decoded. Sets $closed when the connection ended. */
    public function read()
    {
        $chunk = @fread($this->s, 1048576);
        if (($chunk === '' || $chunk === false) && feof($this->s)) {
            $this->closed = true;
            return [];
        }
        $this->buf .= (string) $chunk;
        $out = [];
        foreach (self::parse($this->buf) as $f) {
            if ($f['op'] === 0x8) {
                $this->closed = true;
            } elseif ($f['op'] === 0x9) {
                @fwrite($this->s, self::frame($f['data'], 0xA));
            } elseif ($f['op'] === 0x1 || $f['op'] === 0x0) {
                $this->parts .= $f['data'];
                if (strlen($this->parts) > self::MAX) {
                    $this->closed = true;
                } elseif ($f['fin']) {
                    $m = json_decode($this->parts, true);
                    $this->parts = '';
                    if (is_array($m)) {
                        $out[] = $m;
                    }
                }
            }
        }
        return $out;
    }

    /** One masked client frame (RFC 6455 5.2). */
    public static function frame($data, $op)
    {
        $len = strlen($data);
        $head = chr(0x80 | $op) . ($len < 126 ? chr(0x80 | $len) : ($len < 65536 ? chr(0x80 | 126) . pack('n', $len) : chr(0x80 | 127) . pack('J', $len)));
        $mask = random_bytes(4);
        return $head . $mask . ($data ^ str_repeat($mask, (int) ceil(($len ?: 1) / 4)));
    }

    /** The complete frames at the start of $buf (removed from it): [{op, fin, data}]; a masked server frame is unmasked. */
    public static function parse(&$buf)
    {
        $frames = [];
        while (strlen($buf) >= 2) {
            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $len = $b1 & 0x7f;
            $at = 2;
            if ($len === 126) {
                if (strlen($buf) < 4) {
                    break;
                }
                $len = unpack('n', substr($buf, 2, 2))[1];
                $at = 4;
            } elseif ($len === 127) {
                if (strlen($buf) < 10) {
                    break;
                }
                $len = unpack('J', substr($buf, 2, 8))[1];
                $at = 10;
            }
            $mask = ($b1 & 0x80) ? 4 : 0;
            if ($len > self::MAX) {
                $frames[] = ['op' => 0x8, 'fin' => true, 'data' => '']; // too large to hold: treated as the end of the connection
                $buf = '';
                break;
            }
            if (strlen($buf) < $at + $mask + $len) {
                break;
            }
            $data = (string) substr($buf, $at + $mask, $len);
            if ($mask) {
                $data ^= str_repeat(substr($buf, $at, 4), (int) ceil(($len ?: 1) / 4));
                $data = substr($data, 0, $len);
            }
            $frames[] = ['op' => $b0 & 0x0f, 'fin' => ($b0 & 0x80) !== 0, 'data' => $data];
            $buf = (string) substr($buf, $at + $mask + $len);
        }
        return $frames;
    }
}
