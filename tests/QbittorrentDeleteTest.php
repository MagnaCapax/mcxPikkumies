<?php
/** tools/torrent-clients/qbittorrent: ids, paging and what remove --with-data checks, against a fake WebUI (tests/fixtures/qbittorrent/router.php); no real client is contacted. */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/qbittorrent/QbitSecret.php';

$A = str_repeat('a', 40);
$B = str_repeat('b', 40);

return [
    'qbittorrent: magnet and .torrent ids follow qBittorrent (v2 and hybrid use the truncated v2 hash)' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Bencode.php';
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/qbittorrent/QbitAdd.php';
        $v2 = str_repeat('ab', 32);
        check(QbitAdd::magnet('magnet:?xt=urn:btih:' . str_repeat('C', 40) . "&xt=urn:btmh:1220$v2&dn=Hy")[0] === substr($v2, 0, 40), 'hybrid magnet');
        $info = 'd12:meta versioni2e4:name1:ze';
        check(QbitAdd::torrentFile("d4:info{$info}e") === [substr(hash('sha256', $info), 0, 40), 'z'], 'v2 torrent id');
        check(QbitAdd::torrentFile('d4:infod4:name') === ['', ''] && Bencode::skip('l' . str_repeat('l', 100), 0) === -1, 'malformed input must not loop');
    },
    'qbittorrent: torrents/info is read in pages (limit/offset), and an oversized answer says so' => function () {
        qbitWith([], function ($fx) {
            $fx['page'] = 3;
            list($code, $out) = qbitRun($fx, ['list', '--limit', '50']);
            $ids = array_filter(array_column(cliLines($out), 'id'));
            $pages = array_values(array_filter(qbitState($fx)['calls'], function ($c) {
                return $c[1] === 'torrents/info' && !isset($c[2]['hashes']);
            }));
            check($code === 0 && count($ids) === 10 && count(array_unique($ids)) === 10, 'paged list: ' . $out);
            check(count($pages) === 4 && $pages[1][2]['limit'] === '3' && $pages[1][2]['offset'] === '3' && $pages[0][2]['sort'] === 'hash', 'pages: ' . json_encode($pages));
            $s = qbitState($fx);
            $s['huge'] = true;
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'larger than') !== false && strpos($out, 'not JSON') === false, 'oversized answer: ' . $out);
        });
    },
    'qbittorrent: a torrent without a root folder has its files under content_path (the incomplete folder while it downloads)' => function () {
        qbitWith([], function ($fx) {
            $inc = $fx['home'] . '/incomplete';
            mkdir("$inc/d", 0700, true);
            file_put_contents("$inc/x1.bin", 'one');
            file_put_contents("$inc/d/x2.bin", 'two!');
            $s = qbitState($fx);
            $s['torrents'][] = ['hash' => str_repeat('7', 40), 'name' => 'NoRoot', 'state' => 'downloading', 'progress' => 0.5, 'size' => 7, 'completed' => 3, 'ratio' => 0,
                'dlspeed' => 1, 'upspeed' => 0, 'added_on' => 1, 'category' => '', 'content_path' => $inc, 'save_path' => $fx['data'],
                'files' => [['index' => 0, 'name' => 'x1.bin', 'size' => 3], ['index' => 1, 'name' => 'd/x2.bin', 'size' => 4]], 'props' => []];
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $out) = qbitRun($fx, ['show', str_repeat('7', 40), '--files']);
            $l = cliLines($out);
            $files = array_column(array_filter($l, function ($r) {
                return isset($r['file']);
            }), 'file');
            check($code === 0 && $files === ["$inc/x1.bin", "$inc/d/x2.bin"] && $l[count($l) - 1]['expect'] === '7B:7', 'no-root paths: ' . $out);
        });
    },
    'qbittorrent: a refused --with-data (wrong --expect, a file shared in this client) sends no torrents/delete' => function () use ($B) {
        qbitWith([], function ($fx) use ($B) {
            list($code, $out) = qbitRun($fx, ['remove', $B, '--with-data', '--expect', '5.8KiB:5999']);
            check($code === 1 && qbitState($fx)['deleted'] === [] && is_file($fx['data'] . '/Beta/x.bin'), 'wrong --expect: ' . $out);
            $s = qbitState($fx);
            $s['torrents'][] = ['hash' => str_repeat('6', 40), 'name' => 'Beta twin', 'state' => 'uploading', 'progress' => 1, 'size' => 5000, 'completed' => 5000, 'ratio' => 0,
                'dlspeed' => 0, 'upspeed' => 0, 'added_on' => 1, 'category' => '', 'content_path' => $fx['data'] . '/Beta', 'save_path' => $fx['data'],
                'files' => [['index' => 0, 'name' => 'Beta/x.bin', 'size' => 5000]], 'props' => []];
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $out) = qbitRun($fx, ['remove', $B, '--with-data', '--expect', '5.9KiB:6000']);
            check($code === 1 && strpos($out, 'Beta twin') !== false && strpos($out, 'in this client') !== false && qbitState($fx)['deleted'] === [], 'shared file: ' . $out);
        });
    },
    'qbittorrent: the shared-file check lists every other torrent\'s files,
        also a cross-seed of symlinks in another folder' => function () use ($A) {
        qbitWith([], function ($fx) use ($A) {
            $x = str_repeat('4', 40);
            mkdir($fx['home'] . '/cross/Pack', 0700, true);
            symlink($fx['data'] . '/Alpha.mkv', $fx['home'] . '/cross/Pack/Alpha.mkv'); // a cross-seed tool in symlink mode
            $s = qbitState($fx);
            $s['torrents'][] = ['hash' => $x, 'name' => 'Cross', 'state' => 'uploading', 'progress' => 1, 'size' => 3000,
                'completed' => 3000, 'ratio' => 0,
                'dlspeed' => 0, 'upspeed' => 0, 'added_on' => 1, 'category' => '', 'content_path' => $fx['home']
                    . '/cross/Pack', 'save_path' => $fx['home'] . '/cross',
                'files' => [['index' => 0, 'name' => 'Pack/Alpha.mkv', 'size' => 3000]], 'props' => []];
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $out) = qbitRun($fx, ['show', $A, '--files']);
            $l = cliLines($out);
            check($code === 0 && $l[count($l) - 1]['refused'] === [$A] && $l[1]['shared_with'] === [$x],
                'preview missed the cross-seed: ' . $out);
            check(in_array(['GET', 'torrents/files', ['hash' => $x]], qbitState($fx)['calls'], true),
                'the other torrent\'s files were not read');
            list($code, $out) = qbitRun($fx, ['remove', $A, '--with-data', '--expect', '2.9KiB:3000']);
            check($code === 1 && strpos($out, 'Cross') !== false && qbitState($fx)['deleted'] === [] && is_file($fx['data'] . '/Alpha.mkv'),
                'deleted: ' . $out);
        });
    },
];
