<?php
/**
 * Defects a live run of the changing verbs found on 2026-10-14 (Jellyfin 12.2.0, Emby 4.10.1; jf-endstream.txt,
 * emby-endstream.txt, d-jf-stats.txt under the run's out/ folder), against the fakes: end-stream ends ok:false when the
 * session still plays after the stop, and the media units find the user's own Docker through DOCKER_HOST as *ARR does.
 */
require_once __DIR__ . '/fixtures/media/jellyfin.php';
require_once __DIR__ . '/fixtures/media/units.php';

return [
    'media: end-stream whose session still plays after the stop ends ok:false with a per-session report' => function () {
        // Live jf-endstream.txt / emby-endstream.txt: "result":"stop sent","verified":false and then {"ok":true}.
        $fx = jfFixture(['stop_ignored' => ['s1']]);
        try {
            list($code, , $out) = jfRun($fx, ['end-stream', 's1', 's2']);
            check($code === 1 && strpos($out, '"ok":false') !== false && strpos($out, 's1: stop sent, but it still plays') !== false
                && strpos($out, 's2: ended') !== false, 'jellyfin: ' . $out);
            check(count(preg_grep('#^POST /Sessions/s1/Playing/Stop$#', mediaCalls($fx))) === 1, 'the stop was sent again');
            check(count(preg_grep('#^GET /Sessions$#', mediaCalls($fx))) >= 3, 'the list was not read again before giving up');
        } finally {
            mediaDone($fx);
        }
        $fx = embyFixture(['stop_ignored' => ['e9']]);
        try {
            list($code, , $out) = embyRun($fx, ['end-stream', 'e9']);
            check($code === 1 && strpos($out, 'e9: stop sent, but it still plays') !== false, 'emby: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'media: a container on the user\'s own Docker is found through a unix:// DOCKER_HOST; tcp:// and a foreign socket are not' => function () {
        // Live d-jf-stats.txt: "Jellyfin was not found" with only DOCKER_HOST set, while radarr found its container.
        $fx = embyFixture();
        $was = getenv('DOCKER_HOST');
        try {
            foreach (['unix://' . $fx['dir'] . '/docker.sock' => 0, 'tcp://127.0.0.1:2375' => 1, 'unix:///var/run/docker.sock' => 1,
                'unix://' . $fx['dir'] . '/nonexistent.sock' => 1] as $host => $want) {
                putenv("DOCKER_HOST=$host");
                list($code, $l, $out) = embyRun($fx, ['stats'], ['docker_sock' => null]); // null: the unit's own discovery
                check($code === $want, "DOCKER_HOST=$host: " . $out);
                check($want === 1 || ($l[0]['found'] === 'docker' && $l[0]['url'] === "http://127.0.0.1:{$fx['port']}/emby"),
                    'found: ' . $out);
            }
            check(count(embyDocker($fx)) === 1, 'docker asked through a socket not the user\'s own: ' . json_encode(embyDocker($fx)));
        } finally {
            putenv($was === false ? 'DOCKER_HOST' : "DOCKER_HOST=$was");
            mediaDone($fx);
        }
    },
];
