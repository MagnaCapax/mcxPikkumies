<?php
/** Rules every media unit shares, against the fakes: restart with no credential, owner check on Docker ports, 401 vs 403, end-stream. */
require_once __DIR__ . '/fixtures/media/units.php';
require_once __DIR__ . '/fixtures/media/jellyfin.php';

return [
    'emby and plex: restart needs no stored credential (public status and the user\'s own Docker only)' => function () {
        $fx = embyFixture([], false);
        try {
            list($code, $l, $out) = embyRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true && in_array(['restart', 'c0ffee'], embyDocker($fx), true), 'emby: ' . $out);
            check(array_unique(mediaCalls($fx)) === ['GET /System/Info/Public'], 'emby calls: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
        $fx = plexFixture();
        try {
            unlink($fx['home'] . '/docker/plex/config/Library/Application Support/Plex Media Server/Preferences.xml');
            list($code, $l, $out) = plexRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true && in_array(['restart', 'p1ex'], embyDocker($fx), true), 'plex: ' . $out);
            check(array_unique(mediaCalls($fx)) === ['GET /identity'], 'plex calls: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
    },
    'emby and plex: a port found on the user\'s Docker that another account owns is refused, nothing sent' => function () {
        $uid = posix_geteuid();
        foreach (['emby' => embyFixture(), 'plex' => plexFixture()] as $unit => $fx) {
            try {
                $seams = ['tables' => mediaNetTable($fx['port'], $uid + 1)];
                list($code, , $out) = mediaRun($fx, $unit, ['stats'], $seams, [EMBY_KEY, PLEX_TOKEN]);
                check($code === 1 && strpos($out, 'belongs to another account') !== false, "$unit: $out");
                check(mediaState($fx)['calls'] === [] && embyDocker($fx)[0][0] === 'ps', "$unit sent: " . implode(', ', mediaCalls($fx)));
            } finally {
                mediaDone($fx);
            }
        }
    },
    'plex: a 401 or 403 on the optional /:/prefs read marks nothing and stats still answers' => function () {
        foreach ([401, 403] as $status) {
            $fx = plexFixture(['deny' => ['/:/prefs' => $status]]);
            try {
                list($code, $l, $out) = plexRun($fx, ['stats']);
                check($code === 0 && $l[0]['hardware_acceleration'] === null && $l[0]['libraries'] === 2, "$status stats: $out");
                check(empty(plexSecret($fx)['prefs_rejected']), "$status marked the token");
                list($code, , $out) = plexRun($fx, ['libraries']);
                check($code === 0, "$status, then libraries: $out");
            } finally {
                mediaDone($fx);
            }
        }
    },
    'jellyfin: a 403 from an admin-only read keeps the session (it is not a refused credential)' => function () {
        $fx = jfFixture(['forbid' => ['/Users']]);
        try {
            list($code, , $out) = jfRun($fx, ['recent']);
            check($code === 1 && strpos($out, 'HTTP 403') !== false
                && strpos($out, 'ended the stored login session') === false, 'recent: ' . $out);
            check(jfSecret($fx)['session'] === JF_SESSION, 'the session was dropped');
            list($code, , $out) = jfRun($fx, ['stats']);
            check($code === 0 && mediaState($fx)['logins'] === 1, 'logged in again: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: end-stream reports each id; one failed stop ends ok:false without hiding the stream that did end' => function () {
        $fx = jfFixture(['stop_fail' => ['s1']]);
        try {
            list($code, , $out) = jfRun($fx, ['end-stream', 's1', 's2']);
            check($code === 1 && strpos($out, '"ok":false') !== false && strpos($out, 's1: failed') !== false
                && strpos($out, 's2: ended') !== false,
                'end-stream: ' . $out);
            check(in_array('POST /Sessions/s2/Playing/Stop', mediaCalls($fx), true) && !isset(mediaState($fx)['sessions']['s2'])
                && isset(mediaState($fx)['sessions']['s1']), 'calls: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: another account\'s port (owner check seam) and --url elsewhere are refused before anything is sent' => function () {
        $fx = jfFixture();
        try {
            $uid = posix_geteuid();
            list($code, , $out) = jfRun($fx, ['stats'], ['tables' => mediaNetTable($fx['port'], $uid + 1)]);
            check($code === 1 && strpos($out, 'another account') !== false && mediaState($fx)['calls'] === []
                && mediaState($fx)['logins'] === 0, 'foreign port: ' . $out);
            list($code, , $out) = jfRun($fx, ['stats', '--url', 'https://plex.tv/']);
            check($code === 1 && strpos($out, 'not an address of a server on this machine') !== false
                && mediaState($fx)['calls'] === [], '--url elsewhere: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
];
