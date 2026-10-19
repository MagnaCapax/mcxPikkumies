<?php
/**
 * The containers category's foundations (ADR-0034): the docker unit's dispatcher, its shared classes (DockerCli,
 * DockerRefs, DockerExposure) and the containers mode of the fake docker. The verbs themselves have their own tests.
 */
require_once __DIR__ . '/fixtures/containers/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerCli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerRefs.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerExposure.php';

const CONTAINERS_VERBS = ['stats', 'list', 'show', 'logs', 'start', 'stop', 'restart', 'remove', 'images', 'prune', 'diagnose', 'launch-plan', 'launch',
    'fix-owner', 'expose', 'dump'];

/** A full 64-hex container ID made from a short seed. */
function containersId($seed)
{
    return hash('sha256', (string) $seed);
}

/** A DockerCli for the fixture, with PATH set to the fake while $fn runs. */
function containersWithCli(array $fx, callable $fn, array $seams = [], array $env = [])
{
    return testWithEnv($env + ['PATH' => $fx['dir'] . '/bin:' . getenv('PATH'), 'DOCKER_HOST' => null, 'DOCKER_CONTEXT' => null], function () use ($fx, $fn, $seams) {
        return $fn(new DockerCli(containersSeams($fx, $seams)));
    });
}

return [
    'containers unit: an unknown verb exits 2, and every verb not built yet exits 1 "not built yet" without calling docker' => function () {
        $fx = containersFixture();
        try {
            list($code, $recs) = containersRun($fx, ['nope']);
            check($code === 2 && $recs && $recs[count($recs) - 1]['ok'] === false, "unknown verb must be a usage error (exit 2), got $code");
            foreach (CONTAINERS_VERBS as $verb) {
                $part = ['diagnose' => 'DockerDiagnose', 'launch-plan' => 'DockerLaunch', 'launch' => 'DockerLaunch', 'fix-owner' => 'DockerOwner', 'expose' => 'DockerExpose', 'dump' => 'DockerDump'];
                $built = is_file(dirname(__DIR__) . '/pikkumies/tools/containers/docker/' . (isset($part[$verb]) ? $part[$verb] : 'DockerOps') . '.php');
                if ($built) {
                    continue; // that stage's own tests cover it
                }
                list($code, $recs) = containersRun($fx, [$verb]);
                $last = $recs ? $recs[count($recs) - 1] : [];
                check($code === 1 && isset($last['error']) && strpos($last['error'], "$verb is not built yet") === 0, "$verb: expected exit 1 'not built yet', got $code " . json_encode($last));
            }
            check(containersState($fx)['calls'] === [], 'loading docker.php or a stub verb called docker: ' . json_encode(containersState($fx)['calls']));
        } finally {
            containersDone($fx);
        }
    },
    'containers unit: --verbs lists the 16 verbs in USAGE order, and a mistyped option is a usage error that runs nothing' => function () {
        $fx = containersFixture();
        try {
            list($code, , $out) = containersRun($fx, ['--verbs']);
            check($code === 0 && array_values(array_filter(explode("\n", $out), 'strlen')) === CONTAINERS_VERBS, 'verbs: ' . $out);
            list($code) = containersRun($fx, ['list', '--dry-run']);
            check($code === 2, "an invented option must exit 2, got $code");
            check(containersState($fx)['calls'] === [], 'a usage error called docker');
        } finally {
            containersDone($fx);
        }
    },
    'containers refs: names and 4+ hex ID prefixes resolve to full IDs; unknown, ambiguous, short or malformed refs fail the whole call' => function () {
        $a = containersId('a');
        $b = containersId('b');
        $list = [['id' => $a, 'name' => 'web-1'], ['id' => $b, 'name' => '/db']];
        check(DockerRefs::resolve(['web-1', 'db'], $list) === [$a, $b], 'names must resolve, a leading / in the name ignored');
        check(DockerRefs::resolve([substr($b, 0, 6), 'db'], $list) === [$b], 'a prefix and the name of one container collapse to one ID');
        $twins = [['id' => 'abcd' . substr($a, 4), 'name' => 'x'], ['id' => 'abcd' . substr($b, 4), 'name' => 'y']];
        foreach ([[['web-9'], $list, 'no container'], [['abcd'], $twins, 'matches 2'], [[substr($a, 0, 3)], $list, 'no container'],
            [['-rf'], $list, 'is not a container name'], [['web-1', 'nope'], $list, 'no container'], [[], $list, 'name at least one']] as $case) {
            list($refs, $cs, $want) = $case;
            $err = '';
            try {
                DockerRefs::resolve($refs, $cs, true);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, 'nothing changed') === 0 && strpos($err, $want) !== false, json_encode($refs) . ": expected '$want', got '$err'");
        }
        $err = '';
        try {
            DockerRefs::resolve(['nope'], $list);
        } catch (CliError $e) {
            $err = $e->getMessage();
        }
        check(strpos($err, 'not found: ') === 0, 'a read verb says "not found:", got ' . $err);
    },
    'containers refs: name and image grammar refuse option-looking, empty and control-character input' => function () {
        foreach (['web', 'web-1', 'a.b_c', 'X9'] as $ok) {
            check(DockerRefs::validName($ok), "$ok must be a valid name");
        }
        foreach (['', '-x', '.x', 'a b', "a\nb", 'a/b', str_repeat('a', 129)] as $bad) {
            check(!DockerRefs::validName($bad), json_encode($bad) . ' must be refused as a name');
        }
        foreach (['nginx:alpine', 'lscr.io/linuxserver/jellyfin', 'mariadb:11', 'img@sha256:' . str_repeat('a', 64)] as $ok) {
            check(DockerRefs::validImage($ok), "$ok must be a valid image");
        }
        foreach (['', '--privileged', '-v', 'a b', "nginx\n", 'img;rm'] as $bad) {
            check(!DockerRefs::validImage($bad), json_encode($bad) . ' must be refused as an image');
        }
    },
    'containers refs: readBack takes inspect\'s state; zero times are null; oom is "unknown" unless limits are enforced' => function () {
        $i = ['Id' => containersId('r'), 'Name' => '/web', 'RestartCount' => 2, 'State' => ['Status' => 'exited', 'Running' => false, 'ExitCode' => 137,
            'StartedAt' => '2026-10-17T10:00:00Z', 'FinishedAt' => '0001-01-01T00:00:00Z', 'OOMKilled' => false, 'Error' => '']];
        $r = DockerRefs::readBack($i);
        check($r['name'] === 'web' && $r['state'] === 'exited' && $r['exit_code'] === 137 && $r['restart_count'] === 2, 'fields: ' . json_encode($r));
        check($r['finished_at'] === null && $r['started_at'] === '2026-10-17T10:00:00Z' && $r['error_text'] === null, 'times: ' . json_encode($r));
        check($r['oom'] === 'unknown', 'oom must be unknown when limits are not enforced (OOMKilled false there is not evidence)');
        check(DockerRefs::readBack($i, true)['oom'] === false, 'with limits enforced OOMKilled is read');
        check(DockerRefs::readBack([])['state'] === null, 'an empty inspect gives nulls, not a guess');
    },
    'containers exposure: every interface is public, loopback is local-shared on a shared host and none alone, a dropped port is not public' => function () {
        $pmss = DockerExposure::facts(true, Host::TABLE['pmss']['docker']);
        $solo = DockerExposure::facts(false, null);
        $rows = [
            ['0.0.0.0', 8096, $pmss, 'public'], ['', 8096, $pmss, 'public'], ['::', 8096, $pmss, 'public'], ['[::]', 8096, $pmss, 'public'],
            ['127.0.0.1', 8096, $pmss, 'local-shared'], ['::1', 8096, $pmss, 'local-shared'], ['127.0.0.2', 8096, $pmss, 'local-shared'],
            ['::ffff:127.0.0.1', 8096, $pmss, 'local-shared'], ['0.0.0.0', 8080, $pmss, 'local-shared'], ['0.0.0.0', 8112, $pmss, 'local-shared'],
            ['203.0.113.5', 8080, $pmss, 'public'], ['127.0.0.1', 8096, $solo, 'none'], ['0.0.0.0', 8080, $solo, 'public'], ['0.0.0.0', '', $pmss, 'none'],
        ];
        foreach ($rows as $r) {
            list($ip, $port, $facts, $want) = $r;
            $got = DockerExposure::classify($ip, $port, $facts);
            check($got === $want, "$ip:$port shared=" . json_encode($facts['shared']) . " -> $got, expected $want");
        }
        $insp = ['NetworkSettings' => ['Ports' => ['80/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '38001']], '443/tcp' => null]],
            'HostConfig' => ['PortBindings' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '9']]]]];
        check(DockerExposure::ports($insp, $pmss) === [['container_port' => '80/tcp', 'host_ip' => '127.0.0.1', 'host_port' => 38001, 'exposure' => 'local-shared']],
            'running container: NetworkSettings wins, unpublished ports are left out: ' . json_encode(DockerExposure::ports($insp, $pmss)));
        $stopped = ['NetworkSettings' => ['Ports' => []], 'HostConfig' => ['PortBindings' => ['80/tcp' => [['HostIp' => '', 'HostPort' => '8081']]]]];
        check(DockerExposure::ports($stopped, $pmss)[0]['exposure'] === 'public' && DockerExposure::ports($stopped, $pmss)[0]['host_ip'] === '0.0.0.0', 'stopped container: PortBindings, empty HostIp = every interface');
    },
    'containers cli: absence says cli-missing, socket-missing, refused tcp and refused other-account, and run() refuses before calling docker' => function () {
        $fx = containersFixture();
        try {
            $noCli = testWithEnv(['PATH' => $fx['dir'] . '/empty', 'DOCKER_HOST' => null], function () use ($fx) {
                return (new DockerCli(containersSeams($fx)))->absence();
            });
            check($noCli['state'] === 'cli-missing', 'no docker anywhere: ' . json_encode($noCli));
            check(containersWithCli($fx, function ($d) {
                return $d->absence();
            })['state'] === 'ok', 'the fixture socket must be usable');
            $tcp = containersWithCli($fx, function ($d) {
                return $d->absence();
            }, [], ['DOCKER_HOST' => 'tcp://127.0.0.1:2375']);
            check($tcp['state'] === 'refused' && $tcp['reason'] === 'tcp', 'tcp DOCKER_HOST: ' . json_encode($tcp));
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            $other = containersWithCli($fx, function ($d) {
                return $d->absence();
            }, ['uid' => $uid + 1]);
            check($other['state'] === 'refused' && $other['reason'] === 'other-account', 'a socket of another uid: ' . json_encode($other));
            $missing = containersWithCli($fx, function ($d) {
                return $d->absence();
            }, ['candidates' => [$fx['dir'] . '/run/none.sock']]);
            check($missing['state'] === 'socket-missing', 'no socket: ' . json_encode($missing));
            $err = '';
            containersWithCli($fx, function ($d) use (&$err) {
                try {
                    $d->run(['ps']);
                } catch (CliError $e) {
                    $err = $e->getMessage();
                }
            }, [], ['DOCKER_HOST' => 'tcp://127.0.0.1:2375']);
            check(strpos($err, 'refused (tcp)') !== false && strpos($err, 'nothing was done') !== false, 'run() on a refused daemon: ' . $err);
            check(containersState($fx)['calls'] === [], 'a refused daemon was called');
        } finally {
            containersDone($fx);
        }
    },
    'containers cli: every call is `timeout N docker`, with DOCKER_HOST the own socket and DOCKER_CONTEXT removed; the probe reads the server version' => function () {
        $fx = containersFixture();
        try {
            $r = containersWithCli($fx, function ($d) {
                return [$d->argv(['ps']), $d->argv(['version'], DockerCli::PROBE_SECONDS), $d->env(), $d->probe()];
            }, [], ['DOCKER_CONTEXT' => 'elsewhere']);
            list($argv, $probeArgv, $env, $probe) = $r;
            check($argv[0] === 'timeout' && $argv[1] === '60' && basename($argv[2]) === 'docker' && $argv[3] === 'ps', 'argv: ' . json_encode($argv));
            check($probeArgv[1] === '10', 'the probe must use 10 s');
            check($env['DOCKER_HOST'] === 'unix://' . $fx['sock'] && array_key_exists('DOCKER_CONTEXT', $env) && $env['DOCKER_CONTEXT'] === null, 'env: ' . json_encode($env));
            check($probe['ok'] === true && $probe['server'] === '26.1.5' && $probe['engine'] === 'Engine', 'probe: ' . json_encode($probe));
            $st = containersState($fx);
            check($st['calls'] === [['version', '--format', '{{json .}}']], 'calls: ' . json_encode($st['calls']));
            check($st['contexts'] === [null], 'DOCKER_CONTEXT reached docker: ' . json_encode($st['contexts']));
        } finally {
            containersDone($fx);
        }
    },
    'containers cli: a daemon that does not answer costs the time limit, not a hang (real timeout, fake docker sleeping)' => function () {
        $fx = containersFixture();
        try {
            $t = microtime(true);
            $r = containersWithCli($fx, function ($d) {
                return $d->run(['version', '--format', '{{json .}}'], 1);
            }, [], ['FAKE_SLEEP' => '6']);
            $took = microtime(true) - $t;
            check($r['timed_out'] === true && $took < 5, 'expected timed_out within 5 s, got ' . json_encode(['code' => $r['code'], 'took' => round($took, 2)]));
        } finally {
            containersDone($fx);
        }
    },
    'containers cli: requireHealthy stops with the ladder state when the probe fails; a server-less answer is not healthy' => function () {
        $fx = containersFixture(['fail' => ['version' => ['code' => 1, 'err' => 'Cannot connect to the Docker daemon']]]);
        try {
            $err = '';
            $hint = '';
            mkdir($fx['dir'] . '/pmss');
            containersWithCli($fx, function ($d) use (&$err, &$hint) {
                try {
                    $d->requireHealthy();
                } catch (CliError $e) {
                    $err = $e->getMessage();
                    $hint = $e->hint;
                }
            }, ['pmss_dir' => $fx['dir'] . '/pmss']);
            check(strpos($err, 'Docker is not answering (down)') === 0 && strpos($hint, 'systemctl --user start docker') !== false, "down: $err / $hint");
            check(count(containersState($fx)['calls']) === 1, 'no verb may fan out after a failed probe');
        } finally {
            containersDone($fx);
        }
        $fx = containersFixture(['engine' => ['version' => ['Client' => ['Version' => '26.1.5']]]]);
        try {
            $p = containersWithCli($fx, function ($d) {
                return $d->probe();
            });
            check($p['ok'] === false && $p['client'] === '26.1.5' && $p['server'] === null, 'a client-only answer is not a daemon: ' . json_encode($p));
        } finally {
            containersDone($fx);
        }
    },
    'containers ladder: healthy, context mismatch, second daemon, storage driver, wedged, linger and down, with PMSS advice from Host::TABLE' => function () {
        $base = ['probe_ok' => false, 'timed_out' => false, 'socket_file' => false, 'daemons' => 0, 'processes' => 0, 'context_mismatch' => false,
            'cli_context' => '', 'storage_driver' => '', 'storage_driver_missing' => false, 'pmss' => true, 'linger' => null];
        $cases = [
            [['probe_ok' => true], 'healthy'], [['probe_ok' => true, 'context_mismatch' => true], 'cli-context-mismatch'],
            [['probe_ok' => true, 'daemons' => 2, 'processes' => 4], 'second-daemon'], [['storage_driver_missing' => true], 'storage-driver-missing'],
            [['processes' => 3, 'daemons' => 1, 'timed_out' => true], 'wedged'], [['processes' => 1], 'wedged'],
            [['pmss' => false, 'linger' => false], 'linger-missing'], [[], 'down'], [['pmss' => false, 'linger' => true], 'down'],
        ];
        foreach ($cases as $c) {
            $l = DockerCli::ladder($c[0] + $base);
            check($l['state'] === $c[1], json_encode($c[0]) . " -> {$l['state']}, expected {$c[1]}");
            check(in_array($l['state'], DockerCli::STATES, true), "{$l['state']} is not a declared state");
        }
        $wedged = DockerCli::ladder(['processes' => 2] + $base);
        check(!array_key_exists('needs_user_ok', $wedged) && strpos($wedged['next_step'], 'asks the user') === false && strpos($wedged['next_step'], Host::TABLE['pmss']['docker']['recovery']) !== false, 'wedged on PMSS: run after a review, no approval question, the provider page');
        check(strpos($wedged['next_step'], 'no dockerd-rootless process') !== false && !preg_match('/\bpkill\b|\bkillall\b/', $wedged['next_step']), 'wedged: check for leftovers, print no kill command');
        check($wedged['source'] === Host::TABLE['pmss']['docker']['source'] && $wedged['verified'] === Host::TABLE['pmss']['docker']['verified'], 'PMSS advice carries source and verified');
        $down = DockerCli::ladder($base);
        check(strpos($down['next_step'], 'wait 5 minutes') !== false && strpos($down['next_step'], 'systemctl --user start docker.service') !== false && strpos($down['next_step'], 'support') !== false, 'PMSS down advice: ' . $down['next_step']);
        foreach ($cases as $c) {
            $l = DockerCli::ladder($c[0] + $base);
            check(!preg_match('/systemctl --user (stop|restart)|\bpkill\b|\bkill\b/', $l['next_step']), "{$l['state']}: PMSS advice must never stop, restart or kill the daemon: {$l['next_step']}");
        }
    },
    'containers cli: own daemon processes come from proc_root, only this uid, with the watchdog\'s own match counted apart' => function () {
        $fx = containersFixture();
        try {
            $mk = function ($pid, array $argv) use ($fx) {
                mkdir($fx['dir'] . "/proc/$pid");
                file_put_contents($fx['dir'] . "/proc/$pid/cmdline", implode("\0", $argv) . "\0");
            };
            $mk(10, ['/usr/bin/dockerd', '--rootless']);
            $mk(11, ['rootlesskit', '--state-dir=/run/user/1000/dockerd-rootless', 'dockerd']);
            $mk(12, ['containerd', '--config', 'x']);
            $mk(13, ['/bin/bash', '/usr/bin/dockerd-rootless.sh']);
            $mk(14, ['/usr/bin/php', 'something-else']);
            $n = containersWithCli($fx, function ($d) {
                return $d->ownDaemonProcs();
            });
            check($n === ['dockerd' => 1, 'all' => 3, 'watchdog_alive' => 3], 'counts: ' . json_encode($n));
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            $none = containersWithCli($fx, function ($d) {
                return $d->ownDaemonProcs();
            }, ['uid' => $uid + 1]);
            check($none === ['dockerd' => 0, 'all' => 0, 'watchdog_alive' => 0], 'another uid\'s processes must not count: ' . json_encode($none));
        } finally {
            containersDone($fx);
        }
    },
    'containers cli: observe reads the storage driver, the CLI context and the socket without the daemon' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['home'] . '/.config/docker', 0700, true);
            file_put_contents($fx['home'] . '/.config/docker/daemon.json', '{"storage-driver":"fuse-overlayfs"}');
            mkdir($fx['home'] . '/.docker', 0700, true);
            file_put_contents($fx['home'] . '/.docker/config.json', '{"currentContext":"default","auths":{"x":{"auth":"c2VjcmV0"}}}');
            $o = containersWithCli($fx, function ($d) {
                return $d->observe(['ok' => true, 'timed_out' => false]);
            }, [], ['PATH' => $fx['dir'] . '/bin']);
            check($o['storage_driver'] === 'fuse-overlayfs' && $o['storage_driver_missing'] === true, 'fuse-overlayfs configured and not on PATH: ' . json_encode($o));
            check($o['cli_context'] === 'default' && $o['context_mismatch'] === true, 'DOCKER_HOST unset with a non-default socket: the user\'s own docker talks elsewhere');
            check($o['socket_file'] === true && $o['pmss'] === false && $o['linger'] !== null, 'socket and host: ' . json_encode($o));
            check(strpos(json_encode($o), 'c2VjcmV0') === false, 'observe must read currentContext only, never auths');
            $ok = containersWithCli($fx, function ($d) {
                return $d->observe(['ok' => true]);
            }, [], ['DOCKER_HOST' => 'unix://' . $fx['sock']]);
            check($ok['context_mismatch'] === false, 'DOCKER_HOST naming the own socket is no mismatch');
        } finally {
            containersDone($fx);
        }
    },
    'containers fake docker: containers mode answers json lines for ps, inspect, images and run, and refuses removing a running container' => function () {
        $id = containersId('fake');
        $fx = containersFixture(['containers' => [['id' => $id, 'name' => 'web-1', 'image' => 'nginx:alpine', 'state' => 'running', 'exit_code' => 0,
            'ports' => [['ip' => '127.0.0.1', 'host' => 38001, 'container' => 80, 'proto' => 'tcp']], 'labels' => ['pikkumies.group' => 'web'], 'env' => ['PASSWORD=S3CRET']]],
            'images' => [['id' => 'sha256:' . str_repeat('c', 64), 'repository' => 'nginx', 'tag' => 'alpine', 'size' => 5000000, 'created' => '2026-10-01']]]);
        try {
            containersWithCli($fx, function ($d) use ($id) {
                $ps = DockerCli::jsonLines($d->run(['ps', '-a', '--format', '{{json .}}'])['out']);
                check(count($ps) === 1 && $ps[0]['ID'] === substr($id, 0, 12) && $ps[0]['Ports'] === '127.0.0.1:38001->80/tcp' && $ps[0]['Labels'] === 'pikkumies.group=web', 'ps: ' . json_encode($ps));
                $in = DockerCli::jsonLines($d->run(['inspect', '--format', '{{json .}}', $id])['out']);
                check($in[0]['Name'] === '/web-1' && $in[0]['NetworkSettings']['Ports']['80/tcp'][0]['HostPort'] === '38001', 'inspect: ' . json_encode($in));
                check(DockerCli::jsonLines($d->run(['images', '--format', '{{json .}}'])['out'])[0]['Repository'] === 'nginx', 'images');
                $rm = $d->run(['rm', $id]);
                check($rm['code'] === 1 && strpos($rm['err'], 'container is running') !== false, 'rm of a running container must fail without -f');
                $run = $d->run(['run', '-d', '--name=web-2', '--publish', '127.0.0.1:38002:80', 'nginx:alpine']);
                check($run['code'] === 0 && preg_match('/^[0-9a-f]{64}$/', trim($run['out'])), 'run: ' . json_encode($run));
                $dup = $d->run(['run', '-d', '--name', 'web-2', 'nginx:alpine']);
                check($dup['code'] === 125 && strpos($dup['err'], 'already in use') !== false, 'a taken name must conflict');
            });
            check(count(containersState($fx)['containers']) === 2, 'run must add one container');
        } finally {
            containersDone($fx);
        }
    },
];
