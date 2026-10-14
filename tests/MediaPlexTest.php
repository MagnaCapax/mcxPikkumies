<?php
/** The plex unit against a fake Plex (tests/fixtures/media/plex.php), found through a fake rootless Docker. */
require_once __DIR__ . '/fixtures/media/units.php';
return [
    'plex: found on the user\'s rootless Docker; the token from Preferences.xml goes only in the header' => function () {
        $fx = plexFixture();
        try {
            list($code, $l, $out) = plexRun($fx, ['stats']);
            $r = $l[0];
            check($code === 0 && $r['found'] === 'docker' && $r['listen'] === "0.0.0.0:{$fx['port']}" && $r['version'] === '1.42.1.10060'
                && $r['server_name'] === 'fakeplex', 'stats: ' . $out);
            check($r['libraries'] === 2 && $r['streams'] === 2 && $r['transcodes'] === 1
                && $r['hardware_acceleration'] === ['HardwareAcceleratedCodecs' => true], 'fields: ' . $out);
            check(strpos($r['login_from'], 'Preferences.xml') !== false
                && mediaState($fx)['violations'] === [], 'token: ' . $out . json_encode(mediaState($fx)['violations']));
            list($code, $l, $out) = plexRun($fx, ['libraries']);
            check($code === 0 && $l[0]['id'] === '1' && $l[0]['items'] === 10 && $l[1]['scanning'] === true
                && $l[0]['paths'] === ['/media/Movies'], 'libraries: ' . $out);
            list($code, $l, $out) = plexRun($fx, ['recent', '--limit', '2']);
            check($code === 0 && $l[0]['title'] === 'A Show - Pilot' && $l[0]['library'] === 'TV'
                && $l[1]['added_at'] === 1760300000, 'recent: ' . $out);
            check(!preg_grep('/token/i', mediaCalls($fx))
                && !preg_grep('/plex\.tv/', mediaCalls($fx)), 'a token or plex.tv in a URL: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
    },
    'plex: sessions hide both addresses unless --addresses; scan never sends force' => function () {
        $fx = plexFixture();
        try {
            list($code, $l, $out) = plexRun($fx, ['sessions']);
            check($code === 0 && $l[0]['id'] === 'k1' && $l[0]['play'] === 'transcode' && $l[0]['hardware'] === true
                && $l[0]['remote'] === true && $l[0]['progress'] === 0.5, 'sessions: ' . $out);
            check($l[1]['play'] === 'direct' && $l[1]['paused'] === true && !isset($l[0]['address'], $l[0]['public_address'])
                && strpos($out, '203.0.113.20') === false, 'fields: ' . $out);
            list($code, $l) = plexRun($fx, ['sessions', '--addresses']);
            check($code === 0 && $l[0]['address'] === '203.0.113.20' && $l[0]['public_address'] === '203.0.113.20', '--addresses');
            list($code, $l, $out) = plexRun($fx, ['scan', '--all']);
            check($code === 0 && $l[0]['result'] === 'started' && $l[1]['result'] === 'already scanning', 'scan: ' . $out);
            check(array_values(preg_grep('#/refresh#', mediaCalls($fx))) === ['POST /library/sections/1/refresh'],
                'refresh: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
    },
    'plex: a feature-gated end-stream is reported, not counted against the token; otherwise the session ends' => function () {
        $fx = plexFixture(['gate' => true]);
        try {
            list($code, , $out) = plexRun($fx, ['end-stream', 'k2']);
            check($code === 1 && strpos($out, 'feature enabled') !== false && plexSecret($fx) !== []
                && empty(plexSecret($fx)['prefs_rejected']), 'gate: ' . $out);
            list($code, , $out) = plexRun($fx, ['end-stream', 'all']);
            check($code === 1 && count(preg_grep('#terminate#', mediaCalls($fx))) === 1, 'all: ' . $out);
            mediaSetState($fx, ['gate' => false]);
            list($code, $l, $out) = plexRun($fx, ['end-stream', 'k2', '--reason', 'Maintenance']);
            check($code === 0 && $l[0]['verified'] === true
                && (bool) preg_grep('#^POST /status/sessions/terminate\?sessionId=k2&reason=Maintenance$#', mediaCalls($fx)),
                'end-stream: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'plex: a refused token is marked and not tried again; logs come from the data directory, redacted; plex.tv is never a target'
        => function () {
        $fx = plexFixture();
        try {
            list($code, $l, $out) = plexRun($fx, ['logs', '--lines', '5']);
            check($code === 0 && count($l[0]['lines']) === 3 && strpos($l[0]['file'], 'Plex Media Server.log') !== false
                && strpos($out, '203.0.113.20') === false, 'logs: ' . $out);
            mediaSetState($fx, ['tokens' => []]);
            list($code, , $out) = plexRun($fx, ['libraries']);
            check($code === 1 && strpos($out, 'marked') !== false && !empty(plexSecret($fx)['prefs_rejected']), 'refused: ' . $out);
            $n = count(mediaState($fx)['calls']);
            list($code, , $out) = plexRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'not trying it again') !== false
                && count(mediaState($fx)['calls']) === $n, 'retried: ' . $out);
            list($code, , $out) = plexRun($fx, ['stats', '--url', 'https://plex.tv']);
            check($code === 1 && strpos($out, 'plex.tv are never contacted') !== false, 'plex.tv: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
];
