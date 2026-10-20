<?php
/**
 * A minimal raw-socket HTTP/1.1 server bound to a UNIX SOCKET, for TransmissionUnixSocketTest.php.
 * `php -S` cannot bind a unix socket on PHP 7.3 (that flag was added in PHP 7.4), so this end-to-end
 * smoke test of lib/Http.php's new `unix_socket` option and the PMSS discovery path needs its own tiny
 * server: argv[1] is the socket path, argv[2] the session id every request must eventually carry
 * (rpc-spec.md's X-Transmission-Session-Id 409 handshake). The first request on each connection that
 * lacks it gets a 409 with the id in the response header; one that carries it gets a canned
 * `torrent-get` reply. `Connection: close` keeps this trivial (no persistent-connection handling).
 */
$socketPath = $argv[1];
$sessionId = $argv[2];
@unlink($socketPath);
$srv = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
if ($srv === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(1);
}
for ($n = 0; $n < 10; $n++) {
    $conn = @stream_socket_accept($srv, 10);
    if ($conn === false) {
        break;
    }
    $head = '';
    while (strpos($head, "\r\n\r\n") === false) {
        $chunk = fread($conn, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    $len = 0;
    if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m)) {
        $len = (int) $m[1];
    }
    list($headerText, $already) = explode("\r\n\r\n", $head, 2) + ['', ''];
    $body = $already;
    while (strlen($body) < $len) {
        $chunk = fread($conn, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    $hasSession = (bool) preg_match('/^X-Transmission-Session-Id:\s*' . preg_quote($sessionId, '/') . '\s*$/mi', $headerText);
    if (!$hasSession) {
        $resp = "HTTP/1.1 409 Conflict\r\nX-Transmission-Session-Id: $sessionId\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        fwrite($conn, $resp);
        fclose($conn);
        continue;
    }
    $req = json_decode($body, true);
    $method = is_array($req) && isset($req['method']) ? $req['method'] : '';
    $tag = is_array($req) && isset($req['tag']) ? $req['tag'] : null;
    if ($method === 'torrent-get') {
        $args = ['torrents' => [['hashString' => str_repeat('a', 40), 'name' => 'UnixSocketTorrent', 'status' => 6,
            'error' => 0, 'errorString' => '', 'isStalled' => false, 'percentDone' => 1.0, 'totalSize' => 1000,
            'sizeWhenDone' => 1000, 'leftUntilDone' => 0, 'uploadRatio' => 1.0, 'rateDownload' => 0, 'rateUpload' => 0,
            'addedDate' => 1700000000, 'downloadDir' => '/x', 'labels' => [], 'doneDate' => 0, 'secondsSeeding' => 0,
            'isPrivate' => false, 'uploadedEver' => 0]]];
        $payload = json_encode(['result' => 'success', 'arguments' => $args, 'tag' => $tag]);
    } else {
        $payload = json_encode(['result' => 'success', 'arguments' => [], 'tag' => $tag]);
    }
    $resp = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n$payload";
    fwrite($conn, $resp);
    fclose($conn);
}
fclose($srv);
@unlink($socketPath);
