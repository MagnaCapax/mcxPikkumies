<?php
/**
 * A fake deluge-web for the tests/Deluge*Test.php files, run with `php -S 127.0.0.1:<port> router.php`; its state is
 * the JSON file named by DELUGE_FAKE_STATE. Every shape follows the Deluge 2.0.3 source:
 * - POST only, Content-Type exactly application/json, else error code 5 (deluge/ui/web/json_api.py:192-227, 238-253);
 * - reply {"result","error","id"}; code 1 not authenticated, 2 unknown method, 4 a failed call (json_api.py:133-190);
 * - core.* methods exist only after web.connect (json_api.py:92-104, 157-164, 425-434);
 * - auth.login sets _session_id with path <base>json (deluge/ui/web/auth.py:90-111, 243-256);
 * - web.get_hosts rows [id, host, port, username] (deluge/ui/hostlist.py:167-174); web.get_host_status answers
 *   [id, 'Connected', version] for the connected host, else a probe result (hostlist.py:176-236); web.disconnect
 *   (json_api.py:479-490). `connected_host` is the host deluge-web is connected to, null when none.
 * - status keys and states (deluge/core/torrent.py:51-60, 632-655, 1035-1140), file paths relative to
 *   download_location (torrent.py:93-123);
 * - pause_torrents/resume_torrents with an empty list act on every torrent (deluge/core/core.py:674-677, 725-728);
 * - remove_torrent(id, remove_data) returns true (core.py:574-589; libtorrent deletes the files).
 */
$file = getenv('DELUGE_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$reply = function ($result, $error = null, $id = null) {
    header('Content-Type: application/json');
    echo json_encode(['result' => $result, 'error' => $error, 'id' => $id]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!empty($s['sleep'])) {
    sleep((int) $s['sleep']);
}
$type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');
if ($type !== 'application/json') {
    $reply(null, ['code' => 5, 'message' => "JSONException: Invalid JSON request content-type: $type"]);
}
$raw = (string) file_get_contents('php://input');
$req = json_decode($raw, true);
$method = $req['method'];
$p = $req['params'];
$s['calls'][] = ['method' => $method, 'params' => $method === 'auth.login' ? ['***'] : $p, 'raw' => $method === 'auth.login' ? '' : $raw];
$save = function () use (&$s, $file) {
    file_put_contents($file, json_encode($s));
};
$out = function ($result, $error = null) use ($save, $reply, $req) {
    $save();
    $reply($result, $error, $req['id']);
};
if ($method === 'auth.login') {
    if ($p[0] !== $s['secret']) {
        $out(false);
    }
    header('Set-Cookie: _session_id=' . $s['sid'] . '; Path=/user-fake/deluge/json');
    $out(true);
}
if (!isset($_COOKIE['_session_id']) || $_COOKIE['_session_id'] !== $s['sid']) {
    $out(null, ['code' => 1, 'message' => 'Not authenticated']);
}
if ($method === 'web.connected') {
    $out($s['connected']);
}
if ($method === 'web.get_hosts') {
    $out($s['hosts']);
}
if ($method === 'web.get_host_status') {
    $out($s['connected'] && isset($s['connected_host']) && $s['connected_host'] === $p[0] ? [$p[0], 'Connected', '2.0.3'] : [$p[0], 'Offline', '']);
}
if ($method === 'web.disconnect') {
    $s['connected'] = false;
    $s['connected_host'] = null;
    $out('Connection was closed cleanly.');
}
if ($method === 'web.connect') {
    $s['connected'] = !empty($s['daemon_up']) && $p[0] === $s['hosts'][count($s['hosts']) - 1][0];
    $s['connected_host'] = $s['connected'] ? $p[0] : null;
    $out($s['connected'] ? ['core.get_torrents_status'] : null);
}
if (!$s['connected']) {
    $out(null, ['code' => 2, 'message' => 'Unknown method']);
}
$pick = function ($t, $keys) {
    return $keys ? array_intersect_key($t, array_flip($keys)) : $t;
};
switch ($method) {
    case 'core.get_torrents_status':
        $all = [];
        foreach ($s['torrents'] as $id => $t) {
            $all[$id] = $pick($t, $p[1]);
        }
        $out($all);
        break;
    case 'core.get_torrent_status':
        $out(isset($s['torrents'][$p[0]]) ? $pick($s['torrents'][$p[0]], $p[1]) : []);
        break;
    case 'core.pause_torrents':
    case 'core.resume_torrents':
        foreach ($p[0] ?: array_keys($s['torrents']) as $id) {
            $s['torrents'][$id]['state'] = $method === 'core.pause_torrents' ? 'Paused' : 'Seeding';
        }
        $out(null);
        break;
    case 'core.remove_torrent':
        $t = $s['torrents'][$p[0]];
        if ($p[1]) {
            foreach ($t['files'] as $f) {
                if (!in_array($f['path'], isset($s['keep_files']) ? $s['keep_files'] : [], true)) { // keep_files: ones libtorrent failed to delete
                    @unlink($t['download_location'] . '/' . $f['path']);
                }
            }
        }
        unset($s['torrents'][$p[0]]);
        $out(true);
        break;
    case 'core.add_torrent_magnet':
    case 'core.add_torrent_url':
    case 'core.add_torrent_file':
        $id = $method === 'core.add_torrent_magnet' && preg_match('/btih:([0-9a-fA-F]{40})/', $p[0], $m) ? strtolower($m[1]) : sha1($p[0] . (isset($p[1]) && is_string($p[1]) ? $p[1] : ''));
        if (isset($s['torrents'][$id])) {
            $out(null, ['code' => 4, 'message' => 'AddTorrentError: Torrent already in session (' . $id . ').']);
        }
        $s['torrents'][$id] = ['hash' => $id, 'name' => 'added', 'state' => 'Downloading', 'progress' => 0.0, 'total_wanted' => 0, 'total_done' => 0,
            'ratio' => -1.0, 'download_payload_rate' => 0, 'upload_payload_rate' => 0, 'time_added' => 1, 'download_location' => '/x', 'message' => 'OK',
            'tracker_status' => '', 'files' => []];
        $out($id);
        break;
    case 'core.get_session_status':
        $out(['payload_download_rate' => 1000, 'payload_upload_rate' => 2000, 'total_payload_download' => 30000, 'total_payload_upload' => 40000]);
        break;
    case 'core.get_config_values':
        $out(['max_download_speed' => -1.0, 'max_upload_speed' => 512.0]);
        break;
    case 'core.get_config_value': // core.py:812-815 (2.0.3)
        $out($p[0] === 'daemon_port' && isset($s['daemon_port']) ? $s['daemon_port'] : null);
        break;
    case 'core.get_free_space':
        $out(123456789);
        break;
    case 'daemon.get_version':
        $out('2.0.3');
        break;
    case 'core.get_libtorrent_version':
        $out('1.1.13.0');
        break;
    case 'core.get_listen_port':
        $out(58846);
        break;
}
$out(null, ['code' => 2, 'message' => 'Unknown method']);
