<?php
/**
 * tools/torrent-clients/qbittorrent: `repath` against the fake WebUI (tests/fixtures/qbittorrent/router.php).
 * The export payload is precomputed so that re-adding it reproduces the same info-hash (as a real export of
 * the same torrent would): `d4:infod4:name7:repaths6:lengthi9000eee` -> sha1 of the info span ->
 * bb7133b3989addc02d7426968a4a3c043d5b612f (computed once, not re-derived here).
 */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Config.php';

const RP_HASH = 'bb7133b3989addc02d7426968a4a3c043d5b612f';
const RP_PAYLOAD = 'd4:infod4:name7:repaths6:lengthi9000eee';

/** A fixture whose only torrent is the repath one, state stoppedUP (paused) by default. */
function rpQbitTorrent($data, $state = 'stoppedUP')
{
    return ['hash' => RP_HASH, 'name' => 'RepathMe', 'state' => $state, 'progress' => 1, 'size' => 9000, 'completed' => 9000,
        'ratio' => 0.1, 'dlspeed' => 0, 'upspeed' => 0, 'added_on' => 1700000000, 'category' => 'tv', 'tags' => 'linux,iso',
        'content_path' => "$data/RepathMe", 'save_path' => $data,
        'files' => [['index' => 0, 'name' => 'RepathMe/f.bin', 'size' => 9000]], 'props' => ['total_size' => 9000],
        'torrent_file' => RP_PAYLOAD];
}

return [
    'qbittorrent: repath refuses unless every file already exists at --to with the exact size' => function () {
        qbitWith([], function ($fx) {
            $t = rpQbitTorrent($fx['data']);
            $over = qbitState($fx);
            $over['torrents'] = [$t];
            file_put_contents($fx['dir'] . '/state.json', json_encode($over));
            mkdir("$fx[data]/Moved", 0700, true); // no files inside: wrong
            list($code, $out) = qbitRun($fx, ['repath', substr(RP_HASH, 0, 8), '--to', "$fx[data]/Moved"]);
            check($code === 1 && strpos($out, 'nothing repathed') !== false && strpos($out, 'RepathMe/f.bin') !== false, "missing-data refusal: $out");
            check(!in_array('GET torrents/export', qbitCalls($fx), true), 'export must not run before the data is verified: ' . $out);
        });
    },
    'qbittorrent: repath exports, removes keeping data, re-adds paused with savepath, carries category/tags, rechecks, and resumes only if it was active' => function () {
        qbitWith([], function ($fx) {
            $t = rpQbitTorrent($fx['data'], 'uploading'); // active before repath
            $over = qbitState($fx);
            $over['torrents'] = [$t];
            file_put_contents($fx['dir'] . '/state.json', json_encode($over));
            mkdir("$fx[data]/Moved/RepathMe", 0700, true);
            file_put_contents("$fx[data]/Moved/RepathMe/f.bin", str_repeat('x', 9000));
            list($code, $out) = qbitRun($fx, ['repath', substr(RP_HASH, 0, 8), '--to', "$fx[data]/Moved"]);
            $l = cliLines($out);
            check($code === 0, "repath failed: $out");
            check($l[0]['id'] === RP_HASH && $l[0]['to'] === "$fx[data]/Moved" && $l[0]['was_active'] === true && $l[0]['verified'] === true, 'record: ' . $out);
            check((bool) array_filter(qbitCalls($fx), function ($c) {
                return $c === 'GET torrents/export';
            }), 'export: ' . $out);
            $deleted = qbitState($fx)['deleted'];
            check($deleted && $deleted[0]['hashes'] === [RP_HASH] && $deleted[0]['deleteFiles'] === 'false', 'removed keeping data: ' . json_encode($deleted));
            $now = qbitState($fx)['torrents'];
            check(count($now) === 1 && $now[0]['hash'] === RP_HASH && $now[0]['save_path'] === "$fx[data]/Moved", 're-added at the new savepath with the same id: ' . json_encode($now));
            check($now[0]['category'] === 'tv' && $now[0]['tags'] === 'linux,iso', 'category and tags carried over: ' . json_encode($now[0]));
            check((bool) array_filter(qbitCalls($fx), function ($c) {
                return $c === 'POST torrents/recheck';
            }), 'recheck: ' . $out);
            check((bool) array_filter(qbitCalls($fx), function ($c) {
                return $c === 'POST torrents/resume' || $c === 'POST torrents/start';
            }), 'resume/start attempted since it was active before: ' . $out);
        });
    },
    'qbittorrent: repath leaves a paused torrent paused' => function () {
        qbitWith([], function ($fx) {
            $t = rpQbitTorrent($fx['data'], 'pausedDL'); // already paused before
            $over = qbitState($fx);
            $over['torrents'] = [$t];
            file_put_contents($fx['dir'] . '/state.json', json_encode($over));
            mkdir("$fx[data]/Moved2/RepathMe", 0700, true);
            file_put_contents("$fx[data]/Moved2/RepathMe/f.bin", str_repeat('x', 9000));
            list($code, $out) = qbitRun($fx, ['repath', substr(RP_HASH, 0, 8), '--to', "$fx[data]/Moved2"]);
            $l = cliLines($out);
            check($code === 0 && $l[0]['was_active'] === false, "repath failed: $out");
            check(!array_filter(qbitCalls($fx), function ($c) {
                return $c === 'POST torrents/resume' || $c === 'POST torrents/start';
            }), 'a torrent that was paused before must not be resumed: ' . $out);
        });
    },
    'qbittorrent: repath keeps the exported .torrent on disk when the re-add fails after the old torrent was already removed (security review N4)' => function () {
        qbitWith([], function ($fx) {
            $t = rpQbitTorrent($fx['data'], 'uploading');
            $over = qbitState($fx);
            $over['torrents'] = [$t];
            $over['add_fails'] = true; // fixture knob: simulate the re-add failing after the old torrent is already gone
            file_put_contents($fx['dir'] . '/state.json', json_encode($over));
            mkdir("$fx[data]/Moved3/RepathMe", 0700, true);
            file_put_contents("$fx[data]/Moved3/RepathMe/f.bin", str_repeat('x', 9000));
            $tmpGlob = Config::localDir() . '/state/tmp/repath-*.torrent';
            $before = glob($tmpGlob);
            list($code, $out) = qbitRun($fx, ['repath', substr(RP_HASH, 0, 8), '--to', "$fx[data]/Moved3"]);
            try {
                check($code === 1 && strpos($out, 'kept at') !== false, "a re-add failure must say where the exported .torrent was kept: $out");
                $new = array_values(array_diff((array) glob($tmpGlob), (array) $before));
                check(count($new) === 1, 'exactly one new temp .torrent file must have been left behind on this failure path: ' . json_encode($new));
                check(is_file($new[0]) && filesize($new[0]) > 0, 'the kept file must actually exist with content, never deleted on this path: ' . var_export($new[0] ?? null, true));
            } finally {
                foreach (array_diff((array) glob($tmpGlob), (array) $before) as $f) {
                    @unlink($f); // this test deliberately leaves it (as production does, for the user to act on); clean up after asserting
                }
            }
        });
    },
];
