<?php
/**
 * docker diagnose (ADR-0034, DockerDiagnose.php): the overview's severity order, one container's facts, the compose
 * check that never prints env values, and the scheduled form's state file and notice. Fake docker on PATH, fixture
 * homes only (tests/fixtures/containers/harness.php); nothing reads the real pikkumies/local/, /proc or /etc/subuid.
 */
require_once __DIR__ . '/fixtures/containers/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerCli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerRefs.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerExposure.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerDiagnose.php';

/** A fake-docker container record. */
function diagC($seed, $name, array $extra = [])
{
    return $extra + ['id' => hash('sha256', 'diag-' . $seed), 'name' => $name, 'image' => 'nginx:alpine', 'state' => 'running', 'exit_code' => 0, 'ports' => [], 'labels' => [], 'mounts' => []];
}

/** Replace the fake's containers/images (the next docker call sees them). */
function diagSetFake(array $fx, array $changes)
{
    $s = containersState($fx);
    file_put_contents($fx['dir'] . '/run/docker.json', json_encode(array_merge($s, $changes)));
}

/** A small inspect object for the pure findings tests. */
function diagInspect($name, array $state = [], array $more = [])
{
    return $more + ['Id' => hash('sha256', $name), 'Name' => '/' . $name, 'RestartCount' => 0,
        'State' => $state + ['Status' => 'running', 'Running' => true, 'ExitCode' => 0, 'Error' => '', 'OOMKilled' => false],
        'HostConfig' => [], 'Mounts' => [], 'NetworkSettings' => ['Ports' => []]];
}

function diagCtx($home = '/home/u', $shared = true, $enforced = false)
{
    return ['facts' => DockerExposure::facts($shared, Host::TABLE['pmss']['docker']), 'home' => $home, 'limits_enforced' => $enforced, 'pmss_ports' => Host::TABLE['pmss']['docker']['ports']];
}

function diagKinds(array $recs)
{
    return array_map(function ($r) {
        return $r['kind'];
    }, $recs);
}

function diagStatePath(array $fx)
{
    return $fx['dir'] . '/local/containers/state.json';
}

function diagNoticePath(array $fx)
{
    return $fx['dir'] . '/local/notices/docker.txt';
}

const DIAG_NOTICE_RE = '/^Docker check( since \d\d-\d\d \d\d:\d\d UTC)?: (all containers and images are gone|\d+ stopped( \(\d+ with an error exit\))?|\d+ restarting)(, (\d+ stopped( \(\d+ with an error exit\))?|\d+ restarting))*; run docker diagnose$/';

return [
    'diagnose: exit meanings; 137 and 143 are signal stops, never crashes' => function () {
        check(strpos(DockerDiagnose::exitMeaning(137, 'exited'), 'stopped by a signal') === 0 && strpos(DockerDiagnose::exitMeaning(143, 'exited'), 'not a crash') !== false, '137/143');
        check(DockerDiagnose::exitMeaning(0, 'exited') === 'finished normally' && DockerDiagnose::exitMeaning(0, 'running') === null, '0 / running');
        check(strpos(DockerDiagnose::exitMeaning(1, 'exited'), 'error (code 1)') !== false && DockerDiagnose::exitMeaning(139, 'exited') === 'stopped by signal 11', '1 / 139');
        check(strpos(DockerDiagnose::exitMeaning(127, 'exited'), 'not found') !== false, '127');
        $f = DockerDiagnose::containerFindings(diagInspect('a', ['Status' => 'exited', 'Running' => false, 'ExitCode' => 143]), diagCtx());
        check(diagKinds($f) === ['signal-stop'], '143 must be a signal stop only, got ' . json_encode(diagKinds($f)));
        $f = DockerDiagnose::containerFindings(diagInspect('a', ['Status' => 'exited', 'Running' => false, 'ExitCode' => 2]), diagCtx());
        check(diagKinds($f) === ['crash'], 'exit 2 is a crash, got ' . json_encode(diagKinds($f)));
    },
    'diagnose: limits are enforced only rootful, or rootless on cgroup v2 with systemd, never with limit warnings; unknown when fields are missing' => function () {
        $rl = ['SecurityOptions' => ['name=seccomp,profile=builtin', 'name=rootless']];
        $rows = [
            [$rl + ['CgroupVersion' => '2', 'CgroupDriver' => 'cgroupfs'], false], [$rl + ['CgroupVersion' => '2', 'CgroupDriver' => 'systemd'], true],
            [$rl + ['CgroupVersion' => '1', 'CgroupDriver' => 'none'], false], [['CgroupVersion' => '1', 'CgroupDriver' => 'cgroupfs'], true],
            [['CgroupVersion' => '2', 'CgroupDriver' => 'systemd', 'Warnings' => ['WARNING: No memory limit support']], false], [['ServerVersion' => '26'], null], [null, null],
        ];
        foreach ($rows as $r) {
            check(DockerDiagnose::limitsEnforced($r[0]) === $r[1], json_encode($r[0]) . ' -> expected ' . json_encode($r[1]));
        }
        $oom = diagInspect('m', ['Status' => 'exited', 'Running' => false, 'ExitCode' => 137, 'OOMKilled' => true]);
        check(!in_array('oom', diagKinds(DockerDiagnose::containerFindings($oom, diagCtx())), true), 'OOMKilled is not evidence when limits are not enforced');
        check(in_array('oom', diagKinds(DockerDiagnose::containerFindings($oom, diagCtx('/home/u', true, true))), true), 'with limits enforced OOMKilled is reported');
    },
    'diagnose: findings for socket mounts, public and shared ports, writable binds outside the home, privileged, host network, restarts and "address already in use"' => function () {
        $i = diagInspect('x', [], ['Mounts' => [['Type' => 'bind', 'Source' => '/run/user/1000/docker.sock', 'Destination' => '/var/run/docker.sock', 'RW' => true],
            ['Type' => 'bind', 'Source' => '/srv/data', 'Destination' => '/d', 'RW' => true], ['Type' => 'bind', 'Source' => '/etc/localtime', 'Destination' => '/etc/localtime', 'RW' => false],
            ['Type' => 'bind', 'Source' => '/home/u/app', 'Destination' => '/app', 'RW' => true]],
            'HostConfig' => ['Privileged' => true, 'NetworkMode' => 'host'],
            'NetworkSettings' => ['Ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8096']], '81/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '38001']]]]]);
        $f = DockerDiagnose::containerFindings($i, diagCtx());
        check(diagKinds($f) === ['socket-mount', 'public-port', 'shared-port', 'bind-outside-home', 'privileged', 'host-network'], 'kinds: ' . json_encode(diagKinds($f)));
        check(count($f[3]['mounts']) === 1 && $f[3]['mounts'][0]['source'] === '/srv/data', 'only the writable outside bind counts (ro /etc/localtime and the home folder do not): ' . json_encode($f[3]));
        $solo = DockerDiagnose::containerFindings($i, diagCtx('/home/u', false));
        check(!in_array('shared-port', diagKinds($solo), true), 'loopback on a server of one account is not shared');
        $loop = diagInspect('r', ['Status' => 'restarting', 'Running' => true], ['RestartCount' => 7]);
        check(diagKinds(DockerDiagnose::containerFindings($loop, diagCtx())) === ['restart-loop'], 'restarting');
        $busy = diagInspect('p', ['Status' => 'exited', 'Running' => false, 'ExitCode' => 128,
            'Error' => 'driver failed programming external connectivity: listen tcp4 127.0.0.1:38005: bind: address already in use']);
        $f = DockerDiagnose::containerFindings($busy, diagCtx());
        check($f[0]['kind'] === 'port-in-use' && $f[0]['host_port'] === 38005 && strpos($f[0]['port_cause'], 'outgoing connection') !== false, 'PMSS window port: ' . json_encode($f[0]));
        $other = DockerDiagnose::portCause('listen tcp 0.0.0.0:8096: bind: address already in use', $busy, ['pmss_ports' => null]);
        check($other['host_port'] === 8096 && strpos($other['port_cause'], 'another program') !== false, 'elsewhere: ' . json_encode($other));
        check(diagKinds(DockerDiagnose::containerFindings(diagInspect('n', ['Status' => 'created', 'Running' => false]), diagCtx())) === ['created'], 'created');
    },
    'diagnose: architecture names are mapped before comparing (host x86_64 = image amd64)' => function () {
        check(DockerDiagnose::archCompare('amd64', 'x86_64') === ['image' => 'amd64', 'host' => 'amd64', 'match' => true], 'x86_64/amd64');
        check(DockerDiagnose::archCompare('arm64', 'x86_64')['match'] === false && DockerDiagnose::archCompare('arm64', 'aarch64')['match'] === true, 'arm64');
        check(DockerDiagnose::archCompare(null, 'x86_64')['match'] === 'unknown', 'a missing side is unknown');
    },
    'diagnose: findings sort most severe first and keep their found order within a rank' => function () {
        $mk = function ($kind, $n) {
            $r = ['kind' => $kind, 'n' => $n];
            $r['_rank'] = DockerDiagnose::KINDS[$kind][0];
            return $r;
        };
        $out = DockerDiagnose::sortFindings([$mk('crash', 1), $mk('signal-stop', 2), $mk('socket-mount', 3), $mk('crash', 4), $mk('public-port', 5), $mk('inventory-gone', 6)]);
        check(array_map(function ($r) {
            return $r['n'];
        }, $out) === [6, 3, 5, 1, 4, 2], 'order: ' . json_encode($out));
        check(!array_key_exists('_rank', $out[0]), 'the sort key is not printed');
    },
    'diagnose overview: 30 problems come back most severe first, so the 20-record page holds every socket mount and public port' => function () {
        $cs = [];
        for ($n = 0; $n < 10; $n++) {
            $cs[] = diagC("crash$n", "crash-$n", ['state' => 'exited', 'exit_code' => 1]);
        }
        for ($n = 0; $n < 5; $n++) {
            $cs[] = diagC("sig$n", "sig-$n", ['state' => 'exited', 'exit_code' => 143]);
        }
        for ($n = 0; $n < 5; $n++) {
            $cs[] = diagC("loop$n", "loop-$n", ['ports' => [['ip' => '127.0.0.1', 'host' => 38100 + $n, 'container' => 80, 'proto' => 'tcp']]]);
        }
        for ($n = 0; $n < 8; $n++) {
            $cs[] = diagC("pub$n", "pub-$n", ['ports' => [['ip' => '0.0.0.0', 'host' => 39000 + $n, 'container' => 80, 'proto' => 'tcp']]]);
        }
        for ($n = 0; $n < 2; $n++) {
            $cs[] = diagC("sock$n", "sock-$n", ['mounts' => [['Type' => 'bind', 'Source' => '/run/user/1000/docker.sock', 'Destination' => '/var/run/docker.sock', 'RW' => true]]]);
        }
        $fx = containersFixture(['containers' => $cs]);
        try {
            mkdir($fx['dir'] . '/pmss');
            list($code, $recs) = containersRun($fx, ['diagnose'], ['pmss_dir' => $fx['dir'] . '/pmss'], ['DOCKER_HOST' => 'unix://' . $fx['sock']]);
            $status = array_pop($recs);
            check($code === 0 && $status['ok'] === true && $status['daemon'] === 'healthy' && $status['containers'] === 30, 'status: ' . json_encode($status));
            check(count($recs) === 20 && $status['truncated'] === true && $status['total'] === 31, 'one page of 20 out of 31 records: ' . json_encode($status));
            $ranks = array_map(function ($r) {
                return DockerDiagnose::KINDS[$r['kind']][0];
            }, $recs);
            $sorted = $ranks;
            sort($sorted);
            check($ranks === $sorted, 'records must be most severe first: ' . json_encode(diagKinds($recs)));
            $kinds = array_count_values(diagKinds($recs));
            check($kinds['socket-mount'] === 2 && $kinds['public-port'] === 8 && $kinds['shared-port'] === 5 && $kinds['crash'] === 5, 'page 1: ' . json_encode($kinds));
            check($recs[0]['severity'] === 'critical' && $recs[0]['name'] === 'sock-0', 'first: ' . json_encode($recs[0]));
        } finally {
            containersDone($fx);
        }
    },
    'diagnose overview: a down PMSS daemon gets the platform advice, a leftover the watchdog counts is named, and no kill command is printed' => function () {
        $fx = containersFixture(['fail' => ['version' => ['code' => 1, 'err' => 'Cannot connect to the Docker daemon']]]);
        try {
            mkdir($fx['dir'] . '/pmss');
            mkdir($fx['dir'] . '/proc/77');
            file_put_contents($fx['dir'] . '/proc/77/cmdline', "/bin/bash\0/usr/bin/dockerd-rootless.sh\0");
            list($code, $recs, $out) = containersRun($fx, ['diagnose'], ['pmss_dir' => $fx['dir'] . '/pmss']);
            array_pop($recs);
            check($code === 0 && $recs[0]['kind'] === 'daemon' && $recs[0]['state'] === 'down' && $recs[0]['watchdog_processes'] === 1, 'daemon: ' . json_encode($recs));
            check($recs[1]['kind'] === 'leftover-process' && !array_key_exists('needs_user_ok', $recs[1]) && strpos($recs[1]['next_step'], 'asks the user') === false && strpos($recs[1]['next_step'], Host::TABLE['pmss']['docker']['recovery']) !== false, 'leftover: ' . json_encode($recs[1]));
            check(!preg_match('/\bpkill\b|\bkillall\b|\bkill -|systemctl --user (stop|restart)/', $out), 'diagnose printed a kill/stop command: ' . $out);
            check(count(containersState($fx)['calls']) === 1, 'no call after the failed probe: ' . json_encode(containersState($fx)['calls']));
        } finally {
            containersDone($fx);
        }
    },
    'diagnose overview: a wedged daemon is named within a real `timeout 1`, in under 5 s, with the recovery link and the user\'s yes' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['dir'] . '/pmss');
            mkdir($fx['dir'] . '/proc/88');
            file_put_contents($fx['dir'] . '/proc/88/cmdline', "/usr/bin/dockerd\0--rootless\0");
            $t = microtime(true);
            // A 2 s budget gives the probe `timeout 1` (whole seconds, never past the budget); the fake sleeps 6 s.
            list($code, $recs, $out) = containersRun($fx, ['diagnose'], ['pmss_dir' => $fx['dir'] . '/pmss', 'budget_seconds' => 2], ['FAKE_SLEEP' => '6']);
            $took = microtime(true) - $t;
            check($took >= 0.9 && $took < 5, 'the probe must run and be cut at 1 s; took ' . round($took, 2) . ' s');
            check($code === 0 && $recs[0]['state'] === 'wedged' && !array_key_exists('needs_user_ok', $recs[0]) && strpos($recs[0]['next_step'], 'asks the user') === false && strpos($recs[0]['next_step'], Host::TABLE['pmss']['docker']['recovery']) !== false, 'wedged: ' . $out);
            check(!preg_match('/\bpkill\b|\bkillall\b/', $out), 'no kill command');
        } finally {
            containersDone($fx);
        }
    },
    'diagnose <c>: exit meaning, oom unknown, architecture mismatch, sub-uid owned bind files, and the last log lines' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $fx = containersFixture();
        try {
            mkdir($fx['home'] . '/data');
            touch($fx['home'] . '/data/a');
            touch($fx['home'] . '/data/b');
            file_put_contents($fx['dir'] . '/subuid', "someoneelse:100000:65536\n$uid:$uid:1\n");
            $img = 'sha256:' . str_repeat('ab', 32); // the fake's inspect names this image for every container
            diagSetFake($fx, ['containers' => [diagC('one', 'app', ['state' => 'exited', 'exit_code' => 137, 'oom' => true, 'restart_count' => 2,
                'mounts' => [['Type' => 'bind', 'Source' => $fx['home'] . '/data', 'Destination' => '/data', 'RW' => true]],
                'logs' => [['stream' => 'stdout', 'text' => 'starting'], ['stream' => 'stderr', 'text' => 'killed']]]), diagC('two', 'other')],
                'images' => [['id' => $img, 'repository' => 'app', 'tag' => 'arm', 'size' => 1000, 'created' => '2026-10-01', 'arch' => 'arm64']]]);
            list($code, $recs, $out) = containersRun($fx, ['diagnose', 'app'], ['subuid_file' => $fx['dir'] . '/subuid']);
            check($code === 0, 'exit: ' . $out);
            array_pop($recs);
            $c = $recs[0];
            check($c['kind'] === 'container' && $c['name'] === 'app' && $c['exit_code'] === 137 && strpos($c['exit_meaning'], 'signal') !== false && $c['restart_count'] === 2, 'summary: ' . json_encode($c));
            check($c['oom'] === 'unknown', 'oom must stay unknown on a rootless cgroup v1 daemon even when docker says OOMKilled');
            check($c['arch'] === ['image' => 'arm64', 'host' => 'amd64', 'match' => false], 'arch: ' . json_encode($c['arch']));
            check(strpos($c['log_tail_text'], 'starting') !== false && strpos($c['log_tail_text'], 'killed') !== false, 'log tail: ' . json_encode($c['log_tail_text']));
            $kinds = diagKinds($recs);
            check(in_array('arch-mismatch', $kinds, true) && in_array('chown-lockout', $kinds, true) && in_array('signal-stop', $kinds, true), 'findings: ' . json_encode($kinds));
            foreach ($recs as $r) {
                if ($r['kind'] === 'chown-lockout') {
                    check($r['subuid_owned'] === 3 && $r['destination'] === '/data', 'lockout: ' . json_encode($r));
                }
            }
            $calls = containersState($fx)['calls'];
            foreach ($calls as $call) {
                check(!in_array('app', $call, true), 'the user\'s text went to docker; only resolved IDs may: ' . json_encode($call));
            }
            list($code, $recs) = containersRun($fx, ['diagnose', 'nope']);
            check($code === 1 && strpos(end($recs)['error'], 'not found') === 0, 'unknown container: ' . json_encode($recs));
            list($code) = containersRun($fx, ['diagnose', 'app', '--changes']);
            check($code === 1, 'two forms at once must refuse');
        } finally {
            containersDone($fx);
        }
    },
    'diagnose --compose: the projection keeps names, ports, mounts and refusals, and never an env value (S3CRET oracle)' => function () {
        $home = testTempDir('pk-diag-compose');
        try {
            mkdir("$home/.ssh");
            mkdir("$home/app");
            $config = ['name' => 'p', 'services' => [
                'web' => ['image' => 'reg/app:S3CRET', 'environment' => ['DB_PASSWORD' => 'S3CRET', 'MODE' => 'x'], 'command' => ['--token', 'S3CRET'],
                    'labels' => ['k' => 'S3CRET'], 'ports' => [['target' => 80, 'published' => '8096', 'protocol' => 'tcp'], ['target' => 81, 'protocol' => 'tcp'],
                    ['target' => 82, 'published' => '38002', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']],
                    'volumes' => [['type' => 'bind', 'source' => "$home/.ssh", 'target' => '/k'], ['type' => 'bind', 'source' => '/run/user/1/docker.sock', 'target' => '/var/run/docker.sock'],
                        ['type' => 'bind', 'source' => "$home/app", 'target' => '/app'], ['type' => 'bind', 'source' => '/srv/x', 'target' => '/x'], ['type' => 'volume', 'source' => 'db', 'target' => '/db']]],
                'db' => ['image' => 'mariadb:11', 'environment' => ['MARIADB_ROOT_PASSWORD=S3CRET'], 'privileged' => true, 'network_mode' => 'host'],
            ]];
            list($recs, $more) = DockerDiagnose::composeProjection($config, ['facts' => DockerExposure::facts(true, Host::TABLE['pmss']['docker']), 'home' => realpath($home),
                'docker_root' => '', 'local_images' => ['mariadb:11'], 'uid' => 0, 'pikkumies' => Config::root()]);
            $all = json_encode([$recs, $more]);
            check(strpos($all, 'S3CRET') === false, 'an env value leaked: ' . $all);
            check($recs[0]['env_names'] === ['DB_PASSWORD', 'MODE'] && $recs[1]['env_names'] === ['MARIADB_ROOT_PASSWORD'], 'env names: ' . json_encode([$recs[0]['env_names'], $recs[1]['env_names']]));
            check(!array_key_exists('command', $recs[0]) && !array_key_exists('labels', $recs[0]), 'command and labels are never printed');
            check($recs[0]['ports'][0]['exposure'] === 'public' && $recs[0]['ports'][1]['host_port'] === 'random' && $recs[0]['ports'][1]['exposure'] === 'public'
                && $recs[0]['ports'][2]['exposure'] === 'local-shared', 'ports: ' . json_encode($recs[0]['ports']));
            $kinds = diagKinds($more);
            foreach (['bind-denied', 'socket-mount', 'bind-outside-home', 'public-port', 'shared-port', 'image-not-local', 'privileged', 'host-network'] as $k) {
                check(in_array($k, $kinds, true), "missing finding $k: " . json_encode($kinds));
            }
            check(count(array_filter($more, function ($r) {
                return $r['kind'] === 'image-not-local';
            })) === 1, 'mariadb:11 is local; only web\'s image is not');
            file_put_contents("$home/.env", "# c\nexport TOKEN=\"S3CRET\"\r\nPORT=80\n");
            check(DockerDiagnose::envFileValues("$home/.env") === ['S3CRET'], '.env values: ' . json_encode(DockerDiagnose::envFileValues("$home/.env")));
            check(DockerDiagnose::imageRef('nginx') === 'nginx:latest' && DockerDiagnose::imageRef('localhost:5000/app') === 'localhost:5000/app:latest'
                && DockerDiagnose::imageRef('lscr.io/linuxserver/jellyfin:10') === 'lscr.io/linuxserver/jellyfin:10', 'image refs');
        } finally {
            testRmTree($home);
        }
    },
    'diagnose --compose end to end: the file path is the only thing passed, values from .env and the config never reach stdout' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['home'] . '/app');
            file_put_contents($fx['home'] . '/app/compose.yaml', "services: {}\n");
            file_put_contents($fx['home'] . '/app/.env', "API_KEY=S3CRET-from-env\n");
            file_put_contents($fx['dir'] . '/compose.json', json_encode(['services' => ['web' => ['image' => 'app:S3CRET-from-env',
                'environment' => ['K' => 'envvalue-xyz'], 'ports' => [['target' => 80, 'published' => '38001', 'host_ip' => '127.0.0.1']]]]]));
            // The shared fake has no `compose config`: this shim answers it and hands every other call to the fake.
            file_put_contents($fx['dir'] . '/bin/docker', '#!' . PHP_BINARY . "\n<?php\n" . '$a = array_slice($argv, 1);' . "\n"
                . 'if (isset($a[0]) && $a[0] === "compose" && in_array("config", $a, true)) {' . "\n"
                . '    $f = dirname(preg_replace("#^unix://#", "", getenv("DOCKER_HOST"))) . "/docker.json"; $s = json_decode(file_get_contents($f), true);' . "\n"
                . '    $s["calls"][] = $a; file_put_contents($f, json_encode($s)); echo file_get_contents(' . var_export($fx['dir'] . '/compose.json', true) . '); exit(0);' . "\n"
                . "}\nrequire " . var_export(__DIR__ . '/fixtures/media/docker.php', true) . ";\n");
            list($code, $recs, $out, $err) = containersRun($fx, ['diagnose', '--compose', $fx['home'] . '/app/compose.yaml']);
            check($code === 0 && $recs[0]['kind'] === 'compose-service' && $recs[0]['env_names'] === ['K'], 'out: ' . $out . $err);
            foreach (['S3CRET', 'envvalue-xyz'] as $v) {
                check(strpos($out, $v) === false && strpos($err, $v) === false, "$v reached the output: " . $out);
            }
            $calls = containersState($fx)['calls'];
            check(strpos(json_encode($calls), 'S3CRET') === false, 'a value reached docker\'s argv');
            $compose = array_values(array_filter($calls, function ($c) {
                return $c[0] === 'compose';
            }));
            check($compose === [['compose', '-f', realpath($fx['home'] . '/app/compose.yaml'), 'config', '--format', 'json']], 'compose argv: ' . json_encode($compose));
        } finally {
            containersDone($fx);
        }
    },
    'diagnose --changes: without a socket of its own it exits 0, prints nothing and calls nothing' => function () {
        $fx = containersFixture([], false);
        try {
            list($code, , $out) = containersRun($fx, ['diagnose', '--changes']);
            check($code === 0 && $out === '', "expected silent exit 0, got $code '$out'");
            check(containersState($fx)['calls'] === [] && !file_exists(diagStatePath($fx)), 'nothing may be called or written');
        } finally {
            containersDone($fx);
        }
    },
    'diagnose --changes: a tick without a healthy answer writes nothing, and the budget is 30 s' => function () {
        $fx = containersFixture(['containers' => [diagC('a', 'a')], 'images' => [['id' => 'sha256:' . str_repeat('1', 64), 'repository' => 'x', 'tag' => '1', 'size' => 1, 'created' => '']]]);
        try {
            list($code, , $out) = containersRun($fx, ['diagnose', '--changes']);
            check($code === 0 && $out === '' && is_file(diagStatePath($fx)), 'first healthy tick: silent, baseline written');
            $before = file_get_contents(diagStatePath($fx));
            check((fileperms(diagStatePath($fx)) & 0777) === 0600, 'state file mode 0600');
            diagSetFake($fx, ['containers' => [], 'images' => [], 'fail' => ['ps' => ['code' => 1, 'err' => 'Error response from daemon: timeout']]]);
            list($code, , $out) = containersRun($fx, ['diagnose', '--changes']);
            check($code === 0 && $out === '', 'unknown tick: silent');
            check(file_get_contents(diagStatePath($fx)) === $before && !file_exists(diagNoticePath($fx)), 'an unknown tick moved the baseline or left a notice');
            diagSetFake($fx, ['fail' => []]);
            $t = microtime(true);
            list($code, , $out) = containersRun($fx, ['diagnose', '--changes'], ['budget_seconds' => 2], ['FAKE_SLEEP' => '1.5']);
            check($code === 0 && $out === '' && microtime(true) - $t < 4.5, 'a spent budget stops the tick: ' . round(microtime(true) - $t, 2) . ' s');
            check(file_get_contents(diagStatePath($fx)) === $before, 'a tick cut by the budget wrote state');
            check(DockerDiagnose::budget(new DockerCli(containersSeams($fx)), true) === 30.0 && DockerDiagnose::budget(new DockerCli(containersSeams($fx)), false) === 150.0, 'budgets 30 / 150');
        } finally {
            containersDone($fx);
        }
    },
    'diagnose --changes: the inventory event needs containers AND images to drop from some to none between two healthy ticks' => function () {
        $img = ['id' => 'sha256:' . str_repeat('2', 64), 'repository' => 'x', 'tag' => '1', 'size' => 1, 'created' => ''];
        foreach ([[[], [], true], [[], [$img], false]] as $case) {
            list($cs, $imgs, $want) = $case;
            $fx = containersFixture(['containers' => [diagC('a', 'a'), diagC('b', 'b', ['state' => 'exited'])], 'images' => [$img]]);
            try {
                containersRun($fx, ['diagnose', '--changes']);
                diagSetFake($fx, ['containers' => $cs, 'images' => $imgs]);
                list($code, , $out) = containersRun($fx, ['diagnose', '--changes']);
                $st = json_decode(file_get_contents(diagStatePath($fx)), true);
                $gone = array_values(array_filter($st['events'], function ($e) {
                    return $e['kind'] === 'inventory-gone';
                }));
                check((bool) $gone === $want, json_encode(['images_left' => count($imgs), 'events' => $st['events']]));
                if ($want) {
                    check($gone[0]['containers_before'] === 2 && $gone[0]['images_before'] === 1, 'counts: ' . json_encode($gone));
                    check(preg_match(DIAG_NOTICE_RE, trim($out)) === 1 && trim(file_get_contents(diagNoticePath($fx))) === trim($out), "printed line and notice: '$out'");
                }
            } finally {
                containersDone($fx);
            }
        }
        $fx = containersFixture(['containers' => [], 'images' => []]);
        try {
            containersRun($fx, ['diagnose', '--changes']);
            containersRun($fx, ['diagnose', '--changes']);
            check(json_decode(file_get_contents(diagStatePath($fx)), true)['events'] === [], 'from none to none is no event');
        } finally {
            containersDone($fx);
        }
    },
    'diagnose --changes: stopped containers become a notice of counts and fixed words only; a manual diagnose marks it seen without moving the baseline' => function () {
        $fx = containersFixture(['containers' => [diagC('a', 'zq-name-alpha'), diagC('b', 'zq-name-beta'), diagC('c', 'zq-name-gamma')],
            'images' => [['id' => 'sha256:' . str_repeat('3', 64), 'repository' => 'x', 'tag' => '1', 'size' => 1, 'created' => '']]]);
        try {
            containersRun($fx, ['diagnose', '--changes']);
            $s = containersState($fx);
            $s['containers'][0] = array_merge($s['containers'][0], ['state' => 'exited', 'exit_code' => 1]);
            $s['containers'][1] = array_merge($s['containers'][1], ['state' => 'exited', 'exit_code' => 143]);
            diagSetFake($fx, ['containers' => $s['containers']]);
            list($code, , $out) = containersRun($fx, ['diagnose', '--changes']);
            $notice = trim((string) @file_get_contents(diagNoticePath($fx)));
            check($code === 0 && preg_match(DIAG_NOTICE_RE, $notice) === 1 && strpos($notice, '2 stopped (1 with an error exit)') !== false, "notice: '$notice'");
            check(trim($out) === $notice, 'the tick prints the notice line once');
            $state = file_get_contents(diagStatePath($fx));
            foreach (['zq-name', substr(hash('sha256', 'diag-a'), 0, 12), 'nginx'] as $leak) {
                check(strpos($notice, $leak) === false, "the notice carries container text: $leak");
            }
            check(strpos($state, 'zq-name') === false, 'the state file keeps no container names');
            $sanitized = HermesConfig::notices($fx['dir'] . '/local/notices');
            check($sanitized === [['from' => 'docker', 'text' => $notice]] && strlen($notice) <= HermesConfig::NOTICE_BYTES && DockerDiagnose::NOTICE_BYTES === HermesConfig::NOTICE_BYTES, 'SOUL.md shows it unchanged: ' . json_encode($sanitized));
            list($code, , $out) = containersRun($fx, ['diagnose', '--changes']);
            check($out === '' && trim(file_get_contents(diagNoticePath($fx))) === $notice, 'no new event: silent, notice kept until seen');
            $baseline = json_decode(file_get_contents(diagStatePath($fx)), true);
            list($code, $recs) = containersRun($fx, ['diagnose']);
            $ev = array_values(array_filter($recs, function ($r) {
                return isset($r['kind']) && $r['kind'] === 'stopped';
            }));
            check($code === 0 && $ev && $ev[0]['new'] === true && $ev[0]['count'] === 2, 'manual diagnose shows the stored event: ' . json_encode($recs));
            check(!file_exists(diagNoticePath($fx)), 'a manual diagnose removes the notice');
            $after = json_decode(file_get_contents(diagStatePath($fx)), true);
            check($after['events'][0]['viewed_at'] !== null && array_diff_key($after, ['events' => 1]) === array_diff_key($baseline, ['events' => 1]), 'only viewed_at may change: ' . json_encode($after));
            unset($after['events'][0]['viewed_at'], $baseline['events'][0]['viewed_at']);
            check($after === $baseline, 'a manual run changed more than viewed_at');
            list(, $recs) = containersRun($fx, ['diagnose']);
            $ev = array_values(array_filter($recs, function ($r) {
                return isset($r['kind']) && $r['kind'] === 'stopped';
            }));
            check($ev && $ev[0]['new'] === false && $ev[0]['severity'] === 'info', 'a seen event is shown as seen');
        } finally {
            containersDone($fx);
        }
    },
    'diagnose: rebaseline after the account\'s own deletes means the next tick raises no inventory event' => function () {
        $fx = containersFixture(['containers' => [diagC('a', 'a')], 'images' => [['id' => 'sha256:' . str_repeat('4', 64), 'repository' => 'x', 'tag' => '1', 'size' => 1, 'created' => '']]]);
        try {
            containersRun($fx, ['diagnose', '--changes']);
            check(DockerDiagnose::rebaseline(new DockerCli(containersSeams($fx))) === true, 'rebaseline');
            diagSetFake($fx, ['containers' => [], 'images' => []]);
            list(, , $out) = containersRun($fx, ['diagnose', '--changes']);
            check($out === '' && json_decode(file_get_contents(diagStatePath($fx)), true)['events'] === [], 'own deletes must not raise an event');
        } finally {
            containersDone($fx);
        }
    },
];
