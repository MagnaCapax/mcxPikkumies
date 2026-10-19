<?php
/**
 * A fake browser DevTools endpoint for WebGuardProcessTest: one WebSocket client, scripted answers, then it closes the
 * connection the way a dying browser does. php fake-devtools.php <mode> <record file>; prints its port first.
 * Modes: ready (one existing page; answers everything; after the page is resumed sends one blocked request, then
 * closes), hold (the same page and answers, then stays open), refuse (answers the attach with an error and waits).
 */
require dirname(__DIR__, 3) . '/pikkumies/tools/system/browse/CdpSocket.php';
list(, $mode, $record) = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0');
$name = stream_socket_get_name($srv, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);
$c = @stream_socket_accept($srv, 15);
if ($c === false) {
    exit(1);
}
$head = '';
while (strpos($head, "\r\n\r\n") === false && !feof($c)) {
    $head .= fread($c, 1);
}
preg_match('/Sec-WebSocket-Key: (\S+)/i', $head, $m);
fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: "
    . base64_encode(sha1($m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)) . "\r\n\r\n");
function fakeSend($c, array $msg)
{
    $d = json_encode($msg, JSON_UNESCAPED_SLASHES);
    $n = strlen($d);
    fwrite($c, chr(0x81) . ($n < 126 ? chr($n) : chr(126) . pack('n', $n)) . $d);
}
$buf = '';
$deadline = time() + 15;
while (time() < $deadline && !feof($c)) {
    $r = [$c];
    $w = $x = null;
    if (!stream_select($r, $w, $x, 1)) {
        continue;
    }
    $buf .= (string) fread($c, 65536);
    foreach (CdpSocket::parse($buf) as $f) {
        $cmd = json_decode($f['data'], true);
        if (!is_array($cmd)) {
            continue;
        }
        file_put_contents($record, json_encode($cmd, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        $sid = isset($cmd['sessionId']) ? ['sessionId' => $cmd['sessionId']] : [];
        if ($cmd['method'] === 'Target.setAutoAttach' && !$sid) {
            if ($mode === 'refuse') {
                fakeSend($c, ['id' => $cmd['id'], 'error' => ['code' => -32000, 'message' => 'refused']]);
                continue;
            }
            fakeSend($c, ['method' => 'Target.attachedToTarget', 'params' => ['sessionId' => 'S1',
                'targetInfo' => ['targetId' => 'T1', 'type' => 'page', 'url' => 'about:blank'], 'waitingForDebugger' => false]]);
        }
        fakeSend($c, ['id' => $cmd['id'], 'result' => new stdClass()] + $sid);
        if ($cmd['method'] === 'Runtime.runIfWaitingForDebugger' && $mode === 'ready') {
            fakeSend($c, ['method' => 'Fetch.requestPaused', 'sessionId' => 'S1', 'params' => ['requestId' => 'job-1', 'frameId' => 'T1',
                'resourceType' => 'Document', 'request' => ['url' => 'https://panel.example.com/user-otheruser/deep?token=abc', 'method' => 'GET', 'headers' => []]]]);
        }
        if ($cmd['method'] === 'Fetch.failRequest') {
            usleep(300000);
            fclose($c); // the browser goes away
            exit(0);
        }
    }
}
