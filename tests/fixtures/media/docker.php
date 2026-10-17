<?php
/*
 * A fake `docker` CLI for the media and containers tests (written to bin/docker with a shebang). It reads
 * docker.json next to the socket DOCKER_HOST names and records each call there.
 *
 * Media mode (no "engine" key in docker.json): output shapes follow a real docker 26.1.5 on 2026-10-14: `ps --format
 * '{{.ID}}\t{{.Image}}\t{{.Names}}\t{{.Ports}}'` gives tab-separated fields with ports like "1900/udp,
 * 127.0.0.1:18097->8096/tcp"; `inspect --format '{{json .Mounts}}'` gives [{"Type","Source","Destination",...}].
 *
 * Containers mode ("engine" key present; containersFake below): every answer is built with json_encode from the state,
 * in the field names docker 26 prints for `--format '{{json .}}'` (one object per line). FAKE_SLEEP (seconds, capped
 * at 10) delays each answer, for time-limit tests. State keys: engine {version, info, df, compose}, containers [{id,
 * name, image, state, exit_code, ports [{ip, host, container, proto}], labels {}, env [], mounts [], restart,
 * auto_remove, started_at, finished_at, restart_count, log_path, oom, created, logs [{stream, text}], stats {}}],
 * images [{id, repository, tag, size, created, arch?, os?, user?, labels?}] (`image inspect` defaults arch amd64, os linux;
 * `info` reports Architecture x86_64), fail {command: {code, err}}, calls [argv...], contexts [DOCKER_CONTEXT
 * seen by each call, null when unset].
 */
$sock = preg_replace('#^unix://#', '', (string) getenv('DOCKER_HOST'));
$file = dirname($sock) . '/docker.json';
$s = json_decode((string) @file_get_contents($file), true);
if (!is_array($s) || !file_exists($sock)) {
    fwrite(STDERR, "Cannot connect to the Docker daemon at unix://$sock\n");
    exit(1);
}
$args = array_slice($argv, 1);
if (isset($s['engine'])) {
    exit(containersFake($s, $args, $file));
}
$s['calls'][] = $args;
file_put_contents($file, json_encode($s));
$cmd = isset($args[0]) ? $args[0] : '';
if ($cmd === 'ps') {
    foreach ($s['containers'] as $c) {
        echo $c['id'], "\t", $c['image'], "\t", $c['name'], "\t", $c['ports'], "\n";
    }
    exit(0);
}
if ($cmd === 'inspect') {
    foreach ($s['containers'] as $c) {
        if ($c['id'] === end($args)) {
            echo json_encode(isset($c['mounts']) ? $c['mounts'] : []), "\n";
            exit(0);
        }
    }
    fwrite(STDERR, "Error: No such object\n");
    exit(1);
}
if ($cmd === 'restart') {
    echo end($args), "\n";
    exit(0);
}
fwrite(STDERR, "fake docker: unsupported command $cmd\n");
exit(1);

/** Containers mode: answers one call from $s, saves the state, returns the exit code. */
function containersFake(array $s, array $args, $file)
{
    $sleep = min(10.0, max(0.0, (float) getenv('FAKE_SLEEP')));
    if ($sleep > 0) {
        usleep((int) ($sleep * 1000000));
    }
    $s['calls'][] = $args;
    $s['contexts'][] = getenv('DOCKER_CONTEXT') === false ? null : getenv('DOCKER_CONTEXT'); // DockerCli removes it
    $s += ['containers' => [], 'images' => [], 'fail' => []];
    // `container ls`, `image rm`, `container rm` ... are the same commands as ps, rmi, rm.
    $alias = ['container ls' => 'ps', 'container list' => 'ps', 'container inspect' => 'inspect', 'container rm' => 'rm', 'container remove' => 'rm',
        'image ls' => 'images', 'image list' => 'images', 'image rm' => 'rmi', 'image remove' => 'rmi', 'container start' => 'start',
        'container stop' => 'stop', 'container restart' => 'restart', 'container logs' => 'logs', 'container run' => 'run', 'system df' => 'df',
        'compose version' => 'compose-version', 'container stats' => 'stats', 'image inspect' => 'image-inspect'];
    $two = isset($args[1]) ? $args[0] . ' ' . $args[1] : '';
    if (isset($alias[$two])) {
        $cmd = $alias[$two];
        $rest = array_slice($args, 2);
    } else {
        $cmd = isset($args[0]) ? $args[0] : '';
        $rest = array_slice($args, 1);
    }
    $out = function ($row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
    };
    $save = function () use (&$s, $file) {
        file_put_contents($file, json_encode($s));
    };
    $save();
    if (isset($s['fail'][$cmd])) {
        fwrite(STDERR, (string) $s['fail'][$cmd]['err'] . "\n");
        return (int) $s['fail'][$cmd]['code'];
    }
    $e = $s['engine'];
    switch ($cmd) {
        case 'version':
            $out(isset($e['version']) ? $e['version'] : ['Client' => ['Version' => '26.1.5', 'ApiVersion' => '1.45'],
                'Server' => ['Version' => '26.1.5', 'ApiVersion' => '1.45', 'Components' => [['Name' => 'Engine', 'Version' => '26.1.5']]]]);
            return 0;
        case 'info':
            $out(isset($e['info']) ? $e['info'] : ['ServerVersion' => '26.1.5', 'Driver' => 'fuse-overlayfs', 'CgroupDriver' => 'none', 'CgroupVersion' => '1',
                'SecurityOptions' => ['name=seccomp,profile=builtin', 'name=rootless'], 'Containers' => count($s['containers']), 'Images' => count($s['images']), 'Warnings' => [],
                'Architecture' => 'x86_64', 'OSType' => 'linux']);
            return 0;
        case 'compose-version':
            if (empty($e['compose'])) {
                fwrite(STDERR, "docker: 'compose' is not a docker command.\n");
                return 1;
            }
            $out(['version' => (string) $e['compose']]);
            return 0;
        case 'df':
            foreach (isset($e['df']) ? $e['df'] : [] as $row) {
                $out($row);
            }
            return 0;
        case 'ps':
            $all = in_array('-a', $rest, true) || in_array('--all', $rest, true);
            $label = containersFakeOpt($rest, '--filter');
            foreach ($s['containers'] as $c) {
                if (!$all && $c['state'] !== 'running') {
                    continue;
                }
                if ($label !== null && strncmp($label, 'label=', 6) === 0 && !containersFakeHasLabel($c, substr($label, 6))) {
                    continue;
                }
                $out(containersFakePs($c));
            }
            return 0;
        case 'inspect':
            $code = 0;
            foreach (containersFakeRefs($rest) as $ref) {
                $i = containersFakeFind($s['containers'], $ref);
                if ($i === null) {
                    fwrite(STDERR, "Error: No such object: $ref\n");
                    $code = 1;
                    continue;
                }
                $out(containersFakeInspect($s['containers'][$i]));
            }
            return $code;
        case 'images':
            foreach ($s['images'] as $img) {
                $out(['Containers' => 'N/A', 'CreatedAt' => (string) $img['created'], 'CreatedSince' => '', 'Digest' => '<none>', 'ID' => substr(preg_replace('/^sha256:/', '', $img['id']), 0, 12),
                    'Repository' => (string) $img['repository'], 'SharedSize' => 'N/A', 'Size' => containersFakeSize((int) $img['size']), 'Tag' => (string) $img['tag'], 'UniqueSize' => 'N/A', 'VirtualSize' => containersFakeSize((int) $img['size'])]);
            }
            return 0;
        case 'image-inspect':
            // One object per ref, matched by repository:tag (a bare repository means :latest), full id or a 12+ hex id prefix.
            $code = 0;
            foreach (containersFakeRefs($rest, ['--format', '-f']) as $ref) {
                $hit = null;
                foreach ($s['images'] as $img) {
                    $bare = preg_replace('/^sha256:/', '', $img['id']);
                    $name = strpos($ref, ':') === false || strncmp($ref, 'sha256:', 7) === 0 ? $ref . ':latest' : $ref;
                    if ($name === $img['repository'] . ':' . $img['tag'] || $ref === $img['id'] || (preg_match('/^[0-9a-f]{12,}$/', $ref) && strpos($bare, $ref) === 0)) {
                        $hit = $img;
                        break;
                    }
                }
                if ($hit === null) {
                    fwrite(STDERR, "Error: No such image: $ref\n");
                    $code = 1;
                    continue;
                }
                $out(['Id' => (string) $hit['id'], 'RepoTags' => [$hit['repository'] . ':' . $hit['tag']], 'Architecture' => isset($hit['arch']) ? (string) $hit['arch'] : 'amd64',
                    'Os' => isset($hit['os']) ? (string) $hit['os'] : 'linux', 'Size' => (int) $hit['size'],
                    'Config' => ['User' => isset($hit['user']) ? (string) $hit['user'] : '', 'Labels' => isset($hit['labels']) ? (array) $hit['labels'] : null]]);
            }
            return $code;
        case 'stats':
            foreach ($s['containers'] as $c) {
                if ($c['state'] === 'running') {
                    $out((isset($c['stats']) ? $c['stats'] : []) + ['ID' => substr($c['id'], 0, 12), 'Name' => $c['name'], 'CPUPerc' => '0.00%', 'MemUsage' => '0B / 0B', 'MemPerc' => '0.00%', 'PIDs' => '0']);
                }
            }
            return 0;
        case 'logs':
            $refs = containersFakeRefs($rest, ['--tail', '-n', '--since', '--until']);
            $i = $refs ? containersFakeFind($s['containers'], $refs[0]) : null;
            if ($i === null) {
                fwrite(STDERR, 'Error response from daemon: No such container: ' . ($refs ? $refs[0] : '') . "\n");
                return 1;
            }
            $logs = isset($s['containers'][$i]['logs']) ? $s['containers'][$i]['logs'] : [];
            $tail = containersFakeOpt($rest, '--tail');
            if ($tail !== null && $tail !== 'all') {
                $logs = array_slice($logs, -max(0, (int) $tail));
            }
            foreach ($logs as $l) {
                fwrite($l['stream'] === 'stderr' ? STDERR : STDOUT, $l['text'] . "\n");
            }
            return 0;
        case 'start':
        case 'stop':
        case 'restart':
        case 'rm':
            $code = 0;
            foreach (containersFakeRefs($rest, ['-t', '--time', '--signal', '-s']) as $ref) {
                $i = containersFakeFind($s['containers'], $ref);
                if ($i === null) {
                    fwrite(STDERR, "Error response from daemon: No such container: $ref\n");
                    $code = 1;
                    continue;
                }
                $c = &$s['containers'][$i];
                if ($cmd === 'rm') {
                    if ($c['state'] === 'running' && !in_array('-f', $rest, true) && !in_array('--force', $rest, true)) {
                        fwrite(STDERR, "Error response from daemon: cannot remove container \"/{$c['name']}\": container is running: stop the container before removing or force remove\n");
                        $code = 1;
                        unset($c);
                        continue;
                    }
                    unset($c);
                    array_splice($s['containers'], $i, 1);
                } elseif ($cmd === 'stop') {
                    $c['state'] = 'exited';
                    $c['exit_code'] = 0;
                    $c['finished_at'] = '2026-10-17T12:00:00.000000000Z';
                    if (!empty($c['auto_remove'])) {
                        unset($c);
                        array_splice($s['containers'], $i, 1);
                    }
                } else {
                    $c['state'] = 'running';
                    $c['exit_code'] = 0;
                    $c['started_at'] = '2026-10-17T12:00:00.000000000Z';
                }
                unset($c);
                echo $ref, "\n";
            }
            $save();
            return $code;
        case 'rmi':
            $code = 0;
            foreach (containersFakeRefs($rest) as $ref) {
                $hit = null;
                foreach ($s['images'] as $k => $img) {
                    $short = preg_replace('/^sha256:/', '', $img['id']);
                    if ($ref === $img['id'] || strncmp($short, preg_replace('/^sha256:/', '', $ref), max(4, strlen(preg_replace('/^sha256:/', '', $ref)))) === 0 || $ref === $img['repository'] . ':' . $img['tag']) {
                        $hit = $k;
                    }
                }
                if ($hit === null) {
                    fwrite(STDERR, "Error response from daemon: No such image: $ref\n");
                    $code = 1;
                    continue;
                }
                foreach ($s['containers'] as $c) {
                    if ($c['image'] === $s['images'][$hit]['repository'] . ':' . $s['images'][$hit]['tag'] || $c['image'] === $s['images'][$hit]['id']) {
                        fwrite(STDERR, "Error response from daemon: conflict: unable to delete $ref (must be forced) - image is being used by stopped container " . substr($c['id'], 0, 12) . "\n");
                        $code = 1;
                        continue 2;
                    }
                }
                echo 'Deleted: ', $s['images'][$hit]['id'], "\n";
                array_splice($s['images'], $hit, 1);
            }
            $save();
            return $code;
        case 'run':
            return containersFakeRun($s, $rest, $save);
    }
    fwrite(STDERR, "fake docker: unsupported command $cmd\n");
    return 1;
}

/** The value of option $name in $args (`--name v` or `--name=v`), or null. */
function containersFakeOpt(array $args, $name)
{
    foreach ($args as $i => $a) {
        if ($a === $name && isset($args[$i + 1])) {
            return $args[$i + 1];
        }
        if (strpos($a, $name . '=') === 0) {
            return substr($a, strlen($name) + 1);
        }
    }
    return null;
}

/** The non-option words of $args; $valued: options that take the next word. */
function containersFakeRefs(array $args, array $valued = [])
{
    $valued = array_merge($valued, ['--format', '-f', '--filter']);
    $out = [];
    for ($i = 0; $i < count($args); $i++) {
        if (in_array($args[$i], $valued, true)) {
            $i++;
        } elseif ($args[$i] === '' || $args[$i][0] !== '-') {
            $out[] = $args[$i];
        }
    }
    return $out;
}

/** Index of the container $ref names (exact name, full ID or an ID prefix), or null. */
function containersFakeFind(array $containers, $ref)
{
    foreach ($containers as $i => $c) {
        if ($c['name'] === ltrim($ref, '/') || $c['id'] === $ref || ($ref !== '' && strncmp($c['id'], $ref, strlen($ref)) === 0)) {
            return $i;
        }
    }
    return null;
}

function containersFakeHasLabel(array $c, $want)
{
    $labels = isset($c['labels']) ? $c['labels'] : [];
    if (strpos($want, '=') === false) {
        return array_key_exists($want, $labels);
    }
    list($k, $v) = explode('=', $want, 2);
    return isset($labels[$k]) && (string) $labels[$k] === $v;
}

function containersFakeSize($bytes)
{
    return $bytes >= 1000000 ? round($bytes / 1000000, 1) . 'MB' : round($bytes / 1000, 1) . 'kB';
}

/** One `ps --format '{{json .}}'` line. */
function containersFakePs(array $c)
{
    $ports = [];
    foreach (isset($c['ports']) ? $c['ports'] : [] as $p) {
        $ports[] = ($p['ip'] === '::' ? '[::]' : $p['ip']) . ':' . $p['host'] . '->' . $p['container'] . '/' . $p['proto'];
    }
    $labels = [];
    foreach (isset($c['labels']) ? $c['labels'] : [] as $k => $v) {
        $labels[] = "$k=$v";
    }
    return ['Command' => '"/entrypoint"', 'CreatedAt' => isset($c['created']) ? $c['created'] : '2026-10-17 10:00:00 +0000 UTC', 'ID' => substr($c['id'], 0, 12),
        'Image' => $c['image'], 'Labels' => implode(',', $labels), 'LocalVolumes' => '0', 'Mounts' => '', 'Names' => $c['name'], 'Networks' => 'bridge',
        'Ports' => implode(', ', $ports), 'RunningFor' => 'About an hour ago', 'Size' => '0B', 'State' => $c['state'],
        'Status' => $c['state'] === 'running' ? 'Up About an hour' : 'Exited (' . (int) $c['exit_code'] . ') 5 minutes ago'];
}

/** One `inspect --format '{{json .}}'` object. */
function containersFakeInspect(array $c)
{
    $bindings = [];
    foreach (isset($c['ports']) ? $c['ports'] : [] as $p) {
        $bindings[$p['container'] . '/' . $p['proto']][] = ['HostIp' => $p['ip'], 'HostPort' => (string) $p['host']];
    }
    $running = $c['state'] === 'running';
    return ['Id' => $c['id'], 'Created' => '2026-10-17T10:00:00.000000000Z', 'Path' => '/entrypoint', 'Args' => [],
        'State' => ['Status' => $c['state'], 'Running' => $running, 'Paused' => false, 'Restarting' => false, 'OOMKilled' => !empty($c['oom']), 'Dead' => false,
            'Pid' => $running ? 4242 : 0, 'ExitCode' => (int) $c['exit_code'], 'Error' => '', 'StartedAt' => isset($c['started_at']) ? $c['started_at'] : '0001-01-01T00:00:00Z',
            'FinishedAt' => isset($c['finished_at']) ? $c['finished_at'] : '0001-01-01T00:00:00Z'],
        'Image' => 'sha256:' . str_repeat('ab', 32), 'Name' => '/' . $c['name'], 'RestartCount' => isset($c['restart_count']) ? (int) $c['restart_count'] : 0,
        'LogPath' => isset($c['log_path']) ? $c['log_path'] : '',
        'HostConfig' => ['AutoRemove' => !empty($c['auto_remove']), 'RestartPolicy' => ['Name' => isset($c['restart']) ? $c['restart'] : 'no', 'MaximumRetryCount' => 0],
            'PortBindings' => $bindings ?: new stdClass(), 'LogConfig' => ['Type' => 'json-file', 'Config' => new stdClass()]],
        'Mounts' => isset($c['mounts']) ? $c['mounts'] : [],
        'Config' => ['Image' => $c['image'], 'Env' => isset($c['env']) ? $c['env'] : [], 'Cmd' => isset($c['cmd']) ? $c['cmd'] : [], 'Labels' => isset($c['labels']) && $c['labels'] ? $c['labels'] : new stdClass()],
        'NetworkSettings' => ['Ports' => $running && $bindings ? $bindings : new stdClass()]];
}

/** `run`: creates a container from the options docker would read; prints its new ID. */
function containersFakeRun(array &$s, array $rest, $save)
{
    $valued = ['--name', '-p', '--publish', '-v', '--volume', '--mount', '--label', '-l', '--env-file', '-e', '--env', '--restart', '--log-opt',
        '--network', '--net', '-u', '--user', '--cpus', '-m', '--memory', '--log-driver', '-w', '--workdir'];
    $c = ['name' => '', 'image' => '', 'state' => 'running', 'exit_code' => 0, 'ports' => [], 'labels' => [], 'env' => [], 'mounts' => [], 'restart' => 'no',
        'auto_remove' => false, 'started_at' => '2026-10-17T12:00:00.000000000Z', 'run_args' => $rest];
    for ($i = 0; $i < count($rest); $i++) {
        $a = $rest[$i];
        $eq = strpos($a, '=');
        $name = $eq !== false && strncmp($a, '--', 2) === 0 ? substr($a, 0, $eq) : $a;
        if (in_array($name, $valued, true)) {
            $v = $eq !== false && strncmp($a, '--', 2) === 0 ? substr($a, $eq + 1) : (isset($rest[$i + 1]) ? $rest[++$i] : '');
            if ($name === '--name') {
                $c['name'] = $v;
            } elseif ($name === '-p' || $name === '--publish') {
                $parts = explode(':', $v);
                $cport = array_pop($parts);
                $host = array_pop($parts);
                $c['ports'][] = ['ip' => $parts ? implode(':', $parts) : '0.0.0.0', 'host' => (int) $host, 'container' => (int) $cport, 'proto' => 'tcp'];
            } elseif ($name === '--label' || $name === '-l') {
                $kv = explode('=', $v, 2);
                $c['labels'][$kv[0]] = isset($kv[1]) ? $kv[1] : '';
            } elseif ($name === '--restart') {
                $c['restart'] = $v;
            } elseif ($name === '-v' || $name === '--volume' || $name === '--mount') {
                $c['mounts'][] = ['Type' => 'bind', 'Source' => explode(':', $v)[0], 'Destination' => isset(explode(':', $v)[1]) ? explode(':', $v)[1] : ''];
            }
        } elseif ($a === '--rm') {
            $c['auto_remove'] = true;
        } elseif ($a !== '' && $a[0] !== '-') {
            $c['image'] = $a;
            $c['cmd'] = array_slice($rest, $i + 1);
            break;
        }
    }
    foreach ($s['containers'] as $other) {
        if ($c['name'] !== '' && $other['name'] === $c['name']) {
            fwrite(STDERR, "docker: Error response from daemon: Conflict. The container name \"/{$c['name']}\" is already in use by container \"{$other['id']}\". You have to remove (or rename) that container to be able to reuse that name.\n");
            return 125;
        }
    }
    $c['id'] = hash('sha256', json_encode($rest) . count($s['containers']) . microtime(true));
    $c['name'] = $c['name'] !== '' ? $c['name'] : 'fake_' . substr($c['id'], 0, 6);
    $s['containers'][] = $c;
    $save();
    echo $c['id'], "\n";
    return 0;
}
