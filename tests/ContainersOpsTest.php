<?php
/**
 * The docker unit's container verbs (DockerOps, ADR-0034): stats, list, show, logs, start, stop, restart, remove,
 * images, prune. Every test runs docker.php against the fake docker (tests/fixtures/media/docker.php, containers
 * mode) in a throwaway home; nothing reads the real pikkumies/local/, /proc or /run/user, and no real daemon is used.
 */
require_once __DIR__ . '/fixtures/containers/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerCli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerOps.php';

/** A fake-docker container record. */
function opsC($name, $state = 'running', array $extra = [])
{
    return $extra + ['id' => hash('sha256', 'ops-' . $name), 'name' => $name, 'image' => 'nginx:alpine', 'state' => $state,
        'exit_code' => $state === 'running' ? 0 : 137, 'ports' => [], 'labels' => [], 'env' => [], 'started_at' => '2026-10-17T10:00:00.000000000Z'];
}

/** A fake-docker image record. */
function opsImg($seed, $repo, $tag, $size = 5000000)
{
    return ['id' => 'sha256:' . hash('sha256', 'img-' . $seed), 'repository' => $repo, 'tag' => $tag, 'size' => $size, 'created' => '2026-10-01 10:00:00 +0000 UTC'];
}

/** Every docker call the fake recorded, as argv lists (without the docker binary). */
function opsCalls(array $fx)
{
    $st = containersState($fx);
    return isset($st['calls']) ? $st['calls'] : [];
}

/** Calls whose first word is $cmd. */
function opsCallsOf(array $fx, $cmd)
{
    return array_values(array_filter(opsCalls($fx), function ($c) use ($cmd) {
        return isset($c[0]) && $c[0] === $cmd;
    }));
}

/** The last record (the status line) and the records before it. */
function opsSplit(array $recs)
{
    $status = $recs ? $recs[count($recs) - 1] : [];
    return [$status, array_slice($recs, 0, -1)];
}

/** A passwd file with one human account: this host is not shared, so loopback is `none`. */
function opsSoloPasswd(array $fx)
{
    file_put_contents($fx['dir'] . '/passwd', 'root:x:0:0:root:/root:/bin/sh' . "\n" . 'me:x:1000:1000::' . $fx['home'] . ":/bin/sh\n");
    return ['passwd' => $fx['dir'] . '/passwd'];
}

return [
    'containers ops: stats without a usable daemon says cli-missing, socket-missing or refused, exits 0 and calls nothing' => function () {
        $fx = containersFixture();
        try {
            unlink($fx['dir'] . '/bin/docker');
            list($code, $recs) = containersRun($fx, ['stats'], [], ['PATH' => $fx['dir'] . '/empty']);
            check($code === 0 && $recs[0]['docker'] === 'cli-missing' && $recs[0]['hint'] !== '', 'cli-missing: ' . json_encode($recs));
        } finally {
            containersDone($fx);
        }
        $fx = containersFixture([], false);
        try {
            list($code, $recs) = containersRun($fx, ['stats']);
            check($code === 0 && $recs[0]['docker'] === 'socket-missing', 'socket-missing: ' . json_encode($recs));
            list($code, $recs) = containersRun($fx, ['stats'], [], ['DOCKER_HOST' => 'tcp://127.0.0.1:2375']);
            check($code === 0 && $recs[0]['docker'] === 'refused' && $recs[0]['reason'] === 'tcp', 'tcp: ' . json_encode($recs));
            check(opsCalls($fx) === [], 'no daemon: nothing may be called');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: stats on a rootless cgroup v1 daemon gives engine facts and space, per_container unavailable, no docker stats call' => function () {
        $fx = containersFixture(['engine' => ['compose' => '2.29.0', 'df' => [['Type' => 'Images', 'TotalCount' => '2', 'Active' => '1', 'Size' => '10MB', 'Reclaimable' => '5MB (50%)']]],
            'containers' => [opsC('web-1')]]);
        try {
            list($code, $recs) = containersRun($fx, ['stats']);
            list($status, $rows) = opsSplit($recs);
            $e = $rows[0];
            check($code === 0 && $status['ok'] === true && $e['kind'] === 'engine', 'stats: ' . json_encode($recs));
            check($e['server_version'] === '26.1.5' && $e['rootless'] === true && $e['cgroup_version'] === 1 && $e['cgroup_driver'] === 'none'
                && $e['storage_driver'] === 'fuse-overlayfs' && $e['compose'] === 'plugin', 'engine facts: ' . json_encode($e));
            check($e['limits_enforced'] === false && $e['per_container'] === 'unavailable', 'cgroup v1 rootless: limits not enforced: ' . json_encode($e));
            check($rows[1]['kind'] === 'space' && $rows[1]['type'] === 'Images' && $rows[1]['total_count'] === 2 && $rows[1]['active'] === 1, 'space: ' . json_encode($rows[1]));
            check(opsCallsOf($fx, 'stats') === [], 'docker stats must not be called where limits are not enforced');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: a cgroupfs rootless daemon on cgroup v2 (PMSS) has per_container unavailable and no docker stats call' => function () {
        $info = ['ServerVersion' => '29.0.1', 'Driver' => 'overlay2', 'CgroupDriver' => 'cgroupfs', 'CgroupVersion' => '2', 'SecurityOptions' => ['name=seccomp,profile=builtin', 'name=rootless'], 'Warnings' => []];
        $fx = containersFixture(['engine' => ['info' => $info], 'containers' => [opsC('web-1', 'running', ['stats' => ['CPUPerc' => '3.00%', 'MemUsage' => '50MiB / 1GiB']])]]);
        try {
            list($code, $recs) = containersRun($fx, ['stats']);
            check($code === 0 && $recs[0]['limits_enforced'] === false && $recs[0]['per_container'] === 'unavailable', 'cgroupfs rootless: ' . json_encode($recs[0]));
            check(opsCallsOf($fx, 'stats') === [], 'cgroupfs rootless: docker stats was called');
            foreach ($recs as $r) {
                check(!isset($r['kind']) || $r['kind'] !== 'container', 'no per-container record may be printed: ' . json_encode($r));
            }
        } finally {
            containersDone($fx);
        }
        check(DockerOps::limitsEnforced(false, 1, 'cgroupfs', []) === true, 'a rootful cgroupfs daemon applies limits');
        check(DockerOps::limitsEnforced(true, 2, 'systemd', ['WARNING: No memory limit support']) === false, 'a limit warning means not enforced');
        check(DockerOps::limitsEnforced(true, 2, 'systemd', ['WARNING: No swap limit support']) === true, 'a swap warning alone does not');
        check(DockerOps::limitsEnforced(null, 2, 'systemd', []) === null, 'unknown rootless: unknown');
    },
    'containers ops: a systemd rootless daemon on cgroup v2 measures per-container use; a running container at 0 B is null, not zero' => function () {
        $info = ['ServerVersion' => '29.0.1', 'Driver' => 'overlay2', 'CgroupDriver' => 'systemd', 'CgroupVersion' => '2', 'SecurityOptions' => ['name=rootless'], 'Warnings' => []];
        $fx = containersFixture(['engine' => ['info' => $info], 'containers' => [
            opsC('web-1', 'running', ['stats' => ['CPUPerc' => '3.50%', 'MemUsage' => '50MiB / 1GiB', 'MemPerc' => '4.88%', 'PIDs' => '7']]),
            opsC('web-2', 'running', ['stats' => ['CPUPerc' => '0.00%', 'MemUsage' => '0B / 0B', 'MemPerc' => '0.00%', 'PIDs' => '0']])]]);
        try {
            list($code, $recs) = containersRun($fx, ['stats']);
            check($code === 0 && $recs[0]['limits_enforced'] === true && $recs[0]['per_container'] === 'measured', 'systemd v2: ' . json_encode($recs[0]));
            $c = array_values(array_filter($recs, function ($r) {
                return isset($r['kind']) && $r['kind'] === 'container';
            }));
            check(count($c) === 2 && $c[0]['name'] === 'web-1' && $c[0]['cpu_percent'] === 3.5 && $c[0]['memory'] === '50MiB' && $c[0]['pids'] === 7, 'usage: ' . json_encode($c));
            check($c[1]['memory'] === null && $c[1]['cpu_percent'] === null, '0 B sampled is unknown: ' . json_encode($c[1]));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: list shows running containers (all with --all), exposure per port, restart policy and log size; --label filters' => function () {
        $fx = containersFixture(['containers' => [
            opsC('web-1', 'running', ['ports' => [['ip' => '127.0.0.1', 'host' => 38001, 'container' => 80, 'proto' => 'tcp']], 'restart' => 'unless-stopped', 'labels' => ['pikkumies.group' => 'web']]),
            opsC('pub', 'running', ['ports' => [['ip' => '0.0.0.0', 'host' => 38002, 'container' => 80, 'proto' => 'tcp']]]),
            opsC('old', 'exited')]]);
        try {
            mkdir($fx['dir'] . '/pmss');
            $st = containersState($fx);
            file_put_contents($fx['dir'] . '/web-1.log', str_repeat('x', 123));
            $st['containers'][0]['log_path'] = $fx['dir'] . '/web-1.log';
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            $pmss = ['pmss_dir' => $fx['dir'] . '/pmss'];
            list($code, $recs) = containersRun($fx, ['list'], $pmss);
            list(, $rows) = opsSplit($recs);
            check($code === 0 && array_column($rows, 'name') === ['web-1', 'pub'], 'running only: ' . json_encode($rows));
            check($rows[0]['id'] === opsC('web-1')['id'] && $rows[0]['restart_policy'] === 'unless-stopped' && $rows[0]['log_bytes'] === 123, 'fields: ' . json_encode($rows[0]));
            check($rows[0]['ports'][0]['exposure'] === 'local-shared' && $rows[1]['ports'][0]['exposure'] === 'public', 'PMSS exposure: ' . json_encode(array_column($rows, 'ports')));
            check(isset($rows[0]['status_text']) && !array_key_exists('status', $rows[0]), 'docker\'s status text must sit under status_text');
            check($rows[1]['log_bytes'] === null, 'no log path: null, not 0');
            list(, $recs) = containersRun($fx, ['list', '--all'], opsSoloPasswd($fx));
            list(, $rows) = opsSplit($recs);
            check(array_column($rows, 'name') === ['web-1', 'pub', 'old'] && $rows[0]['ports'][0]['exposure'] === 'none', 'all, solo host: ' . json_encode($rows));
            list(, $recs) = containersRun($fx, ['list', '--all', '--label', 'pikkumies.group=web'], $pmss);
            check(array_column(opsSplit($recs)[1], 'name') === ['web-1'], 'label filter: ' . json_encode($recs));
            $n = count(opsCalls($fx));
            list($code) = containersRun($fx, ['list', '--label', '-x;rm'], $pmss);
            check($code === 1 && count(opsCalls($fx)) === $n, 'a malformed label is refused before any call');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: show prints a fixed list of fields: env names only, never env values, command, arguments or secret label values (S3CRET oracle)' => function () {
        $fx = containersFixture(['containers' => [opsC('db', 'running', ['image' => 'mariadb:11', 'env' => ['MARIADB_ROOT_PASSWORD=S3CRET', 'TZ=UTC', 'PLAIN=S3CRET'],
            'cmd' => ['--password', 'S3CRET'], 'labels' => ['traefik.http.middlewares.a.basicauth.users' => 'admin:S3CRET', 'com.docker.compose.project' => 'media',
            'pikkumies.env_file' => '/home/u/docker/db/env'], 'mounts' => [['Type' => 'bind', 'Source' => '/home/u/docker/db/data', 'Destination' => '/var/lib/mysql', 'Mode' => '', 'RW' => true]]])]]);
        try {
            list($code, $recs, $out) = containersRun($fx, ['show', 'db'], opsSoloPasswd($fx));
            check($code === 0, 'show: ' . $out);
            check(strpos($out, 'S3CRET') === false, 'a secret reached stdout: ' . $out);
            $r = $recs[0];
            check($r['env_names'] === ['MARIADB_ROOT_PASSWORD', 'TZ', 'PLAIN'], 'env names as a list: ' . json_encode($r['env_names']));
            check(in_array('com.docker.compose.project=media', $r['labels_text'], true) && in_array('traefik.http.middlewares.a.basicauth.users', $r['labels_text'], true), 'labels: ' . json_encode($r['labels_text']));
            check($r['image'] === 'mariadb:11' && $r['state'] === 'running' && $r['mounts'][0]['destination'] === '/var/lib/mysql' && $r['env_file'] === '/home/u/docker/db/env', 'fields: ' . json_encode($r));
            foreach (['cmd', 'Cmd', 'args', 'Args', 'env', 'Env', 'Config', 'labels', 'command'] as $k) {
                check(!array_key_exists($k, $r), "show must not print $k");
            }
            list($code, $recs) = containersRun($fx, ['show', 'nope']);
            check($code === 1 && strpos(end($recs)['error'], 'not found') === 0, 'unknown container: ' . json_encode($recs));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: logs gives stream and log_text records for the last N lines; --lines is capped at 500; --since is passed attached' => function () {
        $logs = [];
        for ($i = 1; $i <= 8; $i++) {
            $logs[] = ['stream' => $i === 5 ? 'stderr' : 'stdout', 'text' => "line $i"];
        }
        $fx = containersFixture(['containers' => [opsC('web-1', 'running', ['logs' => $logs])]]);
        try {
            list($code, $recs) = containersRun($fx, ['logs', 'web-1', '--lines', '3']);
            list($status, $rows) = opsSplit($recs);
            check($code === 0 && array_column($rows, 'log_text') === ['line 6', 'line 7', 'line 8'] && $rows[0]['stream'] === 'out', 'tail 3: ' . json_encode($recs));
            list(, $recs) = containersRun($fx, ['logs', 'web-1', '--lines', '600', '--since', '10m']);
            list($status, $rows) = opsSplit($recs);
            check($status['lines'] === 500 && count($rows) === 8 && in_array('err', array_column($rows, 'stream'), true), 'capped: ' . json_encode($status));
            $last = opsCallsOf($fx, 'logs');
            $last = end($last);
            check(in_array('--tail=500', $last, true) && in_array('--since=10m', $last, true) && end($last) === opsC('web-1')['id'], 'argv: ' . json_encode($last));
            list($code) = containersRun($fx, ['logs', 'web-1', '--since', '-x']);
            check($code === 1, 'an option-looking --since is refused');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: a failing docker logs ("No such container") is an error, never a log record' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1')], 'fail' => ['logs' => ['code' => 1, 'err' => 'Error response from daemon: No such container: x']]]);
        try {
            list($code, $recs, $out) = containersRun($fx, ['logs', 'web-1']);
            check($code === 1 && count($recs) === 1 && $recs[0]['ok'] === false && strpos($recs[0]['error'], 'No such container') !== false, 'logs failure: ' . $out);
            check(strpos($out, 'log_text') === false, 'the error text became a log record: ' . $out);
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: start, stop and restart act on full IDs, wait 30 s, and read each container back with verified' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1'), opsC('web-2', 'exited')]]);
        try {
            list($code, $recs) = containersRun($fx, ['stop', 'web-1']);
            check($code === 0 && $recs[0]['state'] === 'exited' && $recs[0]['verified'] === true && $recs[0]['action'] === 'stop', 'stop: ' . json_encode($recs));
            check(opsCallsOf($fx, 'stop') === [['stop', '-t', '30', opsC('web-1')['id']]], 'stop argv: ' . json_encode(opsCallsOf($fx, 'stop')));
            list($code, $recs) = containersRun($fx, ['start', 'web-1', substr(opsC('web-2')['id'], 0, 6)]);
            list(, $rows) = opsSplit($recs);
            check($code === 0 && array_column($rows, 'name') === ['web-1', 'web-2'] && array_column($rows, 'verified') === [true, true], 'start by name and ID prefix: ' . json_encode($rows));
            list($code, $recs) = containersRun($fx, ['restart', 'web-1']);
            check($code === 0 && $recs[0]['running'] === true, 'restart: ' . json_encode($recs));
            check(opsCallsOf($fx, 'restart')[0] === ['restart', '-t', '30', opsC('web-1')['id']], 'restart argv');
            $n = count(opsCallsOf($fx, 'start'));
            list($code, $recs) = containersRun($fx, ['start', 'web-1', 'nope']);
            check($code === 1 && strpos(end($recs)['error'], 'nothing changed') === 0 && count(opsCallsOf($fx, 'start')) === $n, 'one unknown name fails the whole call');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: stopping an auto-remove container refuses without --expect 1 and alone, and reports it removed' => function () {
        $fx = containersFixture(['containers' => [opsC('tmp', 'running', ['auto_remove' => true]), opsC('web-1')]]);
        try {
            list($code, $recs) = containersRun($fx, ['stop', 'tmp']);
            check($code === 1 && strpos(end($recs)['error'], '--expect 1') !== false, 'no --expect: ' . json_encode($recs));
            list($code, $recs) = containersRun($fx, ['stop', 'tmp', 'web-1', '--expect', '1']);
            check($code === 1 && strpos(end($recs)['error'], 'on its own') !== false, 'with another container: ' . json_encode($recs));
            check(opsCallsOf($fx, 'stop') === [], 'a refused stop called docker stop');
            list($code, $recs) = containersRun($fx, ['stop', 'tmp', '--expect', '1']);
            check($code === 0 && $recs[0]['state'] === 'removed' && $recs[0]['verified'] === true, 'stopped and gone: ' . json_encode($recs));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: remove needs --expect (checked before any call), refuses a different count and running containers, never forces' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1', 'running'), opsC('web-2', 'exited', ['labels' => ['pikkumies.env_file' => '/home/u/docker/web/env']]), opsC('web-3', 'exited')]]);
        try {
            list($code, $recs) = containersRun($fx, ['remove', 'web-2']);
            check($code === 1 && strpos(end($recs)['error'], '--expect is missing') !== false && opsCalls($fx) === [], 'no --expect: refused with zero calls: ' . json_encode(opsCalls($fx)));
            list($code, $recs) = containersRun($fx, ['remove', 'web-2', 'web-3', '--expect', '1']);
            check($code === 1 && strpos(end($recs)['error'], 'resolve to 2 containers, not 1') !== false, 'count mismatch: ' . json_encode($recs));
            list($code, $recs) = containersRun($fx, ['remove', 'web-2', 'web-2', '--expect', '2']);
            check($code === 1 && strpos(end($recs)['error'], 'resolve to 1 container, not 2') !== false, 'a name given twice is one container: ' . json_encode($recs));
            list($code, $recs) = containersRun($fx, ['remove', 'web-1', 'web-2', '--expect', '2']);
            check($code === 1 && strpos(end($recs)['error'], 'web-1 is running') !== false, 'running: ' . json_encode($recs));
            check(opsCallsOf($fx, 'rm') === [], 'a refused remove called docker rm');
            list($code, $recs) = containersRun($fx, ['remove', 'web-2', 'web-3', '--expect', '2']);
            list($status, $rows) = opsSplit($recs);
            check($code === 0 && $status['removed'] === 2 && array_column($rows, 'verified') === [true, true], 'remove: ' . json_encode($recs));
            check($rows[0]['env_file'] === '/home/u/docker/web/env' && $rows[1]['env_file'] === null, 'env-file left behind is named: ' . json_encode($rows));
            check(array_column(containersState($fx)['containers'], 'name') === ['web-1'], 'only the two stopped containers are gone');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: a successful remove clears the scheduled check baseline, so the user deleting containers is never reported as containers gone' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1', 'exited'), opsC('web-2', 'exited')]]);
        try {
            $state = $fx['dir'] . '/local/containers/state.json';
            @mkdir(dirname($state), 0700, true);
            file_put_contents($state, json_encode(['as_of' => 1, 'containers' => ['a' => 1, 'b' => 1], 'images' => 2, 'states' => [], 'events' => []]));
            list($code) = containersRun($fx, ['remove', 'web-1', 'web-2', '--expect', '2']);
            $after = json_decode((string) file_get_contents($state), true);
            check($code === 0 && is_array($after) && !isset($after['containers']) && !isset($after['as_of']) && array_key_exists('events', $after), 'baseline not cleared after remove: ' . json_encode($after));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: images marks in_use; --prune-plan lists dangling unused images (all unused with --all) with expect and rebuildable' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1')], 'images' => [opsImg('a', 'nginx', 'alpine'), opsImg('b', '<none>', '<none>'), opsImg('c', 'redis', '7')]]);
        try {
            list($code, $recs) = containersRun($fx, ['images']);
            list(, $rows) = opsSplit($recs);
            check($code === 0 && array_column($rows, 'in_use') === [true, false, false] && $rows[1]['dangling'] === true, 'images: ' . json_encode($rows));
            list(, $recs) = containersRun($fx, ['images', '--prune-plan']);
            list($status, $rows) = opsSplit($recs);
            check($status['expect'] === 1 && count($rows) === 1 && $rows[0]['ref'] === substr(hash('sha256', 'img-b'), 0, 12) && $rows[0]['rebuildable'] === false, 'plan: ' . json_encode($recs));
            list(, $recs) = containersRun($fx, ['images', '--prune-plan', '--all']);
            list($status, $rows) = opsSplit($recs);
            check($status['expect'] === 2 && array_column($rows, 'ref') === [substr(hash('sha256', 'img-b'), 0, 12), 'redis:7'], 'plan --all: ' . json_encode($recs));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: prune without --expect refuses with zero docker calls; a different count refuses without removing anything' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1')], 'images' => [opsImg('a', 'nginx', 'alpine'), opsImg('b', '<none>', '<none>'), opsImg('c', 'redis', '7')]]);
        try {
            list($code, $recs) = containersRun($fx, ['prune']);
            check($code === 1 && strpos(end($recs)['error'], '--expect is missing') !== false, 'no --expect: ' . json_encode($recs));
            list($code) = containersRun($fx, ['prune', '--expect', 'all']);
            check($code === 1 && opsCalls($fx) === [], 'prune without a numeric --expect must not call docker: ' . json_encode(opsCalls($fx)));
            list($code, $recs) = containersRun($fx, ['prune', '--expect', '2']);
            check($code === 1 && strpos(end($recs)['error'], 'would now remove 1 image, not 2') !== false, 'recompute: ' . json_encode($recs));
            check(opsCallsOf($fx, 'rmi') === [] && count(containersState($fx)['images']) === 3, 'a refused prune removed something');
            list($code, $recs) = containersRun($fx, ['prune', '--all', '--expect', '2']);
            list($status, $rows) = opsSplit($recs);
            check($code === 0 && $status['removed'] === 2 && $status['verified'] === true && array_column($rows, 'result') === ['removed', 'removed'], 'prune --all: ' . json_encode($recs));
            check(array_column(containersState($fx)['images'], 'repository') === ['nginx'], 'only the in-use image stays');
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: an image that became in use is skipped and reported, not forced' => function () {
        $fx = containersFixture(['images' => [opsImg('b', '<none>', '<none>')],
            'fail' => ['rmi' => ['code' => 1, 'err' => 'Error response from daemon: conflict: unable to delete abc (cannot be forced) - image is being used by running container def']]]);
        try {
            list($code, $recs) = containersRun($fx, ['prune', '--expect', '1']);
            list($status, $rows) = opsSplit($recs);
            check($code === 0 && $rows[0]['result'] === 'skipped' && $rows[0]['reason'] === 'in use' && $status['removed'] === 0 && $status['skipped'] === 1, 'skipped: ' . json_encode($recs));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: no delete argv ever carries -f or --force' => function () {
        $fx = containersFixture(['containers' => [opsC('a', 'exited'), opsC('b', 'created')], 'images' => [opsImg('b', '<none>', '<none>'), opsImg('c', 'redis', '7')]]);
        try {
            containersRun($fx, ['remove', 'a', 'b', '--expect', '2']);
            containersRun($fx, ['prune', '--all', '--expect', '2']);
            $deletes = array_merge(opsCallsOf($fx, 'rm'), opsCallsOf($fx, 'rmi'));
            check(count($deletes) === 4, 'expected 2 rm and 2 rmi calls: ' . json_encode($deletes));
            foreach ($deletes as $argv) {
                check(!array_intersect($argv, ['-f', '--force', '-fv', '--force=true']), 'a forced delete: ' . json_encode($argv));
            }
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: every docker call of every verb runs under timeout (a logging timeout on PATH counts them)' => function () {
        $real = ToolProc::which('timeout');
        if ($real === null) {
            skip('no timeout command here');
        }
        $fx = containersFixture(['containers' => [opsC('web-1'), opsC('old', 'exited')], 'images' => [opsImg('b', '<none>', '<none>')]]);
        try {
            $log = $fx['dir'] . '/timeout.log';
            file_put_contents($fx['dir'] . '/bin/timeout', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\nexec " . escapeshellarg($real) . " \"\$@\"\n");
            chmod($fx['dir'] . '/bin/timeout', 0700);
            foreach ([['stats', '--sizes'], ['list', '--all'], ['show', 'web-1'], ['logs', 'web-1'], ['restart', 'web-1'], ['stop', 'web-1'], ['start', 'web-1'],
                ['remove', 'old', '--expect', '1'], ['images'], ['images', '--prune-plan'], ['prune', '--expect', '1']] as $args) {
                list($code, , $out) = containersRun($fx, $args);
                check($code === 0, implode(' ', $args) . ': ' . $out);
            }
            $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($log)), 'strlen'));
            foreach ($lines as $l) {
                check(preg_match('#^\d+ \S*/docker #', $l) === 1, 'not `timeout N docker ...`: ' . $l);
            }
            $words = function (array $argvs) {
                return implode(', ', array_map(function ($a) {
                    return implode(' ', array_slice($a, 0, 2));
                }, $argvs));
            };
            check(count(opsCalls($fx)) > 20 && count($lines) === count(opsCalls($fx)), 'timeout lines ' . count($lines) . ' vs docker calls ' . count(opsCalls($fx))
                . '; timeout: ' . $words(array_map(function ($l) { return array_slice(explode(' ', $l), 2); }, $lines)) . '; calls: ' . $words(opsCalls($fx)));
        } finally {
            containersDone($fx);
        }
    },
    'containers ops: a daemon that does not answer stops every verb after the probe with the ladder state' => function () {
        $fx = containersFixture(['containers' => [opsC('web-1')], 'fail' => ['version' => ['code' => 1, 'err' => 'Cannot connect to the Docker daemon']]]);
        try {
            foreach ([['stats'], ['list'], ['show', 'web-1'], ['logs', 'web-1'], ['stop', 'web-1'], ['images']] as $args) {
                $before = count(opsCalls($fx));
                list($code, $recs) = containersRun($fx, $args);
                check($code === 1 && strpos(end($recs)['error'], 'Docker is not answering') === 0, implode(' ', $args) . ': ' . json_encode($recs));
                check(count(opsCalls($fx)) === $before + 1, implode(' ', $args) . ' made a call after a failed probe');
            }
        } finally {
            containersDone($fx);
        }
    },
];
