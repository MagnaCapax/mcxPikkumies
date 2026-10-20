<?php
/**
 * End-to-end smoke test of the PMSS path through a REAL unix socket: lib/Http.php's additive
 * `unix_socket` option (CURLOPT_UNIX_SOCKET_PATH), TransmissionLocate's unix-socket branch, and the
 * X-Transmission-Session-Id 409 handshake, all together -- `php -S` cannot bind a unix socket on
 * PHP 7.3, so tests/fixtures/transmission/unixServer.php is a tiny raw-socket server instead
 * (see that file's docblock). This is the one test that exercises the actual transport PMSS uses
 * (docs/adr/0108-transmission-socket-integration.md); the full verb behaviour is tested over TCP in
 * TransmissionTest.php, since the RPC method/field logic is identical either way.
 */
function tusStart($socketPath, $sessionId)
{
    $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/transmission/unixServer.php')
        . ' ' . escapeshellarg($socketPath) . ' ' . escapeshellarg($sessionId),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        clearstatcache(true, $socketPath);
        if (@filetype($socketPath) === 'socket') {
            break;
        }
        usleep(100000);
    }
    return $proc;
}

return [
    'transmission + lib/Http.php: a PMSS unix-socket RPC call survives the 409 handshake and returns real torrent-get data' => function () {
        $dir = sys_get_temp_dir() . '/pk-tus-' . getmypid() . '-' . mt_rand();
        $home = "$dir/home";
        @mkdir($home, 0700, true);
        @mkdir("$dir/bin", 0700, true);
        $sessionId = 'unixSocketSession99';
        $socketPath = "$home/transmission.sock";
        @mkdir("$home/.config/transmission-daemon", 0700, true);
        file_put_contents("$home/.config/transmission-daemon/settings.json", json_encode([
            'rpc-enabled' => true, 'rpc-bind-address' => 'unix:' . $socketPath, 'rpc-url' => '/user-fake/transmission/',
            'rpc-authentication-required' => false,
        ]));
        $proc = tusStart($socketPath, $sessionId);
        try {
            $env = ['HOME' => $home, 'PATH' => "$dir/bin", 'PIKKUMIES_PMSS_DIR' => $dir, 'PIKKUMIES_TORRENT_TIMEOUT' => '5'];
            $old = [];
            foreach ($env as $k => $v) {
                $old[$k] = getenv($k);
                putenv("$k=$v");
            }
            try {
                list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/tools/torrent-clients/transmission/transmission.php', ['list']);
            } finally {
                foreach ($old as $k => $v) {
                    putenv($v === false ? $k : "$k=$v");
                }
            }
            $lines = array_map('json_decode', array_values(array_filter(explode("\n", trim($out)), 'strlen')));
            check($code === 0, "the unix-socket call failed: $out");
            check($lines[0]->name === 'UnixSocketTorrent' && $lines[0]->id === str_repeat('a', 40) && $lines[0]->state === 'seeding',
                'the record did not come from the unix-socket server: ' . $out);
            check(strpos($out, $sessionId) === false, 'the session id leaked into the output');
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'lib/Http.php unix_socket: a socket owned by another uid (or missing) is refused before any connection is attempted' => function () {
        $dir = sys_get_temp_dir() . '/pk-tus2-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        $missing = "$dir/nope.sock";
        $r = Http::request('POST', 'http://localhost/x', ['unix_socket' => $missing, 'body' => '{}', 'timeout' => 2]);
        check($r['code'] === 0 && strpos($r['error'], "unix socket $missing is not this account's own") !== false, 'a missing socket was not refused: ' . json_encode($r));
        exec('rm -rf ' . escapeshellarg($dir));
    },
];
