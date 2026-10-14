<?php
/** tools/torrent-clients/qbittorrent: list, show, add, pause and remove against a fake WebUI (tests/fixtures/qbittorrent/router.php); no real client is contacted. */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/qbittorrent/QbitSecret.php';

$A = str_repeat('a', 40);
$B = str_repeat('b', 40);

return [
    'qbittorrent: list maps the client states to the shared words; one login, ids lower case, native kept internal' => function () use ($B) {
        qbitWith([], function ($fx) use ($B) {
            list($code, $out) = qbitRun($fx, ['list', '--limit', '50']);
            $by = [];
            foreach (cliLines($out) as $r) {
                if (isset($r['id'])) {
                    $by[$r['name']] = $r;
                }
            }
            $want = ['Alpha' => 'seeding', 'Beta' => 'paused', 'Gamma' => 'paused', 'T-stalledDL' => 'stalled', 'T-checkingUP' => 'checking', 'T-queuedDL' => 'queued',
                'T-error' => 'error', 'T-missingFiles' => 'error', 'T-metaDL' => 'downloading', 'T-moving' => 'unknown'];
            foreach ($want as $name => $st) {
                check(isset($by[$name]) && $by[$name]['state'] === $st, "$name: expected $st: $out");
            }
            check($code === 0 && $by['Beta']['id'] === $B && !isset($by['Beta']['native']) && array_keys($by['Alpha'])[0] === 'id', 'record shape: ' . $out);
            check($by['Alpha']['path'] === $fx['data'] . '/Alpha.mkv' && $by['Alpha']['size'] === 3000 && $by['Alpha']['up_rate'] === 20, 'fields: ' . $out);
            check(qbitState($fx)['logins'] === 1, 'expected exactly one login');
            list(, $out) = qbitRun($fx, ['list', '--state', 'paused']);
            check(count(cliLines($out)) === 3, 'state filter: ' . $out);
        });
    },
    'qbittorrent: show resolves prefixes and the client id; --files gives absolute paths and the delete preview' => function () use ($B) {
        qbitWith([], function ($fx) use ($B) {
            list($code, $out) = qbitRun($fx, ['show', 'aaaaaaaa']);
            $r = cliLines($out)[0];
            check($code === 0 && $r['seeds'] === 4 && $r['comment'] === 'hi' && !isset($r['trackers']), 'show: ' . $out);
            check(strpos($out, 'SECRETKEY123') === false && !in_array('GET torrents/trackers', qbitCalls($fx), true),
                'show without --trackers read the trackers: ' . $out);
            list($code, $out) = qbitRun($fx, ['show', $B, 'aaaaaaaa', '--files']);
            $files = array_column(array_filter(cliLines($out), function ($l) {
                return isset($l['file']);
            }), 'file');
            sort($files);
            check($files === [$fx['data'] . '/Alpha.mkv', $fx['data'] . '/Beta/sub/y.bin', $fx['data'] . '/Beta/x.bin'], 'paths: ' . $out);
            $calls = qbitState($fx)['calls'];
            check(in_array(['GET', 'torrents/files', ['hash' => str_repeat('B', 40)]], $calls, true), 'the client id string must be sent as the client gave it');
        });
    },
    'qbittorrent: add computes ids (hex and base32 magnets, .torrent info hash), sends stopped on 5.x, confirms in the list' => function () use ($A) {
        $base32 = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U'; // 20 bytes "abcdefghijklmnopqrst"
        qbitWith(['base32_hash' => bin2hex('abcdefghijklmnopqrst')], function ($fx) use ($A, $base32) {
            $info = 'd6:lengthi3e4:name5:x.bin12:piece lengthi16384e6:pieces20:' . str_repeat('p', 20) . 'e';
            file_put_contents($fx['dir'] . '/x.torrent', 'd8:announce9:http://t/4:info' . $info . 'e');
            $hex = str_repeat('9', 40);
            list($code, $out) = qbitRun($fx, ['add', "magnet:?xt=urn:btih:$hex&dn=Nine", "magnet:?xt=urn:btih:$base32", $fx['dir'] . '/x.torrent', "magnet:?xt=urn:btih:$A", '/no/such.file', '--paused']);
            $l = cliLines($out);
            check($l[0]['id'] === $hex && $l[0]['result'] === 'added', 'hex magnet: ' . $out);
            check($l[1]['id'] === bin2hex('abcdefghijklmnopqrst') && $l[1]['result'] === 'added', 'base32 magnet: ' . $out);
            check($l[2]['id'] === sha1($info) && $l[2]['result'] === 'added', '.torrent id: ' . $out);
            check($l[3]['result'] === 'already in the client' && strpos($l[4]['result'], 'error') === 0, 'duplicate / bad item: ' . $out);
            check($code === 0 && end($l)['verified'] === false, 'one failed item must leave verified false: ' . $out);
            $adds = array_values(array_filter(qbitState($fx)['calls'], function ($c) {
                return $c[1] === 'torrents/add';
            }));
            check(count($adds) === 3 && $adds[0][2]['stopped'] === 'true' && !isset($adds[0][2]['paused']), '5.x must get stopped=true: ' . json_encode($adds));
        });
        qbitWith(['api' => '2.8.19'], function ($fx) {
            list($code) = qbitRun($fx, ['add', 'magnet:?xt=urn:btih:' . str_repeat('8', 40), '--dir', '/x/y']);
            check($code === 1 && !in_array('POST torrents/add', qbitCalls($fx), true), 'a --dir outside the home was sent');
            qbitRun($fx, ['add', 'magnet:?xt=urn:btih:' . str_repeat('8', 40), '--paused', '--dir', $fx['data'] . '/Beta/']);
            $c = array_values(array_filter(qbitState($fx)['calls'], function ($c) {
                return $c[1] === 'torrents/add';
            }))[0][2];
            check($c['paused'] === 'true' && !isset($c['stopped']) && $c['savepath'] === realpath($fx['data'] . '/Beta'), '4.x must get paused=true: ' . json_encode($c));
        });
    },
    'qbittorrent: pause and resume use stop/start on 5.x and pause/resume on 4.x, read the state back, never send all' => function () use ($A) {
        foreach (['2.11.2' => ['torrents/stop', 'torrents/start'], '2.8.19' => ['torrents/pause', 'torrents/resume']] as $api => $paths) {
            qbitWith(['api' => $api], function ($fx) use ($A, $paths, $api) {
                list($code, $out) = qbitRun($fx, ['pause', $A]);
                $l = cliLines($out);
                check($code === 0 && $l[0]['state'] === 'paused' && $l[1]['verified'] === true, "$api pause: $out");
                list($code, $out) = qbitRun($fx, ['resume', 'aaaaaaaa']);
                check($code === 0 && cliLines($out)[0]['state'] === 'seeding', "$api resume: $out");
                $calls = qbitCalls($fx);
                check(in_array("POST {$paths[0]}", $calls, true) && in_array("POST {$paths[1]}", $calls, true), "$api endpoints: " . implode(', ', $calls));
                check(empty(qbitState($fx)['sent_all']), 'the client "all" value was sent');
            });
        }
    },
    'qbittorrent: remove keeps files; --with-data reports trashed (5.x default) or deleted with freed bytes' => function () use ($A, $B) {
        qbitWith([], function ($fx) use ($A) {
            list($code, $out) = qbitRun($fx, ['remove', $A]);
            $l = cliLines($out);
            check($code === 0 && $l[0]['disposal'] === 'kept' && qbitState($fx)['deleted'][0]['deleteFiles'] === 'false', 'plain remove: ' . $out);
        });
        qbitWith([], function ($fx) use ($B) {
            list($code, $out) = qbitRun($fx, ['remove', $B, '--with-data', '--expect', '5.9KiB:6000']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['disposal'] === 'trashed' && $l[1]['freed_bytes'] === 0 && qbitState($fx)['deleted'][0]['deleteFiles'] === 'true', 'trash: ' . $out);
        });
        qbitWith(['prefs' => ['torrent_content_remove_option' => 'Delete', 'listen_port' => 1, 'save_path' => '/tmp']], function ($fx) use ($B) {
            list($code, $out) = qbitRun($fx, ['remove', $B, '--with-data', '--expect', '5.9KiB:6000']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['disposal'] === 'deleted' && $l[1]['freed_bytes'] === 6000, 'delete: ' . $out);
        });
    },
];
