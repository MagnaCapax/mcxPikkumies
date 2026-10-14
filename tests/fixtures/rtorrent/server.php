<?php
/**
 * A fake rTorrent SCGI server for the tests/Rtorrent*Test.php files: php server.php <unix:///path|tcp://127.0.0.1:N> <state.json> [hang]
 * It answers the XML-RPC calls the rtorrent unit makes from the torrents in state.json and logs
 * every call there. Shapes, as rTorrent 0.9.8 produces them:
 * - request: netstring header, CONTENT_LENGTH first, then the XML body (rpc/scgi_task.cc:139-146);
 * - reply: "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: N\r\n\r\n" + methodResponse (scgi_task.cc:234);
 * - integers as <i8>, strings as <string>, lists as <array> (xmlrpc-c, as rtorrent's xmlrpc.cc builds them);
 * - an unknown method is a <fault> struct with faultCode and faultString (XML-RPC spec; xmlrpc-c's
 *   "method not defined" is -506);
 * - d.hash is upper-case hex (command_download.cc:676); d.ratio is in thousandths (:229-237);
 * - load.* return 0 and add asynchronously (command_events.cc:198-213, 383-392).
 * In "hang" mode it listens and never accepts, like a busy rtorrent whose accept queue is full.
 */
require __DIR__ . '/codec.php';
list(, $addr, $stateFile) = $argv;
$server = stream_socket_server($addr, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
if (isset($argv[3]) && $argv[3] === 'hang') {
    while (true) {
        sleep(60);
    }
}
while (true) {
    $c = @stream_socket_accept($server, 3600);
    if (!$c) {
        continue;
    }
    $buf = '';
    while (!preg_match('/^(\d+):/', $buf, $m) || strlen($buf) < strlen($m[0]) + (int) $m[1] + 1 || !preg_match('/^CONTENT_LENGTH\x00(\d+)\x00/', substr($buf, strlen($m[0])), $cl)
        || strlen($buf) < strlen($m[0]) + (int) $m[1] + 1 + (int) $cl[1]) {
        $chunk = fread($c, 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $buf .= $chunk;
    }
    $body = substr($buf, strlen($m[0]) + (int) $m[1] + 1);
    $xml = simplexml_load_string($body);
    $method = (string) $xml->methodName;
    $params = [];
    foreach ($xml->params->param as $p) {
        $params[] = fakeDecode($p->value);
    }
    $state = json_decode(file_get_contents($stateFile), true);
    try {
        $result = fakeAnswer($state, $method, $params);
        $out = '<?xml version="1.0" encoding="UTF-8"?><methodResponse><params><param>' . fakeEncode($result) . '</param></params></methodResponse>';
    } catch (Exception $e) {
        $out = '<?xml version="1.0" encoding="UTF-8"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><i4>-506</i4></value></member>'
            . '<member><name>faultString</name><value><string>' . htmlspecialchars($e->getMessage(), ENT_XML1) . '</string></value></member></struct></value></fault></methodResponse>';
    }
    $state['calls'][] = array_merge([$method], array_map(function ($p) {
        return is_array($p) && isset($p['base64']) ? 'base64:' . strlen($p['base64']) : $p;
    }, $params));
    file_put_contents($stateFile, json_encode($state));
    fwrite($c, "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($out) . "\r\n\r\n" . $out);
    fclose($c);
}

function fakeAnswer(array &$state, $method, array $p)
{
    $sys = ['system.client_version' => '0.9.8', 'system.library_version' => '0.13.8', 'system.pid' => 4242, 'system.time' => 1760000000,
        'network.listen.port' => 51413, 'throttle.global_down.rate' => 1000, 'throttle.global_up.rate' => 2000, 'throttle.global_down.total' => 10000,
        'throttle.global_up.total' => 20000, 'throttle.global_down.max_rate' => 0, 'throttle.global_up.max_rate' => 512000];
    if (isset($sys[$method])) {
        return $sys[$method];
    }
    if ($method === 'download_list') {
        return array_column($state['torrents'], 'hash');
    }
    if ($method === 'd.multicall2') {
        return array_map(function ($t) use ($p) {
            return array_map(function ($f) use ($t) {
                return fakeGet($t, $f);
            }, array_slice($p, 2));
        }, $state['torrents']);
    }
    if (strpos($method, 'load.') === 0) {
        $src = $p[1];
        if (is_array($src)) { // the .torrent fixtures put "info" last, so its bencoded value runs to the final "e"
            $hash = strtoupper(sha1(substr($src['base64'], strpos($src['base64'], '4:info') + 6, -1)));
        } else {
            $hash = preg_match('/xt=urn:btih:([0-9a-fA-F]{40})/', $src, $m) ? strtoupper($m[1]) : '';
        }
        if ($hash !== '') {
            $state['torrents'][] = ['hash' => $hash, 'name' => 'added', 'state' => strpos($method, 'start') !== false ? 1 : 0, 'dir' => '', 'files' => []];
        }
        return 0;
    }
    if (!isset($p[0])) {
        throw new Exception("Method '$method' not defined"); // e.g. system.startup_time, which only the PMSS rc defines
    }
    $t = &fakeFind($state, (string) $p[0]);
    if ($method === 'd.custom5.set') {
        $t['custom5'] = (string) $p[1];
        return 0;
    }
    if ($method === 'd.erase') {
        if (!empty($t['custom5'])) { // ruTorrent's erasedata on-erase hook deletes the files when custom5 is set (PMSS rutorrent/plugins/erasedata/init.php:10-22)
            foreach ($t['files'] as $f) {
                @unlink($t['dir'] . '/' . $f[0]);
            }
        }
        $t['erased_while_files_existed'] = array_values(array_filter($t['files'], function ($f) use ($t) {
            return file_exists($t['dir'] . '/' . $f[0]);
        }));
        $state['erased'][] = $t;
        $state['torrents'] = array_values(array_filter($state['torrents'], function ($x) use ($t) {
            return $x['hash'] !== $t['hash'];
        }));
        return 0;
    }
    if ($method === 'd.stop' || $method === 'd.start') {
        $t['state'] = $t['open'] = $t['active'] = $method === 'd.start' ? 1 : 0;
        return 0;
    }
    if ($method === 'f.multicall') {
        return array_map(function ($f) use ($p) {
            return array_map(function ($c) use ($f) {
                return $c === 'f.path=' ? $f[0] : $f[1];
            }, array_slice($p, 2));
        }, $t['files']);
    }
    if ($method === 't.multicall') {
        return [['https://tracker.example/announce?passkey=SECRETPASSKEY123', 1, 7, 3]];
    }
    return fakeGet($t, $method);
}
