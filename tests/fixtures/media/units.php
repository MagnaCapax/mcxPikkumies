<?php
/**
 * The Emby and Plex fixtures for tests/MediaEmbyTest.php, MediaPlexTest.php and MediaUnitsTest.php: a fake
 * server (mediabrowser.php in Emby mode, plex.php), a container on the fake rootless Docker, and the
 * credential each unit reads. The constants are the literal-credential oracle of mediaRun().
 */
require_once __DIR__ . '/harness.php';
const EMBY_KEY = 'embyApiKeyFromDashboard9';
const PLEX_TOKEN = 'plexOnlineTokenAbc123';

/** A fake Emby with two libraries and one transcoding session, an Emby container on the fake Docker, and the key stored. */
function embyFixture(array $over = [], $storeKey = true)
{
    $session = ['Id' => 'e9', 'UserName' => 'carol', 'Client' => 'Emby Theater', 'DeviceName' => 'TV', 'RemoteEndPoint' => '198.51.100.4',
        'NowPlayingItem' => ['Name' => 'New Film', 'RunTimeTicks' => 200],
        'PlayState' => ['PositionTicks' => 100, 'PlayMethod' => 'Transcode'],
        'TranscodingInfo' => ['Bitrate' => 2000000, 'VideoDecoderIsHardware' => false, 'VideoEncoderIsHardware' => true]];
    $fx = mediaFixture('mediabrowser', $over + ['kind' => 'emby', 'base' => '/emby', 'version' => '4.10.1.0', 'user' => 'fake',
        'password' => 'x', 'session_token' => 'unused', 'tokens' => [EMBY_KEY],
        'libraries' => [['3', 'Movies', 'movies', null], ['7', 'Music', 'music', null]], 'sessions' => ['e9' => $session]]);
    $fx['sock'] = mediaDocker($fx, [
        ['id' => 'b0b0', 'image' => 'mariadb:10.11', 'name' => 'db', 'ports' => '127.0.0.1:3306->3306/tcp'],
        ['id' => 'c0ffee', 'image' => 'lscr.io/linuxserver/emby:latest', 'name' => 'emby',
            'ports' => "1900/udp, 0.0.0.0:{$fx['port']}->8096/tcp, :::{$fx['port']}->8096/tcp"],
    ]);
    if ($storeKey) {
        (new MediaSecret($fx['dir'] . '/secrets', 'emby'))->update(['key' => EMBY_KEY]);
    }
    return $fx;
}

function embyRun(array $fx, array $args, array $seams = [])
{
    return mediaRun($fx, 'emby', $args, $seams, [EMBY_KEY]);
}

/** The calls the fake docker received, each as its argument list. */
function embyDocker(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/docker.json'), true)['calls'];
}

/** A fake Plex with two sections and two sessions; a Plex container whose /config holds Preferences.xml and a log. */
function plexFixture(array $over = [])
{
    $player = function ($title, $product, $addr, $local, $state) {
        return ['title' => $title, 'product' => $product, 'address' => $addr, 'remotePublicAddress' => $local ? '' : $addr,
            'local' => $local, 'state' => $state];
    };
    $sessions = [
        'k1' => ['title' => 'Pilot', 'grandparentTitle' => 'A Show', 'type' => 'episode', 'duration' => 1000, 'viewOffset' => 500,
            'Session' => ['id' => 'k1', 'location' => 'wan'], 'User' => ['title' => 'dave'],
            'Player' => $player('Living Room', 'Plex for Roku', '203.0.113.20', false, 'playing'),
            'TranscodeSession' => ['transcodeHwRequested' => true, 'videoDecision' => 'transcode']],
        'k2' => ['title' => 'New Film', 'type' => 'movie', 'duration' => 100, 'viewOffset' => 10,
            'Session' => ['id' => 'k2', 'location' => 'lan'], 'User' => ['title' => 'erin'],
            'Player' => $player('Laptop', 'Plex Web', '192.168.1.9', true, 'paused')],
    ];
    $fx = mediaFixture('plex', $over + ['version' => '1.42.1.10060', 'tokens' => [PLEX_TOKEN],
        'sections' => [['1', 'Movies', 'movie', false], ['2', 'TV', 'show', true]], 'sessions' => $sessions, 'violations' => []]);
    $data = $fx['home'] . '/docker/plex/config/Library/Application Support/Plex Media Server';
    mkdir("$data/Logs", 0700, true);
    file_put_contents("$data/Preferences.xml", '<?xml version="1.0" encoding="utf-8"?>'
        . "\n<Preferences MachineIdentifier=\"m1\" PlexOnlineToken=\"" . PLEX_TOKEN . "\" PlexOnlineUsername=\"someone\"/>\n");
    file_put_contents("$data/Logs/Plex Media Server.log", "start\nRequest from 203.0.113.20 GET /library?X-Plex-Token=" . PLEX_TOKEN
        . "\nend\n");
    $fx['sock'] = mediaDocker($fx, [['id' => 'p1ex', 'image' => 'plexinc/pms-docker:latest', 'name' => 'plex',
        'ports' => "0.0.0.0:{$fx['port']}->32400/tcp, :::{$fx['port']}->32400/tcp",
        'mounts' => [['Type' => 'bind', 'Source' => $fx['home'] . '/docker/plex/config', 'Destination' => '/config']]]]);
    return $fx;
}

/** Plex's data-directory variable is never taken from the test runner's environment. */
function plexRun(array $fx, array $args, array $seams = [])
{
    return mediaRun($fx, 'plex', $args, $seams + ['support_dir' => ''], [PLEX_TOKEN]);
}

function plexSecret(array $fx)
{
    return json_decode((string) @file_get_contents($fx['dir'] . '/secrets/plex'), true) ?: [];
}
