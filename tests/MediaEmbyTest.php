<?php
/** The emby unit against the fake in Emby mode (tests/fixtures/media/mediabrowser.php), found through a fake rootless Docker. */
require_once __DIR__ . '/fixtures/media/units.php';
return [
    'emby: found on the user\'s rootless Docker by image and published port; X-Emby-Token under /emby, PascalCase parameters'
        => function () {
        $fx = embyFixture();
        try {
            list($code, $l, $out) = embyRun($fx, ['stats']);
            check($code === 0 && $l[0]['found'] === 'docker' && $l[0]['listen'] === "0.0.0.0:{$fx['port']}"
                && $l[0]['url'] === "http://127.0.0.1:{$fx['port']}/emby", 'where: ' . $out);
            check($l[0]['version'] === '4.10.1.0' && $l[0]['hardware_acceleration'] === 'Auto' && $l[0]['transcodes'] === 1
                && $l[0]['transcode_bitrate'] === 2000000, 'stats: ' . $out);
            check(!preg_grep('/outside base/', mediaCalls($fx))
                && embyDocker($fx)[0][0] === 'ps', 'calls: ' . implode(', ', mediaCalls($fx)));
            list($code, $l, $out) = embyRun($fx, ['scan', 'Movies']);
            check($code === 0 && $l[0]['result'] === 'started', 'scan: ' . $out);
            $want = ['POST /Items/3/Refresh?MetadataRefreshMode=Default&ImageRefreshMode=Default&Recursive=true'];
            check(array_values(preg_grep('#/Refresh#', mediaCalls($fx))) === $want, 'refresh: ' . implode(', ', mediaCalls($fx)));
            list($code, $l, $out) = embyRun($fx, ['recent']);
            check($code === 0 && $l[0]['title'] === 'A Show - Pilot'
                && (bool) preg_grep('#^GET /Users/u1/Items/Latest\?ParentId=3&Limit=21&Fields=DateCreated$#', mediaCalls($fx)),
                'recent: ' . $out . implode(', ', mediaCalls($fx)));
            list($code, $l, $out) = embyRun($fx, ['sessions']);
            check($code === 0 && $l[0]['hardware'] === true && $l[0]['play'] === 'transcode'
                && strpos($out, '198.51.100.4') === false, 'sessions: ' . $out);
            list($code, $l, $out) = embyRun($fx, ['logs', '--lines', '2']);
            check($code === 0 && $l[0]['file'] === 'log_20261014.log' && count($l[0]['lines']) === 2
                && strpos($out, '2001:db8') === false, 'logs: ' . $out);
            check((bool) preg_grep('#^GET /System/Logs/Log\?Name=log_20261014.log$#', mediaCalls($fx)), 'log name parameter');
        } finally {
            mediaDone($fx);
        }
    },
    'emby: end-stream sends the reason as query parameters, then Stop; never DELETE /Videos/ActiveEncodings' => function () {
        $fx = embyFixture();
        try {
            list($code, $l, $out) = embyRun($fx, ['end-stream', 'e9', '--reason', 'Back soon']);
            check($code === 0 && $l[0]['verified'] === true, 'end-stream: ' . $out);
            $calls = mediaCalls($fx);
            check((bool) preg_grep('#^POST /Sessions/e9/Message\?Header=Playback%20stopped&Text=Back%20soon&TimeoutMs=10000$#', $calls)
                && in_array('POST /Sessions/e9/Playing/Stop', $calls, true), 'calls: ' . implode(', ', $calls));
            check(!preg_grep('/^DELETE /', $calls), 'a DELETE was sent');
        } finally {
            mediaDone($fx);
        }
    },
    'emby: without a stored key nothing is sent; a refused key is marked and not tried again' => function () {
        $fx = embyFixture([], false);
        try {
            list($code, , $out) = embyRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'login') !== false && mediaState($fx)['calls'] === [], 'no key: ' . $out);
            (new MediaSecret($fx['dir'] . '/secrets', 'emby'))->update(['key' => EMBY_KEY]);
            mediaSetState($fx, ['tokens' => []]);
            list($code, , $out) = embyRun($fx, ['libraries']);
            check($code === 1 && strpos($out, 'marked') !== false, 'refused: ' . $out);
            $n = count(mediaState($fx)['calls']);
            list($code, , $out) = embyRun($fx, ['libraries']);
            check($code === 1 && strpos($out, 'not trying it again') !== false
                && count(mediaState($fx)['calls']) === $n, 'retried: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'emby: restart is docker restart on the user\'s own socket; --url is refused; no owned socket means not found' => function () {
        $fx = embyFixture();
        try {
            list($code, $l, $out) = embyRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true && in_array(['restart', 'c0ffee'], embyDocker($fx), true), 'restart: ' . $out);
            list($code, , $out) = embyRun($fx, ['restart', '--url', "http://127.0.0.1:{$fx['port']}"]);
            check($code === 1 && strpos($out, 'found by --url') !== false && count(array_filter(embyDocker($fx), function ($c) {
                return $c[0] === 'restart';
            })) === 1, '--url restart: ' . $out);
            check(!preg_grep('#System/Restart#', mediaCalls($fx)), 'the API restart was used');
            list($code, , $out) = embyRun($fx, ['stats'], ['docker_sock' => $fx['dir'] . '/elsewhere.sock']);
            check($code === 1 && strpos($out, 'Emby was not found') !== false, 'a socket the user does not own: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
];
