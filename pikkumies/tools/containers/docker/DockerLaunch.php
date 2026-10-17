<?php
/**
 * DockerLaunch: launch-plan and launch (ADR-0034, USAGE.md). launch-plan checks everything a launch needs and prints
 * `can_run`, `blockers` (each a reason launch would refuse), `notes` and the exact `launch` line with concrete values;
 * it changes nothing. launch takes that line, runs every check again on the concrete values, then `docker run -d`
 * once per container, one after another, reading each back; it stops at the first failure, and past the verb's
 * budget it stops with `remaining` and the line that continues. No retry, no rollback, no directories created.
 *
 * Arguments are read the same way by both verbs, except that launch accepts concrete values only:
 *   --name   plan: one base name (`web`), numbered after the containers already named web / web-N; launch: the
 *            exact names, comma-separated (`web-4,web-5`).
 *   --port   plan: container ports (`80`), or `HOST:PORT` for one container; launch: `HOST:PORT` for every entry,
 *            container after container (each container's entries in turn).
 *   --volume plan: `SRC:DST[:ro]`, SRC may start with `~/` and hold `{n}` (the container's number); launch: absolute
 *            sources without `{n}`, container after container, as for --port.
 * Env values reach docker only through --env-file, a regular file this account owns with no group or other bits;
 * only the names are printed, and docker's error text is scrubbed of the values before it is shown.
 *
 * Seams (DockerCli seams, set by tests): passwd (for Detect::sharedTenancy), cgroup_base, self_cgroup, host_arch,
 * launch_budget (seconds instead of DockerCli::BUDGET_SECONDS).
 */
require_once dirname(__DIR__, 3) . '/lib/Detect.php';
require_once dirname(__DIR__, 3) . '/lib/Quota.php';

class DockerLaunch
{
    const MAX_COUNT = 50;            // containers per launch; 150 s does not start many more (INFERRED)
    const DEFAULT_PORT_BASE = 10000; // first host port tried where the provider names no window (INFERRED choice)
    const PORT_TRIES = 2000;         // host ports probed at most per plan
    const RESERVE_SECONDS = 20;      // a next container is started only with this much of the budget left
    const GROUP_LABEL = 'pikkumies.group';
    const ENV_FILE_LABEL = 'pikkumies.env_file'; // the path only; `remove` prints it back (DockerOps)
    const LOG_OPTS = ['max-size=10m', 'max-file=3'];
    const EADDRINUSE = 98;           // Linux

    /** launch-plan: one summary record (can_run, blockers, notes, launch), then one record per container. */
    public static function launchPlan(DockerCli $docker, array $args, array $opt)
    {
        $plan = self::prepare($docker, $args, $opt, false);
        $records = [[
            'can_run' => !$plan['blockers'],
            'blockers' => $plan['blockers'],
            'notes' => $plan['notes'],
            'count' => count($plan['specs']),
            'image' => $plan['image'],
            'env_file' => $plan['env_file'],
            'env_names' => $plan['env_names'],
            'launch' => $plan['blockers'] ? null : self::line($plan, $plan['specs']),
        ]];
        foreach ($plan['specs'] as $s) {
            $records[] = self::specRecord($s, $plan);
        }
        return $records;
    }

    /** launch: re-checks everything on the concrete values, then starts the containers one by one. */
    public static function launch(DockerCli $docker, array $args, array $opt)
    {
        $plan = self::prepare($docker, $args, $opt, true);
        if ($plan['blockers']) {
            throw new CliError('launch refused, nothing was started: ' . implode('; ', $plan['blockers']),
                'run launch-plan with the same image and settings again and show the user the new plan');
        }
        $budget = isset($docker->seams['launch_budget']) ? (float) $docker->seams['launch_budget'] : DockerCli::BUDGET_SECONDS;
        $records = [];
        $specs = $plan['specs'];
        foreach ($specs as $i => $s) {
            $left = $docker->remaining($budget);
            if ($left < self::RESERVE_SECONDS) {
                $rest = array_slice($specs, $i);
                return ['records' => $records, 'status' => ['started' => count($records), 'remaining' => count($rest),
                    'resume' => self::line($plan, $rest), 'hint' => 'time budget used up; run the resume line to start the rest']];
            }
            $r = $docker->run(self::runArgv($s, $plan), (int) max(5, min(DockerCli::CALL_SECONDS, floor($left - 10))));
            $id = trim((string) substr(trim((string) $r['out']), -64));
            if ($r['code'] !== 0 || !preg_match(DockerRefs::ID_RE, $id)) {
                throw self::failed($docker, $plan, $s, $records, $r['timed_out'] ? 'docker run timed out' : self::scrub((string) $r['err'], $plan['env_values']));
            }
            $back = $docker->run(['inspect', '--format', '{{json .}}', $id], DockerCli::PROBE_SECONDS);
            $rows = DockerCli::jsonLines($back['out']);
            $state = $back['code'] === 0 && $rows ? DockerRefs::readBack($rows[0], $plan['limits_enforced'] === true) : null;
            $rec = ['name' => $s['name'], 'id' => $id, 'group' => $plan['group_of'][$s['name']], 'labels' => self::labels($s, $plan)]
                + ($state ? array_diff_key($state, ['id' => 1, 'name' => 1]) : ['state' => 'unknown'])
                + ['verified' => $state !== null, 'ports' => self::portRecords($s, $plan), 'mounts' => self::mountRecords($s)];
            $records[] = $rec;
            if (!$state || $state['running'] !== true) {
                throw self::failed($docker, $plan, $s, array_slice($records, 0, -1), $state
                    ? "{$s['name']} was created but is " . $state['state'] . ' (exit code ' . json_encode($state['exit_code']) . '); see `diagnose ' . $s['name'] . '`'
                    : "{$s['name']} was created but could not be read back", true);
            }
        }
        return ['records' => $records, 'status' => ['started' => count($records), 'remaining' => 0]];
    }

    /**
     * Everything both verbs need: blockers, notes, the containers (specs) with concrete names, host ports and mounts,
     * the env file's names (values kept only to scrub docker's error text), and the facts the line and argv use.
     */
    private static function prepare(DockerCli $docker, array $args, array $opt, $concrete)
    {
        if ($args) {
            throw new CliError('launch-plan and launch take only options; nothing was done', 'example: launch-plan --image nginx:alpine --name web --count 3 --port 80');
        }
        foreach (['image', 'name'] as $k) {
            if (!isset($opt[$k]) || !is_string($opt[$k]) || $opt[$k] === '') {
                throw new CliError("--$k is required; nothing was done", 'example: launch-plan --image nginx:alpine --name web --count 3 --port 80');
            }
        }
        if ($concrete && isset($opt['host-port-base'])) {
            throw new CliError('--host-port-base belongs to launch-plan; launch takes the concrete host ports the plan printed; nothing was started', 'run the exact launch line launch-plan printed');
        }
        $count = isset($opt['count']) ? (string) $opt['count'] : null;
        if ($count !== null && (!ctype_digit($count) || (int) $count < 1 || (int) $count > self::MAX_COUNT)) {
            throw new CliError('--count must be a whole number from 1 to ' . self::MAX_COUNT . '; nothing was done', 'launch more in a second run; numbering continues');
        }
        $docker->requireHealthy();

        $blockers = [];
        $notes = [];
        $home = self::real($docker->home);
        $info = self::firstJson($docker->run(['info', '--format', '{{json .}}']));
        $rootless = isset($info['SecurityOptions']) && is_array($info['SecurityOptions']) && (bool) preg_grep('/(^|,)name=rootless(,|$)/', $info['SecurityOptions']);
        $limitsEnforced = self::limitsEnforced($info, $rootless);
        $ps = $docker->run(['ps', '-a', '--format', '{{json .}}']);
        if ($ps['code'] !== 0) {
            throw new CliError('could not list the containers: ' . self::firstLine($ps['err']) . '; nothing was started', 'run `diagnose`');
        }
        $existing = [];
        $usedPorts = [];
        foreach (DockerCli::jsonLines($ps['out']) as $row) {
            $existing[] = explode(',', isset($row['Names']) ? (string) $row['Names'] : '')[0];
            if (isset($row['Ports']) && preg_match_all('/:(\d+)->/', (string) $row['Ports'], $m)) {
                foreach ($m[1] as $p) {
                    $usedPorts[(int) $p] = true;
                }
            }
        }

        // The image: as the user named it, local, and built for this machine.
        $image = (string) $opt['image'];
        $lsio = false;
        if (!DockerRefs::validImage($image)) {
            $blockers[] = "'" . substr($image, 0, 80) . "' is not an image name";
        } else {
            $lsio = (bool) preg_match('#^((docker\.io|lscr\.io|ghcr\.io)/)?linuxserver/#', $image);
            $ii = $docker->run(['image', 'inspect', '--format', '{{json .}}', $image]);
            $meta = $ii['code'] === 0 ? self::firstJson($ii) : null;
            if (!$meta) {
                $blockers[] = $ii['timed_out'] ? "reading image $image timed out" : (stripos($ii['err'], 'no such image') !== false
                    ? "image $image is not on this machine: the user runs `docker pull $image` first (only images the user named)"
                    : "image $image could not be read: " . self::firstLine($ii['err']));
            } else {
                $arch = isset($meta['Architecture']) && is_string($meta['Architecture']) ? $meta['Architecture'] : '';
                $host = self::hostArch($docker, $info);
                if ($arch === '') {
                    $notes[] = "image architecture unknown; this machine is $host";
                } elseif ($arch !== $host) {
                    $blockers[] = "image $image is built for $arch, this machine is $host";
                }
            }
        }

        // Names.
        $base = (string) $opt['name'];
        $names = $concrete ? explode(',', $base) : [$base];
        foreach ($names as $n) {
            if (!DockerRefs::validName($n)) {
                $blockers[] = "'" . substr($n, 0, 80) . "' is not a container name: letters, digits, _ . - only, starting with a letter or digit";
            }
        }
        if ($concrete) {
            if ($count !== null && (int) $count !== count($names)) {
                $blockers[] = "--count $count does not match the " . count($names) . ' names given';
            }
            if (count($names) > self::MAX_COUNT) {
                $blockers[] = 'more than ' . self::MAX_COUNT . ' containers in one launch';
            }
            if (count(array_unique($names)) !== count($names)) {
                $blockers[] = 'a name is given twice';
            }
        } elseif (DockerRefs::validName($base)) {
            $names = self::numberedNames($base, $count === null ? 1 : (int) $count, $existing);
            foreach ($names as $n) {
                if (!DockerRefs::validName($n)) {
                    $blockers[] = "'$n' would be longer than a container name may be";
                }
            }
        }
        foreach ($names as $n) {
            if (in_array($n, $existing, true)) {
                $blockers[] = "a container named $n already exists";
            }
        }
        $k = count($names);
        $groupOf = [];
        foreach ($names as $n) {
            $groupOf[$n] = self::groupOf($n);
        }

        // The env file: names only; values kept in memory to scrub error text.
        $envFile = null;
        $envNames = [];
        $envValues = [];
        if (isset($opt['env-file'])) {
            list($envFile, $envNames, $envValues) = self::envFile($docker, (string) $opt['env-file'], $home, $concrete, $blockers, $notes);
        }

        // Volumes, per container.
        $volumes = self::volumes($docker, isset($opt['volume']) ? (string) $opt['volume'] : '', $names, $home, $info, $concrete, $blockers, $notes);

        // Ports, per container.
        $public = !empty($opt['public']);
        $facts = $docker->facts();
        $shared = Detect::sharedTenancy($docker->pmss(), isset($docker->seams['passwd']) ? $docker->seams['passwd'] : '/etc/passwd');
        $exposureFacts = DockerExposure::facts($shared, $facts);
        $ports = self::ports(isset($opt['port']) ? (string) $opt['port'] : '', $k, $opt, $facts, $usedPorts, $public, $concrete, $blockers);

        $specs = [];
        foreach ($names as $i => $n) {
            $specs[] = ['name' => $n, 'ports' => isset($ports[$i]) ? $ports[$i] : [], 'volumes' => isset($volumes[$i]) ? $volumes[$i] : []];
        }
        $published = (bool) array_filter($ports);
        $accept = !empty($opt['accept-shared-exposure']);
        if ($published && $shared && !$accept) {
            if ($concrete) {
                $blockers[] = 'a published port on a server shared with other accounts needs --accept-shared-exposure (every account here can reach 127.0.0.1)';
            } else {
                $notes[] = 'this server is shared with other accounts: the launch line carries --accept-shared-exposure, which asks the user once; tell them every account here can reach the port';
            }
        }

        // Notes the user should hear before saying yes.
        $plan = ['blockers' => $blockers, 'notes' => $notes, 'specs' => $specs, 'image' => $image, 'env_file' => $envFile, 'env_names' => $envNames,
            'env_values' => $envValues, 'public' => $public, 'shared' => $shared, 'accept' => $published && $shared, 'exposure' => $exposureFacts,
            'limits_enforced' => $limitsEnforced, 'rootless' => $rootless, 'lsio' => $lsio, 'group_of' => $groupOf,
            'log_opts' => !isset($info['LoggingDriver']) || in_array($info['LoggingDriver'], ['json-file', 'local'], true)];
        $plan['notes'] = array_merge($plan['notes'], self::infoNotes($docker, $plan, $info));
        return $plan;
    }

    /** web, or web-N numbered after the containers already called web (as 1) or web-N. */
    private static function numberedNames($base, $count, array $existing)
    {
        $top = 0;
        foreach ($existing as $e) {
            if ($e === $base) {
                $top = max($top, 1);
            } elseif (strncmp($e, $base . '-', strlen($base) + 1) === 0 && ctype_digit(substr($e, strlen($base) + 1))) {
                $top = max($top, (int) substr($e, strlen($base) + 1));
            }
        }
        if ($top === 0 && $count === 1) {
            return [$base];
        }
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = $base . '-' . ($top + $i);
        }
        return $out;
    }

    /** The group a container name belongs to: the name without a trailing -N (the label pikkumies.group). */
    private static function groupOf($name)
    {
        return preg_match('/^(.+)-\d+$/', $name, $m) ? $m[1] : $name;
    }

    /** [absolute path or null, names, values]; adds blockers for a file docker must not read for this account. */
    private static function envFile(DockerCli $docker, $path, $home, $concrete, array &$blockers, array &$notes)
    {
        if ($concrete && $path !== '' && $path[0] !== '/') {
            $blockers[] = 'the env file must be an absolute path in a launch line';
            return [null, [], []];
        }
        if (strncmp($path, '~/', 2) === 0) {
            $path = $docker->home . substr($path, 1);
        }
        if ($path === '' || $path[0] !== '/') {
            $blockers[] = 'the env file must be an absolute path or start with ~/';
            return [null, [], []];
        }
        $st = @lstat($path);
        if ($st === false) {
            $blockers[] = "env file $path does not exist: write it with the file tool, then `chmod 600 $path`";
            return [$path, [], []];
        }
        if (($st['mode'] & 0170000) !== 0100000) {
            $blockers[] = "env file $path is not a regular file (a link or a folder)";
            return [$path, [], []];
        }
        if ((int) $st['uid'] !== $docker->uid) {
            $blockers[] = "env file $path is not owned by this account";
            return [$path, [], []];
        }
        if ($st['mode'] & 0077) {
            $blockers[] = "env file $path can be read by other accounts: run `chmod 600 $path` first";
            return [$path, [], []];
        }
        $names = [];
        $values = [];
        $bad = 0;
        foreach (preg_split('/\n/', (string) @file_get_contents($path)) as $line) {
            $line = rtrim($line, "\r");
            if (trim($line) === '' || ltrim($line)[0] === '#') {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $line, $m)) {
                $names[] = $m[1];
                if (strlen($m[2]) >= 3) {
                    $values[] = $m[2];
                }
            } else {
                $bad++;
            }
        }
        if ($bad) {
            // A bare NAME line makes docker copy the value from this tool's own environment; `export X=1` is refused by docker.
            $blockers[] = "env file $path has $bad line(s) that are not NAME=value (comments start with #; no `export`, no bare names)";
        }
        return [$path, array_values(array_unique($names)), $values];
    }

    /**
     * Mounts per container (index => list of ['src', 'dst', 'mode']). A source must exist inside the home, not be
     * the home itself, ~/.ssh, pikkumies' own folder, docker's own data or config, or a socket (nor a folder holding
     * one of them); with several containers each source holds {n} or is mounted :ro.
     */
    private static function volumes(DockerCli $docker, $spec, array $names, $home, $info, $concrete, array &$blockers, array &$notes)
    {
        $k = count($names);
        $items = $spec === '' ? [] : explode(',', $spec);
        if ($concrete && $items && count($items) % $k !== 0) {
            $blockers[] = 'the --volume entries do not divide evenly between the ' . $k . ' containers';
            return [];
        }
        $deny = array_filter([ // the home folder itself is checked on its own: everything else sits inside it
            '~/.ssh' => $home . '/.ssh',
            'pikkumies\' own folder' => self::real(Config::root()),
            'docker\'s data folder' => isset($info['DockerRootDir']) && is_string($info['DockerRootDir']) && $info['DockerRootDir'] !== '' ? self::real($info['DockerRootDir']) : '',
            'docker\'s config folder' => $home . '/.config/docker',
        ], 'strlen');
        $perContainer = $concrete ? (int) (count($items) / $k) : count($items);
        $out = array_fill(0, $k, []);
        $missing = [];
        foreach ($names as $i => $name) {
            $own = $concrete ? array_slice($items, $i * $perContainer, $perContainer) : $items;
            $dsts = [];
            foreach ($own as $item) {
                $parts = explode(':', $item);
                if (count($parts) < 2 || count($parts) > 3 || (isset($parts[2]) && !in_array($parts[2], ['ro', 'rw'], true))) {
                    $blockers[] = "volume '" . substr($item, 0, 120) . "' is not SRC:DST or SRC:DST:ro (a source may not hold ':' or ',')";
                    continue;
                }
                list($src, $dst) = $parts;
                $mode = isset($parts[2]) ? $parts[2] : 'rw';
                if ($concrete && (strpos($src, '{n}') !== false || $src === '' || $src[0] !== '/')) {
                    $blockers[] = "volume source '$src' is not concrete: a launch line has absolute sources without {n}";
                    continue;
                }
                if (!$concrete && $k > 1 && strpos($src, '{n}') === false && $mode !== 'ro') {
                    if ($i === 0) {
                        $blockers[] = "volume source '$src' would be shared by $k containers: put {n} in it (~/docker/web-{n}) or mount it :ro";
                    }
                    continue;
                }
                $src = str_replace('{n}', preg_match('/-(\d+)$/', $name, $m) ? $m[1] : '1', $src);
                if (strncmp($src, '~/', 2) === 0) {
                    $src = $docker->home . substr($src, 1);
                }
                if (strpbrk($src . $dst, "\n\r") !== false || $dst === '' || $dst[0] !== '/') {
                    $blockers[] = "volume '" . substr($item, 0, 120) . "': the source must be a path in the home and the destination an absolute path, without newlines";
                    continue;
                }
                if (isset($dsts[$dst])) {
                    $blockers[] = "two volumes mount on $dst";
                    continue;
                }
                $dsts[$dst] = true;
                if ($src[0] !== '/') {
                    $blockers[] = "volume source '$src' must be absolute or start with ~/";
                    continue;
                }
                clearstatcache(true, $src);
                if (!file_exists($src) && !is_link($src)) {
                    $missing[$src] = true;
                    $blockers[] = "volume source $src does not exist (launch creates no folders)";
                    continue;
                }
                $real = self::real($src);
                if (@filetype($real) === 'socket') {
                    $blockers[] = "volume source $src is a socket: never mounted";
                    continue;
                }
                $why = $real === $home ? 'the home folder itself' : null;
                foreach ($why === null ? $deny : [] as $label => $d) {
                    // the denied folder itself, anything inside it, or a folder that holds it
                    if ($real === $d || strncmp($real, $d . '/', strlen($d) + 1) === 0 || strncmp($d, $real . '/', strlen($real) + 1) === 0) {
                        $why = $label;
                        break;
                    }
                }
                if ($why !== null) {
                    $blockers[] = "volume source $src is $why (or holds it): never mounted into a container";
                    continue;
                }
                if (strncmp($real, $home . '/', strlen($home) + 1) !== 0) {
                    $blockers[] = "volume source $src is outside the home folder";
                    continue;
                }
                if (strpbrk($real, ",:\n") !== false) {
                    $blockers[] = "volume source $src resolves to a path holding ',' or ':'";
                    continue;
                }
                if ($real === $home . '/www/public' || strncmp($real, $home . '/www/public/', strlen($home) + 12) === 0) {
                    $notes[] = "$src is under ~/www/public, which the web server shows to anyone without a login";
                }
                $out[$i][] = ['src' => $real, 'dst' => $dst, 'mode' => $mode];
            }
        }
        if ($missing) {
            $notes[] = 'create the missing folders first: mkdir -m 700 -p ' . implode(' ', array_map([self::class, 'word'], array_keys($missing)));
        }
        return $out;
    }

    /**
     * Published ports per container (index => list of ['ip', 'host', 'container']). Plan: container ports get the
     * first free host ports from the provider's window (or --host-port-base); every host port is probed on the
     * address it will be published on. Launch: the given HOST:PORT pairs, probed again.
     */
    private static function ports($spec, $k, array $opt, $facts, array $usedPorts, $public, $concrete, array &$blockers)
    {
        $items = $spec === '' ? [] : explode(',', $spec);
        $parsed = [];
        foreach ($items as $item) {
            if (!preg_match('/^(?:(\d{1,5}):)?(\d{1,5})$/', trim($item), $m) || (int) $m[2] < 1 || (int) $m[2] > 65535 || ($m[1] !== '' && ((int) $m[1] < 1 || (int) $m[1] > 65535))) {
                $blockers[] = "port '" . substr($item, 0, 40) . "' is not PORT or HOST:PORT (tcp, 1-65535)";
                return [];
            }
            $parsed[] = ['host' => $m[1] === '' ? null : (int) $m[1], 'container' => (int) $m[2]];
        }
        if (!$parsed) {
            return [];
        }
        $ip = $public ? '0.0.0.0' : '127.0.0.1';
        $out = array_fill(0, $k, []);
        $taken = [];
        if ($concrete) {
            if (count($parsed) % $k !== 0) {
                $blockers[] = "the --port entries do not divide evenly between the $k containers";
                return [];
            }
            $per = (int) (count($parsed) / $k);
            foreach ($parsed as $j => $p) {
                if ($p['host'] === null) {
                    $blockers[] = "port {$p['container']} has no host port: a launch line gives HOST:PORT";
                    continue;
                }
                $out[intdiv($j, $per)][] = ['ip' => $ip, 'host' => $p['host'], 'container' => $p['container']];
            }
        } else {
            $dropped = isset($facts['inbound_dropped']) ? (array) $facts['inbound_dropped'] : [];
            if (isset($opt['host-port-base'])) {
                $next = ctype_digit((string) $opt['host-port-base']) ? (int) $opt['host-port-base'] : 0;
                $high = 65535;
                if ($next < 1 || $next > 65535) {
                    $blockers[] = '--host-port-base must be a port number';
                    return [];
                }
            } else {
                $next = isset($facts['ports'][0]) ? (int) $facts['ports'][0] : self::DEFAULT_PORT_BASE;
                $high = isset($facts['ports'][1]) ? (int) $facts['ports'][1] : 65535;
            }
            $tries = 0;
            for ($i = 0; $i < $k; $i++) {
                foreach ($parsed as $p) {
                    if ($p['host'] !== null) {
                        if ($k > 1) {
                            $blockers[] = "host port {$p['host']} cannot be given for $k containers: give the container port only";
                            return [];
                        }
                        $out[$i][] = ['ip' => $ip, 'host' => $p['host'], 'container' => $p['container']];
                        continue;
                    }
                    $found = null;
                    while ($found === null && $next <= $high && $tries < self::PORT_TRIES) {
                        $cand = $next++;
                        if (isset($usedPorts[$cand]) || isset($taken[$cand]) || ($public && in_array($cand, $dropped, true))) {
                            continue;
                        }
                        $tries++;
                        if (self::probe($ip, $cand) === true) {
                            $found = $cand;
                        }
                    }
                    if ($found === null) {
                        $blockers[] = "no free host port found for container port {$p['container']} (tried up to $high)";
                        return [];
                    }
                    $taken[$found] = true;
                    $out[$i][] = ['ip' => $ip, 'host' => $found, 'container' => $p['container']];
                }
            }
        }
        // Every host port given or chosen is probed now (launch probes the plan's ports again).
        $seen = [];
        foreach ($out as $list) {
            foreach ($list as $p) {
                if (isset($seen[$p['host']])) {
                    $blockers[] = "host port {$p['host']} is given twice";
                    continue;
                }
                $seen[$p['host']] = true;
                if (!isset($taken[$p['host']])) {
                    $why = isset($usedPorts[$p['host']]) ? true : self::probe($ip, $p['host']);
                    if ($why !== true || isset($usedPorts[$p['host']])) {
                        $blockers[] = "host port {$p['host']} cannot be bound on " . ($public ? 'every interface' : '127.0.0.1')
                            . (isset($usedPorts[$p['host']]) ? ' (a container publishes it)' : ($why === self::EADDRINUSE ? ' (in use)' : ''));
                    }
                }
            }
        }
        return $out;
    }

    /**
     * True when $port can be bound now on $ip as this account; else the errno. Every interface means 0.0.0.0 and ::
     * (an IPv6 address that does not exist here is not a conflict, only one in use is).
     */
    private static function probe($ip, $port)
    {
        $try = function ($addr) use ($port) {
            $errno = 0;
            $errstr = '';
            $s = @stream_socket_server("tcp://$addr:$port", $errno, $errstr);
            if ($s === false) {
                return $errno ? $errno : -1;
            }
            fclose($s);
            return true;
        };
        $v4 = $try($ip);
        if ($v4 !== true || $ip !== '0.0.0.0') {
            return $v4;
        }
        $v6 = $try('[::]');
        return $v6 === true || $v6 !== self::EADDRINUSE ? true : $v6;
    }

    /** Notes from the account's limits, quota, the engine and the exposure. */
    private static function infoNotes(DockerCli $docker, array $plan, $info)
    {
        $notes = [];
        $q = Quota::fields($docker->home);
        $notes[] = $q['quota_free_bytes'] !== null
            ? 'quota: ' . self::size($q['quota_free_bytes']) . ' free (' . $q['quota_source'] . '); images and container files count against it'
            : 'quota: no quota found for this account';
        $base = isset($docker->seams['cgroup_base']) ? $docker->seams['cgroup_base'] : '/sys/fs/cgroup';
        $self = isset($docker->seams['self_cgroup']) ? $docker->seams['self_cgroup'] : '/proc/self/cgroup';
        $u = Cgroup::usageAtLimit($base, $self);
        $notes[] = $u['memory_max'] !== null
            ? 'memory: ' . ($u['memory_current'] !== null ? self::size($u['memory_current']) : 'unknown') . ' of ' . self::size($u['memory_max']) . ' used, shared by every process and container of the account (limit at ' . $u['memory_path'] . ')'
            : 'memory: no account memory limit found';
        if ($u['pids_max'] !== null) {
            $notes[] = 'processes: ' . ($u['pids_current'] !== null ? $u['pids_current'] : 'unknown') . ' of ' . $u['pids_max'] . ' (limit at ' . $u['pids_path'] . ')';
        }
        $notes[] = $plan['limits_enforced'] === true
            ? 'per-container limits are enforced by this daemon'
            : ($plan['limits_enforced'] === false
                ? 'per-container limits are not enforced here (cgroup v1 or a cgroupfs rootless daemon): the containers share the account\'s limits with the torrent client'
                : 'whether per-container limits are enforced is unknown');
        foreach (['public', 'local-shared', 'none'] as $kind) {
            $ports = [];
            foreach ($plan['specs'] as $s) {
                foreach ($s['ports'] as $p) {
                    if (DockerExposure::classify($p['ip'], $p['host'], $plan['exposure']) === $kind) {
                        $ports[] = $p['host'];
                    }
                }
            }
            if ($ports) {
                $notes[] = 'port' . (count($ports) > 1 ? 's ' : ' ') . implode(', ', $ports) . ': ' . DockerExposure::SENTENCE[$kind];
            }
        }
        $notes[] = $plan['log_opts'] ? 'logs are capped at 10 MB x 3 files per container' : 'logs are not capped: the daemon logs with ' . $info['LoggingDriver'];
        $notes[] = 'restart policy unless-stopped: the containers come back after a daemon restart unless stopped';
        if ($plan['lsio'] && $plan['rootless'] && !array_intersect(['PUID', 'PGID'], $plan['env_names'])) {
            $notes[] = 'LinuxServer.io image on a rootless daemon: PUID=0 and PGID=0 are set (container root is this account outside)';
        }
        $notes[] = 'labelled ' . self::GROUP_LABEL . '=' . implode(', ', array_unique(array_values($plan['group_of'])))
            . ': `list --label ' . self::GROUP_LABEL . '=' . reset($plan['group_of']) . '` shows the group';
        return $notes;
    }

    /** limits_enforced: false on cgroup v1, a rootless daemon on cgroupfs, or no cgroup driver; null when unknown. */
    private static function limitsEnforced($info, $rootless)
    {
        $version = isset($info['CgroupVersion']) ? (string) $info['CgroupVersion'] : '';
        $driver = isset($info['CgroupDriver']) ? (string) $info['CgroupDriver'] : '';
        if ($version === '' && $driver === '') {
            return null;
        }
        return !($version === '1' || $driver === 'none' || ($rootless && $driver === 'cgroupfs'));
    }

    /** `docker run -d ...` for one container, every valued option attached (--name=x), the image last. */
    private static function runArgv(array $s, array $plan)
    {
        $argv = ['run', '-d', '--name=' . $s['name']];
        foreach (self::labels($s, $plan) as $k => $v) {
            $argv[] = "--label=$k=$v";
        }
        $argv[] = '--restart=unless-stopped';
        if ($plan['log_opts']) {
            foreach (self::LOG_OPTS as $o) {
                $argv[] = '--log-opt=' . $o;
            }
        }
        foreach ($s['ports'] as $p) {
            $argv[] = '--publish=' . ($plan['public'] ? '' : $p['ip'] . ':') . $p['host'] . ':' . $p['container'];
        }
        foreach ($s['volumes'] as $v) {
            $argv[] = '--volume=' . $v['src'] . ':' . $v['dst'] . ($v['mode'] === 'ro' ? ':ro' : '');
        }
        if ($plan['env_file'] !== null) {
            $argv[] = '--env-file=' . $plan['env_file'];
        }
        if ($plan['lsio'] && $plan['rootless'] && !array_intersect(['PUID', 'PGID'], $plan['env_names'])) {
            $argv[] = '--env=PUID=0';
            $argv[] = '--env=PGID=0';
        }
        $argv[] = $plan['image'];
        return $argv;
    }

    /** The launch line for $specs: concrete names, host ports, absolute mounts, the env file, the flags. */
    private static function line(array $plan, array $specs)
    {
        $names = [];
        $ports = [];
        $vols = [];
        foreach ($specs as $s) {
            $names[] = $s['name'];
            foreach ($s['ports'] as $p) {
                $ports[] = $p['host'] . ':' . $p['container'];
            }
            foreach ($s['volumes'] as $v) {
                $vols[] = $v['src'] . ':' . $v['dst'] . ($v['mode'] === 'ro' ? ':ro' : '');
            }
        }
        $words = ['php', __DIR__ . '/docker.php', 'launch', '--image=' . $plan['image'], '--name=' . implode(',', $names)];
        if ($ports) {
            $words[] = '--port=' . implode(',', $ports);
        }
        if ($vols) {
            $words[] = '--volume=' . implode(',', $vols);
        }
        if ($plan['env_file'] !== null) {
            $words[] = '--env-file=' . $plan['env_file'];
        }
        if ($plan['public']) {
            $words[] = '--public';
        }
        if ($plan['accept']) {
            $words[] = '--accept-shared-exposure';
        }
        return implode(' ', array_map([self::class, 'word'], $words));
    }

    /** A shell word: as is when it holds only safe characters, else single-quoted. */
    private static function word($w)
    {
        return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $w) ? $w : escapeshellarg($w);
    }

    private static function specRecord(array $s, array $plan)
    {
        return ['name' => $s['name'], 'group' => $plan['group_of'][$s['name']], 'labels' => self::labels($s, $plan), 'ports' => self::portRecords($s, $plan), 'mounts' => self::mountRecords($s)];
    }

    /** The labels launch sets: the group, and the env file's path (never its contents) for `remove` to print back. */
    private static function labels(array $s, array $plan)
    {
        return [self::GROUP_LABEL => $plan['group_of'][$s['name']]] + ($plan['env_file'] !== null ? [self::ENV_FILE_LABEL => $plan['env_file']] : []);
    }

    private static function portRecords(array $s, array $plan)
    {
        $out = [];
        foreach ($s['ports'] as $p) {
            $out[] = ['container_port' => $p['container'] . '/tcp', 'host_ip' => $p['ip'], 'host_port' => $p['host'],
                'exposure' => DockerExposure::classify($p['ip'], $p['host'], $plan['exposure'])];
        }
        return $out;
    }

    private static function mountRecords(array $s)
    {
        $out = [];
        foreach ($s['volumes'] as $v) {
            $out[] = ['source' => $v['src'], 'destination' => $v['dst'], 'read_only' => $v['mode'] === 'ro'];
        }
        return $out;
    }

    /**
     * The error for a container that did not start: what was started before it (left running), and whether docker
     * left a created container behind. Nothing is retried or removed.
     */
    private static function failed(DockerCli $docker, array $plan, array $s, array $started, $why, $created = false)
    {
        $names = [];
        foreach ($started as $r) {
            $names[] = $r['name'];
        }
        if (!$created && $docker->remaining() > 15) {
            $ps = $docker->run(['ps', '-a', '--format', '{{json .}}'], DockerCli::PROBE_SECONDS);
            foreach (DockerCli::jsonLines($ps['out']) as $row) {
                if (isset($row['Names']) && explode(',', (string) $row['Names'])[0] === $s['name']) {
                    $created = true;
                }
            }
        }
        $left = $created ? " A container {$s['name']} was left by docker (not started)." : '';
        return new CliError("{$s['name']} did not start: " . self::firstLine($why) . '. Started before it: ' . ($names ? implode(', ', $names) : 'none')
            . '; nothing after it was tried.' . $left, 'no retry and no rollback: show the user; `diagnose ' . $s['name'] . '` explains, `remove <names> --expect <n>` deletes what the user does not want (stop them first)');
    }

    /** $text with every env value replaced: docker's error text can quote what it read. */
    private static function scrub($text, array $values)
    {
        usort($values, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        return $values ? str_replace($values, 'REDACTED', $text) : $text;
    }

    /** The daemon's architecture (docker info), else this machine's (the host_arch seam, else uname), in docker's names. */
    private static function hostArch(DockerCli $docker, $info)
    {
        $m = isset($info['Architecture']) && is_string($info['Architecture']) && $info['Architecture'] !== ''
            ? $info['Architecture'] : (isset($docker->seams['host_arch']) ? (string) $docker->seams['host_arch'] : php_uname('m'));
        $map = ['x86_64' => 'amd64', 'amd64' => 'amd64', 'aarch64' => 'arm64', 'arm64' => 'arm64', 'armv7l' => 'arm', 'armv6l' => 'arm', 'i686' => '386', 'i386' => '386'];
        return isset($map[$m]) ? $map[$m] : $m;
    }

    /** realpath, or the path itself when it cannot be resolved. */
    private static function real($path)
    {
        $r = $path === '' ? false : realpath($path);
        return $r === false ? rtrim((string) $path, '/') : $r;
    }

    private static function firstJson(array $r)
    {
        $rows = $r['code'] === 0 ? DockerCli::jsonLines($r['out']) : [];
        return $rows ? $rows[0] : [];
    }

    private static function firstLine($text)
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
        return $lines && $lines[0] !== '' ? substr($lines[0], 0, 300) : 'no error text';
    }

    private static function size($bytes)
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return round($b, 1) . ' ' . $units[$i];
    }
}
