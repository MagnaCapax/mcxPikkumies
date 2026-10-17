<?php
/**
 * docker launch-plan and launch (ADR-0034, DockerLaunch.php): preflight blockers and notes, the concrete launch line,
 * sequential `docker run -d` in attached option forms, read-back, stop at the first failure, the time budget, and
 * the secret rules (env values never in an argv or on stdout). The fake docker runs in containers mode.
 */
require_once __DIR__ . '/fixtures/containers/harness.php';

const LAUNCH_SECRET = 'S3CRET-do-not-print-7f3a';

/**
 * A fixture with local images and a single-account passwd (not shared), plus the seams launch reads. $state is
 * merged into the fake's state; $shared adds a second account with a home (Detect::sharedTenancy true).
 */
function launchFixture(array $state = [], $shared = false)
{
    $images = [
        ['id' => 'sha256:' . str_repeat('1a', 32), 'repository' => 'nginx', 'tag' => 'alpine', 'size' => 50000000, 'created' => '2026-10-01 10:00:00 +0000 UTC'],
        ['id' => 'sha256:' . str_repeat('2b', 32), 'repository' => 'mariadb', 'tag' => '11', 'size' => 400000000, 'created' => '2026-10-01 10:00:00 +0000 UTC'],
        ['id' => 'sha256:' . str_repeat('3c', 32), 'repository' => 'lscr.io/linuxserver/jellyfin', 'tag' => 'latest', 'size' => 700000000, 'created' => '2026-10-01 10:00:00 +0000 UTC'],
        ['id' => 'sha256:' . str_repeat('4d', 32), 'repository' => 'armonly', 'tag' => '1', 'size' => 1000000, 'created' => '2026-10-01 10:00:00 +0000 UTC', 'arch' => 'arm64'],
    ];
    $fx = containersFixture($state + ['images' => $images]);
    mkdir($fx['dir'] . '/other', 0700);
    file_put_contents($fx['dir'] . '/passwd', "root:x:0:0:root:/root:/bin/sh\nme:x:1000:1000::{$fx['home']}:/bin/sh\n"
        . ($shared ? "them:x:1001:1001::{$fx['dir']}/other:/bin/sh\n" : ''));
    return $fx;
}

function launchSeams(array $fx, array $extra = [])
{
    return $extra + ['passwd' => $fx['dir'] . '/passwd', 'cgroup_base' => $fx['dir'] . '/no-cgroup', 'self_cgroup' => $fx['dir'] . '/no-self-cgroup'];
}

/** [code, records, stdout, stderr] of docker.php with the launch seams. */
function launchRun(array $fx, array $args, array $seams = [], array $env = [])
{
    return containersRun($fx, $args, launchSeams($fx, $seams), $env);
}

/** The arguments of the printed launch line (after `php <path>/docker.php`). */
function launchLineArgs($line)
{
    check(is_string($line) && strpos($line, "'") === false, 'launch line missing or quoted: ' . json_encode($line));
    $words = explode(' ', $line);
    check($words[0] === 'php' && substr($words[1], -11) === '/docker.php' && $words[2] === 'launch', 'launch line shape: ' . $line);
    return array_slice($words, 2);
}

/** Every recorded `run` call's argv. */
function launchRunCalls(array $fx)
{
    return array_values(array_filter(containersState($fx)['calls'], function ($c) {
        return isset($c[0]) && $c[0] === 'run';
    }));
}

/** The first record (launch-plan's summary). */
function launchSummary(array $recs)
{
    check($recs && isset($recs[0]['can_run']), 'no plan summary: ' . json_encode($recs));
    return $recs[0];
}

function launchBlocked(array $summary, $needle)
{
    foreach ($summary['blockers'] as $b) {
        if (strpos($b, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** A port on 127.0.0.1 with nothing listening, and the next one free too. */
function launchPortBase()
{
    for ($i = 0; $i < 20; $i++) {
        $p = testFreePort();
        if ($p < 65000) {
            return $p;
        }
    }
    skip('no free port range');
}

return [
    'launch-plan: three nginx containers get concrete names, distinct probed host ports and a launch line; nothing is created' => function () {
        $fx = launchFixture();
        try {
            $base = launchPortBase();
            list($code, $recs, $out) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--count', '3', '--port', '80', '--host-port-base', (string) $base]);
            check($code === 0, "exit $code: $out");
            $s = launchSummary($recs);
            check($s['can_run'] === true && $s['blockers'] === [], 'blocked: ' . json_encode($s['blockers']));
            check(array_column(array_slice($recs, 1, 3), 'name') === ['web-1', 'web-2', 'web-3'], 'names: ' . $out);
            $ports = [];
            foreach (array_slice($recs, 1, 3) as $r) {
                check(count($r['ports']) === 1 && $r['ports'][0]['host_ip'] === '127.0.0.1' && $r['ports'][0]['exposure'] === 'none' && $r['ports'][0]['host_port'] >= $base, 'port: ' . json_encode($r['ports']));
                check($r['labels'] === ['pikkumies.group' => 'web'], 'labels: ' . json_encode($r['labels']));
                $ports[] = $r['ports'][0]['host_port'];
            }
            check(count(array_unique($ports)) === 3, 'host ports must differ: ' . json_encode($ports));
            $args = launchLineArgs($s['launch']);
            check(in_array('--name=web-1,web-2,web-3', $args, true) && in_array('--image=nginx:alpine', $args, true), 'line: ' . $s['launch']);
            check(in_array('--port=' . $ports[0] . ':80,' . $ports[1] . ':80,' . $ports[2] . ':80', $args, true), 'line ports: ' . $s['launch']);
            check(!in_array('--accept-shared-exposure', $args, true), 'a single-account host needs no exposure flag: ' . $s['launch']);
            check(launchRunCalls($fx) === [], 'launch-plan ran a container');
            $cmds = array_map(function ($c) {
                return $c[0] . (isset($c[1]) && $c[0] === 'image' ? ' ' . $c[1] : '');
            }, containersState($fx)['calls']);
            check($cmds === ['version', 'info', 'ps', 'image inspect'], 'plan calls: ' . json_encode($cmds));
        } finally {
            containersDone($fx);
        }
    },
    'launch: the printed line starts each container with docker run -d, valued options attached, labels and log caps, never -f, read back' => function () {
        $fx = launchFixture();
        try {
            $base = launchPortBase();
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--count', '2', '--port', '80', '--host-port-base', (string) $base]);
            list($code, $recs, $out) = launchRun($fx, launchLineArgs(launchSummary($recs)['launch']));
            check($code === 0, "exit $code: $out");
            $status = array_pop($recs);
            check(count($recs) === 2 && $recs[0]['name'] === 'web-1' && $recs[1]['name'] === 'web-2' && $status['started'] === 2 && $status['remaining'] === 0, 'records: ' . $out);
            foreach ($recs as $r) {
                check($r['verified'] === true && $r['state'] === 'running' && $r['running'] === true && preg_match('/^[0-9a-f]{64}$/', $r['id']), 'read back: ' . json_encode($r));
            }
            $runs = launchRunCalls($fx);
            check(count($runs) === 2, 'expected 2 runs, got ' . count($runs));
            $valued = ['--name', '-p', '--publish', '-v', '--volume', '--label', '-l', '--env-file', '-e', '--env', '--restart', '--log-opt', '--mount'];
            foreach ($runs as $argv) {
                check($argv[1] === '-d', 'detached: ' . json_encode($argv));
                foreach ($argv as $a) {
                    check(!in_array($a, $valued, true), "option '$a' given with its value as the next word: " . json_encode($argv));
                }
                check(in_array('--label=pikkumies.group=web', $argv, true) && in_array('--restart=unless-stopped', $argv, true)
                    && in_array('--log-opt=max-size=10m', $argv, true) && in_array('--log-opt=max-file=3', $argv, true), 'defaults: ' . json_encode($argv));
                check(end($argv) === 'nginx:alpine', 'the image comes last: ' . json_encode($argv));
                check(!preg_grep('/^--env=PUID/', $argv), 'PUID is only for LinuxServer.io images: ' . json_encode($argv));
                check((bool) preg_grep('/^--publish=127\.0\.0\.1:\d+:80$/', $argv), 'loopback publish: ' . json_encode($argv));
            }
            foreach (containersState($fx)['calls'] as $c) {
                check(!in_array('-f', $c, true) && !in_array('--force', $c, true), 'a call carried -f/--force: ' . json_encode($c));
            }
            foreach (containersState($fx)['contexts'] as $ctx) {
                check($ctx === null, 'DOCKER_CONTEXT reached docker');
            }
        } finally {
            containersDone($fx);
        }
    },
    'launch-plan: numbering continues after the existing group (two more of web are web-4 and web-5)' => function () {
        $c = function ($n) {
            return ['id' => hash('sha256', $n), 'name' => $n, 'image' => 'nginx:alpine', 'state' => 'running', 'exit_code' => 0, 'labels' => ['pikkumies.group' => 'web']];
        };
        $fx = launchFixture(['containers' => [$c('web-1'), $c('web-2'), $c('web-3')]]);
        try {
            list($code, $recs, $out) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--count', '2']);
            check($code === 0 && launchSummary($recs)['can_run'] === true, $out);
            check(array_column(array_slice($recs, 1), 'name') === ['web-4', 'web-5'], 'names: ' . $out);
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'mariadb:11', '--name', 'db']);
            check($recs[1]['name'] === 'db', 'a fresh single container keeps the plain name: ' . json_encode($recs));
        } finally {
            containersDone($fx);
        }
    },
    'launch: names must follow the grammar; a malformed or taken name refuses before any docker run' => function () {
        $c = ['id' => hash('sha256', 'x'), 'name' => 'web-1', 'image' => 'nginx:alpine', 'state' => 'exited', 'exit_code' => 0];
        $fx = launchFixture(['containers' => [$c]]);
        try {
            list($code, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', '-rf']);
            $s = launchSummary($recs);
            check($code === 0 && $s['can_run'] === false && $s['launch'] === null && launchBlocked($s, 'is not a container name'), 'grammar: ' . json_encode($s));
            foreach (['--name=web-9,bad$name', '--name=web-1', '--name=.hidden'] as $bad) {
                list($code, $recs) = launchRun($fx, ['launch', '--image=nginx:alpine', $bad]);
                $last = $recs[count($recs) - 1];
                check($code === 1 && strpos($last['error'], 'launch refused, nothing was started') === 0, "$bad: " . json_encode($last));
            }
            check(launchRunCalls($fx) === [], 'a refused launch ran docker run');
        } finally {
            containersDone($fx);
        }
    },
    'launch: a host port that cannot be bound refuses; launch probes the plan\'s ports again' => function () {
        $fx = launchFixture();
        $held = null;
        try {
            $held = @stream_socket_server('tcp://127.0.0.1:0');
            if ($held === false) {
                skip('cannot bind a TCP port here');
            }
            $name = (string) stream_socket_get_name($held, false);
            $busy = (int) substr($name, strrpos($name, ':') + 1);
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--port', "$busy:80"]);
            $s = launchSummary($recs);
            check($s['can_run'] === false && launchBlocked($s, "host port $busy cannot be bound"), 'busy port: ' . json_encode($s));
            fclose($held);
            $held = null;
            // Plan on a free port, then something takes it before the launch.
            $free = launchPortBase();
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--port', "$free:80"]);
            $s = launchSummary($recs);
            check($s['can_run'] === true, 'free port blocked: ' . json_encode($s));
            $held = @stream_socket_server("tcp://127.0.0.1:$free");
            if ($held === false) {
                skip("port $free was taken by something else meanwhile");
            }
            list($code, $recs) = launchRun($fx, launchLineArgs($s['launch']));
            $last = $recs[count($recs) - 1];
            check($code === 1 && strpos($last['error'], "host port $free cannot be bound") !== false, 're-probe: ' . json_encode($last));
            check(launchRunCalls($fx) === [], 'docker run despite a taken port');
        } finally {
            if (is_resource($held)) {
                fclose($held);
            }
            containersDone($fx);
        }
    },
    'launch: an env file\'s values never reach an argv or stdout, not even in docker\'s error text; only names are printed' => function () {
        $fx = launchFixture();
        try {
            $env = $fx['home'] . '/db.env';
            file_put_contents($env, "# database\nMARIADB_ROOT_PASSWORD=" . LAUNCH_SECRET . "\r\nMARIADB_DATABASE=app\n\n");
            chmod($env, 0600);
            mkdir($fx['home'] . '/docker/db/data', 0700, true);
            list($code, $recs, $out) = launchRun($fx, ['launch-plan', '--image', 'mariadb:11', '--name', 'db', '--env-file', $env, '--volume', $fx['home'] . '/docker/db/data:/var/lib/mysql']);
            $s = launchSummary($recs);
            check($code === 0 && $s['can_run'] === true && $s['env_names'] === ['MARIADB_ROOT_PASSWORD', 'MARIADB_DATABASE'], 'plan: ' . $out);
            check($recs[1]['labels'] === ['pikkumies.group' => 'db', 'pikkumies.env_file' => $env], 'labels: ' . json_encode($recs[1]['labels']));
            check(strpos($out, LAUNCH_SECRET) === false, 'plan printed the secret');
            $line = launchLineArgs($s['launch']);
            check(in_array("--env-file=$env", $line, true), 'line: ' . $s['launch']);
            list($code, , $out2) = launchRun($fx, $line);
            check($code === 0 && strpos($out2, LAUNCH_SECRET) === false, "launch: $code $out2");
            $runs = launchRunCalls($fx);
            check(count($runs) === 1 && in_array("--env-file=$env", $runs[0], true) && in_array("--label=pikkumies.env_file=$env", $runs[0], true), 'run argv: ' . json_encode($runs));
            // docker quoting the value in its error text: scrubbed before it is shown.
            $st = containersState($fx);
            $st['containers'] = [];
            $st['fail'] = ['run' => ['code' => 125, 'err' => 'docker: invalid env value ' . LAUNCH_SECRET . ' in file']];
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            list($code, , $out3, $err3) = launchRun($fx, $line);
            check($code === 1 && strpos($out3, 'REDACTED') !== false, 'failed run: ' . $out3);
            foreach (containersState($fx)['calls'] as $c) {
                check(strpos(implode(' ', $c), LAUNCH_SECRET) === false, 'the secret was in an argv: ' . json_encode($c));
            }
            check(strpos($out3 . $err3, LAUNCH_SECRET) === false, 'the secret reached stdout/stderr: ' . $out3);
        } finally {
            containersDone($fx);
        }
    },
    'launch-plan: an env file readable by others, a link, or with bare-name lines is refused' => function () {
        $fx = launchFixture();
        try {
            $env = $fx['home'] . '/app.env';
            file_put_contents($env, "TOKEN=abc123\n");
            chmod($env, 0640);
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--env-file', $env]);
            $s = launchSummary($recs);
            check($s['can_run'] === false && launchBlocked($s, "chmod 600 $env"), 'group-readable: ' . json_encode($s));
            chmod($env, 0604);
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--env-file', $env]);
            check(launchBlocked(launchSummary($recs), 'chmod 600'), 'other-readable passed');
            chmod($env, 0600);
            symlink($env, $fx['home'] . '/link.env');
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--env-file', $fx['home'] . '/link.env']);
            check(launchBlocked(launchSummary($recs), 'not a regular file'), 'a symlink passed');
            file_put_contents($env, "TOKEN=abc123\nHOME_KEY\nexport X=1\n");
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--env-file', $env]);
            check(launchBlocked(launchSummary($recs), '2 line(s) that are not NAME=value'), 'bare names passed: ' . json_encode(launchSummary($recs)));
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--env-file', $fx['home'] . '/missing.env']);
            check(launchBlocked(launchSummary($recs), 'does not exist'), 'a missing env file passed');
        } finally {
            containersDone($fx);
        }
    },
    'launch-plan: bind sources in the denylist (home, ~/.ssh, pikkumies, docker data and config, sockets, a folder holding one) are refused' => function () {
        $h = null;
        $fx = launchFixture(['engine' => ['info' => ['ServerVersion' => '26.1.5', 'CgroupDriver' => 'cgroupfs', 'CgroupVersion' => '2', 'Architecture' => 'x86_64',
            'SecurityOptions' => ['name=seccomp,profile=builtin', 'name=rootless'], 'DockerRootDir' => '/PLACEHOLDER']]]);
        try {
            $home = $fx['home'];
            foreach (['.ssh', '.config/docker', '.local/share/docker', 'docker/ok', '.config/other'] as $d) {
                mkdir("$home/$d", 0700, true);
            }
            $st = containersState($fx);
            $st['engine']['info']['DockerRootDir'] = "$home/.local/share/docker";
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            symlink(dirname(__DIR__) . '/pikkumies', "$home/pk");
            symlink("$home/.ssh", "$home/innocent");
            $h = @stream_socket_server("unix://$home/app.sock");
            $cases = [
                "$home:/data" => 'the home folder itself',
                "~/.ssh:/data" => '~/.ssh',
                "$home/innocent:/data" => '~/.ssh',
                "$home/pk:/data" => 'pikkumies',
                "$home/.config/docker:/data" => 'docker\'s config folder',
                "$home/.config:/data" => 'docker\'s config folder',
                "$home/.local/share/docker:/data" => 'docker\'s data folder',
                "$home/.local:/data" => 'docker\'s data folder',
                $fx['dir'] . '/other:/data' => 'outside the home folder',
                "docker/ok:/data" => 'must be absolute',
            ];
            if ($h !== false) {
                $cases["$home/app.sock:/data"] = 'a socket';
            }
            foreach ($cases as $vol => $want) {
                list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--volume', $vol]);
                $s = launchSummary($recs);
                check($s['can_run'] === false && launchBlocked($s, $want), "$vol: expected '$want', got " . json_encode($s['blockers']));
            }
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--volume', "~/docker/ok:/data:ro,$home/.config/other:/cfg"]);
            $s = launchSummary($recs);
            check($s['can_run'] === true, 'allowed sources blocked: ' . json_encode($s['blockers']));
            check($recs[1]['mounts'] === [['source' => realpath("$home/docker/ok"), 'destination' => '/data', 'read_only' => true],
                ['source' => realpath("$home/.config/other"), 'destination' => '/cfg', 'read_only' => false]], 'mounts: ' . json_encode($recs[1]['mounts']));
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--volume', "$home/docker/new:/data"]);
            $s = launchSummary($recs);
            check(launchBlocked($s, 'does not exist') && (bool) preg_grep('#^create the missing folders first: mkdir -m 700 -p ' . preg_quote("$home/docker/new", '#') . '$#', $s['notes']), 'missing source: ' . json_encode($s));
            check(!is_dir("$home/docker/new"), 'launch-plan created a folder');
        } finally {
            if (is_resource($h)) {
                fclose($h);
            }
            containersDone($fx);
        }
    },
    'launch-plan: with --count above 1 a bind source needs {n} or :ro' => function () {
        $fx = launchFixture();
        try {
            $home = $fx['home'];
            foreach (['docker/data', 'docker/web-1', 'docker/web-2', 'docker/html'] as $d) {
                mkdir("$home/$d", 0700, true);
            }
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--count', '2', '--volume', '~/docker/data:/data']);
            check(launchBlocked(launchSummary($recs), 'would be shared by 2 containers'), 'shared rw source passed: ' . json_encode(launchSummary($recs)));
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--count', '2', '--volume', '~/docker/web-{n}:/data,~/docker/html:/usr/share/nginx/html:ro']);
            $s = launchSummary($recs);
            check($s['can_run'] === true, '{n} and :ro blocked: ' . json_encode($s['blockers']));
            check($recs[1]['mounts'][0]['source'] === realpath("$home/docker/web-1") && $recs[2]['mounts'][0]['source'] === realpath("$home/docker/web-2"), 'expanded: ' . json_encode($recs));
            $args = launchLineArgs($s['launch']);
            $want = '--volume=' . realpath("$home/docker/web-1") . ':/data,' . realpath("$home/docker/html") . ':/usr/share/nginx/html:ro,'
                . realpath("$home/docker/web-2") . ':/data,' . realpath("$home/docker/html") . ':/usr/share/nginx/html:ro';
            check(in_array($want, $args, true), 'concrete volumes: ' . $s['launch']);
            list($code, $recs, $out) = launchRun($fx, $args);
            check($code === 0 && count($recs) === 3 && $recs[2]['started'] === 2, 'launch: ' . $out);
            $runs = launchRunCalls($fx);
            check(in_array('--volume=' . realpath("$home/docker/web-2") . ':/data', $runs[1], true), 'second container mounts web-2: ' . json_encode($runs[1]));
            list($code) = launchRun($fx, ['launch', '--image=nginx:alpine', '--name=web-7', '--volume=~/docker/web-{n}:/data']);
            check($code === 1, 'launch accepted a non-concrete volume');
        } finally {
            containersDone($fx);
        }
    },
    'launch: on a shared server a published port needs --accept-shared-exposure; the plan adds it and uses the provider window' => function () {
        $fx = launchFixture([], true);
        try {
            list(, $recs, $out) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--port', '80']);
            $s = launchSummary($recs);
            check($s['can_run'] === true && $recs[1]['ports'][0]['exposure'] === 'local-shared', 'plan: ' . $out);
            check((bool) preg_grep('/every account here can reach the port/', $s['notes']), 'notes: ' . json_encode($s['notes']));
            $args = launchLineArgs($s['launch']);
            check(end($args) === '--accept-shared-exposure', 'line: ' . $s['launch']);
            $without = array_values(array_diff($args, ['--accept-shared-exposure']));
            list($code, $recs) = launchRun($fx, $without);
            $last = $recs[count($recs) - 1];
            check($code === 1 && strpos($last['error'], 'needs --accept-shared-exposure') !== false, 'without the flag: ' . json_encode($last));
            check(launchRunCalls($fx) === [], 'docker run without the exposure flag');
            list($code) = launchRun($fx, ['launch', '--image=nginx:alpine', '--name=quiet']);
            check($code === 0 && count(launchRunCalls($fx)) === 1, 'no port, no flag needed');
            // PMSS: the provider's window.
            mkdir($fx['dir'] . '/pmss', 0700);
            list(, $recs, $out) = launchRun($fx, ['launch-plan', '--image', 'nginx:alpine', '--name', 'web', '--port', '80'], ['pmss_dir' => $fx['dir'] . '/pmss']);
            $port = $recs[1]['ports'][0]['host_port'];
            check($port >= 38001 && $port <= 49999, 'PMSS host port outside 38001-49999: ' . $out);
        } finally {
            containersDone($fx);
        }
    },
    'launch: --public publishes on every interface and says so; a LinuxServer.io image on a rootless daemon gets PUID/PGID 0' => function () {
        $fx = launchFixture();
        try {
            $base = launchPortBase();
            list(, $recs, $out) = launchRun($fx, ['launch-plan', '--image', 'lscr.io/linuxserver/jellyfin', '--name', 'jellyfin', '--port', '8096', '--public', '--host-port-base', (string) $base]);
            $s = launchSummary($recs);
            check($s['can_run'] === true && $recs[1]['ports'][0]['exposure'] === 'public' && $recs[1]['ports'][0]['host_ip'] === '0.0.0.0', 'plan: ' . $out);
            check((bool) preg_grep('/reachable from the internet/', $s['notes']) && (bool) preg_grep('/PUID=0 and PGID=0/', $s['notes']), 'notes: ' . json_encode($s['notes']));
            $args = launchLineArgs($s['launch']);
            check(in_array('--public', $args, true), 'line: ' . $s['launch']);
            list($code, , $out) = launchRun($fx, $args);
            check($code === 0, $out);
            $run = launchRunCalls($fx)[0];
            check((bool) preg_grep('/^--publish=\d+:8096$/', $run) && in_array('--env=PUID=0', $run, true) && in_array('--env=PGID=0', $run, true), 'run: ' . json_encode($run));
        } finally {
            containersDone($fx);
        }
    },
    'launch-plan: an image that is not local or built for another architecture is refused, never pulled' => function () {
        $fx = launchFixture();
        try {
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'redis:7', '--name', 'cache']);
            check(launchBlocked(launchSummary($recs), 'docker pull redis:7'), 'not local: ' . json_encode(launchSummary($recs)));
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', 'armonly:1', '--name', 'x']);
            check(launchBlocked(launchSummary($recs), 'built for arm64, this machine is amd64'), 'arch: ' . json_encode(launchSummary($recs)));
            list(, $recs) = launchRun($fx, ['launch-plan', '--image', '-v/:/host', '--name', 'x']);
            check(launchBlocked(launchSummary($recs), 'is not an image name'), 'option-like image passed');
            foreach (containersState($fx)['calls'] as $c) {
                check($c[0] !== 'pull' && $c[0] !== 'run', 'pulled or ran: ' . json_encode($c));
            }
            list($code, $recs) = launchRun($fx, ['launch-plan', '--name', 'x']);
            check($code === 1 && strpos($recs[count($recs) - 1]['error'], '--image is required') === 0, 'missing --image');
            list($code) = launchRun($fx, ['launch-plan', 'stray', '--image', 'nginx:alpine', '--name', 'x']);
            check($code === 1, 'a positional argument must be refused');
        } finally {
            containersDone($fx);
        }
    },
    'launch: stops at the first failure (a failed run, or a container that cannot be read back) and tries nothing after it' => function () {
        $fx = launchFixture(['fail' => ['run' => ['code' => 125, 'err' => 'docker: Error response from daemon: driver failed programming external connectivity']]]);
        try {
            list($code, $recs) = launchRun($fx, ['launch', '--image=nginx:alpine', '--name=web-1,web-2,web-3']);
            $last = $recs[count($recs) - 1];
            check($code === 1 && strpos($last['error'], 'web-1 did not start') === 0 && strpos($last['error'], 'Started before it: none') !== false, json_encode($last));
            check(count(launchRunCalls($fx)) === 1, 'runs after a failure: ' . count(launchRunCalls($fx)));
            $st = containersState($fx);
            $st['fail'] = ['inspect' => ['code' => 1, 'err' => 'Error: No such object']];
            $st['calls'] = [];
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            list($code, $recs) = launchRun($fx, ['launch', '--image=nginx:alpine', '--name=app-1,app-2,app-3']);
            $last = $recs[count($recs) - 1];
            check($code === 1 && strpos($last['error'], 'could not be read back') !== false, json_encode($last));
            check(count(launchRunCalls($fx)) === 1, 'runs after an unverified container: ' . count(launchRunCalls($fx)));
        } finally {
            containersDone($fx);
        }
    },
    'launch: past the time budget it stops with remaining and a resume line for the rest' => function () {
        $fx = launchFixture();
        try {
            // Every fake call takes 0.75 s: 4 preflight calls (~3.1 s), then run + inspect (~1.6 s) per container. With a 23.9 s
            // budget the first container starts with ~20.8 s left and the second would start with ~19.2 s, under the 20 s reserve.
            list($code, $recs, $out) = launchRun($fx, ['launch', '--image=nginx:alpine', '--name=web-1,web-2,web-3'], ['launch_budget' => 23.9], ['FAKE_SLEEP' => '0.75']);
            $status = $recs[count($recs) - 1];
            check($code === 0 && $status['started'] === 1 && $status['remaining'] === 2, 'status: ' . $out);
            check(in_array('--name=web-2,web-3', launchLineArgs($status['resume']), true), 'resume: ' . $status['resume']);
            check(count(launchRunCalls($fx)) === 1, 'runs: ' . count(launchRunCalls($fx)));
        } finally {
            containersDone($fx);
        }
    },
];
