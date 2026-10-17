<?php
/**
 * DockerCli: the one way this unit talks to Docker (ADR-0034). The daemon is the user's own: the socket comes from
 * Host::dockerSocket (a unix socket this account owns; tcp://, root's and another account's daemon are refused with
 * the reason). Every call is `timeout <N> docker ...` with DOCKER_HOST set to that socket and DOCKER_CONTEXT
 * removed, run through ToolProc (an argv, never a shell string), so a wedged daemon costs N seconds, not a hung
 * terminal. Every verb probes first (`docker version`, 10 s) and stops on the ladder state instead of fanning out.
 * Output is read only from `--format '{{json .}}'`; a missing field is unknown, never a guess.
 *
 * Seams (an array, set by tests; production passes nothing): uid, home, candidates (socket paths, see
 * Host::dockerSocket), pmss_dir, proc_root, local_dir, docker_config (path of ~/.docker/config.json),
 * system_socket (root's daemon socket, named in a refusal), linger_dir (systemd's per-user linger flags).
 */
$dockerLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Host', 'Cgroup'] as $dockerClass) {
    require_once "$dockerLib/$dockerClass.php";
}
require_once dirname(__DIR__, 2) . '/storage/lib/ToolProc.php';

class DockerCli
{
    const PROBE_SECONDS = 10;      // the API probe every verb starts with
    const CALL_SECONDS = 60;       // any other single call
    const BUDGET_SECONDS = 150;    // a whole verb, under the agent's 180 s terminal default
    const SCHEDULED_SECONDS = 30;  // the scheduled check, which shares the cron tick
    const TIMED_OUT = 124;         // coreutils timeout's exit code

    /** Ladder states, in the order a diagnosis reads them. */
    const STATES = ['healthy', 'down', 'wedged', 'second-daemon', 'cli-context-mismatch', 'storage-driver-missing', 'linger-missing'];

    public $uid;
    public $home;
    public $seams;
    private $sock = null;
    private $started;

    public function __construct(array $seams = [])
    {
        $this->seams = $seams + ['uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(), 'home' => rtrim((string) getenv('HOME'), '/'),
            'candidates' => null, 'pmss_dir' => '/etc/seedbox/config', 'proc_root' => '/proc', 'local_dir' => Config::localDir(), 'docker_config' => null,
            'system_socket' => '/var/run/docker.sock', 'linger_dir' => '/var/lib/systemd/linger'];
        $this->uid = (int) $this->seams['uid'];
        $this->home = (string) $this->seams['home'];
        $this->started = microtime(true);
    }

    /** The docker CLI: PATH first, then ~/bin (PMSS puts helpers there), or null. Never installs it. */
    public function bin()
    {
        $path = ToolProc::which('docker');
        if ($path === null && $this->home !== '' && is_file("{$this->home}/bin/docker") && is_executable("{$this->home}/bin/docker")) {
            $path = "{$this->home}/bin/docker";
        }
        return $path;
    }

    /** The user's own daemon socket, or '' (cached for the call). */
    public function socket()
    {
        if ($this->sock === null) {
            $this->sock = Host::dockerSocket($this->uid, $this->seams['candidates']);
        }
        return $this->sock;
    }

    /**
     * Why there is no usable daemon, before any call: ['state' => ok|cli-missing|socket-missing|refused,
     * 'reason' => null|tcp|root-daemon|other-account, 'socket' => path or '', 'hint' => text]. Never contacts a daemon.
     */
    public function absence()
    {
        $r = ['state' => 'ok', 'reason' => null, 'socket' => '', 'hint' => ''];
        if ($this->bin() === null) {
            return ['state' => 'cli-missing', 'hint' => 'no docker command on PATH or in ~/bin; this account has no Docker here'] + $r;
        }
        if ($this->socket() !== '') {
            return ['socket' => $this->socket()] + $r;
        }
        $env = getenv('DOCKER_HOST');
        if ($env !== false && $env !== '' && strncmp($env, 'unix://', 7) !== 0) {
            return ['state' => 'refused', 'reason' => 'tcp', 'hint' => 'DOCKER_HOST is not a unix socket; pikkumies uses only a daemon your account owns, through its socket'] + $r;
        }
        $paths = $env !== false && $env !== '' ? [substr($env, 7)] : array_merge(
            $this->seams['candidates'] !== null ? $this->seams['candidates'] : Host::dockerSocketCandidates($this->uid),
            $this->uid === 0 ? [] : [(string) $this->seams['system_socket']]
        );
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            if (@filetype($path) !== 'socket') {
                continue;
            }
            $owner = @fileowner($path);
            return $owner === 0
                ? ['state' => 'refused', 'reason' => 'root-daemon', 'socket' => $path, 'hint' => "$path is root's daemon: using it is root access, so pikkumies does not; a rootless daemon of your own is what it manages"] + $r
                : ['state' => 'refused', 'reason' => 'other-account', 'socket' => $path, 'hint' => "$path belongs to another account; never use it"] + $r;
        }
        return ['state' => 'socket-missing', 'hint' => $this->pmss()
            ? 'the platform starts your daemon; run `diagnose` for what to do'
            : 'no Docker socket of your own; a rootless daemon listens at /run/user/' . $this->uid . '/docker.sock'] + $r;
    }

    /** True on a PMSS host (the pmss_dir seam). */
    public function pmss()
    {
        return is_dir((string) $this->seams['pmss_dir']);
    }

    /** The provider's docker facts (Host::TABLE `docker`), or null. */
    public function facts()
    {
        return $this->pmss() ? Host::TABLE['pmss']['docker'] : Host::dockerFacts(Host::family($this->seams['pmss_dir']), Host::stack($this->seams['pmss_dir']));
    }

    /** Environment of every call: the own socket, no CLI context that could point elsewhere. */
    public function env()
    {
        return ['DOCKER_HOST' => 'unix://' . $this->socket(), 'DOCKER_CONTEXT' => null, 'HOME' => $this->home];
    }

    /** `timeout <seconds> <docker> <args...>`. */
    public function argv(array $args, $seconds = self::CALL_SECONDS)
    {
        ToolProc::need('timeout', 'coreutils provides it; this tool never calls docker without a time limit');
        return array_merge(['timeout', (string) max(1, (int) $seconds), (string) $this->bin()], array_map('strval', $args));
    }

    /**
     * One docker call. Returns ToolProc's ['code', 'out', 'err', 'stopped', 'cut'] plus 'timed_out'. Refuses
     * (CliError) when there is no own daemon, so no verb can reach root's or another account's.
     */
    public function run(array $args, $seconds = self::CALL_SECONDS)
    {
        $a = $this->absence();
        if ($a['state'] !== 'ok') {
            throw new CliError('no Docker daemon of your own to use: ' . $a['state'] . ($a['reason'] ? " ({$a['reason']})" : '') . '; nothing was done', $a['hint']);
        }
        $r = ToolProc::run($this->argv($args, $seconds), $this->env());
        $r['timed_out'] = $r['code'] === self::TIMED_OUT;
        return $r;
    }

    /** Seconds left of the verb's budget. */
    public function remaining($budget = self::BUDGET_SECONDS)
    {
        return $budget - (microtime(true) - $this->started);
    }

    /** Each line of `--format '{{json .}}'` output that decodes to an object; others are dropped. */
    public static function jsonLines($out)
    {
        $rows = [];
        foreach (explode("\n", (string) $out) as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * The API probe: `docker version --format '{{json .}}'` in 10 s. ['ok' => bool, 'timed_out' => bool, 'client' =>
     * version|null, 'server' => version|null, 'engine' => name of the server's first component|null, 'error' => first
     * stderr line]. A daemon answers only when the Server part is there.
     */
    public function probe()
    {
        $r = $this->run(['version', '--format', '{{json .}}'], self::PROBE_SECONDS);
        $v = self::jsonLines($r['out']);
        $v = $v ? $v[0] : [];
        $server = isset($v['Server']) && is_array($v['Server']) ? $v['Server'] : null;
        return [
            'ok' => $r['code'] === 0 && $server !== null,
            'timed_out' => $r['timed_out'],
            'client' => isset($v['Client']['Version']) ? (string) $v['Client']['Version'] : null,
            'server' => isset($server['Version']) ? (string) $server['Version'] : null,
            'engine' => isset($server['Components'][0]['Name']) ? (string) $server['Components'][0]['Name'] : null,
            'error' => self::firstLine($r['err']),
        ];
    }

    /** The probe, or a CliError carrying the ladder state when the daemon does not answer (no verb fans out on a dead daemon). */
    public function requireHealthy()
    {
        $p = $this->probe();
        if ($p['ok']) {
            return $p;
        }
        $l = self::ladder($this->observe($p));
        throw new CliError("Docker is not answering ({$l['state']}); nothing was done", $l['next_step']);
    }

    /**
     * What the ladder decides from, read without the daemon: the probe, this account's own Docker processes (from
     * proc_root, own uid only), the socket file, the CLI context the user's own `docker` would use, the configured
     * storage driver and whether its program exists, and (not on PMSS) the systemd linger file.
     */
    public function observe(array $probe)
    {
        $procs = $this->ownDaemonProcs();
        $driver = $this->configuredDriver();
        $context = $this->cliContext();
        $env = getenv('DOCKER_HOST');
        $sock = $this->socket();
        return [
            'probe_ok' => !empty($probe['ok']),
            'timed_out' => !empty($probe['timed_out']),
            'socket_file' => $sock !== '' || (bool) array_filter($this->seams['candidates'] !== null ? $this->seams['candidates'] : Host::dockerSocketCandidates($this->uid), 'file_exists'),
            'daemons' => $procs['dockerd'],
            'processes' => $procs['all'],
            'context_mismatch' => $sock !== '' && ($env === false || $env !== 'unix://' . $sock) && in_array($context, ['', 'default'], true)
                && $sock !== (string) $this->seams['system_socket'], // the CLI's own default needs no DOCKER_HOST
            'cli_context' => $context,
            'storage_driver' => $driver,
            'storage_driver_missing' => $driver === 'fuse-overlayfs' && ToolProc::which('fuse-overlayfs') === null,
            'pmss' => $this->pmss(),
            'linger' => $this->pmss() ? null : file_exists(rtrim((string) $this->seams['linger_dir'], '/') . '/' . $this->userName()),
        ];
    }

    /**
     * The daemon ladder, a pure function of observe(): ['state', 'next_step', 'effect', 'needs_user_ok', 'source',
     * 'verified'] (source and verified from Host::TABLE when the advice is the provider's). Never advises stopping,
     * restarting or killing a PMSS daemon as a fix; a wedged daemon's recovery is the provider's page, on the user's yes.
     */
    public static function ladder(array $o)
    {
        $facts = !empty($o['pmss']) ? Host::TABLE['pmss']['docker'] : null;
        $src = $facts ? ['source' => $facts['source'], 'verified' => $facts['verified']] : ['source' => null, 'verified' => null];
        $mk = function ($state, $next, $effect, $ask) use ($src) {
            return ['state' => $state, 'next_step' => $next, 'effect' => $effect, 'needs_user_ok' => $ask] + $src;
        };
        if (!empty($o['daemons']) && $o['daemons'] > 1) {
            return $mk('second-daemon', 'two Docker daemons run for this account; say so to the user and to support; do not stop either', 'containers may be split between two daemons', false);
        }
        if (!empty($o['probe_ok'])) {
            if (!empty($o['context_mismatch'])) {
                return $mk('cli-context-mismatch', 'the daemon answers; the user\'s own `docker` command talks to another address: set DOCKER_HOST=unix://<socket> (PMSS sets it in ~/.bashrc)', 'none', false);
            }
            return $mk('healthy', 'none', 'none', false);
        }
        if (!empty($o['storage_driver_missing'])) {
            return $mk('storage-driver-missing', !empty($o['pmss'])
                ? 'the configured storage driver program (fuse-overlayfs) is missing on this server: tell support; the next platform update installs it again'
                : 'the configured storage driver program (fuse-overlayfs) is missing: ask the server admin to install it', 'the daemon cannot start', false);
        }
        if (!empty($o['processes'])) {
            return $mk('wedged', $facts
                ? 'the daemon\'s processes are alive but it does not answer: only on the user\'s yes, follow the platform\'s documented recovery (' . $facts['recovery'] . '); it stops every container; afterwards confirm that no dockerd-rootless process of this account remains'
                : 'the daemon\'s processes are alive but it does not answer: only on the user\'s yes, restart the user\'s own daemon the way it was started (for a systemd user unit: systemctl --user restart docker); it stops every container', 'every container stops during the recovery', true);
        }
        if (array_key_exists('linger', $o) && $o['linger'] === false) {
            return $mk('linger-missing', 'the daemon is not running and systemd lingering is off for this account, so it stops at logout: ask the server admin to run `loginctl enable-linger <user>`, then `systemctl --user start docker`', 'none until it is started', false);
        }
        return $mk('down', !empty($o['pmss'])
            ? 'the platform starts the daemon: wait 5 minutes; still down: `systemctl --user start docker.service`; still down: support, saying Docker may be off for this plan'
            : 'start the user\'s own daemon: `systemctl --user start docker`', 'none', false);
    }

    /**
     * This account's own daemon processes, read from proc_root (no pgrep: a pattern run through a shell can match its
     * own wrapper): 'dockerd' (exact dockerd), 'all' (dockerd, rootlesskit, containerd, dockerd-rootless*), and
     * 'watchdog_alive': processes the PMSS watchdog counts as a running daemon (a full command line containing
     * dockerd-rootless, or an exact dockerd; scripts/util/userDocker.php:240-243). After a wedged-daemon recovery
     * that last count must be 0, or the watchdog never starts a new daemon.
     */
    public function ownDaemonProcs()
    {
        $n = ['dockerd' => 0, 'all' => 0, 'watchdog_alive' => 0];
        foreach ((array) glob(rtrim((string) $this->seams['proc_root'], '/') . '/[0-9]*', GLOB_ONLYDIR) as $dir) {
            if (@fileowner($dir) !== $this->uid) {
                continue;
            }
            $argv = explode("\0", rtrim((string) @file_get_contents("$dir/cmdline"), "\0"));
            $exe = basename((string) $argv[0]);
            if ($exe === 'dockerd') {
                $n['dockerd']++;
            }
            if (in_array($exe, ['dockerd', 'rootlesskit', 'containerd'], true) || strpos($exe, 'dockerd-rootless') === 0) {
                $n['all']++;
            }
            if ($exe === 'dockerd' || strpos(implode(' ', $argv), 'dockerd-rootless') !== false) {
                $n['watchdog_alive']++;
            }
        }
        return $n;
    }

    /** storage-driver from ~/.config/docker/daemon.json, '' when unset or unreadable. */
    public function configuredDriver()
    {
        $c = json_decode((string) @file_get_contents($this->home . '/.config/docker/daemon.json'), true);
        return is_array($c) && isset($c['storage-driver']) && is_string($c['storage-driver']) ? $c['storage-driver'] : '';
    }

    /** The CLI context the user's own `docker` uses: DOCKER_CONTEXT, else currentContext of ~/.docker/config.json, else ''. Reads that one key only. */
    public function cliContext()
    {
        $env = getenv('DOCKER_CONTEXT');
        if ($env !== false && $env !== '') {
            return $env;
        }
        $file = $this->seams['docker_config'] !== null ? $this->seams['docker_config'] : $this->home . '/.docker/config.json';
        $c = json_decode((string) @file_get_contents($file), true);
        return is_array($c) && isset($c['currentContext']) && is_string($c['currentContext']) ? $c['currentContext'] : '';
    }

    private function userName()
    {
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($this->uid);
            if (is_array($pw) && isset($pw['name'])) {
                return $pw['name'];
            }
        }
        return basename($this->home);
    }

    private static function firstLine($text)
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
        return $lines && $lines[0] !== '' ? substr($lines[0], 0, 300) : null;
    }
    /** Docker's own warnings that a per-container limit is unsupported (a swap warning alone is not one). */
    const LIMIT_WARNING_RE = '/\bNo (memory limit|cpu cfs quota|cpu cfs period|cpu shares|pids limit) support/i';

    /**
     * Whether per-container limits apply, from `docker info` (the one rule for stats, diagnose and launch): a rootful
     * daemon with a cgroup driver, or a rootless one on cgroup v2 with the systemd driver (a cgroupfs rootless daemon,
     * which PMSS configures, cannot apply them); never with driver "none" or docker's own limit warnings. null when
     * docker did not say enough to tell.
     */
    public static function limitsEnforced($info)
    {
        if (!is_array($info)) {
            return null;
        }
        // docker info always lists SecurityOptions; a rootless daemon names itself there, so no mention means rootful.
        $rootless = (bool) array_filter(isset($info['SecurityOptions']) && is_array($info['SecurityOptions']) ? $info['SecurityOptions'] : [], function ($o) {
            return is_string($o) && preg_match('/(^|,)name=rootless(,|$)/', $o);
        });
        $version = isset($info['CgroupVersion']) && ctype_digit((string) $info['CgroupVersion']) ? (int) $info['CgroupVersion'] : null;
        $driver = isset($info['CgroupDriver']) && is_string($info['CgroupDriver']) ? $info['CgroupDriver'] : null;
        return self::limitsEnforcedFrom($rootless, $version, $driver, isset($info['Warnings']) && is_array($info['Warnings']) ? $info['Warnings'] : []);
    }

    /** limitsEnforced() from its parts: rootless (bool|null), cgroup version (int|null), driver (string|null), warnings. */
    public static function limitsEnforcedFrom($rootless, $cgroupVersion, $driver, array $warnings)
    {
        if ($rootless === null || $cgroupVersion === null || $driver === null) {
            return null;
        }
        if ($driver === 'none') {
            return false;
        }
        foreach ($warnings as $w) {
            if (is_string($w) && preg_match(self::LIMIT_WARNING_RE, $w)) {
                return false;
            }
        }
        return !$rootless || ($cgroupVersion === 2 && $driver === 'systemd');
    }
}
