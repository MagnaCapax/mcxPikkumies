<?php
/**
 * tools/torrent-clients/rtorrent: what remove --with-data deletes, previews and refuses, against a fake rTorrent
 * (tests/fixtures/rtorrent/server.php, helpers in tests/fixtures/rtorrent/helpers.php). Nothing real is contacted.
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';

return [
    'rtorrent: the preview leaves out files the deleter will skip (behind a symlinked folder)' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['show', str_repeat('e', 40), '--files']);
            $by = array_column(array_filter($l, function ($r) {
                return isset($r['file']);
            }), null, 'file');
            $s = $l[count($l) - 1];
            check($code === 0 && $s['expect'] === '1000B:1000' && $s['freed_bytes'] === 1000 && $s['skipped_files'] === 1, 'the skipped file was counted: ' . $out);
            check(isset($by["$h/data/Linked/lnk/victim.mkv"]['skip']) && !isset($by["$h/data/Linked/real.mkv"]['skip']), 'skip not marked: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: plain remove clears a leftover ruTorrent erasedata flag (d.custom5) so rtorrent deletes nothing' => function () {
        $h = rtorrentHome();
        $s = rtorrentState($h);
        $s['torrents'][1]['custom5'] = '1'; // set by a failed ruTorrent "delete with data"; the fake's erase hook then deletes the files
        file_put_contents("$h/state.json", json_encode($s));
        $p = rtorrentServe($h);
        try {
            list($code, $out) = rtorrentRun($h, ['remove', str_repeat('b', 40)]);
            check($code === 0 && is_file("$h/data/single.mkv"), 'plain remove deleted data through custom5: ' . $out);
            $names = array_column(rtorrentState($h)['calls'], 0);
            check(array_search('d.custom5.set', $names, true) < array_search('d.erase', $names, true), 'custom5 not cleared before d.erase: ' . implode(',', $names));
        } finally {
            rtorrentDone($h, $p);
        }
    },
    'rtorrent: --with-data refuses a file path with .., an absolute path, or a folder outside home; nothing is erased or unlinked' => function () {
        $h = rtorrentHome();
        rtorrentAddRefused($h);
        $p = rtorrentServe($h);
        try {
            foreach (['1' => 'leaves its directory', '2' => 'leaves its directory', '3' => 'no directory inside your home'] as $c => $needle) {
                list($code, $out) = rtorrentRun($h, ['show', str_repeat($c, 40), '--files']);
                check($code === 1 && strpos($out, $needle) !== false, "the preview of torrent $c must refuse as the delete does: $out");
                list($code, $out) = rtorrentRun($h, ['remove', str_repeat($c, 40), '--with-data', '--expect', '1B:1']);
                check($code === 1 && strpos($out, $needle) !== false, "torrent $c: $out");
            }
            check(!rtorrentCalls($h, 'd.erase') && is_file("$h/data/single.mkv") && is_file("$h/outside/victim.mkv") && is_file("$h-away/f.bin"), 'something was erased or deleted');
        } finally {
            rtorrentDone($h, $p);
            exec('rm -rf ' . escapeshellarg("$h-away"));
        }
    },
    'rtorrent: a refused batch changes nothing: every plan stands before d.custom5 is cleared,
        and the preview refuses it first' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/RtorrentClient.php';
        $h = rtorrentHome();
        rtorrentAddRefused($h, '1');
        $p = rtorrentServe($h);
        $multi = str_repeat('a', 40);
        try {
            // Multi is fine, Bad3's folder is outside the home: before, the preview counted Bad3's file as `skip`, so this
            // --expect (Multi alone) passed and the deleter cleared Multi's flag before refusing Bad3.
            list($code, $out) = rtorrentRun($h, ['remove', $multi, str_repeat('3', 40), '--with-data', '--expect', '2.9KiB:3000']);
            check($code === 1 && strpos($out, 'no directory inside your home') !== false, 'batch with an outside folder: ' . $out);
            check(!rtorrentCalls($h, 'd.custom5.set') && rtorrentState($h)['torrents'][0]['custom5'] === '1',
                'a refused batch changed d.custom5');
            // The deleter itself, past the plan: Bad1 lists ../single.mkv; Multi's flag must stay until every plan stands.
            try {
                RtorrentDeleter::remove(new RtorrentClient('pmss', $h, posix_geteuid()), [strtoupper($multi), str_repeat('1', 40)], true);
                check(false, 'the deleter accepted a path that leaves its folder');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'leaves its directory') !== false, 'refusal: ' . $e->getMessage());
            }
            clearstatcache();
            check(!rtorrentCalls($h, 'd.custom5.set') && !rtorrentCalls($h, 'd.erase') && is_file("$h/data/Multi/a.mkv"),
                'the deleter changed something before refusing');
        } finally {
            rtorrentDone($h, $p);
            exec('rm -rf ' . escapeshellarg("$h-away"));
        }
    },
];
