<?php
/** The deluge unit: stats, traffic, restart and locating Deluge, against a fake deluge-web (tests/fixtures/deluge/router.php; helpers in tests/fixtures/deluge/helpers.php), HOME in a temp dir. */
require_once __DIR__ . '/fixtures/deluge/helpers.php';

return [
    'deluge: stats and traffic in bytes; limits from KiB/s, -1 = no limit' => function () {
        $fx = delugeFixture();
        try {
            // ~/.quota as PMSS cron writes it (quota -u <user> -v -s): 100G used of a 1024G plan, hard 1280G.
            file_put_contents($fx['home'] . '/.quota', "Disk quotas for user fake (uid 1001):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n       /dev/md2    100G   1024G   1280G               5       0       0\n");
            list($code, $l, $out) = delugeRun($fx, ['stats']);
            check($code === 0 && $l[0]['version'] === '2.0.3' && $l[0]['torrents'] === 5 && $l[0]['by_state']['paused'] === 1, 'stats: ' . $out);
            check($l[0]['quota_free_bytes'] === 992137445376 && $l[0]['quota_limit_bytes'] === 1099511627776 && $l[0]['quota_source'] === '~/.quota' && $l[0]['over_quota'] === false && is_int($l[0]['fs_free_bytes']) && !isset($l[0]['free_bytes']) && $l[0]['web_port'] === $fx['port'] && $l[0]['up_limit'] === 524288 && $l[0]['down_limit'] === 0, 'stats fields: ' . $out);
            list($code, $l, $out) = delugeRun($fx, ['traffic']);
            check($code === 0 && $l[0]['down_rate'] === 1000 && $l[0]['up_total'] === 40000, 'traffic: ' . $out);
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: restart is refused off PMSS, when not enabled, and when not installed' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'provider') !== false, 'generic restart: ' . $out);
            @mkdir($fx['dir'] . '/pmss', 0700, true);
            list($code, $l, $out) = delugeRun($fx, ['restart'], ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/pmss']);
            check($code === 1 && strpos($out, 'not enabled') !== false, 'restart without ~/.delugeEnable: ' . $out);
            if (!is_file('/usr/bin/deluged') && !is_file('/usr/local/bin/deluged')) {
                touch($fx['home'] . '/.delugeEnable');
                list($code, $l, $out) = delugeRun($fx, ['restart'], ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/pmss']);
                check($code === 1 && strpos($out, 'not installed') !== false, 'restart without deluged: ' . $out);
            }
        } finally {
            delugeDone($fx);
        }
    },
    'deluge: locate errors name the cause (not installed, not enabled, not running, other account, busy, refused secret, daemon down)' => function () {
        $fx = delugeFixture(['daemon_up' => false]);
        try {
            $pmss = ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/pmss'];
            list($code, $l, $out) = delugeRun($fx, ['list']);
            check($code === 1 && strpos($out, 'cannot reach the deluged daemon') !== false, 'daemon down: ' . $out);
            unlink($fx['home'] . '/.config/deluge/web.conf');
            list($code, $l, $out) = delugeRun($fx, ['list']);
            check($code === 1 && strpos($out, 'not installed') !== false, 'no deluged, no config: ' . $out);
            file_put_contents($fx['dir'] . '/bin/deluged', "#!/bin/sh\nexit 1\n"); // only looked at, never run
            chmod($fx['dir'] . '/bin/deluged', 0700);
            @mkdir($fx['dir'] . '/pmss', 0700, true);
            list($code, $l, $out) = delugeRun($fx, ['list'], $pmss);
            check($code === 1 && strpos($out, 'not enabled') !== false, 'PMSS without ~/.delugeEnable: ' . $out);
            touch($fx['home'] . '/.delugeEnable');
            $srv = stream_socket_server('tcp://127.0.0.1:0');
            $free = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
            fclose($srv);
            file_put_contents($fx['home'] . '/.delugeWebPort', "$free\n");
            list($code, $l, $out) = delugeRun($fx, ['list'], $pmss);
            check($code === 1 && strpos($out, 'not running') !== false, 'nothing listening: ' . $out);
            file_put_contents($fx['home'] . '/.delugeWebPort', $fx['port']);
            $s = delugeState($fx);
            $s['secret'] = 'different';
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $l, $out) = delugeRun($fx, ['list'], $pmss);
            check($code === 1 && strpos($out, 'refused the localclient secret') !== false, 'wrong secret: ' . $out);
            $s['secret'] = DELUGE_SECRET;
            $s['sleep'] = 3;
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list($code, $l, $out) = delugeRun($fx, ['list'], $pmss + ['PIKKUMIES_TORRENT_TIMEOUT' => '1']);
            check($code === 1 && strpos($out, 'busy') !== false, 'timeout must read as busy: ' . $out);
            // Another account's deluge-web port, from a fixed /proc/net/tcp so the check always runs.
            require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeLocate.php';
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            $table = sprintf("  sl  local_address rem_address   st\n   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d 0 1 1\n", $fx['port'], $uid + 1);
            try {
                DelugeLocate::find($fx['home'], 'pmss', $uid, [$table, '']); // ~/.delugeWebPort points at the fake's port
                check(false, 'a port of another account was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'another account') !== false, 'foreign web port: ' . $e->getMessage());
            }
        } finally {
            delugeDone($fx);
        }
    },
];
