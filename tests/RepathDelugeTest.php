<?php
/**
 * tools/torrent-clients/deluge: `repath` against the fake deluge-web (tests/fixtures/deluge/router.php).
 * Deluge's own saved .torrent copy (<config>/state/<hash>.torrent) is read from disk directly, so the test
 * writes one there; its bencode is the same scheme RepathQbittorrentTest.php uses, so re-adding it
 * reproduces the same id: `d4:infod4:name7:repaths6:lengthi9000eee` -> sha1 of the info span ->
 * bb7133b3989addc02d7426968a4a3c043d5b612f.
 */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

const RPD_HASH = 'bb7133b3989addc02d7426968a4a3c043d5b612f';
const RPD_PAYLOAD = 'd4:infod4:name7:repaths6:lengthi9000eee';

/** Replaces the fixture's torrents with exactly one (the repath target) and writes its .torrent to disk. */
function rpDelugeSetup(array $fx, $paused)
{
    mkdir($fx['home'] . '/.config/deluge/state', 0700, true);
    file_put_contents($fx['home'] . '/.config/deluge/state/' . RPD_HASH . '.torrent', RPD_PAYLOAD);
    delugeEdit($fx, function (&$s) use ($fx, $paused) {
        $s['torrents'] = [RPD_HASH => ['hash' => RPD_HASH, 'name' => 'RepathMe', 'state' => $paused ? 'Paused' : 'Seeding',
            'progress' => 100.0, 'total_wanted' => 9000, 'total_done' => 9000, 'ratio' => 0.1, 'download_payload_rate' => 0,
            'upload_payload_rate' => 0, 'time_added' => 1700000000.0, 'download_location' => $fx['home'] . '/data', 'message' => 'OK',
            'tracker_status' => 'Announce OK', 'files' => [['index' => 0, 'path' => 'RepathMe/f.bin', 'size' => 9000, 'offset' => 0]],
            'completed_time' => 1700000500, 'seeding_time' => 0, 'private' => false, 'total_uploaded' => 0, 'trackers' => []]];
    });
}

return [
    'deluge: repath refuses unless every file already exists at --to with the exact size' => function () {
        $fx = delugeFixture();
        try {
            rpDelugeSetup($fx, false);
            mkdir($fx['home'] . '/data/Moved', 0700, true); // no files inside: wrong
            list($code, , $out) = delugeRun($fx, ['repath', substr(RPD_HASH, 0, 8), '--to', $fx['home'] . '/data/Moved']);
            check($code === 1 && strpos($out, 'nothing repathed') !== false && strpos($out, 'RepathMe/f.bin') !== false, "missing-data refusal: $out");
            check(!delugeCalls($fx, 'core.remove_torrent'), 'nothing was removed when the data was not verified: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: repath removes keeping data, re-adds Deluge\'s own .torrent paused at the new location, rechecks, and resumes only if it was active' => function () {
        $fx = delugeFixture();
        try {
            rpDelugeSetup($fx, false); // seeding (active) before repath
            mkdir($fx['home'] . '/data/Moved/RepathMe', 0700, true);
            file_put_contents($fx['home'] . '/data/Moved/RepathMe/f.bin', str_repeat('x', 9000));
            list($code, $l, $out) = delugeRun($fx, ['repath', substr(RPD_HASH, 0, 8), '--to', $fx['home'] . '/data/Moved']);
            check($code === 0, "repath failed: $out");
            check($l[0]['id'] === RPD_HASH && $l[0]['to'] === $fx['home'] . '/data/Moved' && $l[0]['was_paused'] === false && $l[0]['verified'] === true,
                'record: ' . $out);
            $removed = delugeCalls($fx, 'core.remove_torrent');
            check($removed && $removed[0]['params'] === [RPD_HASH, false], 'removed keeping data: ' . json_encode($removed));
            $added = delugeCalls($fx, 'core.add_torrent_file');
            check($added && $added[0]['params'][2]['download_location'] === $fx['home'] . '/data/Moved' && $added[0]['params'][2]['add_paused'] === true,
                're-added paused at the new location: ' . json_encode($added));
            check((bool) delugeCalls($fx, 'core.force_recheck'), 'recheck: ' . $out);
            check((bool) delugeCalls($fx, 'core.resume_torrents'), 'resumed since it was active before: ' . $out);
            $now = delugeState($fx)['torrents'];
            check(isset($now[RPD_HASH]), 'the same id is back in the client: ' . json_encode(array_keys($now)));
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: repath leaves an already-paused torrent paused' => function () {
        $fx = delugeFixture();
        try {
            rpDelugeSetup($fx, true); // already paused before
            mkdir($fx['home'] . '/data/Moved2/RepathMe', 0700, true);
            file_put_contents($fx['home'] . '/data/Moved2/RepathMe/f.bin', str_repeat('x', 9000));
            list($code, $l, $out) = delugeRun($fx, ['repath', substr(RPD_HASH, 0, 8), '--to', $fx['home'] . '/data/Moved2']);
            check($code === 0 && $l[0]['was_paused'] === true, "repath failed: $out");
            check(!delugeCalls($fx, 'core.resume_torrents'), 'a torrent that was paused before must not be resumed: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
];
