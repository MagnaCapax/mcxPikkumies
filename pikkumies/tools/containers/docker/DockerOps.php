<?php
/**
 * DockerOps: the container verbs of the docker unit (ADR-0034, USAGE.md): stats, list, show, logs, start, stop,
 * restart, remove, images, prune. Each is a static method (DockerCli $d, array $args, array $opt) returning records.
 *
 * Every verb probes the daemon first (DockerCli::requireHealthy) and every docker call goes through call(), so it is
 * `timeout <N> docker ...` within what is left of the verb's 150 s. Containers are named by the user and resolved in
 * PHP against one `ps --all` + batched `inspect` (DockerRefs); docker is then given full IDs only. Output is a fixed
 * list of fields: env NAMES only, never a container's command or arguments; text a container or image sets (status,
 * error, labels, log lines) sits under `*_text` keys. Deletes (remove, prune) take `--expect`, recompute what they
 * would delete and refuse a different count; neither ever passes `-f`/`--force`.
 */
require_once dirname(__DIR__, 3) . '/lib/Detect.php';

class DockerOps
{
    const INSPECT_CHUNK = 100;   // IDs per inspect call
    const STOP_WAIT = 30;        // stop/restart: seconds docker waits before it kills the container
    const LOG_LINES = 20; // the default Cli page, so a plain `logs` call is not cut
    const LOG_LINES_MAX = 500;
    const LOG_TEXT_MAX = Cli::TEXT_MAX;   // bytes of one log line kept (Cli's record budget is 16 KiB)
    const ENV_FILE_LABEL = 'pikkumies.env_file'; // set by launch when it used --env-file; docker itself keeps no path
    const LABEL_RE = '#^[a-zA-Z0-9][a-zA-Z0-9._/-]{0,127}(=[^\x00-\x1f,]{0,255})?\z#';
    const SINCE_RE = '/^[0-9][0-9A-Za-z:.+-]{0,39}\z/';
    /** Label keys whose value is not printed (only the key): basic-auth hashes, tokens, passwords. */
    const LABEL_HIDDEN_RE = '/pass|secret|token|auth|credential|api.?key|private/i';
    /** Log options worth showing; others (a splunk token...) are never printed. */
    const LOG_OPTS = ['max-size', 'max-file', 'mode', 'compress', 'max-buffer-size'];
    /** docker info warnings that mean per-container limits are not applied (swap-limit warnings do not). */

    /** stats: engine facts, space used, per-container use only where limits are enforced. */
    public static function stats(DockerCli $d, array $args, array $opt)
    {
        self::noArgs('stats', $args);
        $a = $d->absence();
        if ($a['state'] !== 'ok') { // no usable daemon of this account's own: say why, call nothing
            return ['records' => [['kind' => 'engine', 'docker' => $a['state'], 'reason' => $a['reason'], 'socket' => $a['socket'] !== '' ? $a['socket'] : null,
                'hint' => $a['hint'], 'podman' => ToolProc::which('podman') !== null ? 'present' : null] + self::provider($d)], 'status' => ['docker' => $a['state']]];
        }
        $p = $d->requireHealthy();
        $info = self::firstJson(self::call($d, ['info', '--format', '{{json .}}']));
        $rootless = isset($info['SecurityOptions']) && is_array($info['SecurityOptions'])
            ? (bool) array_filter($info['SecurityOptions'], function ($o) {
                return is_string($o) && preg_match('/(^|,)name=rootless(,|$)/', $o);
            }) : null;
        $cgv = isset($info['CgroupVersion']) && ctype_digit((string) $info['CgroupVersion']) ? (int) $info['CgroupVersion'] : null;
        $drv = isset($info['CgroupDriver']) && is_string($info['CgroupDriver']) ? $info['CgroupDriver'] : null;
        $enforced = DockerCli::limitsEnforced($info);
        $compose = self::call($d, ['compose', 'version', '--format', 'json'], 15)['code'] === 0 ? 'plugin'
            : (ToolProc::which('docker-compose') !== null ? 'standalone' : 'none');
        $engine = ['kind' => 'engine', 'docker' => 'ok', 'engine' => $p['engine'], 'server_version' => $p['server'], 'client_version' => $p['client'],
            'rootless' => $rootless, 'cgroup_version' => $cgv, 'cgroup_driver' => $drv, 'limits_enforced' => $enforced,
            'storage_driver' => isset($info['Driver']) && is_string($info['Driver']) ? $info['Driver'] : null, 'compose' => $compose,
            'socket' => $d->socket(), 'per_container' => $enforced === true ? 'measured' : 'unavailable'] + self::provider($d);
        $records = [];
        $df = self::call($d, ['system', 'df', '--format', '{{json .}}']);
        if ($df['timed_out'] || $df['code'] !== 0) {
            $engine['space'] = $df['timed_out'] ? 'timed_out' : 'unknown';
        }
        foreach ($df['code'] === 0 ? DockerCli::jsonLines($df['out']) : [] as $row) {
            $records[] = ['kind' => 'space', 'type' => self::str($row, 'Type'), 'total_count' => self::int($row, 'TotalCount'), 'active' => self::int($row, 'Active'),
                'size' => self::str($row, 'Size'), 'reclaimable' => self::str($row, 'Reclaimable')];
        }
        if (!empty($opt['sizes'])) { // walks every container's writable layer: slow on a big or busy daemon
            $ps = self::call($d, ['ps', '--all', '--size', '--format', '{{json .}}']);
            if ($ps['timed_out'] || $ps['code'] !== 0) {
                $engine['sizes'] = $ps['timed_out'] ? 'timed_out' : 'unknown';
                $engine['sizes_hint'] = 'do not repeat --sizes now: it walks every container and the daemon is slow or busy';
            }
            foreach ($ps['code'] === 0 ? DockerCli::jsonLines($ps['out']) : [] as $row) {
                $records[] = ['kind' => 'container_size', 'name' => self::str($row, 'Names'), 'size' => self::str($row, 'Size')];
            }
        }
        if ($enforced === true) { // elsewhere docker reports zeros or the account's totals, not the container's own use
            $st = self::call($d, ['stats', '--no-stream', '--format', '{{json .}}']);
            foreach ($st['code'] === 0 ? DockerCli::jsonLines($st['out']) : [] as $row) {
                $records[] = self::usage($row);
            }
        }
        array_unshift($records, $engine);
        return ['records' => $records, 'status' => ['docker' => 'ok', 'limits_enforced' => $enforced]];
    }

    /**
     * After the account's own deletes, the scheduled check starts a new baseline, so a remove or prune never becomes
     * a "containers are gone" notice (DockerDiagnose::rebaseline). Nothing to do when nothing was deleted.
     */
    private static function rebaseline(DockerCli $d, array $deleted)
    {
        if ($deleted && class_exists('DockerDiagnose') && isset($d->seams['local_dir'])) {
            DockerDiagnose::rebaseline($d);
        }
    }

    /** Whether per-container limits apply: DockerCli::limitsEnforcedFrom() (the one rule). */
    public static function limitsEnforced($rootless, $cgroupVersion, $driver, array $warnings)
    {
        return DockerCli::limitsEnforcedFrom($rootless, $cgroupVersion, $driver, $warnings);
    }

    /** list: containers (running, or all with --all) with state, restart policy, health, log size and port exposure. */
    public static function listContainers(DockerCli $d, array $args, array $opt)
    {
        self::noArgs('list', $args);
        $ps = ['ps', '--format', '{{json .}}'];
        if (!empty($opt['all'])) {
            $ps[] = '--all';
        }
        if (isset($opt['label'])) {
            if (!is_string($opt['label']) || !preg_match(self::LABEL_RE, $opt['label'])) {
                throw new CliError('--label takes key or key=value (letters, digits, . _ / -); nothing was listed', 'e.g. --label com.docker.compose.project=media');
            }
            $ps[] = '--filter=label=' . $opt['label'];
        }
        $d->requireHealthy();
        $facts = self::exposureFacts($d);
        $out = [];
        foreach (self::inventory($d, $ps) as $c) {
            $i = $c['inspect'];
            $out[] = [
                'id' => $i ? (string) $i['Id'] : self::str($c['ps'], 'ID'),
                'name' => $i ? ltrim((string) $i['Name'], '/') : self::str($c['ps'], 'Names'),
                'image' => self::str($c['ps'], 'Image'),
                'state' => self::str($c['ps'], 'State'),
                'status_text' => self::str($c['ps'], 'Status'),
                'restart_policy' => $i && isset($i['HostConfig']['RestartPolicy']['Name']) ? (string) $i['HostConfig']['RestartPolicy']['Name'] : null,
                'health' => $i && isset($i['State']['Health']['Status']) ? (string) $i['State']['Health']['Status'] : null,
                'log_bytes' => $i ? self::logBytes($i) : null,
                'ports' => $i ? DockerExposure::ports($i, $facts) : [],
            ];
        }
        return $out;
    }

    /**
     * show: one container's settings as a fixed list of fields. Env values, command and arguments are never printed.
     * --record <dir>: also writes them, with the line that recreates the container, to <dir>/<name>.json (DockerRecord).
     */
    public static function show(DockerCli $d, array $args, array $opt)
    {
        $ref = self::one('show', $args);
        $d->requireHealthy();
        $inv = self::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $id = DockerRefs::resolve([$ref], self::refList($inv))[0];
        $shown = self::project(self::find($inv, $id), self::exposureFacts($d));
        return [isset($opt['record']) ? $shown + DockerRecord::write($d, self::find($inv, $id), $shown, $opt['record']) : $shown];
    }

    /** The allowlist projection of one inspect object. Anything not named here (Config.Cmd, Args, Env values...) is not printed. */
    public static function project(array $i, array $facts)
    {
        $hc = isset($i['HostConfig']) && is_array($i['HostConfig']) ? $i['HostConfig'] : [];
        $cfg = isset($i['Config']) && is_array($i['Config']) ? $i['Config'] : [];
        $mounts = [];
        foreach (isset($i['Mounts']) && is_array($i['Mounts']) ? $i['Mounts'] : [] as $m) {
            $mounts[] = ['type' => self::str($m, 'Type'), 'volume' => self::str($m, 'Name'), 'source' => self::str($m, 'Source'),
                'destination' => self::str($m, 'Destination'), 'mode' => self::str($m, 'Mode'), 'rw' => isset($m['RW']) ? (bool) $m['RW'] : null];
        }
        $logOpts = [];
        foreach (isset($hc['LogConfig']['Config']) && is_array($hc['LogConfig']['Config']) ? $hc['LogConfig']['Config'] : [] as $k => $v) {
            if (in_array($k, self::LOG_OPTS, true)) {
                $logOpts[$k] = (string) $v;
            }
        }
        $envNames = [];
        foreach (isset($cfg['Env']) && is_array($cfg['Env']) ? $cfg['Env'] : [] as $e) {
            $envNames[] = explode('=', (string) $e, 2)[0]; // the name only: the value never leaves this line
        }
        $labels = isset($cfg['Labels']) && is_array($cfg['Labels']) ? $cfg['Labels'] : [];
        return DockerRefs::readBack($i) + [
            'image' => self::str($cfg, 'Image'),
            'image_id' => self::str($i, 'Image'),
            'created' => self::str($i, 'Created'),
            'health' => isset($i['State']['Health']['Status']) ? (string) $i['State']['Health']['Status'] : null,
            'ports' => DockerExposure::ports($i, $facts),
            'mounts' => $mounts,
            'restart_policy' => isset($hc['RestartPolicy']['Name']) ? (string) $hc['RestartPolicy']['Name'] : null,
            'restart_max_retries' => isset($hc['RestartPolicy']['MaximumRetryCount']) ? (int) $hc['RestartPolicy']['MaximumRetryCount'] : null,
            'auto_remove' => isset($hc['AutoRemove']) ? (bool) $hc['AutoRemove'] : null,
            'log_driver' => isset($hc['LogConfig']['Type']) ? (string) $hc['LogConfig']['Type'] : null,
            'log_options' => $logOpts,
            'labels_text' => self::labelsText($labels),
            'env_names' => $envNames,
            'env_file' => isset($labels[self::ENV_FILE_LABEL]) ? (string) $labels[self::ENV_FILE_LABEL] : null,
            'user' => isset($cfg['User']) && $cfg['User'] !== '' ? (string) $cfg['User'] : null,
            'network_mode' => self::str($hc, 'NetworkMode'),
            'networks' => isset($i['NetworkSettings']['Networks']) && is_array($i['NetworkSettings']['Networks']) ? array_keys($i['NetworkSettings']['Networks']) : [],
            'privileged' => isset($hc['Privileged']) ? (bool) $hc['Privileged'] : null,
            'cap_add' => isset($hc['CapAdd']) && is_array($hc['CapAdd']) ? array_values(array_map('strval', $hc['CapAdd'])) : [],
        ];
    }

    /** logs: the last N lines (default 20, at most 500), stdout and stderr merged in time order. */
    public static function logs(DockerCli $d, array $args, array $opt)
    {
        $ref = self::one('logs', $args);
        $n = self::LOG_LINES;
        if (isset($opt['lines'])) {
            if (!is_string($opt['lines']) || !ctype_digit($opt['lines']) || (int) $opt['lines'] < 1) {
                throw new CliError('--lines takes a number from 1 to ' . self::LOG_LINES_MAX . '; nothing was read', 'e.g. --lines 100');
            }
            $n = min(self::LOG_LINES_MAX, (int) $opt['lines']);
        }
        $since = null;
        if (isset($opt['since'])) {
            if (!is_string($opt['since']) || !preg_match(self::SINCE_RE, $opt['since'])) {
                throw new CliError('--since takes docker\'s form: 10m, 2h, or an RFC 3339 time; nothing was read', 'e.g. --since 10m');
            }
            $since = $opt['since'];
        }
        $d->requireHealthy();
        $inv = self::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $id = DockerRefs::resolve([$ref], self::refList($inv))[0];
        $argv = array_merge(['logs', '--tail=' . $n, '--timestamps'], $since !== null ? ['--since=' . $since] : [], [$id]);
        $r = self::call($d, $argv);
        if ($r['code'] !== 0) { // stderr then holds docker's error, not log lines
            $err = (string) self::firstLine($r['err']);
            throw new CliError('logs: ' . ($r['timed_out'] ? 'docker did not answer in time' : ($err !== '' ? $err : 'docker failed (exit ' . $r['code'] . ')')) . '; nothing was read',
                stripos($err, 'No such container') !== false ? 'it may be gone (a container started with --rm is deleted when it stops); see `list --all`'
                : (stripos($err, 'does not support reading') !== false ? 'this container\'s log driver keeps no local log; read it where the driver sends it' : 'see `diagnose ' . $ref . '`'));
        }
        $records = self::mergeLogs(self::logLines($r['out'], 'out'), self::logLines($r['err'], 'err'));
        return ['records' => $records, 'status' => ['lines' => $n, 'total' => count($records)]];
    }

    public static function start(DockerCli $d, array $args, array $opt)
    {
        return self::change($d, 'start', $args, $opt);
    }

    public static function stop(DockerCli $d, array $args, array $opt)
    {
        return self::change($d, 'stop', $args, $opt);
    }

    public static function restart(DockerCli $d, array $args, array $opt)
    {
        return self::change($d, 'restart', $args, $opt);
    }

    /** remove: stopped containers only, exactly --expect of them, never forced. Prints the env-file each leaves. */
    public static function remove(DockerCli $d, array $args, array $opt)
    {
        if (!$args) {
            throw new CliError('nothing removed: name the containers to remove', 'remove <name>... --expect <how many>; names come from `list --all`');
        }
        $expect = self::expect($opt, 'list --all, show the user which containers, then add --expect <how many>');
        $d->requireHealthy();
        $inv = self::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $ids = DockerRefs::resolve($args, self::refList($inv), true);
        if (count($ids) !== $expect) {
            throw new CliError('nothing removed: the names given resolve to ' . count($ids) . ' container' . (count($ids) === 1 ? '' : 's') . ", not $expect",
                'show the user the containers again, then pass their count as --expect');
        }
        $running = [];
        foreach ($ids as $id) {
            $s = self::find($inv, $id)['State'];
            if (!empty($s['Running']) || !in_array(isset($s['Status']) ? $s['Status'] : '', ['created', 'exited', 'dead'], true)) {
                $running[] = self::nameOf(self::find($inv, $id));
            }
        }
        if ($running) {
            throw new CliError('nothing removed: ' . implode(', ', $running) . (count($running) === 1 ? ' is' : ' are') . ' running (remove takes stopped containers only)',
                'stop ' . implode(' ', $running) . ' first, then run remove again');
        }
        $done = [];
        foreach ($ids as $k => $id) {
            if ($d->remaining() < 15) {
                return self::partial(self::removed($d, $inv, $done), $inv, array_slice($ids, $k));
            }
            $r = self::call($d, ['rm', $id]); // never -f: a container that started meanwhile is refused by docker
            if ($r['code'] !== 0) {
                self::rebaseline($d, $done);
                throw self::failed('remove', self::nameOf(self::find($inv, $id)), $r, $inv, $done, array_slice($ids, $k + 1));
            }
            $done[] = $id;
        }
        self::rebaseline($d, $done);
        return ['records' => self::removed($d, $inv, $done), 'status' => ['removed' => count($done)]];
    }

    /** images: every image with in_use; --prune-plan: what prune would remove and the expect to pass it. */
    public static function images(DockerCli $d, array $args, array $opt)
    {
        self::noArgs('images', $args);
        $d->requireHealthy();
        $plan = self::imagePlan($d, !empty($opt['all']));
        if (!empty($opt['prune-plan'])) {
            $n = count($plan['remove']);
            return ['records' => $plan['remove'], 'status' => ['remove' => $n, 'keep' => count($plan['images']) - $n, 'expect' => $n]];
        }
        return ['records' => $plan['images'], 'status' => ['total' => count($plan['images'])]];
    }

    /** prune: removes what `images --prune-plan` listed, after computing it again; never forced; in-use items are skipped. */
    public static function prune(DockerCli $d, array $args, array $opt)
    {
        self::noArgs('prune', $args);
        $again = '`images --prune-plan' . (!empty($opt['all']) ? ' --all' : '') . '`';
        $expect = self::expect($opt, "run $again, show the user the list, then add --expect <its expect>"); // before any docker call
        $d->requireHealthy();
        $plan = self::imagePlan($d, !empty($opt['all']));
        $n = count($plan['remove']);
        if ($n !== $expect) {
            throw new CliError("nothing removed: prune would now remove $n image" . ($n === 1 ? '' : 's') . ", not $expect", "run $again again and show the user the new list");
        }
        $records = [];
        $gone = [];
        foreach ($plan['remove'] as $k => $img) {
            if ($d->remaining() < 15) {
                self::rebaseline($d, $gone);
                return ['records' => $records, 'status' => ['removed' => count($gone), 'remaining' => array_column(array_slice($plan['remove'], $k), 'ref'), 'complete' => false]];
            }
            $r = self::call($d, ['rmi', $img['ref']]); // never -f, never --force
            $err = (string) self::firstLine($r['err']);
            if ($r['code'] === 0) {
                $gone[] = $img['ref'];
                $records[] = ['ref' => $img['ref'], 'result' => 'removed'];
            } elseif (preg_match('/conflict|being used|in use/i', $err)) {
                $records[] = ['ref' => $img['ref'], 'result' => 'skipped', 'reason' => 'in use', 'error_text' => $err];
            } else {
                $records[] = ['ref' => $img['ref'], 'result' => 'failed', 'error_text' => $r['timed_out'] ? 'docker did not answer in time' : $err];
            }
        }
        self::rebaseline($d, $gone);
        $left = array_column(self::imageRows($d), 'ref');
        return ['records' => $records, 'status' => ['removed' => count($gone), 'skipped' => count($records) - count($gone),
            'verified' => !array_intersect($gone, $left)]];
    }

    // ---- shared pieces ----

    /** One docker call within the verb's remaining budget (DockerCli::run: `timeout <N> docker ...`, own socket only). */
    public static function call(DockerCli $d, array $args, $seconds = DockerCli::CALL_SECONDS)
    {
        return $d->run($args, max(1, min((int) $seconds, (int) floor($d->remaining()))));
    }

    /**
     * [['ps' => row, 'inspect' => object|null], ...] in ps order: one `ps` call ($psArgs) and inspect in chunks of 100.
     * A container gone between the two calls keeps inspect null.
     */
    public static function inventory(DockerCli $d, array $psArgs)
    {
        $r = self::call($d, $psArgs);
        if ($r['code'] !== 0) {
            throw new CliError('docker could not list containers: ' . ($r['timed_out'] ? 'no answer in time' : (string) self::firstLine($r['err'])) . '; nothing was done', 'run `diagnose`');
        }
        $rows = DockerCli::jsonLines($r['out']);
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['ID']) && preg_match('/^[0-9a-f]{12,64}$/', (string) $row['ID'])) {
                $ids[] = (string) $row['ID'];
            }
        }
        $inspected = [];
        foreach (array_chunk($ids, self::INSPECT_CHUNK) as $chunk) {
            foreach (DockerCli::jsonLines(self::call($d, array_merge(['inspect', '--format', '{{json .}}'], $chunk))['out']) as $obj) {
                if (isset($obj['Id']) && preg_match(DockerRefs::ID_RE, (string) $obj['Id'])) {
                    $inspected[] = $obj;
                }
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $hit = null;
            foreach ($inspected as $obj) {
                if (isset($row['ID']) && (string) $row['ID'] !== '' && strncmp((string) $obj['Id'], (string) $row['ID'], strlen((string) $row['ID'])) === 0) {
                    $hit = $obj;
                    break;
                }
            }
            $out[] = ['ps' => $row, 'inspect' => $hit];
        }
        return $out;
    }

    /** start/stop/restart: resolve every name first, act one by one, then read each back. */
    private static function change(DockerCli $d, $verb, array $args, array $opt)
    {
        $d->requireHealthy();
        $inv = self::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $ids = DockerRefs::resolve($args, self::refList($inv), true);
        if ($verb === 'stop') {
            self::autoRemoveGate($inv, $ids, $opt);
        }
        $need = $verb === 'start' ? 15 : self::STOP_WAIT + 15;
        $done = [];
        foreach ($ids as $k => $id) {
            if ($d->remaining() < $need) {
                return self::partial(self::readBackAll($d, $verb, $inv, $done), $inv, array_slice($ids, $k));
            }
            $argv = $verb === 'start' ? ['start', $id] : [$verb, '-t', (string) self::STOP_WAIT, $id];
            $r = self::call($d, $argv, $verb === 'start' ? DockerCli::CALL_SECONDS : self::STOP_WAIT + 30);
            if ($r['code'] !== 0) {
                throw self::failed($verb, self::nameOf(self::find($inv, $id)), $r, $inv, $done, array_slice($ids, $k + 1));
            }
            $done[] = $id;
        }
        return self::readBackAll($d, $verb, $inv, $done);
    }

    /** A container started with --rm is deleted by docker when it stops: stopping one is a delete, one per call, --expect 1. */
    private static function autoRemoveGate(array $inv, array $ids, array $opt)
    {
        foreach ($ids as $id) {
            $i = self::find($inv, $id);
            if (empty($i['HostConfig']['AutoRemove'])) {
                continue;
            }
            $name = self::nameOf($i);
            if (count($ids) > 1) {
                throw new CliError("nothing changed: $name was started with --rm, so docker deletes it when it stops; stop it on its own",
                    "tell the user it will be deleted, then: stop $name --expect 1");
            }
            if (!isset($opt['expect']) || !is_string($opt['expect']) || trim($opt['expect']) !== '1') {
                throw new CliError("nothing changed: $name was started with --rm, so stopping it deletes it; that needs --expect 1",
                    "tell the user it will be deleted, then: stop $name --expect 1");
            }
        }
    }

    /** The state of each changed container from one inspect call, with `verified` for what the verb meant to do. */
    private static function readBackAll(DockerCli $d, $verb, array $inv, array $ids)
    {
        $now = [];
        if ($ids) {
            foreach (DockerCli::jsonLines(self::call($d, array_merge(['inspect', '--format', '{{json .}}'], $ids))['out']) as $obj) {
                $now[isset($obj['Id']) ? (string) $obj['Id'] : ''] = $obj;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $before = DockerRefs::readBack(self::find($inv, $id));
            if (!isset($now[$id])) { // gone: only an auto-remove container that was stopped may be
                $out[] = ['id' => $id, 'name' => $before['name'], 'action' => $verb, 'state' => 'removed',
                    'verified' => $verb === 'stop' && !empty(self::find($inv, $id)['HostConfig']['AutoRemove'])];
                continue;
            }
            $r = DockerRefs::readBack($now[$id]);
            $ok = $verb === 'stop' ? $r['running'] === false
                : ($r['running'] === true && ($verb === 'start' || $r['started_at'] !== $before['started_at']));
            $out[] = ['action' => $verb] + $r + ['verified' => $ok];
        }
        return $out;
    }

    /** Remove's read-back: one inspect; a container still there is not verified. */
    private static function removed(DockerCli $d, array $inv, array $ids)
    {
        $left = [];
        if ($ids) {
            foreach (DockerCli::jsonLines(self::call($d, array_merge(['inspect', '--format', '{{json .}}'], $ids))['out']) as $obj) {
                $left[] = isset($obj['Id']) ? (string) $obj['Id'] : '';
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $i = self::find($inv, $id);
            $labels = isset($i['Config']['Labels']) && is_array($i['Config']['Labels']) ? $i['Config']['Labels'] : [];
            $out[] = ['id' => $id, 'name' => self::nameOf($i), 'image' => self::str(isset($i['Config']) ? $i['Config'] : [], 'Image'), 'removed' => true,
                'verified' => !in_array($id, $left, true), 'env_file' => isset($labels[self::ENV_FILE_LABEL]) ? (string) $labels[self::ENV_FILE_LABEL] : null];
        }
        return $out;
    }

    /** The verb ran out of its 150 s: what was done, and what was not tried. */
    private static function partial(array $records, array $inv, array $notTried)
    {
        $names = [];
        foreach ($notTried as $id) {
            $names[] = self::nameOf(self::find($inv, $id));
        }
        return ['records' => $records, 'status' => ['complete' => false, 'remaining' => $names]];
    }

    /** A docker failure in the middle: stop there and say what was done and what was not tried. */
    private static function failed($verb, $name, array $r, array $inv, array $done, array $notTried)
    {
        $names = function (array $ids) use ($inv) {
            $n = [];
            foreach ($ids as $id) {
                $n[] = self::nameOf(self::find($inv, $id));
            }
            return $n ? implode(', ', $n) : 'none';
        };
        $err = $r['timed_out'] ? 'docker did not answer in time' : (string) self::firstLine($r['err']);
        return new CliError("$verb failed for $name: $err; done before it: " . $names($done) . '; not tried: ' . $names($notTried), 'run `list --all` to see the state now, and `diagnose ' . $name . '`');
    }

    /** ['images' => every image record, 'remove' => what prune would remove: dangling and unused (with $all: every unused image)]. */
    private static function imagePlan(DockerCli $d, $all)
    {
        $images = self::imageRows($d);
        $ps = self::call($d, ['ps', '--all', '--format', '{{json .}}']);
        if ($ps['code'] !== 0) {
            throw new CliError('docker could not list containers, so which images are in use is unknown; nothing was done', 'run `diagnose`');
        }
        $used = [];
        foreach (DockerCli::jsonLines($ps['out']) as $row) {
            $used[] = self::normImage(self::str($row, 'Image'));
        }
        $remove = [];
        foreach ($images as $k => $img) {
            $inUse = false;
            foreach ($used as $u) {
                $hex = preg_replace('/^sha256:/', '', $u);
                $inUse = $inUse || ($img['dangling'] ? false : $u === self::normImage($img['ref']))
                    || (ctype_xdigit($hex) && strlen($hex) >= 12 && (strncmp($hex, $img['id'], strlen($img['id'])) === 0 || strncmp($img['id'], $hex, strlen($hex)) === 0));
            }
            $images[$k]['in_use'] = $inUse;
            if (!$inUse && ($img['dangling'] || $all) && $img['valid']) {
                $remove[] = $images[$k];
            }
        }
        $strip = function ($img) {
            unset($img['valid']);
            return $img;
        };
        return ['images' => array_map($strip, $images), 'remove' => array_map($strip, $remove)];
    }

    /** `docker images` as records; ref is what rmi is given: repository:tag, or the ID for a dangling image. */
    private static function imageRows(DockerCli $d)
    {
        $r = self::call($d, ['images', '--format', '{{json .}}']);
        if ($r['code'] !== 0) {
            throw new CliError('docker could not list images: ' . ($r['timed_out'] ? 'no answer in time' : (string) self::firstLine($r['err'])) . '; nothing was done', 'run `diagnose`');
        }
        $out = [];
        foreach (DockerCli::jsonLines($r['out']) as $row) {
            $id = preg_replace('/^sha256:/', '', self::str($row, 'ID'));
            $repo = self::str($row, 'Repository');
            $tag = self::str($row, 'Tag');
            $dangling = $repo === null || $repo === '<none>' || $tag === null || $tag === '<none>';
            $ref = $dangling ? $id : "$repo:$tag";
            $digest = self::str($row, 'Digest');
            $out[] = ['id' => $id, 'repository' => $repo, 'tag' => $tag, 'ref' => $ref, 'size' => self::str($row, 'Size'), 'created_at' => self::str($row, 'CreatedAt'),
                'dangling' => $dangling, 'in_use' => false,
                'rebuildable' => !$dangling && $digest !== null && $digest !== '' && $digest !== '<none>', // pulled from a registry, so it can be pulled again
                'valid' => $dangling ? (bool) preg_match('/^[0-9a-f]{12,64}$/', (string) $id) : DockerRefs::validImage($ref)];
        }
        return $out;
    }

    /** nginx -> nginx:latest; docker.io/library/ prefixes dropped, as `docker images` prints them. */
    private static function normImage($image)
    {
        $image = preg_replace('#^(docker\.io/)?(library/)?#', '', (string) $image);
        if ($image !== '' && strpos($image, '@') === false && strpos((string) substr($image, (int) strrpos($image, '/')), ':') === false && !preg_match('/^(sha256:)?[0-9a-f]{12,64}$/', $image)) {
            $image .= ':latest';
        }
        return $image;
    }

    /** Who can reach a published port here: shared tenancy and the provider's dropped ports. */
    private static function exposureFacts(DockerCli $d)
    {
        $passwd = isset($d->seams['passwd']) ? (string) $d->seams['passwd'] : '/etc/passwd';
        return DockerExposure::facts(Detect::sharedTenancy($d->pmss(), $passwd), $d->facts());
    }

    /** provider, provider_daemon, source, verified from Host::TABLE (nulls where the provider documents nothing). */
    private static function provider(DockerCli $d)
    {
        $dir = (string) $d->seams['pmss_dir'];
        $stack = Host::stack($dir);
        $f = $d->facts();
        return ['provider' => $stack !== 'none' ? $stack : Host::family($dir), 'provider_daemon' => $f ? $f['daemon'] : null,
            'source' => $f ? $f['source'] : null, 'verified' => $f ? $f['verified'] : null];
    }

    /** One `docker stats` row: CPU and memory of a container; a running container sampled at 0 bytes is unknown (null). */
    private static function usage(array $row)
    {
        $mem = trim(explode('/', (string) self::str($row, 'MemUsage'))[0]);
        $zero = $mem === '' || preg_match('/^0(\.0+)?\s*[KMGT]?i?B$/', $mem);
        $pct = function ($v) {
            return is_string($v) && preg_match('/^([0-9.]+)%$/', trim($v), $m) ? (float) $m[1] : null;
        };
        return ['kind' => 'container', 'name' => self::str($row, 'Name'), 'cpu_percent' => $zero ? null : $pct(self::str($row, 'CPUPerc')),
            'memory' => $zero ? null : $mem, 'memory_percent' => $zero ? null : $pct(self::str($row, 'MemPerc')),
            'pids' => isset($row['PIDs']) && ctype_digit((string) $row['PIDs']) ? (int) $row['PIDs'] : null];
    }

    /** Bytes of the container's log file, or null when the driver keeps none here or it cannot be read. */
    private static function logBytes(array $i)
    {
        $type = isset($i['HostConfig']['LogConfig']['Type']) ? (string) $i['HostConfig']['LogConfig']['Type'] : '';
        $path = isset($i['LogPath']) ? (string) $i['LogPath'] : '';
        if ($path === '' || !in_array($type, ['json-file', 'local', ''], true)) {
            return null;
        }
        $size = @filesize($path);
        return $size === false ? null : (int) $size;
    }

    /** Labels as "key=value" text; a key that looks like it holds a secret is printed without its value. */
    private static function labelsText(array $labels)
    {
        $out = [];
        foreach ($labels as $k => $v) {
            $out[] = preg_match(self::LABEL_HIDDEN_RE, (string) $k) ? (string) $k : $k . '=' . (is_scalar($v) ? (string) $v : '');
        }
        return $out;
    }

    /** Stdout or stderr of `docker logs --timestamps` as [time|null, text] lines. */
    private static function logLines($text, $stream)
    {
        if (rtrim((string) $text, "\r\n") === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", rtrim((string) $text, "\r\n")) as $line) {
            $line = rtrim($line, "\r");
            $time = null;
            if (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)) (.*)$/s', $line, $m)) {
                $time = $m[1];
                $line = $m[2];
            }
            $out[] = ['stream' => $stream, 'time' => $time, 'log_text' => substr($line, 0, self::LOG_TEXT_MAX)];
        }
        return $out;
    }

    /** Both streams in time order (each is already in order); without timestamps, stdout first. */
    private static function mergeLogs(array $a, array $b)
    {
        $key = function ($t) {
            return preg_match('/^(.{19})(?:\.(\d+))?(.*)$/', (string) $t, $m) ? $m[1] . str_pad(substr($m[2], 0, 9), 9, '0') . $m[3] : (string) $t;
        };
        $out = [];
        while ($a && $b) {
            $out[] = $a[0]['time'] !== null && $b[0]['time'] !== null && strcmp($key($b[0]['time']), $key($a[0]['time'])) < 0 ? array_shift($b) : array_shift($a);
        }
        return array_merge($out, $a, $b);
    }

    /** The integer --expect, or a refusal before any docker call. */
    private static function expect(array $opt, $hint)
    {
        $e = isset($opt['expect']) && is_string($opt['expect']) ? trim($opt['expect']) : '';
        if ($e === '') {
            throw new CliError('nothing removed: --expect is missing', $hint);
        }
        if (!ctype_digit($e)) {
            throw new CliError("nothing removed: --expect takes a count, not '" . substr($e, 0, 40) . "'", $hint);
        }
        return (int) $e;
    }

    public static function refList(array $inv)
    {
        $out = [];
        foreach ($inv as $c) {
            if ($c['inspect']) {
                $out[] = ['id' => (string) $c['inspect']['Id'], 'name' => ltrim((string) $c['inspect']['Name'], '/')];
            }
        }
        return $out;
    }

    public static function find(array $inv, $id)
    {
        foreach ($inv as $c) {
            if ($c['inspect'] && $c['inspect']['Id'] === $id) {
                return $c['inspect'];
            }
        }
        return [];
    }

    public static function nameOf(array $i)
    {
        return isset($i['Name']) ? ltrim((string) $i['Name'], '/') : (isset($i['Id']) ? substr((string) $i['Id'], 0, 12) : '?');
    }

    private static function one($verb, array $args)
    {
        if (count($args) !== 1) {
            throw new CliError("$verb takes one container", "$verb <name>; names come from `list --all`");
        }
        return (string) $args[0];
    }

    private static function noArgs($verb, array $args)
    {
        if ($args) {
            throw new CliError("$verb takes no container names; nothing was done", 'see `php docker.php --help`');
        }
    }

    private static function firstJson(array $r)
    {
        $rows = $r['code'] === 0 ? DockerCli::jsonLines($r['out']) : [];
        return $rows ? $rows[0] : [];
    }

    private static function firstLine($text)
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
        return $lines && $lines[0] !== '' ? substr($lines[0], 0, Cli::TEXT_MAX) : null;
    }

    private static function str(array $row, $key)
    {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : null;
    }

    private static function int(array $row, $key)
    {
        return isset($row[$key]) && ctype_digit((string) $row[$key]) ? (int) $row[$key] : null;
    }
}
