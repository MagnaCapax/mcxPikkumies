<?php
/**
 * Transmission endpoint discovery (TransmissionLocate), black-box through the real tool (like the
 * other units' own host-detection tests): a temp HOME with its own settings.json, no fake RPC server
 * needed for a refusal, a real unix socket or TCP listener when a case must be found.
 */
function tlDir()
{
    $dir = sys_get_temp_dir() . '/pk-tl-' . getmypid() . '-' . mt_rand();
    @mkdir($dir, 0700, true);
    @mkdir("$dir/bin", 0700, true); // empty PATH: no transmission-daemon binary anywhere
    return $dir; // "$dir/no-pmss" is deliberately never created: Host::family() must see no directory there to say 'generic'
}

function tlSettings($home, array $data)
{
    @mkdir("$home/.config/transmission-daemon", 0700, true);
    file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode($data));
}

/** Runs `stats` (any verb that must locate the client first) and returns [code, output]. A wrapper
 * script sets `$transmissionSecretDir` to an isolated dir under $dir (never the real repo's
 * pikkumies/local/secrets), the same technique transmissionRunAuth() uses in fixtures/transmission. */
function tlRun($dir, $home, $pmssDir)
{
    $unit = dirname(__DIR__) . '/pikkumies/tools/torrent-clients/transmission/transmission.php';
    $wrapper = "$dir/tl-run.php";
    file_put_contents($wrapper, '<?php
putenv("HOME=" . ' . var_export($home, true) . ');
putenv("PATH=" . ' . var_export("$dir/bin", true) . ');
putenv("PIKKUMIES_PMSS_DIR=" . ' . var_export($pmssDir, true) . ');
putenv("PIKKUMIES_TORRENT_TIMEOUT=2");
$transmissionSecretDir = ' . var_export("$dir/secrets", true) . ';
require ' . var_export($unit, true) . ';
');
    return runPhp($wrapper, ['stats']);
}

return [
    'transmission locate: no settings.json yet means not installed (generic) or no build selected (PMSS)' => function () {
        $dir = tlDir();
        $home = "$dir/home";
        @mkdir($home, 0700, true);
        try {
            list($code, $out) = tlRun($dir, $home, "$dir/no-pmss");
            check($code === 1 && strpos($out, 'not installed') !== false, 'generic, no settings.json: ' . $out);
            list($code2, $out2) = tlRun($dir, $home, $dir); // an existing dir makes Host::family() say 'pmss'
            check($code2 === 1 && strpos($out2, 'no Transmission build is selected') !== false, 'pmss, no settings.json: ' . $out2);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'transmission locate: rpc-enabled false refuses before any connection is tried; rpc-authentication-required true alone is NEVER a locate-time refusal (security review F1 -- the credential, not the locate step, is what may refuse)' => function () {
        $dir = tlDir();
        $home = "$dir/home";
        tlSettings($home, ['rpc-enabled' => false, 'rpc-bind-address' => 'unix:' . $home . '/x.sock']);
        try {
            list($code, $out) = tlRun($dir, $home, $dir);
            check($code === 1 && strpos($out, 'rpc-enabled') !== false, 'rpc-enabled false: ' . $out);
            // A selected build and a live socket (as the "found" sub-test below sets up), but now with
            // rpc-authentication-required: true: find() itself must reach the socket (no "not installed"
            // or auth-specific refusal at this layer); the eventual refusal is the missing credential,
            // never "disable authentication" (TransmissionAuthTest.php checks that hint precisely).
            tlSettings($home, ['rpc-enabled' => true, 'rpc-authentication-required' => true, 'rpc-username' => 'alice',
                'rpc-bind-address' => 'unix:' . $home . '/transmission.sock']);
            @mkdir("$home/bin/transmission", 0700, true);
            symlink($home, "$home/bin/transmission/default");
            $srv = stream_socket_server('unix://' . $home . '/transmission.sock');
            try {
                list($code2, $out2) = tlRun($dir, $home, $dir);
                check($code2 === 1 && strpos($out2, 'not installed') === false && strpos($out2, 'not running') === false,
                    'authentication-required true alone must not look like a missing/not-running client: ' . $out2);
                check(stripos($out2, 'turn') === false && stripos($out2, 'disable') === false,
                    'authentication-required true must never be refused by suggesting it be turned off: ' . $out2);
            } finally {
                fclose($srv);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'transmission locate: a PMSS unix socket that is not live refuses "not running"; a real one this account owns is reached' => function () {
        $dir = tlDir();
        $home = "$dir/home";
        tlSettings($home, ['rpc-enabled' => true, 'rpc-bind-address' => 'unix:' . $home . '/transmission.sock', 'rpc-url' => '/user-fake/transmission/']);
        @mkdir("$home/bin/transmission", 0700, true);
        symlink($home, "$home/bin/transmission/default"); // "a build is selected": installed() must say so before "not running" is checked
        try {
            list($code, $out) = tlRun($dir, $home, $dir);
            check($code === 1 && strpos($out, 'not running') !== false, 'no socket file yet: ' . $out);
            $srv = stream_socket_server('unix://' . $home . '/transmission.sock');
            check($srv !== false, 'could not create the fake socket for this test');
            list($code2, $out2) = tlRun($dir, $home, $dir);
            // Nothing answers HTTP on it, so the call is busy/refused -- but TransmissionLocate itself must have
            // accepted the socket (an error naming "not running"/"not installed"/"not this account" would mean
            // the locate step, not the RPC call, failed).
            check($code2 === 1 && strpos($out2, 'not running') === false && strpos($out2, 'not installed') === false
                && strpos($out2, "not this account's own") === false, 'an owned, existing socket was not accepted: ' . $out2);
            fclose($srv);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'transmission locate: elsewhere (TCP), rpc-port is used when given, else Transmission\'s own default 9091' => function () {
        $dir = tlDir();
        $home = "$dir/home";
        file_put_contents("$dir/bin/transmission-daemon", "#!/bin/sh\n"); // "installed" (on PATH) so absent() reaches the live-or-not check
        chmod("$dir/bin/transmission-daemon", 0755);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        tlSettings($home, ['rpc-enabled' => true, 'rpc-bind-address' => '0.0.0.0', 'rpc-port' => $port]);
        try {
            list($code, $out) = tlRun($dir, $home, "$dir/no-pmss");
            check($code === 1 && strpos($out, 'not running') === false && strpos($out, "belongs to another account") === false, 'an open TCP port was not accepted: ' . $out);
            fclose($listener);
            list($code2, $out2) = tlRun($dir, $home, "$dir/no-pmss");
            check($code2 === 1 && strpos($out2, "port $port") !== false && strpos($out2, 'not running') !== false, 'a closed rpc-port must report "not running": ' . $out2);
            tlSettings($home, ['rpc-enabled' => true, 'rpc-bind-address' => '0.0.0.0']); // no rpc-port given at all
            list($code3, $out3) = tlRun($dir, $home, "$dir/no-pmss");
            check($code3 === 1 && strpos($out3, '9091') !== false, "Transmission's own default port must be tried: " . $out3);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
