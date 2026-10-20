<?php
/**
 * A fake qBittorrent WebUI API v2 for the tests/Qbittorrent*Test.php files, run with `php -S`. State lives in the
 * JSON file named by QBIT_FAKE_STATE. Shapes follow the qBittorrent WebUI API source: login answers
 * "Ok." + Set-Cookie SID or "Fails." (qbt-wiki-5.md:133-160); every other call needs the cookie,
 * else 403 (webapp-5.0.0.cpp:323-324); GET reads / POST changes, 405 otherwise (qbt-wiki-5.md:121-125);
 * torrents/info rows (:1202-1338), properties (:1340-1440), files (:1540-1600), transfer/info
 * (:1028-1086); stop/start exist from API 2.11, pause/resume before (tc-5.0.0.cpp:928-942, research §2.3);
 * add answers "Ok."/"Fails." and takes `stopped` (5.x) or `paused` (4.x) (tc-5.0.0.cpp:693-806); recheck and reannounce take
 * hashes (tc-5.0.0.cpp:1260-1274); the global limits are bytes/s kept as whole KiB/s (sessionimpl-5.0.0.cpp:3359-3373);
 * trackers rows (tc-5.0.0.cpp:506-530) start with the DHT/PeX/LSD rows (:175-245).
 * With `v52` set it answers as qBittorrent 5.2 (release-5.2.4 source, and live 5.2.4 / API 2.15.1 on 2026-10-14): login
 * 204 + cookie or 401; add a JSON object with added_torrent_ids and success_count, 409 when nothing was added; a URL
 * http://fake.example/<40 hex>.torrent is added under that id.
 */
$file = getenv('QBIT_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$api = substr($path, strlen('/api/v2/'));
$method = $_SERVER['REQUEST_METHOD'];
$in = $method === 'POST' ? $_POST : $_GET;
$s['calls'][] = [$method, $api, $in];
$done = function ($code, $body, $json = false) use (&$s, $file) {
    file_put_contents($file, json_encode($s));
    http_response_code($code);
    header('Content-Type: ' . ($json ? 'application/json' : 'text/plain'));
    echo $json ? json_encode($body) : $body;
    exit;
};
if (!empty($s['slow'])) {
    sleep((int) $s['slow']);
}
if ($api === 'auth/login') {
    $s['logins']++;
    if (isset($_POST['username'], $_POST['password']) && $_POST['username'] === $s['user'] && $_POST['password'] === $s['password']) {
        header('Set-Cookie: SID=fakesid; HttpOnly; path=/; SameSite=Strict');
        empty($s['v52']) ? $done(200, 'Ok.') : $done(204, '');
    }
    empty($s['v52']) ? $done(200, 'Fails.') : $done(401, '');
}
if ($s['auth'] && (!isset($_COOKIE['SID']) || $_COOKIE['SID'] !== 'fakesid')) {
    $done(403, 'Forbidden');
}
$v5 = version_compare($s['api'], '2.11', '>=');
$writes = ['torrents/stop', 'torrents/start', 'torrents/pause', 'torrents/resume', 'torrents/delete', 'torrents/add', 'torrents/recheck',
    'torrents/reannounce', 'torrents/setCategory', 'torrents/addTags',
    'transfer/setDownloadLimit', 'transfer/setUploadLimit'];
if (in_array($api, $writes, true) !== ($method === 'POST')) {
    $done(405, 'Method Not Allowed');
}
$hashes = isset($in['hashes']) ? explode('|', $in['hashes']) : [];
if (in_array('all', $hashes, true) || (isset($in['hashes']) && $in['hashes'] === '')) {
    $s['sent_all'] = true;
}
$find = function ($h) use (&$s) {
    foreach ($s['torrents'] as $i => $t) {
        if ($t['hash'] === $h) {
            return $i;
        }
    }
    return -1;
};
$row = function ($t) {
    unset($t['files'], $t['props']);
    return $t;
};
switch ($api) {
    case 'app/webapiVersion':
        $done(200, $s['api']);
    case 'app/version':
        $done(200, $s['version']);
    case 'app/preferences':
        $done(200, $s['prefs'], true);
    case 'transfer/info':
        $done(200, $s['transfer'], true);
    case 'torrents/info': // sort, limit and offset as qbt-wiki-5.md:1213-1216 describes them
        if (!empty($s['huge'])) {
            $done(200, str_repeat('x', 6 * 1048576)); // more than lib/Http.php takes by default
        }
        $out = [];
        foreach ($s['torrents'] as $t) {
            if (!$hashes || in_array($t['hash'], $hashes, true)) {
                $out[] = $row($t);
            }
        }
        if (isset($in['sort'])) {
            usort($out, function ($a, $b) use ($in) {
                return strcmp((string) $a[$in['sort']], (string) $b[$in['sort']]);
            });
        }
        $out = array_slice($out, isset($in['offset']) ? (int) $in['offset'] : 0, isset($in['limit']) ? (int) $in['limit'] : null);
        $done(200, $out, true);
    case 'torrents/export': // GET, application/x-bittorrent bytes (qBittorrent master torrentscontroller.cpp exportAction())
        $i = $find(isset($in['hash']) ? $in['hash'] : '');
        if ($i < 0) {
            $done(404, 'Not Found');
        }
        if (!empty($s['torrents'][$i]['no_metadata'])) {
            $done(409, 'Conflict');
        }
        $done(200, isset($s['torrents'][$i]['torrent_file']) ? $s['torrents'][$i]['torrent_file'] : 'd4:infod4:name1:x6:lengthi1eee');
    case 'torrents/setCategory':
        foreach ($hashes as $h) {
            if (($i = $find($h)) >= 0) {
                $s['torrents'][$i]['category'] = isset($in['category']) ? $in['category'] : '';
            }
        }
        $done(200, '');
    case 'torrents/addTags':
        foreach ($hashes as $h) {
            if (($i = $find($h)) >= 0) {
                $s['torrents'][$i]['tags'] = isset($in['tags']) ? $in['tags'] : '';
            }
        }
        $done(200, '');
    case 'torrents/properties':
    case 'torrents/files':
    case 'torrents/trackers':
        $i = $find(isset($in['hash']) ? $in['hash'] : '');
        if ($i < 0) {
            $done(404, 'Not Found');
        }
        $t = $s['torrents'][$i];
        $done(200, $api === 'torrents/files' ? $t['files'] : ($api === 'torrents/trackers' ? [['url' => '** [DHT] **', 'status' => 0,
            'tier' => -1, 'msg' => ''],
            ['url' => 'https://tracker.example/announce?passkey=SECRETKEY123', 'status' => 2, 'tier' => 0, 'num_seeds' => 9,
                'num_leeches' => 2, 'msg' => 'working'],
            ['url' => 'http://tracker2.example:2710/SECRETPATHKEY4567abcd/announce', 'status' => 4, 'tier' => 1, 'num_seeds' => -1,
                'num_leeches' => -1, 'msg' => 'unregistered torrent']] : $t['props']), true);
    case 'torrents/recheck':
    case 'torrents/reannounce':
        foreach ($hashes as $h) {
            if ($api === 'torrents/recheck' && ($i = $find($h)) >= 0) {
                $s['torrents'][$i]['state'] = $s['torrents'][$i]['progress'] >= 1 ? 'checkingUP' : 'checkingDL';
            }
        }
        $done(200, '');
    case 'transfer/speedLimitsMode':
        $done(200, empty($s['alt']) ? '0' : '1');
    case 'transfer/setDownloadLimit':
    case 'transfer/setUploadLimit': // bytes/s in, kept as whole KiB/s: <= 0 none, <= 1024 one KiB, else limit / 1024
        // (sessionimpl-5.0.0.cpp:3359-3373)
        $l = isset($in['limit']) ? (int) $in['limit'] : 0;
        $key = $api === 'transfer/setDownloadLimit' ? 'dl_rate_limit' : 'up_rate_limit';
        $s['transfer'][$key] = $l <= 0 ? 0 : ($l <= 1024 ? 1024 : intdiv($l, 1024) * 1024);
        $done(200, '');
    case 'torrents/stop':
    case 'torrents/start':
    case 'torrents/pause':
    case 'torrents/resume':
        $stopName = in_array($api, ['torrents/stop', 'torrents/start'], true);
        if ($stopName !== $v5) {
            $done(404, 'Not Found'); // 5.0 has only stop/start, 4.x only pause/resume
        }
        foreach ($hashes as $h) {
            if (($i = $find($h)) >= 0) {
                $up = $s['torrents'][$i]['progress'] >= 1;
                $s['torrents'][$i]['state'] = in_array($api, ['torrents/stop', 'torrents/pause'], true) ? ($v5 ? 'stopped' : 'paused') . ($up ? 'UP' : 'DL') : ($up ? 'uploading' : 'downloading');
            }
        }
        $done(200, '');
    case 'torrents/delete':
        $s['deleted'][] = ['hashes' => $hashes, 'deleteFiles' => isset($in['deleteFiles']) ? $in['deleteFiles'] : null];
        foreach ($s['torrents'] as $t) { // the files leave their place (deleted, or moved to the trash)
            foreach (in_array($t['hash'], $hashes, true) && isset($in['deleteFiles']) && $in['deleteFiles'] === 'true' ? $t['files'] : [] as $file) {
                @unlink($t['save_path'] . '/' . $file['name']);
            }
        }
        $s['torrents'] = array_values(array_filter($s['torrents'], function ($t) use ($hashes) {
            return !in_array($t['hash'], $hashes, true);
        }));
        $done(200, '');
    case 'torrents/add':
        if (!empty($s['add_fails'])) { // test knob: simulate a re-add failure after a repath's remove already succeeded
            $done(409, '');
        }
        $new = [];
        if (!empty($_POST['urls']) && preg_match('/btih:([0-9a-fA-F]{40})/', $_POST['urls'], $m)) {
            $new[] = [strtolower($m[1]), 'magnet torrent'];
        } elseif (!empty($_POST['urls']) && preg_match('/btih:([A-Z2-7]{32})/', $_POST['urls'])) {
            $new[] = [$s['base32_hash'], 'base32 magnet'];
        } elseif (!empty($s['v52']) && !empty($_POST['urls'])
            && preg_match('#^http://fake\.example/([0-9a-f]{40})\.torrent$#', $_POST['urls'], $m)) {
            $new[] = [$m[1], 'url torrent'];
        }
        if (isset($_FILES['torrents']['tmp_name'])) {
            $data = (string) file_get_contents($_FILES['torrents']['tmp_name']);
            $p = strpos($data, '4:info');
            if ($p === false) {
                $done(415, 'not a valid torrent file');
            }
            $new[] = [sha1(substr($data, $p + 6, -1)), 'file torrent']; // the fixture files end with the info dictionary
        }
        foreach ($new as $n) {
            $s['torrents'][] = ['hash' => $n[0], 'name' => $n[1], 'state' => !empty($_POST['stopped']) ? 'stoppedDL' : (!empty($_POST['paused']) ? 'pausedDL' : 'metaDL'),
                'progress' => 0, 'size' => 0, 'completed' => 0, 'ratio' => 0, 'dlspeed' => 0, 'upspeed' => 0, 'added_on' => time(), 'category' => '',
                'content_path' => '', 'save_path' => isset($_POST['savepath']) ? $_POST['savepath'] : '/tmp', 'files' => [], 'props' => []];
        }
        if (!empty($s['v52'])) {
            $new ? $done(200, ['success_count' => count($new), 'failure_count' => 0, 'pending_count' => 0,
                'added_torrent_ids' => array_column($new, 0)], true) : $done(409, '');
        }
        $done(200, $new ? 'Ok.' : 'Fails.');
}
$done(404, 'Not Found');
