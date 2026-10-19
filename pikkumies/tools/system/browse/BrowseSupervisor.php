<?php
/**
 * BrowseSupervisor: start, stop and watch the browser. A start runs, in order: the readiness check, the memory gate,
 * the private directories, the namespace holder, slirp4netns, the agent-browser daemon (and Chrome, oom_score_adj
 * 1000 so Chrome goes before the account's torrent client), the isolation self-check, then the guard, and only once
 * the guard says it is attached is the browser usable. Chrome resets the score it inherits, so the watcher raises every
 * namespace process to 1000 again each round. Any failure stops everything started so far and returns one
 * plain line. The watcher (`browse.php watch`) stops the browser when any part is gone (the guard exits when its CDP
 * connection drops, so a dead guard or a dead browser both end here) or after IDLE seconds without a CLI call.
 * The stop kills every process of the account in the namespace's network namespace, then slirp4netns, which outlives
 * the namespace otherwise (seen on the development host). The guard's panel login is the agent's own where the panel
 * accepts it (PanelAccess, ADR-0079), in a 0600 file deleted once the guard has read it.
 */
class BrowseSupervisor
{
    const IDLE = 900;          // 15 minutes without a CLI call
    const GUARD_WAIT = 10;     // seconds for the guard's ready file; no file, no browser
    const DAEMON_SECONDS = 60; // the first open starts Chrome
    const TICK = 5;            // the watcher's interval

    public $proc;
    /** Test seams: Cgroup usage and MemAvailable; the account's identity (user, uid, gid, fqdn). */
    public $usage = null;
    public $identity = null;

    public function __construct($proc = null)
    {
        $this->proc = $proc !== null ? $proc : new BrowseProc();
    }

    /** [true, message] when the browser is running (now or already), [false, the reason] otherwise. */
    public function up()
    {
        $lock = $this->lock();
        try {
            $s = BrowseState::read();
            if ($s !== null && BrowseState::gone($s, $this->proc) === '') {
                BrowseState::touch();
                return [true, 'already running'];
            }
            if ($s !== null) {
                $this->stop('restart: ' . BrowseState::gone($s, $this->proc));
            }
            $why = $this->start();
            if ($why !== '') {
                $this->stop('start failed: ' . $why);
                return [false, $why];
            }
            return [true, 'started'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function down($why = 'stopped on request')
    {
        $lock = $this->lock();
        try {
            $had = BrowseState::read() !== null;
            $this->stop($why);
            return $had;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** One watcher round: '' to keep watching, else why it stopped watching. */
    public function tick($self)
    {
        $s = BrowseState::read();
        if ($s === null) {
            return 'not running';
        }
        if (isset($s['watcher']['pid']) && $s['watcher']['pid'] !== $self) {
            return 'another watcher took over';
        }
        $gone = BrowseState::gone($s, $this->proc);
        $idle = BrowseState::idleSeconds($this->proc->now());
        if ($gone === '' && ($idle === null || $idle <= self::IDLE)) {
            $this->proc->raiseOom($this->proc->members($s['net']));
            return '';
        }
        $this->down($gone !== '' ? $gone : 'idle for ' . (int) ($idle / 60) . ' minutes');
        return $gone !== '' ? $gone : 'idle';
    }

    private function start()
    {
        @unlink(BrowsePaths::file('panelAuth')); // a header file a killed start left behind
        $problem = BrowseCheck::problem();
        $pin = BrowseBinary::currentPin();
        $problem = $problem !== '' ? $problem : BrowsePaths::ensureDirs();
        $problem = $problem !== '' ? $problem : BrowseBinary::ensure($pin, HermesInstall::target());
        if ($problem !== '') {
            return $problem;
        }
        list($usage, $available) = $this->usage !== null ? call_user_func($this->usage) : [Cgroup::usageAtLimit(), BrowseCheck::memAvailable()];
        $gate = BrowseCheck::headroomProblem($usage, $available);
        if ($gate !== '') {
            return $gate;
        }
        $id = $this->identity !== null ? $this->identity : self::identity();
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $id['fqdn'])) { // the guard's fence is keyed on the panel host; a guess would leave it open
            return 'the panel host name of this server could not be resolved (hostname -f gave "' . $id['fqdn'] . '")';
        }
        BrowseState::clear();
        HermesConfig::put(BrowsePaths::file('resolv'), BrowseIsolation::resolvConf(), 0600);
        HermesConfig::put(BrowsePaths::file('config'), json_encode(['profile' => BrowsePaths::dirs()['profile']], JSON_UNESCAPED_SLASHES) . "\n", 0600);
        $log = BrowsePaths::file('log');
        $p = $this->proc;
        $s = ['version' => $pin['version'], 'startedAt' => gmdate('c')];

        $outer = $p->spawn(BrowseIsolation::holderArgv(BrowsePaths::file('resolv'), $id['uid'], $id['gid']), $log);
        $s['outer'] = BrowseState::part($outer, $p);
        BrowseState::write($s);
        $inner = $this->waitFor(3, function () use ($p, $outer) {
            $kids = $p->children($outer);
            return count($kids) === 1 && $p->netns($kids[0]) !== '' && $p->netns($kids[0]) !== $p->netns('self') ? $kids[0] : 0;
        });
        if (!$inner) {
            return 'the private network namespace could not be made (unshare)';
        }
        $s['inner'] = BrowseState::part($inner, $p);
        $s['net'] = $p->netns($inner);
        BrowseState::write($s);

        $s['slirp'] = BrowseState::part($p->spawn(BrowseIsolation::slirpArgv($outer), $log), $p);
        BrowseState::write($s);
        $net = $this->waitFor(5, function () use ($p, $inner) {
            $r = $p->run(BrowseIsolation::nsenterArgv($inner, ['ip', '-o', '-4', 'addr', 'show', 'tap0']), null, 5);
            return strpos($r['out'], BrowseIsolation::TAP_ADDRESS . '/') !== false;
        });
        if (!$net) {
            return 'the namespace got no outbound network (slirp4netns)';
        }

        $env = self::cliEnv($id);
        $bin = BrowseBinary::path($pin['version']);
        $open = $p->run(BrowseIsolation::nsenterArgv($inner, ['choom', '-n', '1000', '--', $bin, '--session', BrowsePaths::SESSION, '--json', 'open', 'about:blank']), $env, self::DAEMON_SECONDS);
        $daemon = (int) trim((string) @file_get_contents(BrowsePaths::dirs()['run'] . '/' . BrowsePaths::SESSION . '.pid'));
        if ($open['code'] !== 0 || $daemon < 2) {
            return 'the browser did not start (agent-browser exit ' . $open['code'] . ': ' . substr(trim($open['err'] . ' ' . $open['out']), 0, 200) . ')';
        }
        $s['daemon'] = BrowseState::part($daemon, $p);
        BrowseState::write($s);
        $cdp = trim($p->run(BrowseIsolation::nsenterArgv($inner, [$bin, '--session', BrowsePaths::SESSION, 'get', 'cdp-url']), $env, 20)['out']);
        if (!preg_match('#^ws://127\.0\.0\.1:\d+/devtools/browser/[A-Za-z0-9-]+$#', $cdp)) {
            return 'agent-browser gave no CDP address for the guard';
        }
        $iso = BrowseIsolation::selfCheck($p->netns($daemon), $s['net'], $p->netns('self'), self::ss($p), [$daemon]);
        if ($iso !== '') {
            return $iso;
        }

        $guardArgv = [PHP_BINARY, __DIR__ . '/guard.php', '--cdp', $cdp, '--log', BrowsePaths::file('guardLog'), '--self', $id['user'],
            '--host', $id['fqdn'], '--intent', BrowsePaths::file('intent'), '--ready', BrowsePaths::file('ready')];
        $auth = $this->panelAuth($id);
        if ($auth !== '') {
            $guardArgv = array_merge($guardArgv, ['--auth-file', $auth]);
        }
        $s['guard'] = BrowseState::part($p->spawn(BrowseIsolation::nsenterArgv($inner, $guardArgv), $log, ['HOME' => $env['HOME'], 'PATH' => $env['PATH']]), $p);
        BrowseState::write($s);
        $ready = $this->waitFor(self::GUARD_WAIT, function () use ($p, $s) {
            clearstatcache(true, BrowsePaths::file('ready'));
            return is_file(BrowsePaths::file('ready')) && $p->alive($s['guard']['pid'], $s['guard']['started']);
        });
        @unlink(BrowsePaths::file('panelAuth')); // the guard read it once at its start; no copy stays on disk
        if (!$ready) {
            return 'the guard did not attach to the browser within ' . self::GUARD_WAIT . ' s (see ' . Config::shown($log) . ')';
        }
        $p->raiseOom($p->members($s['net'])); // the whole namespace goes before the account's other programs
        BrowseState::touch();
        $s['watcher'] = BrowseState::part($p->spawn([PHP_BINARY, __DIR__ . '/browse.php', 'watch'], $log), $p);
        BrowseState::write($s);
        BrowseState::log("started (agent-browser {$pin['version']}, isolated, guard up)");
        return '';
    }

    /** Everything the state names, by network namespace first, then one by one; then the state is gone. */
    private function stop($why)
    {
        $s = BrowseState::read();
        if ($s === null) {
            return;
        }
        $p = $this->proc;
        $pids = !empty($s['net']) ? $p->members($s['net']) : [];
        foreach (array_merge(BrowseState::PARTS, ['watcher']) as $part) {
            if (isset($s[$part]['pid']) && $s[$part]['pid'] !== getmypid() && $p->alive($s[$part]['pid'], $s[$part]['started'])) {
                $pids[] = $s[$part]['pid'];
            }
        }
        $pids = array_values(array_unique(array_diff($pids, [getmypid()])));
        $known = [];
        foreach ($pids as $pid) {
            $known[$pid] = $p->started($pid);
            $p->kill($pid, 'TERM');
        }
        $survivors = function () use ($p, $s, $known) {
            $left = !empty($s['net']) ? $p->members($s['net']) : [];
            foreach ($known as $pid => $started) {
                if ($p->alive($pid, $started)) {
                    $left[] = $pid;
                }
            }
            return array_values(array_unique(array_diff($left, [getmypid()])));
        };
        $this->waitFor(3, function () use ($survivors) {
            return $survivors() ? 0 : 1;
        });
        foreach ($survivors() as $pid) {
            $p->kill($pid, 'KILL');
        }
        BrowseState::clear();
        BrowseState::log("stopped: $why");
    }

    /** $fn polled every 0.2 s for up to $seconds; its first truthy answer, else 0. */
    private function waitFor($seconds, callable $fn)
    {
        for ($i = 0; $i <= $seconds * 5; $i++) {
            $v = $fn();
            if ($v) {
                return $v;
            }
            $this->proc->sleep(0.2);
        }
        return 0;
    }

    private function lock()
    {
        @mkdir(BrowsePaths::dir(), 0700, true);
        $lock = fopen(BrowsePaths::file('lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new CliError('cannot take ' . Config::shown(BrowsePaths::file('lock')));
        }
        return $lock;
    }

    /** The complete environment of agent-browser inside the namespace: nothing inherited from Hermes or the caller. */
    public static function cliEnv(array $id)
    {
        $d = BrowsePaths::dirs();
        return ['HOME' => $id['home'], 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'TMPDIR' => $d['tmp'],
            'AGENT_BROWSER_SOCKET_DIR' => $d['run'], 'AGENT_BROWSER_CONFIG' => BrowsePaths::file('config'),
            'AGENT_BROWSER_EXECUTABLE_PATH' => BrowsePaths::chromium(), 'AGENT_BROWSER_SESSION' => BrowsePaths::SESSION];
    }

    /**
     * The guard's --auth-file, '' for none. Where the panel accepts the agent's own login (ADR-0079): that login made
     * sure of, then written to a new 0600 file in local/browse/ that is deleted once the guard has read it. Otherwise a
     * header the user stored (ADR-0077). A failure is one line in browse.log; the browser still starts, without a login.
     */
    private function panelAuth(array $id)
    {
        $panel = new PanelAccess($id['home'], $id['user'], BrowsePaths::local(), $id['uid']);
        if ($panel->available()) {
            list($ok, $what) = $panel->ensure();
            $file = BrowsePaths::file('panelAuth');
            $problem = $ok ? $panel->writeHeader($file) : $what;
            if ($problem === '') {
                return $file;
            }
            BrowseState::log('panel login not used: ' . $problem);
        }
        return self::authFileUsable(BrowsePaths::authFile(), $id['uid']) ? BrowsePaths::authFile() : '';
    }

    /** The guard reads the Basic header only from a regular file this account owns with mode 0600 (ADR-0019). */
    public static function authFileUsable($file, $uid)
    {
        $st = @lstat($file);
        return $st !== false && ($st['mode'] & 0170000) === 0100000 && (int) $st['uid'] === (int) $uid && ($st['mode'] & 0777) === 0600;
    }

    private static function ss($p)
    {
        $r = $p->run(['ss', '-ltnpH'], null, 10);
        return [$r['code'], $r['out']];
    }

    public static function identity()
    {
        $profile = Detect::cached();
        return ['user' => (string) $profile['user'], 'uid' => BrowsePaths::uid(), 'gid' => function_exists('posix_getegid') ? posix_getegid() : getmygid(),
            'fqdn' => (string) $profile['identity']['fqdn'], 'home' => BrowsePaths::home()];
    }
}
