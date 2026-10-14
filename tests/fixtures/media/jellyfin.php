<?php
/**
 * The Jellyfin fixture for tests/MediaJellyfinTest.php: a fake PMSS account (port marker, network.xml with
 * the PMSS BaseUrl, ~/.media-stack-credentials.txt as the installer writes it, the log directory, a fake
 * installer that records its arguments) and the fake server with two playing sessions and one idle one.
 */
const JF_LOGIN = 'pmssGeneratedLoginValue7';
const JF_SESSION = 'sessTok0123456789abcdef';
const JF_KEY = 'apiKeyFromDashboard0042';
const JF_WRONG_LOGIN = 'notTheInstallerValue';

function jfFixture(array $over = [])
{
    $sessions = [
        's1' => ['Id' => 's1', 'UserName' => 'alice', 'Client' => 'Jellyfin Web', 'DeviceName' => 'Firefox', 'RemoteEndPoint' => '203.0.113.9',
            'NowPlayingItem' => ['Name' => 'Pilot', 'SeriesName' => 'A Show', 'RunTimeTicks' => 1000], 'PlayState' => ['PositionTicks' => 250, 'IsPaused' => false, 'PlayMethod' => 'Transcode'],
            'TranscodingInfo' => ['Bitrate' => 4000000, 'HardwareAccelerationType' => 'none', 'TranscodeReasons' => ['VideoCodecNotSupported']]],
        's2' => ['Id' => 's2', 'UserName' => 'bob', 'Client' => 'Android', 'DeviceName' => 'Phone', 'RemoteEndPoint' => '192.168.1.5',
            'NowPlayingItem' => ['Name' => 'New Film', 'RunTimeTicks' => 100], 'PlayState' => ['PositionTicks' => 50, 'IsPaused' => true, 'PlayMethod' => 'DirectPlay']],
        'idle' => ['Id' => 'idle', 'UserName' => 'fake', 'Client' => 'pikkumies', 'DeviceName' => 'pikkumies', 'RemoteEndPoint' => '127.0.0.1', 'PlayState' => []],
    ];
    $fx = mediaFixture('mediabrowser', $over + ['kind' => 'jellyfin', 'base' => '/public-fake/jellyfin', 'version' => '12.2.0', 'user' => 'fake', 'password' => JF_LOGIN,
        'session_token' => JF_SESSION, 'tokens' => [JF_SESSION, JF_KEY], 'libraries' => [['a1b2', 'Movies', 'movies', null], ['c3d4', 'Shows', 'tvshows', 40.0]], 'sessions' => $sessions]);
    $h = $fx['home'];
    mkdir("$h/.config/jellyfin/config", 0700, true);
    mkdir("$h/.config/jellyfin/log", 0700, true);
    mkdir($fx['dir'] . '/pmss', 0700);
    file_put_contents("$h/.media-stack-port-jellyfin", $fx['port'] . "\n");
    // network.xml as PMSS writes it (install-media-stack.sh:2475-2519); the marker's port wins over these.
    file_put_contents("$h/.config/jellyfin/config/network.xml", "<?xml version=\"1.0\"?>\n<NetworkConfiguration>\n  <PublicHttpPort>1</PublicHttpPort>\n  <InternalHttpPort>1</InternalHttpPort>\n  <BaseUrl>/public-fake/jellyfin</BaseUrl>\n  <LocalNetworkSubnets />\n</NetworkConfiguration>\n");
    // The credentials file as install-media-stack.sh:1560-1572 and :2051-2066 write it.
    file_put_contents("$h/.media-stack-credentials.txt", "# PMSS media stack credentials\nJELLYFIN_URL = https://host.example/public-fake/jellyfin/web/\nJELLYFIN_USERNAME = fake\nJELLYFIN_" . 'PASSWORD = ' . JF_LOGIN . "\n");
    chmod("$h/.media-stack-credentials.txt", 0600);
    file_put_contents("$h/.config/jellyfin/log/log_20261014.log", "[INF] one\n[INF] two\n[INF] Playback started by 203.0.113.7\n[WRN] Token=\"" . JF_SESSION . "\" seen from 2001:db8::7\n");
    file_put_contents("$h/.media-stack-status.json", json_encode(['state' => 'healthy', 'apps' => ['jellyfin' => ['label' => 'Jellyfin', 'state' => 'running', 'consecutiveFailures' => 0]]]));
    // A fake installer: records its arguments, and stopping or starting Jellyfin powers the fake server down or up.
    file_put_contents("$h/install-media-stack.sh", "printf '%s\\n' \"\$*\" >> " . escapeshellarg($fx['dir'] . '/installer.log') . "\ncase \"\$1\" in --stop-app=jellyfin) printf down > "
        . escapeshellarg($fx['dir'] . '/power') . ' ;; --start-app=jellyfin) printf up > ' . escapeshellarg($fx['dir'] . '/power') . " ;; esac\n");
    file_put_contents("$h/.bashrc.custom", "alias jellyfin='true'\n");
    return $fx;
}

/** The PMSS seams: the directory whose existence means PMSS. */
function jfPmss(array $fx)
{
    return ['pmss_dir' => $fx['dir'] . '/pmss'];
}

/** Runs the jellyfin unit on the fake PMSS account with the credential oracle on. */
function jfRun(array $fx, array $args, array $seams = [])
{
    return mediaRun($fx, 'jellyfin', $args, $seams + jfPmss($fx), [JF_LOGIN, JF_SESSION, JF_KEY]);
}

function jfSecret(array $fx)
{
    return json_decode((string) @file_get_contents($fx['dir'] . '/secrets/jellyfin'), true) ?: [];
}
