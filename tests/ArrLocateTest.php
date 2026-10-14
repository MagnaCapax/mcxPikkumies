<?php
/** Discovery order, the connection errors in their order, Docker, and the owner of the port (tests/fixtures/arr/). */
require_once __DIR__ . '/fixtures/arr/harness.php';

/** A fake `docker` (port answers from docker-port.txt, restart counts a start) and a socket this user owns. */
function arrDocker(array $fx, $portOut)
{
    file_put_contents($fx['dir'] . '/docker-port.txt', $portOut);
    file_put_contents($fx['dir'] . '/bin/docker', "#!/bin/sh\nd=\$(dirname \"\$0\")/..\necho \"\$DOCKER_HOST \$*\" >> \"\$d/docker.log\"\n"
        . "case \"\$1\" in port) cat \"\$d/docker-port.txt\" ;; restart) echo x >> \"\$d/restarts\" ;; esac\n");
    chmod($fx['dir'] . '/bin/docker', 0700);
    return stream_socket_server('unix://' . $fx['dir'] . '/docker.sock');
}

/** A /proc/net/tcp with one LISTEN socket on 127.0.0.1:$port owned by $uid, and an empty tcp6 (Host::ownsListener's seam). */
function arrNetTables($port, $uid)
{
    $head = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n";
    return [$head . sprintf('   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 4242 1', $port, $uid), ''];
}

/** The CliError message of $fn, or '' when it did not throw one. */
function arrError(callable $fn)
{
    try {
        $fn();
    } catch (CliError $e) {
        return $e->getMessage();
    }
    return '';
}

return [
    'arr locate: PMSS lowercase first, Servarr capitalised elsewhere, an override in local/config.php before both' => function () {
        $fx = arrFixture('sonarr', ['where' => 'servarr']);
        $notPmss = ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/not-pmss'];
        try {
            list($code, $l, $out) = arrRun($fx, ['stats'], $notPmss);
            check($code === 0 && $l[0]['found'] === 'servarr' && $l[0]['process'] === 'answering', 'servarr default: ' . $out);
            @mkdir($fx['home'] . '/.config/sonarr', 0700, true);
            file_put_contents($fx['home'] . '/.config/sonarr/config.xml', '');
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, '0 bytes') !== false, 'on PMSS the lowercase file wins, even when broken: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats'], $notPmss);
            check($code === 0 && $l[0]['found'] === 'servarr', 'off PMSS the lowercase folder is not looked at: ' . $out);
        } finally {
            arrDone($fx);
        }
        $app = ArrApps::get('radarr');
        $c = array_keys(ArrLocate::candidates($app, '/h', 'pmss', ['config' => '/x/config.xml']));
        check($c === ['override', 'pmss', 'servarr', 'docker'], 'order: ' . json_encode($c));
        check(array_keys(ArrLocate::candidates($app, '/h', 'generic')) === ['servarr', 'docker'], 'generic order');
    },
    'arr locate: Docker only with a daemon socket of this user; the published port must be a plain port' => function () {
        $fx = arrFixture('radarr', ['where' => 'docker']);
        try {
            $sock = arrDocker($fx, "0.0.0.0:{$fx['port']}\n[::]:{$fx['port']}\n");
            $env = ['DOCKER_HOST' => 'unix://' . $fx['dir'] . '/docker.sock'];
            list($code, $l, $out) = arrRun($fx, ['stats'], $env);
            check($code === 0 && $l[0]['found'] === 'docker' && $l[0]['port'] === $fx['port'], 'docker: ' . $out);
            $log = (string) file_get_contents($fx['dir'] . '/docker.log');
            check(strpos($log, "unix://{$fx['dir']}/docker.sock port radarr 8989/tcp") !== false && stripos($log, ARR_KEY) === false,
                'docker argv: ' . $log);
            list($code, $l, $out) = arrRun($fx, ['stats']); // DOCKER_HOST at a path that is no socket of ours
            check($code === 1 && strpos($out, 'not installed') !== false && strpos($out, 'docker') === false,
                'a foreign daemon was used: ' . $out);
            file_put_contents($fx['dir'] . '/docker-port.txt', "0.0.0.0:99999\n");
            list($code, $l, $out) = arrRun($fx, ['stats'], $env);
            check($code === 1 && strpos($out, 'not a port') !== false, 'port 99999 accepted: ' . $out);
            file_put_contents($fx['dir'] . '/docker-port.txt', "0.0.0.0:1;rm -rf ~\n");
            list($code, $l, $out) = arrRun($fx, ['stats'], $env);
            check($code === 1 && strpos($out, 'not a port') !== false, 'junk accepted: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['restart'], $env);
            check($code === 0 && strpos((string) file_get_contents($fx['dir'] . '/docker.log'), 'restart radarr') !== false,
                'docker restart: ' . $out);
            fclose($sock);
        } finally {
            arrDone($fx);
        }
    },
    'arr locate: errors name the cause in order (off, wrong base path, not the API, busy)' => function () {
        $fx = arrFixture('sonarr');
        try {
            touch($fx['home'] . '/.sonarrDisable');
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'turned off') !== false && !arrState($fx)['calls'], 'Disable: ' . $out);
            unlink($fx['home'] . '/.sonarrDisable');
            arrSet($fx, ['mode' => 'redirect']);
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'wrong base path') !== false && strpos($out, '/public-fake/sonarr') !== false, '307: ' . $out);
            arrSet($fx, ['mode' => 'html']);
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 1 && strpos($out, 'not with the app') !== false, 'html: ' . $out);
            arrSet($fx, ['mode' => '', 'sleep' => 3]);
            list($code, $l, $out) = arrRun($fx, ['list'], ['PIKKUMIES_ARR_TIMEOUT' => '1']);
            check($code === 1 && strpos($out, 'busy') !== false && strpos($out, 'not down') !== false, 'timeout: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr locate: a port another account listens on is refused before the key is sent, from config.xml or from Docker' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $app = ArrApps::get('sonarr');
        $fx = arrFixture('sonarr', ['start' => false]);
        try {
            $find = function ($owner) use ($app, $fx, $uid) {
                return ArrLocate::find($app, $fx['home'], 'pmss', $uid, [], false, arrNetTables($fx['port'], $owner));
            };
            check($find($uid)['port'] === $fx['port'], 'this user\'s own listener was refused');
            $msg = arrError(function () use ($find, $uid) {
                $find($uid + 1);
            });
            check(strpos($msg, "port {$fx['port']}") !== false && strpos($msg, 'another account') !== false, 'a foreign port was used: ' . $msg);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('radarr', ['where' => 'docker', 'start' => false]);
        $path = getenv('PATH');
        $host = getenv('DOCKER_HOST');
        try {
            $sock = arrDocker($fx, "0.0.0.0:{$fx['port']}\n");
            putenv('PATH=' . $fx['dir'] . '/bin:' . $path);
            putenv('DOCKER_HOST=unix://' . $fx['dir'] . '/docker.sock');
            $find = function ($owner) use ($fx, $uid) {
                return ArrLocate::find(ArrApps::get('radarr'), $fx['home'], 'pmss', $uid, [], false, arrNetTables($fx['port'], $owner));
            };
            $w = $find($uid);
            check($w['source'] === 'docker' && $w['port'] === $fx['port'], 'docker, own listener: ' . json_encode($w));
            $msg = arrError(function () use ($find, $uid) {
                $find($uid + 1);
            });
            check(strpos($msg, 'Docker container radarr') !== false && strpos($msg, 'another account') !== false,
                'a foreign Docker port: ' . $msg);
            fclose($sock);
        } finally {
            putenv($path === false ? 'PATH' : "PATH=$path");
            putenv($host === false ? 'DOCKER_HOST' : "DOCKER_HOST=$host");
            arrDone($fx);
        }
    },
];
