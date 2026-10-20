<?php
/**
 * A fake Transmission RPC server for the tests/Transmission*Test.php files, run with
 * `php -S 127.0.0.1:<port> router.php`; its state is the JSON file named by TRANSMISSION_FAKE_STATE.
 * Shapes follow rpc-spec.md on the 4.0.6 tag (see TransmissionRpc.php for the full citation):
 * - {"method","arguments","tag"} in, {"result","arguments","tag"} out (section 2);
 * - a 409 with the right X-Transmission-Session-Id, once, until the request repeats it (section 2.3.1);
 * - when $s['auth'] is set (['username','secret']), a 401 unless the Authorization header is exactly
 *   that Basic credential (section 2.3.3) -- checked before the session-id handshake, as real auth would be;
 * - torrent-get optional ids + required fields (section 3.3); torrent-add returns torrent-added or
 *   torrent-duplicate with result still "success" (section 3.4); torrent-remove/start/stop/verify/
 *   reannounce/set-location take `ids` (sections 3.1, 3.5, 3.6); session-get/session-set/session-stats
 *   (sections 4.1, 4.1.1, 4.1.2, 4.2).
 */
$file = getenv('TRANSMISSION_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$save = function () use (&$s, $file) {
    file_put_contents($file, json_encode($s));
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!empty($s['sleep'])) {
    sleep((int) $s['sleep']);
}
if (!empty($s['auth'])) {
    $want = 'Basic ' . base64_encode($s['auth']['username'] . ':' . $s['auth']['secret']);
    $got = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($got !== $want) {
        http_response_code(401);
        exit;
    }
}
$sent = isset($_SERVER['HTTP_X_TRANSMISSION_SESSION_ID']) ? $_SERVER['HTTP_X_TRANSMISSION_SESSION_ID'] : '';
if ($sent !== $s['session_id']) {
    header('X-Transmission-Session-Id: ' . $s['session_id']);
    http_response_code(409);
    exit;
}
$raw = (string) file_get_contents('php://input');
$req = json_decode($raw, true);
$method = isset($req['method']) ? $req['method'] : '';
$args = isset($req['arguments']) && is_array($req['arguments']) ? $req['arguments'] : [];
$tag = isset($req['tag']) ? $req['tag'] : null;
$s['calls'][] = ['method' => $method, 'arguments' => $args];
$reply = function ($result, $arguments = null) use (&$s, $save, $tag) {
    $save();
    header('Content-Type: application/json');
    $r = ['result' => $result, 'tag' => $tag];
    if ($arguments !== null) {
        $r['arguments'] = $arguments;
    }
    echo json_encode($r);
    exit;
};
$pick = function (array $t, $fields) {
    return $fields ? array_intersect_key($t, array_flip($fields)) : $t;
};
switch ($method) {
    case 'torrent-get':
        $ids = isset($args['ids']) ? (array) $args['ids'] : null;
        $fields = isset($args['fields']) ? (array) $args['fields'] : [];
        $out = [];
        foreach ($s['torrents'] as $hash => $t) {
            if ($ids !== null && !in_array($hash, $ids, true)) {
                continue;
            }
            $out[] = $pick($t, $fields);
        }
        $reply('success', ['torrents' => $out]);
        break;
    case 'torrent-start':
    case 'torrent-stop':
        $ids = isset($args['ids']) ? (array) $args['ids'] : array_keys($s['torrents']);
        foreach ($ids as $hash) {
            if (isset($s['torrents'][$hash])) {
                $s['torrents'][$hash]['status'] = $method === 'torrent-stop' ? 0 : 6;
            }
        }
        $reply('success');
        break;
    case 'torrent-remove':
        foreach ((array) $args['ids'] as $hash) {
            if (!isset($s['torrents'][$hash])) {
                continue;
            }
            if (!empty($args['delete-local-data'])) {
                foreach ($s['torrents'][$hash]['files'] as $f) {
                    @unlink($s['torrents'][$hash]['downloadDir'] . '/' . $f['name']);
                }
            }
            unset($s['torrents'][$hash]);
        }
        $reply('success');
        break;
    case 'torrent-set-location':
        foreach ((array) $args['ids'] as $hash) {
            if (isset($s['torrents'][$hash])) {
                $s['torrents'][$hash]['downloadDir'] = rtrim((string) $args['location'], '/');
            }
        }
        $reply('success');
        break;
    case 'torrent-verify':
    case 'torrent-reannounce':
        $reply('success');
        break;
    case 'session-set':
        foreach (['speed-limit-down', 'speed-limit-up', 'speed-limit-down-enabled', 'speed-limit-up-enabled'] as $k) {
            if (array_key_exists($k, $args)) {
                $s['session'][$k] = $args[$k];
            }
        }
        $reply('success');
        break;
    case 'session-get':
        $reply('success', $pick($s['session'], isset($args['fields']) ? (array) $args['fields'] : []));
        break;
    case 'session-stats':
        $reply('success', $s['stats']);
        break;
    case 'torrent-add':
        $dup = null;
        $name = 'added';
        if (isset($args['filename']) && stripos($args['filename'], 'magnet:') === 0) {
            $m1 = preg_match('/btih:([0-9a-fA-F]{40})/', $args['filename'], $m);
            $hash = $m1 ? strtolower($m[1]) : sha1($args['filename']);
            if (preg_match('/[?&]dn=([^&]*)/', $args['filename'], $mn)) {
                $name = urldecode($mn[1]);
            }
        } elseif (isset($args['metainfo'])) {
            $hash = sha1((string) base64_decode($args['metainfo']));
            $name = 'file-add';
        } else {
            $hash = sha1($args['filename']);
        }
        if (isset($s['torrents'][$hash])) {
            $dup = $s['torrents'][$hash];
            $reply('success', ['torrent-duplicate' => ['id' => 1, 'name' => $dup['name'], 'hashString' => $hash]]);
        }
        $s['torrents'][$hash] = ['hashString' => $hash, 'name' => $name, 'status' => !empty($args['paused']) ? 0 : 4,
            'error' => 0, 'errorString' => '', 'isStalled' => false, 'percentDone' => 0.0, 'totalSize' => 0, 'sizeWhenDone' => 0,
            'leftUntilDone' => 0, 'uploadRatio' => -1.0, 'rateDownload' => 0, 'rateUpload' => 0, 'addedDate' => 1,
            'downloadDir' => isset($args['download-dir']) ? $args['download-dir'] : '/x', 'labels' => [], 'doneDate' => 0,
            'secondsSeeding' => 0, 'isPrivate' => false, 'uploadedEver' => 0, 'files' => [], 'peers' => [], 'eta' => -1,
            'file-count' => 0, 'trackerStats' => [], 'torrentFile' => ''];
        $reply('success', ['torrent-added' => ['id' => $s['next_id']++, 'name' => $name, 'hashString' => $hash]]);
        break;
    default:
        $reply('no such method');
}
