<?php
/**
 * Prowlarr's `releases`/`grab` (docs/adr/0046 §2): handles, the cache, path A (Prowlarr's own
 * download client). Path B (in-process add, a fake torrent client) is tests/ArrGrabPathBTest.php;
 * Sonarr/Radarr's own `releases`/`grab` is tests/ArrLibraryReleasesTest.php.
 */
require_once __DIR__ . '/fixtures/arr/grab-helpers.php';

return [
    'grab: releases prints a handle and never a guid, link or passkey; the cache file is 0600 under secrets/' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list($code, $l, $out, $err) = arrRun($fx, ['releases', 'ubuntu', '24.04']);
            check($code === 0 && isset($l[0]['handle']) && preg_match('/^[0-9a-f]{6}$/', $l[0]['handle']), 'handle: ' . $out);
            check(strpos($out . $err, 'SECRETGUID4') === false && strpos($out . $err, 'SECRETDL5') === false && strpos($out . $err, 'SECRETMAGNET6') === false,
                'a guid/link/passkey reached output: ' . $out . $err);
            check(!array_key_exists('guid', $l[0]) && !array_key_exists('downloadUrl', $l[0]) && !array_key_exists('magnetUrl', $l[0]), 'a link field was printed: ' . json_encode($l[0]));
            $cache = $fx['dir'] . '/secrets/releases.json';
            check(is_file($cache), 'no cache file written');
            check((fileperms($cache) & 0777) === 0600, sprintf('cache file is %04o, not 0600', fileperms($cache) & 0777));
        } finally {
            arrDone($fx);
        }
    },
    'grab: two searches give different handles, never derived from the guid' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list(, $l1) = arrRun($fx, ['releases', 'ubuntu']);
            list(, $l2) = arrRun($fx, ['releases', 'ubuntu']);
            check($l1[0]['handle'] !== $l2[0]['handle'], 'the same handle was issued twice: ' . $l1[0]['handle']);
            check(strpos($l1[0]['handle'], 'secretguid4') === false, 'the handle looks derived from the guid');
        } finally {
            arrDone($fx);
        }
    },
    'grab: an unknown handle is refused; a handle from another app is refused by name, not "unknown"' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list($code, , $out) = arrRun($fx, ['grab', 'ffffff']);
            check($code === 1 && strpos($out, 'no such result; search again') !== false, 'unknown handle: ' . $out);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $saved = ArrReleases::save([['indexerId' => 1, 'indexer' => 'Tracker One', 'guid' => 'g', 'downloadUrl' => '', 'magnetUrl' => '',
                'title' => 'Old', 'size' => 1, 'seeders' => 1, 'protocol' => 'torrent']], 'sonarr');
            $handle = $saved[0]['handle'];
            list($code, , $out) = arrRun($fx, ['grab', $handle]); // this fixture is prowlarr; the handle is sonarr's
            check($code === 1 && strpos($out, 'is from sonarr, not prowlarr') !== false, 'app mismatch: ' . $out);
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab: an entry older than 30 minutes is treated as gone' => function () {
        $fx = arrFixture('prowlarr');
        try {
            $file = $fx['dir'] . '/secrets/releases.json';
            ArrReleases::$file = $file;
            $saved = ArrReleases::save([['indexerId' => 1, 'indexer' => 'T', 'guid' => 'g', 'downloadUrl' => '', 'magnetUrl' => '',
                'title' => 'Stale', 'size' => 1, 'seeders' => 1, 'protocol' => 'torrent']], 'prowlarr');
            $handle = $saved[0]['handle'];
            $all = json_decode((string) file_get_contents($file), true);
            $all[$handle]['savedAt'] = time() - 1801;
            file_put_contents($file, json_encode($all));
            list($code, , $out) = arrRun($fx, ['grab', $handle]);
            check($code === 1 && strpos($out, 'no such result; search again') !== false, 'a 30+ minute old handle was accepted: ' . $out);
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab: path A (Prowlarr has a client) posts indexerId+guid to search, and forgets the handle' => function () {
        $fx = arrFixture('prowlarr', ['downloadclients' => [['id' => 1, 'name' => 'rTorrent', 'enable' => true]]]);
        try {
            list(, $l) = arrRun($fx, ['releases', 'ubuntu']);
            $handle = $l[0]['handle'];
            list($code, $l2, $out) = arrRun($fx, ['grab', $handle]);
            check($code === 0 && $l2[0]['result'] === 'sent' && $l2[0]['path'] === 'prowlarr client' && $l2[0]['title'] === 'Ubuntu 24.04 ISO', 'path A: ' . $out);
            $sent = arrState($fx)['grabbed'][0];
            check($sent['indexerId'] === 1 && strpos($sent['guid'], 'SECRETGUID4') !== false, 'POST body: ' . json_encode($sent));
            list($code, , $out) = arrRun($fx, ['grab', $handle]); // forgotten after success
            check($code === 1 && strpos($out, 'no such result') !== false, 'the handle was not forgotten: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'grab: path A reports a 409 (indexer fetch failed) and a 404 (expired) the way Prowlarr sends them' => function () {
        $fx = arrFixture('prowlarr', ['downloadclients' => [['id' => 1, 'enable' => true]], 'grabMode' => '409']);
        try {
            list(, $l) = arrRun($fx, ['releases', 'ubuntu']);
            list($code, , $out) = arrRun($fx, ['grab', $l[0]['handle']]);
            check($code === 1 && strpos($out, 'Getting release from indexer failed') !== false, '409: ' . $out);
            arrSet($fx, ['grabMode' => '404']);
            list(, $l) = arrRun($fx, ['releases', 'ubuntu']);
            list($code, , $out) = arrRun($fx, ['grab', $l[0]['handle'], '--via-prowlarr']);
            check($code === 1 && strpos($out, 'no such result; search again') !== false, '404: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'grab: no client anywhere is refused, naming --client/--via-prowlarr; --client and --via-prowlarr together is a usage error' => function () {
        $fx = arrFixture('prowlarr');
        try {
            list(, $l) = arrRun($fx, ['releases', 'ubuntu']);
            list($code, , $out) = arrRun($fx, ['grab', $l[0]['handle']]);
            check($code === 1 && strpos($out, 'no supported torrent client') !== false, 'no client: ' . $out);
            list(, $l) = arrRun($fx, ['releases', 'ubuntu']);
            list($code, , $out) = arrRun($fx, ['grab', $l[0]['handle'], '--client', 'rtorrent', '--via-prowlarr']);
            check($code === 1 && strpos($out, 'not both') !== false, 'both: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
];
