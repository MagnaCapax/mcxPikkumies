<?php
/** The deluge unit: which daemon deluge-web is connected to, against a fake deluge-web (tests/fixtures/deluge/router.php; helpers in tests/fixtures/deluge/helpers.php), HOME in a temp dir. */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

return [
    'deluge: deluge-web is never pointed at a daemon port nobody of ours listens on (it would send the secret)' => function () {
        // The fixture starts first and its own deluge-web listener holds its port throughout this test, so the
        // free-port pick below can never land on the same port the kernel could otherwise re-hand out between
        // delugeFixture()'s own bind-and-close and this one (both use the same technique).
        $fx = delugeFixture();
        try {
            $s = stream_socket_server('tcp://127.0.0.1:0');
            $free = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
            fclose($s); // now nothing listens there; on a shared host another account could
            delugeEdit($fx, function (&$state) use ($free) {
                $state['hosts'] = [['h1', '127.0.0.1', $free, 'localclient']];
            });
            list($code, $l, $out) = delugeRun($fx, ['list']);
            check($code === 1 && strpos($out, "nothing listens on the deluged port $free") !== false, 'connected to an unowned port: ' . $out);
            $calls = json_decode((string) file_get_contents($fx['dir'] . '/state.json'), true)['calls'];
            check(!in_array('web.connect', array_column((array) $calls, 'method'), true) && !in_array('web.connect', (array) $calls, true), 'web.connect was sent: ' . json_encode($calls));
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: the daemon port is checked before deluge-web connects, and when it is already connected (fixed owner answers)' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeRpc.php';
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        foreach ([false, true] as $connected) {
            $fx = delugeFixture(['connected' => $connected, 'connected_host' => $connected ? 'h0' : null, 'daemon_port' => 58846]);
            delugeEdit($fx, 'delugeLocalForeignHost');
            try {
                $asked = [];
                $owns = function ($port) use (&$asked) {
                    $asked[] = $port;
                    return 'other';
                };
                try {
                    (new DelugeRpc('http://127.0.0.1:' . $fx['port'] . '/json', DELUGE_SECRET, 5, $uid))->open(58846, $owns);
                    check(false, ($connected ? 'connected' : 'disconnected') . ': a daemon port of another account was trusted');
                } catch (CliError $e) {
                    check(strpos($e->getMessage(), 'another account') !== false, 'refusal: ' . $e->getMessage());
                }
                check(!delugeCalls($fx, 'web.connect') && $asked === [$connected ? 58846 : $fx['port']], 'web.connect sent or wrong port checked: ' . json_encode($asked));
                $rpc = new DelugeRpc('http://127.0.0.1:' . $fx['port'] . '/json', DELUGE_SECRET, 5, $uid);
                $rpc->open(58846, function () {
                    return 'own';
                });
                check($rpc->call('web.connected', []) === true, 'own daemon refused');
            } finally {
                delugeDone($fx);
            }
        }
    },
    'deluge: an already connected deluge-web is checked by the host it is connected to,
        never by the port its daemon reports' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeRpc.php';
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        // Connected to h0 (port 58846, another account's), whose daemon claims a port of ours as its daemon_port.
        $fx = delugeFixture(['connected' => true, 'connected_host' => 'h0']);
        delugeEdit($fx, 'delugeLocalForeignHost');
        $owns = function ($port) {
            return $port === 58846 ? 'other' : 'own';
        };
        try {
            try {
                (new DelugeRpc('http://127.0.0.1:' . $fx['port'] . '/json', DELUGE_SECRET, 5, $uid))->open($fx['port'], $owns);
                check(false, 'the daemon\'s own claim of a port was trusted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'another account') !== false && strpos($e->getMessage(), '58846') !== false,
                    'refusal: ' . $e->getMessage());
            }
            check(!delugeCalls($fx, 'core.get_config_value') && !delugeCalls($fx, 'web.connect'), 'asked the daemon, or reconnected');
        } finally {
            delugeDone($fx);
        }
        // Connected to the remote h0 (never probed), or no host answers 'Connected': disconnect, then connect to our own daemon.
        $fx = delugeFixture(['connected' => true, 'connected_host' => 'h0']);
        try {
            $rpc = new DelugeRpc('http://127.0.0.1:' . $fx['port'] . '/json', DELUGE_SECRET, 5, $uid);
            $rpc->open($fx['port'], $owns);
            $names = array_column(delugeState($fx)['calls'], 'method');
            $d = array_search('web.disconnect', $names, true);
            check($d !== false && $d < array_search('web.connect', $names, true) && delugeCalls($fx, 'web.connect')[0]['params'] === ['h1'],
                'calls: ' . implode(',', $names));
            check($rpc->call('web.connected', []) === true, 'not connected to the own daemon');
            check(array_column(delugeCalls($fx, 'web.get_host_status'), 'params') === [['h1']], 'a remote host was probed');
        } finally {
            delugeDone($fx);
        }
    },
];
