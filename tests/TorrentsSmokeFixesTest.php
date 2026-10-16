<?php
/**
 * Defects a live smoke run found on 2026-10-14 (rTorrent 0.16.22, qBittorrent 5.1.4/5.2.4, Deluge 2.2.0), each against the
 * unit's fake: one name for the state counts, null for unknown counts, `show` record order and its not-found message, and
 * a found config without a binary named as such instead of "not installed".
 */
require_once __DIR__ . '/fixtures/rtorrent/helpers.php';
require_once __DIR__ . '/fixtures/deluge/helpers.php';
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/Locate.php';

return [
    'torrents: stats counts states as by_state in every unit, only states that have torrents' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out, $l) = rtorrentRun($h, ['stats']);
            check($code === 0 && isset($l[0]['by_state']) && !isset($l[0]['states']) && !in_array(0, $l[0]['by_state'], true),
                'rtorrent: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['stats']);
            check($code === 0 && $l[0]['by_state']['paused'] === 1 && !in_array(0, $l[0]['by_state'], true), 'deluge zero counts: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'torrents: show records start with id; an unknown id on a read says not found, not nothing changed' => function () {
        $h = rtorrentHome();
        $p = rtorrentServe($h);
        try {
            list($code, $out) = rtorrentRun($h, ['show', 'deadbeef']);
            check($code === 1 && strpos($out, '"not found: deadbeef: no such torrent') !== false, 'rtorrent show: ' . $out);
            list($code, $out) = rtorrentRun($h, ['pause', 'deadbeef']);
            check($code === 1 && strpos($out, 'nothing changed: deadbeef') !== false, 'a change verb keeps its words: ' . $out);
        } finally {
            rtorrentDone($h, $p);
        }
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['show', 'aaaaaaaa']);
            $r = cliLines($out)[0];
            check($code === 0 && array_slice(array_keys($r), 0, 3) === ['id', 'name', 'state'] && $r['seeds'] === 4,
                'qbittorrent: ' . $out);
        });
        $fx = delugeFixture();
        try {
            delugeEdit($fx, function (&$s) {
                $s['torrents'][str_repeat('a', 40)] += ['total_seeds' => 12, 'total_peers' => 3];
                $s['torrents'][str_repeat('b', 40)] += ['total_seeds' => -1, 'total_peers' => -1];
            });
            list($code, $l, $out) = delugeRun($fx, ['show', 'aaaaaaaa', 'bbbbbbbb']);
            check($code === 0 && array_keys($l[0])[0] === 'id' && $l[0]['swarm_seeds'] === 12 && $l[0]['swarm_peers'] === 3,
                'deluge: ' . $out);
            check($l[1]['swarm_seeds'] === null && $l[1]['swarm_peers'] === null, 'deluge -1 (no scrape) must be null: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'torrents: a config without a binary on PATH says found <config>, not running, not "not installed"' => function () {
        $fx = delugeFixture();
        try {
            $srv = stream_socket_server('tcp://127.0.0.1:0');
            $free = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
            fclose($srv);
            $web = $fx['home'] . '/.config/deluge/web.conf';
            file_put_contents($web, str_replace('"port": ' . $fx['port'], "\"port\": $free", (string) file_get_contents($web)));
            list($code, $l, $out) = delugeRun($fx, ['list']);
            check($code === 1 && strpos($out, 'not running') !== false && strpos($out, 'found ~/.config/deluge/web.conf') !== false
                && strpos($out, 'not installed') === false, 'deluge: ' . $out);
        } finally {
            delugeDone($fx);
        }
        if (RtorrentLocate::installed()) {
            skip('a real rtorrent is on this machine\'s PATH: the case cannot be built here');
        }
        $h = rtorrentHome();
        try {
            list($code, $out) = rtorrentRun($h, ['list']);
            check($code === 1 && strpos($out, 'not running: found') !== false && strpos($out, '.rtorrent.rc') !== false
                && strpos($out, 'not installed') === false, 'rtorrent: ' . $out);
        } finally {
            rtorrentDone($h, null);
        }
    },
];
