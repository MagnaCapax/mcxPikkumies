<?php
/**
 * DockerDiagnose: the `diagnose` verb (ADR-0034, USAGE.md). Four forms, one at a time:
 * - no argument: the daemon ladder (DockerCli::ladder), then every container with a problem, who can reach its ports,
 *   and the inventory events the scheduled check stored, most severe first (a 20-record page shows the worst).
 * - `<container>`: what its exit code means, restart count, oom (unknown unless limits are enforced), image
 *   architecture against the host's, bind folders whose files belong to the account's sub-uids, the cause of
 *   "address already in use", and the last log lines.
 * - `--compose <file>`: a fixed list of fields from `docker compose config` and what launch would refuse. Env values
 *   are never printed: names only, and every value of `environment` and of the project's `.env` is redacted.
 * - `--changes`: the scheduled form (a no_agent Hermes job). Silent unless something changed; writes only
 *   local/containers/state.json and local/notices/docker.txt, and only from a healthy answer.
 * On PMSS the platform owns the daemon: nothing here stops, restarts or kills it or prints a kill command; a wedged
 * daemon points at the provider's recovery page, and `watchdog_processes` shows afterwards whether any process the
 * platform's watchdog counts as a running daemon is left. Reads only; a manual run only marks stored events seen.
 * Seams (DockerCli's, plus): budget_seconds (the verb's whole budget), subuid_file, passwd (tenancy), shared.
 */
require_once dirname(__DIR__, 3) . '/lib/Detect.php';

class DockerDiagnose
{
    const LOG_LINES = 10;
    const LOG_BYTES = 2048;
    const CHUNK = 100;          // containers per inspect call
    const EVENTS_MAX = 20;      // events kept in the state file
    const SCAN_MAX = 200;       // entries of a bind folder checked for sub-uid owners
    const NOTICE_BYTES = 120;   // = HermesConfig::NOTICE_BYTES, the most SOUL.md shows of one notice

    /** kind => [rank, severity]. Rank follows the plan's order: daemon > inventory > socket mount > public > shared > outside home > privileged > crash > info. */
    const KINDS = [
        'daemon' => [0, 'critical'], 'leftover-process' => [0, 'critical'], 'inventory-gone' => [1, 'critical'], 'socket-mount' => [2, 'critical'],
        'public-port' => [3, 'warning'], 'shared-port' => [4, 'warning'], 'bind-outside-home' => [5, 'warning'], 'bind-denied' => [5, 'warning'],
        'bind-bad-chars' => [5, 'warning'], 'env-file-readable' => [5, 'warning'], 'privileged' => [6, 'warning'], 'host-network' => [6, 'warning'],
        'cap-add' => [6, 'warning'], 'devices' => [6, 'warning'], 'crash' => [7, 'warning'], 'restart-loop' => [7, 'warning'], 'unhealthy' => [7, 'warning'],
        'oom' => [7, 'warning'], 'port-in-use' => [7, 'warning'], 'chown-lockout' => [7, 'warning'], 'arch-mismatch' => [7, 'warning'],
        'stopped' => [7, 'warning'], 'restarting' => [7, 'warning'], 'image-not-local' => [7, 'warning'], 'bind-missing' => [7, 'warning'],
        'signal-stop' => [8, 'info'], 'created' => [8, 'info'], 'build' => [8, 'info'], 'incomplete' => [8, 'info'],
    ];

    public static function diagnose(DockerCli $d, array $args, array $opt)
    {
        $forms = (count($args) > 0 ? 1 : 0) + (isset($opt['compose']) ? 1 : 0) + (!empty($opt['changes']) ? 1 : 0);
        if ($forms > 1 || count($args) > 1) {
            throw new CliError('give one of: nothing, one container, --compose <file> or --changes; nothing was done', 'e.g. `diagnose`, `diagnose web-1`, `diagnose --compose ~/docker/app/compose.yaml`');
        }
        if (!empty($opt['changes'])) {
            self::changes($d); // exits
        }
        if (isset($opt['compose'])) {
            return self::compose($d, $opt['compose']);
        }
        return $args ? self::container($d, (string) $args[0]) : self::overview($d);
    }

    // ---------------------------------------------------------------- shared helpers

    /** The verb's budget in seconds: 30 for the scheduled form, 150 otherwise (seam budget_seconds for tests). */
    public static function budget(DockerCli $d, $scheduled)
    {
        if (isset($d->seams['budget_seconds'])) {
            return (float) $d->seams['budget_seconds'];
        }
        return (float) ($scheduled ? DockerCli::SCHEDULED_SECONDS : DockerCli::BUDGET_SECONDS);
    }

    /** One call whose time limit never runs past the budget; null when the budget is spent (the call is not made). */
    private static function call(DockerCli $d, array $argv, $max, $budget)
    {
        $left = (int) floor($d->remaining($budget));
        if ($left < 1) {
            return null;
        }
        return $d->run($argv, min($max, $left));
    }

    /** The API probe within the budget: ['ok', 'timed_out']. A daemon answers only when the Server part is there. */
    private static function probe(DockerCli $d, $budget)
    {
        $r = self::call($d, ['version', '--format', '{{json .}}'], DockerCli::PROBE_SECONDS, $budget);
        $v = $r ? DockerCli::jsonLines($r['out']) : [];
        return ['ok' => $r !== null && $r['code'] === 0 && isset($v[0]['Server']) && is_array($v[0]['Server']), 'timed_out' => $r === null || $r['timed_out']];
    }

    /** `docker info` as an array, or null. */
    private static function info(DockerCli $d, $budget)
    {
        $r = self::call($d, ['info', '--format', '{{json .}}'], DockerCli::CALL_SECONDS, $budget);
        $rows = $r && $r['code'] === 0 ? DockerCli::jsonLines($r['out']) : [];
        return $rows ? $rows[0] : null;
    }

    /** `ps -a` rows, or null when docker did not answer. */
    private static function ps(DockerCli $d, $budget)
    {
        $r = self::call($d, ['ps', '-a', '--format', '{{json .}}'], DockerCli::CALL_SECONDS, $budget);
        return $r && $r['code'] === 0 ? DockerCli::jsonLines($r['out']) : null;
    }

    /** Inspect objects for $ids in chunks: [objects, complete]. */
    private static function inspectAll(DockerCli $d, array $ids, $budget)
    {
        $out = [];
        foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
            $r = self::call($d, array_merge(['inspect', '--format', '{{json .}}'], $chunk), DockerCli::CALL_SECONDS, $budget);
            if ($r === null || $r['timed_out']) {
                return [$out, false];
            }
            $out = array_merge($out, DockerCli::jsonLines($r['out']));
        }
        return [$out, true];
    }

    /** Short IDs from ps rows, only well-formed ones (they go to docker as arguments). */
    private static function psIds(array $rows)
    {
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['ID']) && preg_match('/^[0-9a-f]{12,64}$/', (string) $row['ID'])) {
                $ids[] = (string) $row['ID'];
            }
        }
        return $ids;
    }

    /**
     * Per-container limits are enforced (and per-container memory and oom are meaningful): a rootful daemon, or a
     * rootless one on cgroup v2 with the systemd driver; never with driver "none" or docker's own limit warnings.
     * A field docker did not give counts as not enforced.
     */
    public static function limitsEnforced($info)
    {
        if (!is_array($info) || !isset($info['CgroupDriver'], $info['CgroupVersion'])) {
            return false;
        }
        $rootless = in_array('name=rootless', isset($info['SecurityOptions']) ? (array) $info['SecurityOptions'] : [], true);
        foreach (isset($info['Warnings']) ? (array) $info['Warnings'] : [] as $w) {
            if (preg_match('/no (memory|swap|cpu|pids|kernel memory)[a-z ]*(limit|support)|no cpu cfs/i', (string) $w)) {
                return false;
            }
        }
        if ($info['CgroupDriver'] === 'none') {
            return false;
        }
        return !$rootless || ((string) $info['CgroupVersion'] === '2' && $info['CgroupDriver'] === 'systemd');
    }

    /** Exposure facts for this host: shared tenancy (seam `shared`, else PMSS or more than one human account) and the provider's dropped ports. */
    private static function exposureFacts(DockerCli $d)
    {
        $shared = array_key_exists('shared', $d->seams) ? (bool) $d->seams['shared']
            : Detect::sharedTenancy($d->pmss(), isset($d->seams['passwd']) ? $d->seams['passwd'] : '/etc/passwd');
        return DockerExposure::facts($shared, $d->facts());
    }

    /** A finding record; $rank/$severity override the kind's. Records are sorted by rank, then by the order found. */
    private static function finding($kind, array $fields, $rank = null, $severity = null)
    {
        $k = isset(self::KINDS[$kind]) ? self::KINDS[$kind] : [8, 'info'];
        return ['kind' => $kind, 'severity' => $severity !== null ? $severity : $k[1]] + $fields + ['_rank' => $rank !== null ? $rank : $k[0]];
    }

    /** Most severe first; equal ranks keep the order they were found in. */
    public static function sortFindings(array $recs)
    {
        $keyed = [];
        foreach (array_values($recs) as $i => $r) {
            $keyed[] = [isset($r['_rank']) ? $r['_rank'] : 8, $i, $r];
        }
        usort($keyed, function ($a, $b) {
            return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
        });
        return array_map(function ($k) {
            unset($k[2]['_rank']);
            return $k[2];
        }, $keyed);
    }

    /** What an exit code means, or null while it runs. 137/143 are signal stops, not crashes. */
    public static function exitMeaning($code, $state)
    {
        if ($state === 'running' || $state === 'restarting' || $code === null) {
            return null;
        }
        $code = (int) $code;
        if ($code === 0) {
            return 'finished normally';
        }
        if ($code === 137 || $code === 143) {
            return 'stopped by a signal (' . ($code === 137 ? 'SIGKILL: docker stop after its wait, docker kill, or the kernel when memory ran out' : 'SIGTERM: docker stop')
                . '); often on purpose, also after a server move; not a crash';
        }
        $known = [125 => 'docker could not create or start it (see error_text)', 126 => 'its command could not be run (not executable)', 127 => 'its command was not found in the image'];
        if (isset($known[$code])) {
            return $known[$code];
        }
        if ($code > 128 && $code < 160) {
            return 'stopped by signal ' . ($code - 128);
        }
        return "the app exited with an error (code $code): read log_tail_text";
    }

    /** True when $mount is a unix socket being handed to the container (docker.sock or any *.sock or socket file). */
    private static function isSocketMount(array $m)
    {
        $src = isset($m['Source']) ? (string) $m['Source'] : '';
        $dst = isset($m['Destination']) ? (string) $m['Destination'] : '';
        foreach ([$src, $dst] as $p) {
            if ($p !== '' && (preg_match('/\.sock$|docker\.sock/', $p) || @filetype($p) === 'socket')) {
                return true;
            }
        }
        return false;
    }

    /** $path is $home or inside it (both already resolved). */
    private static function inside($path, $home)
    {
        return $home !== '' && ($path === $home || strpos($path, rtrim($home, '/') . '/') === 0);
    }

    // ---------------------------------------------------------------- findings from one inspect object

    /**
     * Problems of one container, from its inspect object alone: socket mounts, public and shared ports, writable
     * binds outside the home, privileged, host network, crash, restart loop, health, oom (only when limits are
     * enforced), "address already in use", signal stops and never-started containers. $ctx: facts (exposure), home,
     * limits_enforced, pmss_ports (the provider's port window or null).
     */
    public static function containerFindings(array $i, array $ctx)
    {
        $name = isset($i['Name']) ? ltrim((string) $i['Name'], '/') : null;
        $id = isset($i['Id']) ? substr((string) $i['Id'], 0, 12) : null;
        $who = ['name' => $name, 'id' => $id];
        $s = isset($i['State']) && is_array($i['State']) ? $i['State'] : [];
        $hc = isset($i['HostConfig']) && is_array($i['HostConfig']) ? $i['HostConfig'] : [];
        $state = isset($s['Status']) ? (string) $s['Status'] : null;
        $out = [];
        $home = (string) $ctx['home'];
        $sockets = [];
        $outside = [];
        foreach (isset($i['Mounts']) && is_array($i['Mounts']) ? $i['Mounts'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (self::isSocketMount($m)) {
                $sockets[] = isset($m['Destination']) ? (string) $m['Destination'] : null;
                continue;
            }
            $src = isset($m['Source']) ? (string) $m['Source'] : '';
            $rw = array_key_exists('RW', $m) ? $m['RW'] : 'unknown';
            if ((!isset($m['Type']) || $m['Type'] === 'bind') && $src !== '' && $rw !== false) {
                $real = realpath($src);
                if (!self::inside($real !== false ? $real : $src, $home)) {
                    $outside[] = ['source' => $src, 'destination' => isset($m['Destination']) ? (string) $m['Destination'] : null, 'rw' => $rw];
                }
            }
        }
        if ($sockets) {
            $out[] = self::finding('socket-mount', $who + ['destinations' => $sockets,
                'next_step' => 'this container can control the Docker daemon, so every container and file of this account; tell the user, keep it only if they meant that']);
        }
        $out = array_merge($out, self::exposureFindings($who, DockerExposure::ports($i, $ctx['facts']), ''));
        if ($outside) {
            $out[] = self::finding('bind-outside-home', $who + ['mounts' => $outside, 'next_step' => 'a writable folder outside the home is mounted; tell the user which']);
        }
        if (isset($hc['Privileged']) && $hc['Privileged'] === true) {
            $out[] = self::finding('privileged', $who + ['next_step' => 'runs privileged: it can do anything this account can; tell the user']);
        }
        if (isset($hc['NetworkMode']) && $hc['NetworkMode'] === 'host') {
            $out[] = self::finding('host-network', $who + ['next_step' => 'shares the server\'s network: every port it opens is reachable as if this account opened it; tell the user']);
        }
        $code = array_key_exists('ExitCode', $s) ? $s['ExitCode'] : null;
        $restarts = isset($i['RestartCount']) ? (int) $i['RestartCount'] : 0;
        $error = isset($s['Error']) && is_string($s['Error']) && $s['Error'] !== '' ? $s['Error'] : null;
        if ($error !== null && stripos($error, 'address already in use') !== false) {
            $out[] = self::finding('port-in-use', $who + self::portCause($error, $i, $ctx));
        }
        if ($state === 'restarting' || !empty($s['Restarting']) || $restarts >= 3) {
            $out[] = self::finding('restart-loop', $who + ['state' => $state, 'restart_count' => $restarts, 'next_step' => "it keeps restarting: `diagnose $name` for the exit meaning and the last log lines"]);
        } elseif ($state === 'dead' || ($state === 'exited' && $code !== null && !in_array((int) $code, [0, 137, 143], true))) {
            $out[] = self::finding('crash', $who + ['state' => $state, 'exit_code' => $code, 'exit_meaning' => self::exitMeaning($code, $state),
                'next_step' => "`diagnose $name` for the last log lines; do not start it again on your own"]);
        }
        if (isset($s['Health']['Status']) && $s['Health']['Status'] === 'unhealthy') {
            $out[] = self::finding('unhealthy', $who + ['next_step' => "its own health check fails: `diagnose $name`"]);
        }
        if (!empty($ctx['limits_enforced']) && isset($s['OOMKilled']) && $s['OOMKilled'] === true) {
            $out[] = self::finding('oom', $who + ['next_step' => 'it was killed for memory: the account\'s memory is shared with its other programs']);
        }
        if ($state === 'exited' && in_array((int) $code, [137, 143], true)) {
            $out[] = self::finding('signal-stop', $who + ['exit_code' => (int) $code, 'next_step' => 'stopped by a signal, often on purpose (also after a server move); not a crash; do not start it on your own']);
        } elseif ($state === 'created') {
            $out[] = self::finding('created', $who + ['next_step' => "created but never started (a failed launch leaves one): `diagnose $name`"]);
        }
        return $out;
    }

    /** One public-port and one shared-port finding for the bindings of that exposure; $publicHint is added to the public one. */
    private static function exposureFindings(array $who, array $ports, $publicHint)
    {
        $out = [];
        foreach (['public' => 'public-port', 'local-shared' => 'shared-port'] as $exp => $kind) {
            $hit = array_values(array_filter($ports, function ($p) use ($exp) {
                return $p['exposure'] === $exp;
            }));
            if ($hit) {
                $out[] = self::finding($kind, $who + ['ports' => $hit, 'next_step' => DockerExposure::SENTENCE[$exp] . ($exp === 'public' ? $publicHint : '')]);
            }
        }
        return $out;
    }

    /**
     * The cause of "address already in use" from State.Error: the port, and on the provider's port window the
     * kernel lending ports above the allocator to outgoing connections for a while (PMSS ADR-0026).
     */
    public static function portCause($error, array $i, array $ctx)
    {
        $port = null;
        if (preg_match('/:(\d{1,5}): bind: address already in use/i', (string) $error, $m)) {
            $port = (int) $m[1];
        } else {
            $ports = DockerExposure::ports($i, ['shared' => false, 'inbound_dropped' => []]);
            $port = $ports ? $ports[0]['host_port'] : null;
        }
        $window = isset($ctx['pmss_ports']) ? $ctx['pmss_ports'] : null;
        $lent = $window && $port !== null && $port >= (int) $window[0];
        return ['host_port' => $port, 'error_text' => (string) $error,
            'port_cause' => $lent
                ? 'an outgoing connection on this server holds that port for a while (the platform lets the kernel use ports above its allocator for them)'
                : 'another program on this server listens on that port',
            'next_step' => $lent ? 'wait a few minutes and start it again, or launch it again on another port' : 'start it again later, or launch it again on another port'];
    }

    // ---------------------------------------------------------------- no argument

    private static function overview(DockerCli $d)
    {
        $budget = self::budget($d, false);
        $recs = self::storedEvents($d);
        $status = [];
        $a = $d->absence();
        if ($a['state'] === 'cli-missing' || $a['state'] === 'refused') {
            $recs[] = self::finding('daemon', ['state' => $a['state'], 'reason' => $a['reason'], 'next_step' => $a['hint']]);
            return ['records' => self::sortFindings($recs), 'status' => ['daemon' => $a['state']]];
        }
        $probe = $a['state'] === 'ok' ? self::probe($d, $budget) : ['ok' => false, 'timed_out' => false];
        $ladder = DockerCli::ladder($d->observe($probe));
        $procs = $d->ownDaemonProcs();
        $daemon = ['state' => $ladder['state']] + ($a['state'] === 'socket-missing' ? ['socket' => 'missing'] : [])
            + array_diff_key($ladder, ['state' => 1]) + ($d->pmss() ? ['watchdog_processes' => $procs['watchdog_alive']] : []);
        $status['daemon'] = $ladder['state'];
        if ($ladder['state'] === 'healthy') {
            $recs[] = self::finding('daemon', $daemon, 9, 'ok');
        } else {
            $recs[] = self::finding('daemon', $daemon, 0, $ladder['state'] === 'cli-context-mismatch' ? 'warning' : 'critical');
        }
        $facts = $d->facts();
        if ($d->pmss() && in_array($ladder['state'], ['down', 'linger-missing'], true) && $procs['watchdog_alive'] > 0) {
            $recs[] = self::finding('leftover-process', ['watchdog_processes' => $procs['watchdog_alive'], 'needs_user_ok' => true,
                'next_step' => 'a process the platform\'s watchdog counts as a running daemon is still alive, so it will not start a new one: only on the user\'s yes, follow the platform\'s documented recovery ('
                    . $facts['recovery'] . '); it stops every container; afterwards run `diagnose` again and check that watchdog_processes is 0',
                'source' => $facts['source'], 'verified' => $facts['verified']]);
        }
        if (!$probe['ok']) {
            return ['records' => self::sortFindings($recs), 'status' => $status];
        }
        $info = self::info($d, $budget);
        $rows = self::ps($d, $budget);
        if ($rows === null) {
            $recs[] = self::finding('incomplete', ['next_step' => 'docker did not list the containers in time; run `diagnose` again']);
            return ['records' => self::sortFindings($recs), 'status' => $status];
        }
        list($inspects, $complete) = self::inspectAll($d, self::psIds($rows), $budget);
        $ctx = ['facts' => self::exposureFacts($d), 'home' => self::home($d), 'limits_enforced' => self::limitsEnforced($info),
            'pmss_ports' => isset($facts['ports']) ? $facts['ports'] : null];
        $running = 0;
        foreach ($inspects as $i) {
            $running += !empty($i['State']['Running']) ? 1 : 0;
            $recs = array_merge($recs, self::containerFindings($i, $ctx));
        }
        if (!$complete) {
            $recs[] = self::finding('incomplete', ['checked' => count($inspects), 'containers' => count($rows), 'next_step' => 'the time budget ran out; `diagnose <name>` checks one container']);
        }
        $status += ['containers' => count($rows), 'running' => $running, 'images' => isset($info['Images']) ? (int) $info['Images'] : null,
            'limits_enforced' => $ctx['limits_enforced']];
        return ['records' => self::sortFindings($recs), 'status' => $status];
    }

    private static function home(DockerCli $d)
    {
        $real = realpath($d->home);
        return $real !== false ? $real : $d->home;
    }

    // ---------------------------------------------------------------- one container

    private static function container(DockerCli $d, $ref)
    {
        $budget = self::budget($d, false);
        $d->requireHealthy();
        $info = self::info($d, $budget);
        $rows = self::ps($d, $budget);
        if ($rows === null) {
            throw new CliError('docker did not list the containers in time; nothing was checked', 'run `diagnose` for the daemon\'s state');
        }
        list($inspects) = self::inspectAll($d, self::psIds($rows), $budget);
        $list = [];
        $byId = [];
        foreach ($inspects as $i) {
            if (isset($i['Id'], $i['Name'])) {
                $list[] = ['id' => (string) $i['Id'], 'name' => ltrim((string) $i['Name'], '/')];
                $byId[(string) $i['Id']] = $i;
            }
        }
        $id = DockerRefs::resolve([$ref], $list)[0];
        $i = $byId[$id];
        $facts = $d->facts();
        $enforced = self::limitsEnforced($info);
        $ctx = ['facts' => self::exposureFacts($d), 'home' => self::home($d), 'limits_enforced' => $enforced, 'pmss_ports' => isset($facts['ports']) ? $facts['ports'] : null];
        $back = DockerRefs::readBack($i, $enforced);
        $findings = self::containerFindings($i, $ctx);
        $arch = self::arch($d, $i, $info, $budget);
        if ($arch['match'] === false) {
            $findings[] = self::finding('arch-mismatch', ['name' => $back['name'], 'image_arch' => $arch['image'], 'host_arch' => $arch['host'],
                'next_step' => 'the image is built for another processor: it fails or runs slowly under emulation; use an image for ' . $arch['host']]);
        }
        foreach (self::lockedMounts($d, $i) as $lock) {
            $findings[] = self::finding('chown-lockout', ['name' => $back['name']] + $lock + [
                'next_step' => 'files there belong to the container\'s sub-uids: this account cannot change or delete them, and copying them as the account skips them; change them from inside a container (its root is this account)']);
        }
        $summary = ['kind' => 'container', 'name' => $back['name'], 'id' => substr($id, 0, 12),
            'image' => isset($i['Config']['Image']) ? (string) $i['Config']['Image'] : null, 'state' => $back['state'], 'exit_code' => $back['exit_code'],
            'exit_meaning' => self::exitMeaning($back['exit_code'], $back['state']), 'restart_count' => $back['restart_count'],
            'restart_policy' => isset($i['HostConfig']['RestartPolicy']['Name']) ? (string) $i['HostConfig']['RestartPolicy']['Name'] : null,
            'health' => isset($i['State']['Health']['Status']) ? (string) $i['State']['Health']['Status'] : null, 'oom' => $back['oom'],
            'started_at' => $back['started_at'], 'finished_at' => $back['finished_at'], 'error_text' => $back['error_text'], 'arch' => $arch,
            'ports' => DockerExposure::ports($i, $ctx['facts'])] + self::logTail($d, $id, $budget);
        return ['records' => array_merge([$summary], self::sortFindings($findings)), 'status' => ['limits_enforced' => $enforced]];
    }

    /** Docker's machine names for one processor family (the host says x86_64, images say amd64). */
    public static function normalizeArch($a)
    {
        $a = strtolower(trim((string) $a));
        $map = ['x86_64' => 'amd64', 'x86-64' => 'amd64', 'aarch64' => 'arm64', 'armv8' => 'arm64', 'armv7l' => 'arm', 'armhf' => 'arm', 'armv6l' => 'arm', 'i686' => '386', 'i386' => '386'];
        return isset($map[$a]) ? $map[$a] : $a;
    }

    /** ['image' => arch|null, 'host' => arch|null, 'match' => bool|"unknown"]. */
    public static function archCompare($image, $host)
    {
        $im = $image === null || $image === '' ? null : self::normalizeArch($image);
        $ho = $host === null || $host === '' ? null : self::normalizeArch($host);
        return ['image' => $im, 'host' => $ho, 'match' => $im === null || $ho === null ? 'unknown' : $im === $ho];
    }

    private static function arch(DockerCli $d, array $i, $info, $budget)
    {
        $img = isset($i['Image']) ? (string) $i['Image'] : '';
        $imageArch = null;
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $img)) {
            $r = self::call($d, ['image', 'inspect', '--format', '{{json .}}', $img], DockerCli::CALL_SECONDS, $budget);
            $rows = $r && $r['code'] === 0 ? DockerCli::jsonLines($r['out']) : [];
            $imageArch = isset($rows[0]['Architecture']) ? (string) $rows[0]['Architecture'] : null;
        }
        return self::archCompare($imageArch, isset($info['Architecture']) ? (string) $info['Architecture'] : null);
    }

    /** The account's sub-uid ranges from /etc/subuid (by name or uid): [[first, last], ...]. */
    public static function subuidRanges($file, $name, $uid)
    {
        $out = [];
        foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $p = explode(':', trim((string) $line));
            if (count($p) === 3 && ($p[0] === (string) $name || $p[0] === (string) $uid) && ctype_digit($p[1]) && ctype_digit($p[2]) && (int) $p[2] > 0) {
                $out[] = [(int) $p[1], (int) $p[1] + (int) $p[2] - 1];
            }
        }
        return $out;
    }

    /** Bind sources whose own owner or entries' owners fall in the account's sub-uid range. */
    private static function lockedMounts(DockerCli $d, array $i)
    {
        $ranges = self::subuidRanges(isset($d->seams['subuid_file']) ? $d->seams['subuid_file'] : '/etc/subuid', Detect::userName($d->uid), $d->uid);
        if (!$ranges) {
            return [];
        }
        $inRange = function ($owner) use ($ranges) {
            foreach ($ranges as $r) {
                if ($owner >= $r[0] && $owner <= $r[1]) {
                    return true;
                }
            }
            return false;
        };
        $out = [];
        foreach (isset($i['Mounts']) && is_array($i['Mounts']) ? $i['Mounts'] : [] as $m) {
            $src = isset($m['Source']) ? (string) $m['Source'] : '';
            if (!is_array($m) || (isset($m['Type']) && $m['Type'] !== 'bind') || $src === '' || !file_exists($src) || self::isSocketMount($m)) {
                continue;
            }
            $paths = [$src];
            $all = true;
            $dh = is_dir($src) && !is_link($src) ? @opendir($src) : false;
            while ($dh && ($e = readdir($dh)) !== false) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                if (count($paths) > self::SCAN_MAX) { // a huge folder is sampled, never read whole
                    $all = false;
                    break;
                }
                $paths[] = "$src/$e";
            }
            if ($dh) {
                closedir($dh);
            }
            $n = 0;
            foreach ($paths as $p) {
                $st = @lstat($p);
                $n += $st && $inRange((int) $st['uid']) ? 1 : 0;
            }
            if ($n > 0) {
                $out[] = ['source' => $src, 'destination' => isset($m['Destination']) ? (string) $m['Destination'] : null, 'subuid_owned' => $n,
                    'checked' => count($paths), 'checked_all' => $all];
            }
        }
        return $out;
    }

    /** ['log_tail_text' => last lines (both streams, at most LOG_BYTES)] or a null with the reason. */
    private static function logTail(DockerCli $d, $id, $budget)
    {
        $r = self::call($d, ['logs', '--tail', (string) self::LOG_LINES, $id], DockerCli::CALL_SECONDS, $budget);
        if ($r === null || $r['code'] !== 0) {
            return ['log_tail_text' => null, 'log_note' => $r === null ? 'time budget spent' : 'docker could not read its log (a log driver without local reads?)'];
        }
        $lines = array_values(array_filter(preg_split('/\r?\n/', rtrim((string) $r['out'] . "\n" . (string) $r['err'])), 'strlen'));
        $text = implode("\n", array_slice($lines, -self::LOG_LINES));
        return ['log_tail_text' => strlen($text) > self::LOG_BYTES ? substr($text, -self::LOG_BYTES) : $text];
    }

    // ---------------------------------------------------------------- --compose

    private static function compose(DockerCli $d, $file)
    {
        if (!is_string($file) || $file === '') {
            throw new CliError('--compose needs a file; nothing was done', 'e.g. `diagnose --compose ~/docker/app/compose.yaml`');
        }
        $path = realpath($file);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new CliError("no readable compose file at $file; nothing was done", 'give the path of the compose.yaml (or docker-compose.yml)');
        }
        $budget = self::budget($d, false);
        $d->requireHealthy();
        $secrets = self::envFileValues(dirname($path) . '/.env');
        $r = self::call($d, ['compose', '-f', $path, 'config', '--format', 'json'], DockerCli::CALL_SECONDS, $budget);
        if ($r === null || $r['code'] !== 0) {
            $err = $r === null ? 'time budget spent' : (string) $r['err'];
            if (stripos($err, "'compose' is not a docker command") !== false) {
                throw new CliError('docker compose is not installed here (the compose plugin); nothing was checked', 'the user installs the compose plugin, or runs the containers one by one with launch-plan');
            }
            $first = preg_split('/\r?\n/', trim($err));
            throw new CliError('docker compose could not read the file: ' . self::redact(substr((string) $first[0], 0, 300), $secrets), 'fix the compose file; nothing was started');
        }
        $config = json_decode((string) $r['out'], true);
        if (!is_array($config) || !isset($config['services']) || !is_array($config['services'])) {
            throw new CliError('docker compose gave no services for this file; nothing was checked', 'check the file has a services: section');
        }
        $info = self::info($d, $budget);
        $local = self::localImages($d, $budget);
        $facts = self::exposureFacts($d);
        list($recs, $more) = self::composeProjection($config, ['facts' => $facts, 'home' => self::home($d), 'docker_root' => isset($info['DockerRootDir']) ? (string) $info['DockerRootDir'] : '',
            'local_images' => $local, 'uid' => $d->uid, 'pikkumies' => Config::root()]);
        return ['records' => self::redact(array_merge($recs, self::sortFindings($more)), $secrets), 'status' => ['services' => count($config['services'])]];
    }

    /** An image as `images` lists it: a bare repository means :latest; a digest reference stays as it is. */
    public static function imageRef($image)
    {
        $last = substr($image, (int) strrpos('/' . $image, '/'));
        return strpos($image, '@') !== false || strpos($last, ':') !== false ? $image : $image . ':latest';
    }

    /** Repository:tag names of local images, or null when unknown. */
    private static function localImages(DockerCli $d, $budget)
    {
        $r = self::call($d, ['images', '--format', '{{json .}}'], DockerCli::CALL_SECONDS, $budget);
        if ($r === null || $r['code'] !== 0) {
            return null;
        }
        $out = [];
        foreach (DockerCli::jsonLines($r['out']) as $row) {
            if (isset($row['Repository'], $row['Tag']) && $row['Repository'] !== '<none>') {
                $out[] = $row['Repository'] . ':' . $row['Tag'];
            }
        }
        return $out;
    }

    /**
     * [service records, findings]: one record per service (a fixed list of fields; env NAMES only; never command,
     * entrypoint, labels or values) and what launch would refuse or warn about. Every environment value found in the
     * config is redacted from both; the caller redacts the project .env file's values on top.
     */
    public static function composeProjection(array $config, array $ctx)
    {
        $recs = [];
        $more = [];
        $secrets = [];
        foreach ($config['services'] as $svc => $s) {
            if (!is_array($s)) {
                continue;
            }
            $env = isset($s['environment']) && is_array($s['environment']) ? $s['environment'] : [];
            $names = [];
            foreach ($env as $k => $v) {
                if (is_int($k)) { // list form "K=V"
                    $kv = explode('=', (string) $v, 2);
                    $names[] = $kv[0];
                    $secrets[] = isset($kv[1]) ? $kv[1] : '';
                } else {
                    $names[] = (string) $k;
                    $secrets[] = is_scalar($v) ? (string) $v : '';
                }
            }
            $who = ['service' => (string) $svc];
            $image = isset($s['image']) && is_string($s['image']) ? $s['image'] : null;
            $ports = [];
            foreach (isset($s['ports']) && is_array($s['ports']) ? $s['ports'] : [] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $published = isset($p['published']) ? (string) $p['published'] : '';
                $ip = isset($p['host_ip']) ? (string) $p['host_ip'] : '';
                $first = $published === '' ? 1 : (int) $published; // no published port: docker picks one on every interface
                $ports[] = ['host_ip' => $ip === '' ? '0.0.0.0' : $ip, 'host_port' => $published === '' ? 'random' : $published,
                    'container_port' => (isset($p['target']) ? (int) $p['target'] : 0) . '/' . (isset($p['protocol']) ? (string) $p['protocol'] : 'tcp'),
                    'exposure' => DockerExposure::classify($ip, $first, $ctx['facts'])];
            }
            $mounts = [];
            foreach (isset($s['volumes']) && is_array($s['volumes']) ? $s['volumes'] : [] as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $m = ['type' => isset($v['type']) ? (string) $v['type'] : null, 'source' => isset($v['source']) ? (string) $v['source'] : null,
                    'target' => isset($v['target']) ? (string) $v['target'] : null, 'read_only' => !empty($v['read_only'])];
                $mounts[] = $m;
                if ($m['type'] === 'bind' && $m['source'] !== null) {
                    $why = self::bindRefusal($m['source'], $ctx);
                    if ($why !== null) {
                        $more[] = self::finding($why[0], $who + ['source' => $m['source'], 'target' => $m['target'], 'next_step' => $why[1]]);
                    }
                }
            }
            $recs[] = ['kind' => 'compose-service'] + $who + ['image' => $image, 'build' => isset($s['build']), 'container_name' => isset($s['container_name']) ? (string) $s['container_name'] : null,
                'restart' => isset($s['restart']) ? (string) $s['restart'] : null, 'network_mode' => isset($s['network_mode']) ? (string) $s['network_mode'] : null,
                'privileged' => !empty($s['privileged']), 'cap_add' => isset($s['cap_add']) ? array_values(array_map('strval', (array) $s['cap_add'])) : [],
                'devices' => isset($s['devices']) ? count((array) $s['devices']) : 0, 'user' => isset($s['user']) ? (string) $s['user'] : null,
                'env_names' => $names, 'ports' => $ports, 'mounts' => $mounts];
            $more = array_merge($more, self::exposureFindings($who, $ports, '; publish on 127.0.0.1 ("127.0.0.1:PORT:PORT") unless the user wants it public'));
            if (!empty($s['privileged'])) {
                $more[] = self::finding('privileged', $who + ['next_step' => 'privileged: it can do anything this account can; launch never does this']);
            }
            if (isset($s['network_mode']) && $s['network_mode'] === 'host') {
                $more[] = self::finding('host-network', $who + ['next_step' => 'host network: every port it opens is reachable as if this account opened it; launch never does this']);
            }
            if (!empty($s['cap_add'])) {
                $more[] = self::finding('cap-add', $who + ['next_step' => 'adds kernel capabilities; tell the user; launch never does this']);
            }
            if (!empty($s['devices'])) {
                $more[] = self::finding('devices', $who + ['next_step' => 'passes host devices in; tell the user; launch never does this']);
            }
            if (isset($s['build'])) {
                $more[] = self::finding('build', $who + ['next_step' => 'compose builds this image from a folder; launch only runs local images']);
            } elseif ($image !== null && $ctx['local_images'] !== null && !in_array(self::imageRef($image), $ctx['local_images'], true)) {
                $more[] = self::finding('image-not-local', $who + ['image' => $image, 'next_step' => "`docker pull $image` first, if the user chose this image"]);
            }
            foreach (isset($s['env_file']) ? (array) $s['env_file'] : [] as $ef) {
                $efPath = is_array($ef) ? (isset($ef['path']) ? (string) $ef['path'] : '') : (string) $ef;
                $st = $efPath !== '' ? @lstat($efPath) : false;
                if ($st && (($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== (int) $ctx['uid'] || ($st['mode'] & 077))) {
                    $more[] = self::finding('env-file-readable', $who + ['env_file' => $efPath, 'next_step' => "`chmod 600 $efPath`: it holds passwords and must be this account's own regular file"]);
                }
            }
        }
        $secrets = array_values(array_filter($secrets, function ($v) {
            return strlen($v) >= 4;
        }));
        return [self::redact($recs, $secrets), self::redact($more, $secrets)];
    }

    /**
     * Why launch would refuse a bind source, or null: [kind, next_step]. Same rules as launch: no `,` `:` or newline,
     * no socket, not the home itself, not ~/.ssh, pikkumies, ~/.config/docker or Docker's own folder, inside the home, existing.
     */
    public static function bindRefusal($source, array $ctx)
    {
        if (preg_match('/[,:\n]/', $source)) {
            return ['bind-bad-chars', 'a folder name with , : or a newline cannot be passed safely; rename it'];
        }
        if (preg_match('/\.sock$|docker\.sock/', $source) || @filetype($source) === 'socket') {
            return ['socket-mount', 'mounts a socket: a container with the Docker socket controls every container and file of this account'];
        }
        $home = (string) $ctx['home'];
        $real = realpath($source);
        $p = $real !== false ? $real : $source;
        if ($p === $home) {
            return ['bind-denied', 'mounts the whole home: name a folder inside it instead'];
        }
        foreach ([$home . '/.ssh', $home . '/.config/docker', (string) $ctx['pikkumies'], (string) $ctx['docker_root']] as $deny) {
            if ($deny !== '' && self::inside($p, rtrim($deny, '/'))) {
                return ['bind-denied', 'mounts a folder that holds keys or Docker\'s own data; launch refuses it'];
            }
        }
        if (!self::inside($p, $home)) {
            return ['bind-outside-home', 'a folder outside the home; launch mounts only folders inside it'];
        }
        if ($real === false) {
            return ['bind-missing', "the folder does not exist; create it first (`mkdir -m 700 -p $source`), or docker creates it as the container wants"];
        }
        return null;
    }

    /** Values of a .env file (KEY=VALUE lines; comments, `export` and quotes handled), 4+ characters long, for redaction only. */
    public static function envFileValues($file)
    {
        $out = [];
        foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim(preg_replace('/^\s*export\s+/', '', rtrim((string) $line, "\r")));
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            $v = trim(explode('=', $line, 2)[1]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if (strlen($v) >= 4) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** Every string in $data with each of $secrets replaced by REDACTED (longest first). */
    public static function redact($data, array $secrets)
    {
        if (!$secrets) {
            return $data;
        }
        usort($secrets, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        if (is_string($data)) {
            return str_replace($secrets, 'REDACTED', $data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::redact($v, $secrets);
            }
        }
        return $data;
    }

    // ---------------------------------------------------------------- stored state and the notice

    private static function statePath(DockerCli $d)
    {
        return rtrim((string) $d->seams['local_dir'], '/') . '/containers/state.json';
    }

    private static function noticePath(DockerCli $d)
    {
        return rtrim((string) $d->seams['local_dir'], '/') . '/notices/docker.txt';
    }

    /** The state file, trusted only as a regular file this account owns holding a JSON object; else []. */
    public static function readState(DockerCli $d)
    {
        $f = self::statePath($d);
        clearstatcache(true, $f);
        $st = @lstat($f);
        if (!$st || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== $d->uid) {
            return [];
        }
        $s = json_decode((string) @file_get_contents($f, false, null, 0, 1048576), true);
        return is_array($s) ? $s : [];
    }

    /** Atomic 0600 write in a 0700 directory. */
    private static function writeFile($file, $body)
    {
        $dir = dirname($file);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        $tmp = $file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $body) === strlen($body);
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new CliError("cannot write $file", "check that $dir is writable");
        }
    }

    private static function writeState(DockerCli $d, array $s)
    {
        self::writeFile(self::statePath($d), json_encode($s, JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * The notice line for SOUL.md from unseen events: counts, a date and fixed words only (never a container name,
     * label or log text: it lands in the system prompt). '' when nothing is unseen.
     */
    public static function noticeText(array $events)
    {
        $parts = [];
        $since = null;
        $stopped = 0;
        $errors = 0;
        $restarting = 0;
        $gone = false;
        foreach ($events as $e) {
            if (!empty($e['viewed_at'])) {
                continue;
            }
            $since = $since === null || (string) $e['first_seen'] < $since ? (string) $e['first_seen'] : $since;
            if ($e['kind'] === 'inventory-gone') {
                $gone = true;
            } elseif ($e['kind'] === 'stopped') {
                $stopped += (int) $e['count'];
                $errors += isset($e['error_exits']) ? (int) $e['error_exits'] : 0;
            } elseif ($e['kind'] === 'restarting') {
                $restarting += (int) $e['count'];
            }
        }
        if ($gone) {
            $parts[] = 'all containers and images are gone';
        }
        if ($stopped) {
            $parts[] = $stopped . ' stopped' . ($errors ? " ($errors with an error exit)" : '');
        }
        if ($restarting) {
            $parts[] = $restarting . ' restarting';
        }
        if (!$parts) {
            return '';
        }
        $date = preg_match('/^\d{4}-(\d\d-\d\d)T(\d\d:\d\d)/', (string) $since, $m) ? " since {$m[1]} {$m[2]} UTC" : '';
        return substr('Docker check' . $date . ': ' . implode(', ', $parts) . '; run docker diagnose', 0, self::NOTICE_BYTES);
    }

    /** The notice file follows the unseen events: written while there are any, removed when there are none. */
    private static function syncNotice(DockerCli $d, array $events)
    {
        $text = self::noticeText($events);
        $f = self::noticePath($d);
        if ($text === '') {
            if (is_file($f) && !is_link($f) && @fileowner($f) === $d->uid) {
                @unlink($f);
            }
            return '';
        }
        self::writeFile($f, $text . "\n");
        return $text;
    }

    /**
     * Stored events as records (most recent kept, at most EVENTS_MAX); unseen ones are marked seen and the notice is
     * removed. Only viewed_at changes: a manual run never moves the baseline.
     */
    private static function storedEvents(DockerCli $d)
    {
        $s = self::readState($d);
        $events = isset($s['events']) && is_array($s['events']) ? $s['events'] : [];
        $recs = [];
        $changed = false;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($events as $n => $e) {
            if (!is_array($e) || !isset($e['kind'])) {
                continue;
            }
            $new = empty($e['viewed_at']);
            $fields = ['new' => $new, 'first_seen' => isset($e['first_seen']) ? $e['first_seen'] : null, 'last_seen' => isset($e['last_seen']) ? $e['last_seen'] : null,
                'as_of' => isset($s['as_of']) ? $s['as_of'] : null]
                + array_intersect_key($e, ['count' => 1, 'error_exits' => 1, 'containers_before' => 1, 'images_before' => 1])
                + ['next_step' => $e['kind'] === 'inventory-gone'
                    ? 'containers and images went from some to none between two checks: do not recreate or prune anything; if the user did not remove them, tell support'
                    : '`diagnose` lists the containers now; do not start them on your own'];
            $recs[] = self::finding((string) $e['kind'], $fields, $new ? null : 8, $new ? null : 'info');
            if ($new) {
                $events[$n]['viewed_at'] = $now;
                $changed = true;
            }
        }
        if ($changed) {
            $s['events'] = $events;
            self::writeState($d, $s);
        }
        self::syncNotice($d, $events);
        return $recs;
    }

    /** After this account's own remove or prune: the next scheduled check starts a new baseline (no inventory event from the user's own deletes). */
    public static function rebaseline(DockerCli $d)
    {
        $s = self::readState($d);
        if (!$s) {
            return false;
        }
        unset($s['as_of'], $s['containers'], $s['images'], $s['states']);
        self::writeState($d, $s);
        return true;
    }

    // ---------------------------------------------------------------- --changes (scheduled)

    /**
     * The scheduled form. Prints nothing and exits 0 unless a new event was stored (then the notice line, one line).
     * Silent when there is no own socket or no CLI. A tick that does not get a healthy answer from the probe, info and
     * ps within the 30 s budget writes nothing: the baseline comes only from healthy answers.
     */
    private static function changes(DockerCli $d)
    {
        if ($d->socket() === '' || $d->bin() === null) {
            exit(0);
        }
        $budget = self::budget($d, true);
        $probe = self::probe($d, $budget);
        $info = $probe['ok'] ? self::info($d, $budget) : null;
        $rows = is_array($info) ? self::ps($d, $budget) : null;
        if (!$probe['ok'] || !is_array($info) || $rows === null || !isset($info['Images'])) {
            exit(0); // unknown tick
        }
        $s = self::readState($d);
        $prev = isset($s['states']) && is_array($s['states']) ? $s['states'] : null;
        $states = [];
        foreach ($rows as $row) {
            if (isset($row['ID'], $row['State']) && preg_match('/^[0-9a-f]{12,64}$/', (string) $row['ID'])) {
                $states[substr((string) $row['ID'], 0, 12)] = (string) $row['State'];
            }
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $events = isset($s['events']) && is_array($s['events']) ? $s['events'] : [];
        $added = 0;
        if ($prev !== null && isset($s['containers'], $s['images'])) {
            $stopped = [];
            $restarting = 0;
            foreach ($states as $id => $st) {
                $was = isset($prev[$id]) ? $prev[$id] : null;
                if ($was === 'running' && in_array($st, ['exited', 'dead'], true)) {
                    $stopped[] = $id;
                }
                if ($st === 'restarting' && $was !== 'restarting') {
                    $restarting++;
                }
            }
            if ($stopped) {
                list($ins, $complete) = self::inspectAll($d, $stopped, $budget);
                if (!$complete) {
                    exit(0); // unknown tick: the budget ran out
                }
                $errors = 0;
                foreach ($ins as $i) {
                    $errors += isset($i['State']['ExitCode']) && !in_array((int) $i['State']['ExitCode'], [0, 137, 143], true) ? 1 : 0;
                }
                $added += self::addEvent($events, 'stopped', ['count' => count($stopped), 'error_exits' => $errors], $now);
            }
            if ($restarting) {
                $added += self::addEvent($events, 'restarting', ['count' => $restarting], $now);
            }
            if ((int) $s['containers'] > 0 && (int) $s['images'] > 0 && count($states) === 0 && (int) $info['Images'] === 0) {
                $added += self::addEvent($events, 'inventory-gone', ['containers_before' => (int) $s['containers'], 'images_before' => (int) $s['images']], $now);
            }
        }
        $s = ['version' => 1, 'as_of' => $now, 'containers' => count($states), 'images' => (int) $info['Images'], 'states' => $states,
            'events' => array_slice($events, -self::EVENTS_MAX)];
        self::writeState($d, $s);
        $text = self::syncNotice($d, $s['events']);
        if ($added && $text !== '') {
            echo $text, "\n";
        }
        exit(0);
    }

    /** Adds to the latest unseen event of $kind (counts summed, last_seen moved) or appends a new one; returns 1. */
    private static function addEvent(array &$events, $kind, array $fields, $now)
    {
        for ($n = count($events) - 1; $n >= 0; $n--) {
            if (isset($events[$n]['kind']) && $events[$n]['kind'] === $kind && empty($events[$n]['viewed_at'])) {
                foreach (['count', 'error_exits'] as $k) {
                    if (isset($fields[$k])) {
                        $events[$n][$k] = (isset($events[$n][$k]) ? (int) $events[$n][$k] : 0) + $fields[$k];
                    }
                }
                $events[$n]['last_seen'] = $now;
                return 1;
            }
        }
        $events[] = ['kind' => $kind] + $fields + ['first_seen' => $now, 'last_seen' => $now, 'viewed_at' => null];
        return 1;
    }
}
