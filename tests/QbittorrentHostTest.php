<?php
/** tools/torrent-clients/qbittorrent: stats, traffic, login, locating qBittorrent and restart, against a fake WebUI (tests/fixtures/qbittorrent/router.php); no real client is contacted. */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/qbittorrent/QbitSecret.php';

$A = str_repeat('a', 40);
$B = str_repeat('b', 40);

return [
    'qbittorrent: stats and traffic report version, counts, ports and rates in raw units' => function () {
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['stats']);
            $s = cliLines($out)[0];
            check($code === 0 && $s['version'] === 'v5.0.0' && $s['webapi'] === '2.11.2' && $s['webui_port'] === $fx['port'] && $s['listen_port'] === 51413, 'stats: ' . $out);
            check($s['torrents'] === 10 && $s['by_state']['paused'] === 2 && $s['delete_mode'] === 'trash' && $s['up_limit'] === 1048576 && !isset($s['warning']), 'stats counts: ' . $out);
            list($code, $out) = qbitRun($fx, ['traffic']);
            $t = cliLines($out)[0];
            check($code === 0 && $t['down_rate'] === 100 && $t['up_total'] === 2000 && $t['down_limit'] === 0, 'traffic: ' . $out);
        });
        qbitWith(['auth' => false], function ($fx) {
            list(, $out) = qbitRun($fx, ['stats']);
            check(strpos($out, 'localhost bypass') !== false && qbitState($fx)['logins'] === 0, 'a WebUI without login must be reported, not logged in to: ' . $out);
        });
    },
    'qbittorrent: login needs a terminal; a rejected secret is marked and never tried again' => function () {
        qbitWith(['password' => 'other'], function ($fx) {
            list($code, $out) = qbitRun($fx, ['login']);
            check($code === 1 && strpos($out, 'terminal') !== false, 'login without a tty: ' . $out);
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'rejected') !== false && qbitState($fx)['logins'] === 1, 'first failure: ' . $out);
            check(json_decode(file_get_contents($fx['dir'] . '/secrets/qbittorrent'), true)['rejected'] === true, 'not marked rejected');
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && qbitState($fx)['logins'] === 1, 'a rejected secret was sent again: ' . $out);
            unlink($fx['dir'] . '/secrets/qbittorrent');
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'login') !== false && qbitState($fx)['logins'] === 1, 'nothing stored: ' . $out);
        });
        $tmp = sys_get_temp_dir() . '/pk-qbit-secret-' . getmypid();
        $s = new QbitSecret("$tmp/secrets");
        $s->save('x');
        check((fileperms(dirname($s->path())) & 0777) === 0700 && (fileperms($s->path()) & 0777) === 0600, 'secret file modes');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'qbittorrent: locate errors name the cause (not installed, not enabled, not running, busy, other account)' => function () {
        qbitWith([], function ($fx) {
            unlink($fx['home'] . '/.qbittorrentEnable');
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'not enabled') !== false, 'not enabled: ' . $out);
            touch($fx['home'] . '/.qbittorrentEnable');
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'not running') !== false, 'not running: ' . $out);
            // Another account on the WebUI port, from a fixed /proc/net/tcp so the check always runs.
            require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/qbittorrent/QbitEndpoint.php';
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            $ep = new QbitEndpoint($fx['home'], 'pmss');
            $ep->tables = [sprintf("  sl  local_address rem_address   st\n   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d 0 1 1\n", $fx['port'], $uid + 1), ''];
            try {
                $ep->locate();
                check(false, 'a WebUI port of another account was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'another account') !== false, 'foreign port: ' . $e->getMessage());
            }
            exec('rm -rf ' . escapeshellarg($fx['home'] . '/.config') . ' ' . escapeshellarg($fx['home'] . '/.qbittorrentPort'));
            list($code, $out) = qbitRun($fx, ['list'], 'generic');
            check($code === 1 && strpos($out, 'not installed') !== false, 'not installed: ' . $out);
        }, false);
        qbitWith(['slow' => 7], function ($fx) {
            list($code, $out) = qbitRun($fx, ['list']);
            check($code === 1 && strpos($out, 'busy') !== false, 'timeout must read as busy: ' . $out);
        });
    },
    'qbittorrent: restart refuses where it must; on PMSS it stops the own process and starts it as the panel does' => function () {
        qbitWith([], function ($fx) {
            foreach (['generic' => 'not supported', 'feral' => 'five minutes', 'whatbox' => 'Manage page'] as $family => $needle) {
                list($code, $out) = qbitRun($fx, ['restart'], $family);
                check($code === 1 && strpos($out, $needle) !== false, "$family: $out");
            }
            file_put_contents($fx['dir'] . '/bin/qbittorrent-nox', "#!/bin/sh\npwd > \"\$HOME/started\"\necho \"\$@\" >> \"\$HOME/started\"\n");
            chmod($fx['dir'] . '/bin/qbittorrent-nox', 0755);
            unlink($fx['home'] . '/.qbittorrentEnable');
            list($code, $out) = qbitRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'disabled') !== false && !file_exists($fx['home'] . '/started'), 'disabled client was started: ' . $out);
            touch($fx['home'] . '/.qbittorrentEnable');
            $pids = [];
            foreach (['mnt:[4026531841]', 'mnt:[4026532999]'] as $ns) { // the second one is in another mount namespace (a container)
                $pid = (int) exec(escapeshellarg(PHP_BINARY) . " -r 'sleep(60);' > /dev/null 2>&1 & echo \$!");
                qbitFakeProc($fx, $pid, $ns);
                $pids[] = $pid;
            }
            try {
                list($code, $out) = qbitRun($fx, ['restart']);
                $r = cliLines($out)[0];
                check($code === 0 && $r['stopped'] === 1 && $r['killed'] === 0 && $r['answered'] === true && $r['version'] === 'v5.0.0', 'restart: ' . $out);
                check(file_get_contents($fx['home'] . '/started') === $fx['home'] . "\n-d\n", 'not started from HOME with -d');
                $st = @file_get_contents("/proc/{$pids[0]}/stat");
                check($st === false || strpos(substr($st, strrpos($st, ')')), ') Z') === 0, 'the own process was not stopped');
                $st = @file_get_contents("/proc/{$pids[1]}/stat");
                check(is_string($st) && strpos(substr($st, strrpos($st, ')')), ') Z') !== 0, 'a process in another mount namespace was signalled');
            } finally {
                foreach ($pids as $pid) {
                    if (function_exists('posix_kill')) {
                        @posix_kill($pid, 9);
                    }
                }
            }
        });
    },
    'qbittorrent: the stop re-checks owner, name and namespace before SIGKILL; an unreadable own namespace finds nothing' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/qbittorrent/QbitProc.php';
        $root = sys_get_temp_dir() . '/pk-qbit-proc-' . getmypid() . '-' . mt_rand();
        mkdir("$root/self/ns", 0700, true);
        mkdir("$root/999999/ns", 0700, true);
        file_put_contents("$root/999999/comm", "qbittorrent-nox\n");
        symlink('mnt:[1]', "$root/999999/ns/mnt");
        try {
            check((new QbitProc($root))->pids() === [], 'own namespace unreadable, yet a process was picked');
            symlink('mnt:[1]', "$root/self/ns/mnt");
            // The process ignores SIGTERM and, while the tool waits, its pid goes to another program (pid reuse).
            $p = new class($root) extends QbitProc {
                public $sent = [];
                public function alive($pid)
                {
                    return true;
                }
                public function signal($pid, $sig)
                {
                    $this->sent[] = [$pid, $sig];
                    file_put_contents($this->root . "/$pid/comm", "bash\n");
                    return true;
                }
            };
            $p->wait = 1;
            $r = $p->stop();
            check($p->sent === [[999999, 15]] && $r['killed'] === 0, 'SIGKILL went to a reused pid: ' . json_encode([$p->sent, $r]));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    },
    'qbittorrent: a stored password others can read is set back to 0600; another owner or a symlink is refused' => function () {
        qbitWith([], function ($fx) {
            $f = $fx['dir'] . '/secrets/qbittorrent';
            chmod($f, 0644);
            list($code, $out) = qbitRun($fx, ['stats']);
            clearstatcache();
            check($code === 0 && (fileperms($f) & 0777) === 0600, 'a 0644 password file: ' . $out);
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            try {
                (new QbitSecret(dirname($f), $uid + 1))->usable();
                check(false, 'a password file another account owns was trusted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'belongs to uid') !== false && strpos($e->hint, 'qbittorrent.php login') !== false,
                    'owner: ' . $e->getMessage());
            }
            $logins = qbitState($fx)['logins'];
            rename($f, "$f.real");
            symlink("$f.real", $f);
            list($code, $out) = qbitRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'is a symlink') !== false && qbitState($fx)['logins'] === $logins,
                'a symlinked password file: ' . $out);
        });
    },
];
