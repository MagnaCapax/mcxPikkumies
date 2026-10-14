<?php
/**
 * Defects a live run against Plex Media Server 1.43.4 found on 2026-10-14, each against the fake (shaped on what the live
 * server answered): `recent` total, logs and the Preferences.xml token with --url, the end-stream hint, the restart
 * wording, session ids without a Session object, login's token check on an allowed network, stats without sessions,
 * and the default log file.
 */
require_once __DIR__ . '/fixtures/media/units.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/plex/PlexClient.php';

/** The three items of the live library, newest first. */
function plexThree()
{
    return array_map(function ($i) {
        return ['title' => "Test $i", 'type' => 'movie', 'librarySectionTitle' => 'Movies', 'addedAt' => 1791987939 - $i];
    }, [1, 2, 3]);
}

return [
    'plex: recent --limit 1 reports Plex\'s totalSize, not the items read (live: total 2 for 3 items)' => function () {
        $fx = plexFixture(['recent' => plexThree()]);
        try {
            list($code, $l, $out) = plexRun($fx, ['recent', '--limit', '1']);
            $st = end($l);
            check($code === 0 && $l[0]['title'] === 'Test 1' && $st['truncated'] === true && $st['total'] === 3 && $st['next_offset'] === 1,
                'json: ' . $out);
            list($code, , $out) = plexRun($fx, ['recent', '--limit', '1', '--human']);
            check($code === 0 && strpos($out, '1 shown of 3') !== false, 'human: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'plex: with --url the logs and the Preferences.xml token come from Plex\'s default data folders; the main log by default'
        => function () {
        $fx = plexFixture();
        try {
            $url = ['--url', 'http://127.0.0.1:' . $fx['port']];
            $logs = $fx['home'] . '/docker/plex/config/Library/Application Support/Plex Media Server/Logs';
            file_put_contents("$logs/Plex Media Scanner Analysis.log", "scanner line\n");
            touch("$logs/Plex Media Server.log", time() - 600); // the scanner's log is newer, as live after a scan
            list($code, $l, $out) = plexRun($fx, array_merge(['logs', '--lines', '5'], $url));
            check($code === 0 && substr($l[0]['file'], -21) === 'Plex Media Server.log' && count($l[0]['lines']) === 3, 'default: ' . $out);
            list($code, $l, $out) = plexRun($fx, array_merge(['logs', '--log', 'Plex Media Scanner Analysis'], $url));
            check($code === 0 && $l[0]['lines'] === ['scanner line'], '--log: ' . $out);
            list($code, , $out) = plexRun($fx, array_merge(['logs', '--log', 'Nope'], $url));
            check($code === 1 && strpos($out, 'Plex Media Scanner Analysis.log, Plex Media Server.log') !== false, 'unknown: ' . $out);
            list($code, , $out) = plexRun($fx, array_merge(['logs', '--log', '../../secrets'], $url));
            check($code === 1 && strpos($out, '--log takes a log file name') !== false, 'path: ' . $out);
            list($code, $l, $out) = plexRun($fx, array_merge(['stats'], $url));
            check($code === 0 && $l[0]['found'] === 'url'
                && strpos($l[0]['login_from'], 'PlexOnlineToken in ' . $fx['home'] . '/docker/plex') === 0,
                'prefs token: ' . $out);
            $own = $fx['dir'] . '/own';
            mkdir("$own/Plex Media Server/Logs", 0700, true);
            file_put_contents("$own/Plex Media Server/Logs/Plex Media Server.log", "own dir\n");
            list($code, $l, $out) = plexRun($fx, array_merge(['logs'], $url), ['support_dir' => $own]);
            check($code === 0 && $l[0]['lines'] === ['own dir'], 'PLEX_MEDIA_SERVER_APPLICATION_SUPPORT_DIR first: ' . $out);
            (new MediaSecret($fx['dir'] . '/secrets', 'plex'))->update(['key' => PLEX_TOKEN]); // as `login` stores it
            list($code, , $out) = plexRun($fx, array_merge(['logs'], $url), ['home' => $fx['dir'] . '/empty-home']);
            check($code === 1 && strpos($out, 'looked in: ') !== false && strpos($out, 'Library/Application Support/Plex Media Server/Logs')
                !== false && strpos($out, 'docker/plex/config') !== false, 'none: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'plex: a refused terminate keeps Plex\'s own hint; restart with --url names what Plex supports' => function () {
        $fx = plexFixture(['gate' => true]);
        try {
            list($code, , $out) = plexRun($fx, ['end-stream', 'k2']);
            $st = cliLines($out);
            check($code === 1 && strpos(end($st)['hint'], 'end the stream from the Plex dashboard') === 0, 'hint: ' . $out);
            list($code, , $out) = plexRun($fx, ['restart', '--url', 'http://127.0.0.1:' . $fx['port']]);
            check($code === 1 && strpos($out, 'restart works for Plex in a container on the user\'s own rootless Docker') !== false
                && strpos($out, 'media-stack') === false, 'restart: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'plex: a session without a Session object is named by its sessionKey; two rows with one id are still refused' => function () {
        $player = ['title' => 'pxt1-device', 'product' => 'pxt1 Test Player', 'local' => true, 'state' => 'playing',
            'machineIdentifier' => 'pm1'];
        $sessions = ['7' => ['title' => 'Big Test', 'type' => 'movie', 'sessionKey' => '7', 'Player' => $player],
            'a' => ['title' => 'A', 'sessionKey' => '1', 'Session' => ['id' => 'dup'], 'Player' => $player],
            'b' => ['title' => 'B', 'sessionKey' => '2', 'Session' => ['id' => 'dup'], 'Player' => $player]];
        $fx = plexFixture(['sessions' => $sessions]);
        try {
            list($code, $l, $out) = plexRun($fx, ['sessions']);
            check($code === 0 && $l[0]['id'] === '7' && $l[1]['id'] === 'dup', 'ids: ' . $out);
            list($code, , $out) = plexRun($fx, ['end-stream', 'dup']);
            check($code === 1 && strpos($out, 'matches 2 sessions') !== false && !preg_grep('#terminate#', mediaCalls($fx)),
                'dup: ' . $out);
            list($code, $l, $out) = plexRun($fx, ['end-stream', '7']);
            check($code === 0 && $l[0]['verified'] === true
                && (bool) preg_grep('#^POST /status/sessions/terminate\?sessionId=7$#', mediaCalls($fx)), 'sessionKey: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'plex: stats still prints when /status/sessions is refused (live: 403); streams are null with a note' => function () {
        $fx = plexFixture(['deny' => ['/status/sessions' => 403]]);
        try {
            list($code, $l, $out) = plexRun($fx, ['stats']);
            check($code === 0 && $l[0]['streams'] === null && $l[0]['transcodes'] === null && $l[0]['libraries'] === 2
                && strpos($l[0]['note'], 'streams unknown') === 0 && empty(plexSecret($fx)['prefs_rejected']), 'stats: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'plex: login\'s check proves the token only where Plex asks for one (live: any token passed on an allowed network)' => function () {
        $fx = plexFixture(['open' => true]);
        try {
            $client = function () use ($fx) {
                $http = new MediaHttp('Plex', 'http://127.0.0.1:' . $fx['port'], PlexClient::ALLOW, posix_geteuid(), 5);
                return new PlexClient($http, 'test');
            };
            $err = null;
            try {
                $client()->checkToken('anyTokenAtAll1');
            } catch (CliError $e) {
                $err = $e;
            }
            check($err !== null && !($err instanceof MediaAuthRejected) && strpos($err->getMessage(), 'without any token') !== false,
                'open network: ' . ($err ? $err->getMessage() : 'verified'));
            mediaSetState($fx, ['open' => false]);
            $client()->checkToken(PLEX_TOKEN); // Plex asks for a token: a 200 proves it
            $err = null;
            try {
                $client()->checkToken('wrongTokenValue1');
            } catch (CliError $e) {
                $err = $e;
            }
            check($err instanceof MediaAuthRejected, 'a wrong token was not refused');
        } finally {
            mediaDone($fx);
        }
    },
];
