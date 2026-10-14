<?php
/**
 * tools/torrent-clients/qbittorrent against qBittorrent 5.2 answers (the fake's `v52`): login 204/401, the JSON add answer,
 * the `private` list key. Found by a live smoke run on 2026-10-14 against 5.2.4 (API 2.15.1) and 5.1.4 (API 2.11.4).
 */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';

return [
    'qbittorrent 5.2: a 204 login with a cookie is a login; 5.1 "Ok." still is' => function () {
        foreach ([['v52' => true, 'api' => '2.15.1', 'version' => 'v5.2.4'], ['api' => '2.11.4', 'version' => 'v5.1.4']] as $over) {
            qbitWith($over, function ($fx) use ($over) {
                list($code, $out) = qbitRun($fx, ['list']);
                check($code === 0 && count(cliLines($out)) > 2 && qbitState($fx)['logins'] === 1, $over['version'] . ': ' . $out);
            });
        }
    },
    'qbittorrent 5.2: a 401 login marks the stored password rejected; the next call sends no login' => function () {
        qbitWith(['v52' => true, 'api' => '2.15.1', 'password' => 'other'], function ($fx) {
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'rejected') !== false && qbitState($fx)['logins'] === 1, 'first failure: ' . $out);
            check(json_decode(file_get_contents($fx['dir'] . '/secrets/qbittorrent'), true)['rejected'] === true, 'not marked rejected');
            list($code, $out) = qbitRun($fx, ['stats']);
            check($code === 1 && qbitState($fx)['logins'] === 1, 'a rejected password was sent again (5 failures ban): ' . $out);
        });
    },
    'qbittorrent 5.2: the JSON add answer is a success; 409 is a refusal; a URL gets the id qBittorrent names' => function () {
        qbitWith(['v52' => true, 'api' => '2.15.1'], function ($fx) {
            $hex = str_repeat('9', 40);
            $url = str_repeat('7', 40);
            list($code, $out) = qbitRun($fx, ['add', "magnet:?xt=urn:btih:$hex&dn=Nine", "http://fake.example/$url.torrent",
                'http://fake.example/unknown.torrent']);
            $l = cliLines($out);
            check($l[0]['id'] === $hex && $l[0]['result'] === 'added', 'magnet: ' . $out);
            check($l[1]['id'] === $url && $l[1]['result'] === 'added', 'URL id from added_torrent_ids: ' . $out);
            check(strpos($l[2]['result'], 'error: qBittorrent refused it') === 0 && $code === 0 && end($l)['verified'] === false,
                'nothing added (409): ' . $out);
        });
    },
    'qbittorrent: private comes from `private` (5.0-5.2), else the older isPrivate; stats pid/uptime null when not found' => function () {
        qbitWith([], function ($fx) {
            list(, $out) = qbitRun($fx, ['list', '--search', 'a']);
            $by = array_column(cliLines($out), null, 'name');
            check($by['Alpha']['private'] === true && $by['Beta']['private'] === false && $by['Gamma']['private'] === null,
                'private: ' . $out);
            list($code, $out) = qbitRun($fx, ['stats']);
            $s = cliLines($out)[0];
            check($code === 0 && $s['pid'] === null && $s['uptime'] === null, 'no process found must be null, not 0: ' . $out);
        });
    },
];
