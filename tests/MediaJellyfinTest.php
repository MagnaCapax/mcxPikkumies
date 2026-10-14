<?php
/** The jellyfin unit on a fake PMSS account against a fake Jellyfin 12 (tests/fixtures/media/). */
require_once __DIR__ . '/fixtures/media/harness.php';
require_once __DIR__ . '/fixtures/media/jellyfin.php';

return [
    'jellyfin: a PMSS install is found by its port marker and BaseUrl; one login with the credentials file, then the session is reused'
        => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfRun($fx, ['stats']);
            $r = $l[0];
            check($code === 0 && $r['found'] === 'native' && $r['url'] === "http://127.0.0.1:{$fx['port']}/public-fake/jellyfin"
                && $r['listen'] === "127.0.0.1:{$fx['port']}", 'where: ' . $out);
            check($r['version'] === '12.2.0' && $r['libraries'] === 2 && $r['items'] === 7 && $r['streams'] === 2 && $r['transcodes'] === 1
                && $r['transcode_bitrate'] === 4000000, 'counts: ' . $out);
            check($r['hardware_acceleration'] === 'none' && $r['running_tasks'] === 1 && $r['update_available'] === true
                && $r['pmss_status'] === 'running', 'fields: ' . $out);
            check(strpos($r['login_from'], '.media-stack-credentials.txt') !== false, 'login_from: ' . $out);
            check(mediaState($fx)['logins'] === 1 && jfSecret($fx)['session'] === JF_SESSION, 'one login, session kept');
            check((fileperms($fx['dir'] . '/secrets/jellyfin') & 0777) === 0600
                && (fileperms($fx['dir'] . '/secrets') & 0777) === 0700, 'secret file modes');
            list($code, $l, $out) = jfRun($fx, ['libraries']);
            check($code === 0 && $l[0]['name'] === 'Movies' && $l[0]['items'] === 5 && $l[0]['scanning'] === false
                && $l[1]['scanning'] === true && $l[1]['progress'] === 0.4, 'libraries: ' . $out);
            check(mediaState($fx)['logins'] === 1, 'logged in again instead of reusing the session');
            check(!preg_grep('/outside base|Auth\/Keys|DELETE|System\/Restart/', mediaCalls($fx)),
                'unexpected calls: ' . implode(', ', mediaCalls($fx)));
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: no credential is ever printed: on 401, on 500, on a dead port' => function () {
        $fx = jfFixture();
        try {
            jfRun($fx, ['stats']);
            mediaSetState($fx, ['reject' => true]);
            list($code, , $out) = jfRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'ended the stored login session') !== false
                && !isset(jfSecret($fx)['session']), '401: ' . $out);
            mediaSetState($fx, ['reject' => false, 'fail' => 500]);
            list($code, , $out) = jfRun($fx, ['sessions']);
            check($code === 1 && strpos($out, 'HTTP 500') !== false && mediaState($fx)['logins'] === 2, '500: ' . $out);
            (new MediaSecret($fx['dir'] . '/secrets', 'jellyfin'))->update(['key' => JF_KEY]);
            list($code, , $out) = jfRun($fx, ['logs'], ['pmss_dir' => $fx['dir'] . '/none']);
            check($code === 1 && strpos($out, 'nothing listens on port 1') !== false, 'off PMSS, the config directory port: ' . $out);
            proc_terminate($fx['proc']);
            proc_close($fx['proc']);
            $fx['proc'] = null;
            list($code, , $out) = jfRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'not running') !== false, 'dead port: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: a refused login is marked and not tried again until `login`; login needs a terminal' => function () {
        $fx = jfFixture(['password' => JF_WRONG_LOGIN]);
        try {
            list($code, , $out) = jfRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'refused the user name') !== false && mediaState($fx)['logins'] === 1, 'first: ' . $out);
            list($code, , $out) = jfRun($fx, ['libraries']);
            check($code === 1 && strpos($out, 'not trying it again') !== false && mediaState($fx)['logins'] === 1, 'retried: ' . $out);
            list($code, , $out) = jfRun($fx, ['login']);
            check($code === 1 && strpos($out, 'terminal') !== false && !isset(jfSecret($fx)['key']), 'login without a tty: ' . $out);
            (new MediaSecret($fx['dir'] . '/secrets', 'jellyfin'))->update(['key' => JF_KEY, 'pw_rejected' => null]); // what login stores
            list($code, $l, $out) = jfRun($fx, ['stats']);
            check($code === 0 && strpos($l[0]['login_from'], 'API key') === 0 && mediaState($fx)['logins'] === 1, 'with a key: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: sessions are only playing streams and carry no address unless --addresses' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfRun($fx, ['sessions']);
            check($code === 0 && count($l) === 3 && $l[0]['id'] === 's1' && $l[0]['title'] === 'A Show - Pilot'
                && $l[0]['play'] === 'transcode' && $l[0]['progress'] === 0.25, 'sessions: ' . $out);
            check($l[0]['hardware'] === false && $l[0]['transcode_reasons'] === ['VideoCodecNotSupported'] && $l[0]['remote'] === true
                && $l[1]['play'] === 'direct' && $l[1]['paused'] === true && $l[1]['remote'] === false, 'fields: ' . $out);
            check(!isset($l[0]['address']) && strpos($out, '203.0.113.9') === false
                && strpos($out, '192.168.1.5') === false, 'an address was shown: ' . $out);
            list($code, $l, $out) = jfRun($fx, ['sessions', '--addresses']);
            check($code === 0 && $l[0]['address'] === '203.0.113.9', '--addresses: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: scan sends explicit refresh modes and never replaceAll or force; one unknown library changes nothing' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfRun($fx, ['scan', 'movies', 'c3d4']);
            check($code === 0 && $l[0]['result'] === 'started' && $l[1]['result'] === 'already scanning', 'scan: ' . $out);
            $refresh = array_values(preg_grep('#/Refresh#', mediaCalls($fx)));
            check($refresh === ['POST /Items/a1b2/Refresh?metadataRefreshMode=Default&imageRefreshMode=Default'],
                'refresh calls: ' . implode(', ', $refresh));
            list($code) = jfRun($fx, ['scan', 'Movies', 'Nope']);
            list($code2) = jfRun($fx, ['scan']);
            list($code3, $l) = jfRun($fx, ['scan', '--all']);
            check($code === 1 && $code2 === 1 && $code3 === 0 && count($l) === 3
                && count(preg_grep('#/Refresh#', mediaCalls($fx))) === 2, 'a bad library list scanned something');
            check(!preg_grep('/replaceall|force/i', mediaCalls($fx)), 'an overwrite flag was sent');
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: end-stream takes ids from the live list only, never all, and reads the list back' => function () {
        $fx = jfFixture();
        try {
            foreach ([['all'], ['*'], ['nope'], ['idle'], ['s1', 'nope']] as $args) {
                list($code, , $out) = jfRun($fx, array_merge(['end-stream'], $args));
                check($code === 1, 'end-stream ' . implode(' ', $args) . ' ran: ' . $out);
            }
            check(!preg_grep('#Playing/Stop|/Message#', mediaCalls($fx)), 'a stop was sent for a bad id');
            list($code, $l, $out) = jfRun($fx, ['end-stream', 's2', '--reason', 'Server maintenance']);
            check($code === 0 && $l[0]['id'] === 's2' && $l[0]['result'] === 'stop sent'
                && $l[0]['verified'] === true, 'end-stream: ' . $out);
            $msg = array_values(array_filter(mediaState($fx)['calls'], function ($c) {
                return $c['path'] === '/Sessions/s2/Message';
            }));
            check(count($msg) === 1 && json_decode($msg[0]['body'], true)['Text'] === 'Server maintenance'
                && isset(mediaState($fx)['sessions']['s1']), 'message or another session: ' . json_encode($msg));
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: logs come from the PMSS log directory without addresses or tokens; recent is newest first' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfRun($fx, ['logs', '--lines', '3']);
            check($code === 0 && count($l[0]['lines']) === 3 && strpos($l[0]['file'], 'log_20261014.log') !== false, 'logs: ' . $out);
            check(strpos($out, '203.0.113.7') === false && strpos($out, '2001:db8') === false
                && strpos($out, 'ADDRESS') !== false, 'an address in the log output: ' . $out);
            list(, $l) = jfRun($fx, ['logs', '--addresses']);
            check(strpos(json_encode($l), '203.0.113.7') !== false, '--addresses must keep them');
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '5']);
            check($code === 0 && $l[0]['title'] === 'A Show - Pilot' && $l[0]['library'] === 'Shows' && $l[1]['title'] === 'New Film'
                && $l[0]['added_at'] > $l[1]['added_at'], 'recent: ' . $out);
            check((bool) preg_grep('#^GET /Items/Latest\?.*userId=u1#', mediaCalls($fx)), 'userId not sent');
        } finally {
            mediaDone($fx);
        }
    },
];
