<?php
/**
 * tools/system/browse/BrowseSupervisor.php and BrowseCli.php with a fake process layer: the start order, every
 * fail-closed exit, the watcher's stops, and the wrapper's call into the namespace (ADR-0077). No unshare, slirp4netns
 * or Chromium runs here.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/browse.inc.php';

/** Records every call; processes are pids in $alive with a start time; the namespace's processes sit in net:[2]. */
class FakeBrowseProc
{
    public $calls = [];
    public $alive = [];
    public $net = [];
    public $kids = [];
    public $next = 100;
    public $now = 1000000;
    public $guardReady = true;
    public $leak = false;
    public $oom = [];
    /** [contents, mode] of the guard's --auth-file at the moment the guard was started; null when none was passed. */
    public $authSeen = null;

    private function newPid($net)
    {
        $pid = $this->next++;
        $this->alive[$pid] = "t$pid";
        $this->net[$pid] = $net;
        return $pid;
    }

    public function spawn(array $argv, $log, array $env = null)
    {
        $this->calls[] = ['spawn', $argv];
        if ($argv[0] === 'unshare') {
            $outer = $this->newPid('net:[2]');
            $this->kids[$outer] = [$this->newPid('net:[2]')];
            return $outer;
        }
        if (in_array(dirname(__DIR__) . '/pikkumies/tools/system/browse/guard.php', $argv, true)) {
            $ready = $argv[array_search('--ready', $argv, true) + 1];
            if (in_array('--auth-file', $argv, true)) {
                $auth = $argv[array_search('--auth-file', $argv, true) + 1];
                clearstatcache(true, $auth);
                $this->authSeen = [(string) @file_get_contents($auth), @fileperms($auth) & 0777];
            }
            if ($this->guardReady) {
                file_put_contents($ready, "1\n");
            }
            return $this->newPid('net:[2]');
        }
        return $this->newPid('net:[1]'); // slirp4netns, the watcher: host namespace
    }

    public function run(array $argv, array $env = null, $seconds = 30, $stdin = null)
    {
        $this->calls[] = ['run', $argv, $env, $stdin];
        $line = implode(' ', $argv);
        if (strpos($line, 'tap0') !== false) {
            return ['code' => 0, 'out' => "2: tap0    inet 10.0.2.100/24 brd 10.0.2.255 scope global tap0\n", 'err' => ''];
        }
        if (strpos($line, 'open about:blank') !== false) {
            file_put_contents($env['AGENT_BROWSER_SOCKET_DIR'] . '/' . BrowsePaths::SESSION . '.pid', $this->newPid('net:[2]') . "\n");
            return ['code' => 0, 'out' => '{"success":true}', 'err' => ''];
        }
        if (strpos($line, 'cdp-url') !== false) {
            return ['code' => 0, 'out' => "ws://127.0.0.1:36841/devtools/browser/0d5c794d-621b-4186-9e82-903448556776\n", 'err' => ''];
        }
        if ($argv[0] === 'ss') {
            return ['code' => 0, 'out' => $this->leak ? "LISTEN 0 10 127.0.0.1:36841 0.0.0.0:* users:((\"chrome\",pid=9999,fd=83))\n" : "LISTEN 0 128 0.0.0.0:22 0.0.0.0:*\n", 'err' => ''];
        }
        return ['code' => 0, 'out' => "{\"success\":true,\"data\":{\"ran\":" . json_encode($argv) . "}}\n", 'err' => ''];
    }

    public function started($pid)
    {
        return isset($this->alive[$pid]) ? $this->alive[$pid] : null;
    }

    public function alive($pid, $started)
    {
        return $started !== null && $this->started($pid) === $started;
    }

    public function kill($pid, $signal = 'TERM')
    {
        $this->calls[] = ['kill', $pid, $signal];
        unset($this->alive[$pid]);
    }

    public function members($net)
    {
        return array_values(array_filter(array_keys($this->alive), function ($p) use ($net) {
            return $this->net[$p] === $net;
        }));
    }

    public function netns($pid)
    {
        return $pid === 'self' ? 'net:[1]' : (isset($this->alive[$pid]) ? $this->net[$pid] : '');
    }

    public function children($pid)
    {
        return isset($this->kids[$pid]) ? $this->kids[$pid] : [];
    }

    public function raiseOom(array $pids)
    {
        $this->oom[] = $pids;
    }

    public function sleep($seconds)
    {
    }

    public function now()
    {
        return $this->now;
    }

    public function spawned()
    {
        return array_values(array_filter($this->calls, function ($c) {
            return $c[0] === 'spawn';
        }));
    }
}

/** A machine where every readiness check passes, agent-browser 0.26.0 already fetched; $fn($sup, $proc, $t). */
function browseSupWorld(callable $fn, $usage = null)
{
    $t = testTempDir('pk-browse-sup');
    try {
        file_put_contents("$t/chromium", "#!/bin/sh\n");
        chmod("$t/chromium", 0700);
        foreach (BrowseCheck::HERMES_FACTS as $fact) {
            @mkdir(dirname("$t/hermes/{$fact[0]}"), 0700, true);
            file_put_contents("$t/hermes/{$fact[0]}", (string) @file_get_contents("$t/hermes/{$fact[0]}") . $fact[1] . "\n");
        }
        mkdir("$t/hermes/pm", 0700, true);
        file_put_contents("$t/hermes/pm/lock.json", json_encode(['packages' => ['agent-browser' => ['version' => '0.26.0',
            'artifacts' => ['any' => ['sha256' => str_repeat('a', 64), 'url' => 'https://registry.example.org/a.tgz']]]]]));
        file_put_contents("$t/config.yaml", "browser:\n  restrict_evaluate: true\n");
        BrowsePaths::$chromium = "$t/chromium";
        BrowsePaths::$local = "$t/local";
        BrowseCheck::$source = "$t/hermes";
        BrowseCheck::$userConfig = "$t/config.yaml";
        BrowseCheck::$procSys = "$t/nosys";
        BrowseCheck::$which = function ($tool) {
            return "/usr/bin/$tool";
        };
        BrowseBinary::$lockFile = "$t/hermes/pm/lock.json";
        check(BrowsePaths::ensureDirs() === '', 'dirs');
        mkdir("$t/local/browse/bin/0.26.0", 0700);
        file_put_contents(BrowseBinary::path('0.26.0'), "#!/bin/sh\n");
        file_put_contents(BrowseBinary::recordFile('0.26.0'), json_encode(['tarball' => str_repeat('a', 64), 'binary' => hash_file('sha256', BrowseBinary::path('0.26.0'))]));
        $proc = new FakeBrowseProc();
        $sup = new BrowseSupervisor($proc);
        $sup->identity = ['user' => 'exampleuser', 'uid' => BrowsePaths::uid(), 'gid' => 1000, 'fqdn' => 'host.example.com', 'home' => '/home/exampleuser'];
        $sup->usage = $usage !== null ? $usage : function () {
            return [['memory_max' => null, 'memory_current' => null, 'memory_shared' => false], null];
        };
        return $fn($sup, $proc, $t);
    } finally {
        BrowsePaths::$chromium = null;
        BrowsePaths::$local = null;
        BrowseCheck::$source = null;
        BrowseCheck::$userConfig = null;
        BrowseCheck::$procSys = '/proc/sys';
        BrowseCheck::$which = null;
        BrowseBinary::$lockFile = null;
        testRmTree($t);
    }
}

/** True when every process the fake started is dead and the state is gone. */
function browseAllStopped(FakeBrowseProc $proc)
{
    return $proc->alive === [] && BrowseState::read() === null;
}

return [
    'browse supervisor: a start runs holder, slirp, daemon (oom 1000), isolation check, guard, watcher, in that order' => function () {
        browseSupWorld(function ($sup, $proc) {
            check($sup->up() === [true, 'started'], 'started');
            $spawned = $proc->spawned();
            check(count($spawned) === 4 && $spawned[0][1][0] === 'unshare' && $spawned[1][1][0] === 'slirp4netns' && $spawned[2][1][0] === 'nsenter'
                && end($spawned[3][1]) === 'watch', json_encode(array_map(function ($c) {
                    return $c[1][0];
                }, $spawned)));
            check($spawned[1][1][6] === '100', 'slirp joins through the outer holder (pid 100), not the inner one');
            $guard = $spawned[2][1];
            check(array_slice($guard, 0, 7) === ['nsenter', '--preserve-credentials', '-U', '-n', '-m', '-t', '101'], 'the guard runs inside, through the inner holder');
            check($guard[array_search('--host', $guard, true) + 1] === 'host.example.com' && $guard[array_search('--self', $guard, true) + 1] === 'exampleuser'
                && !in_array('--auth-file', $guard, true), 'host and account passed; no auth file stored, none passed');
            $runs = array_values(array_filter($proc->calls, function ($c) {
                return $c[0] === 'run';
            }));
            $open = implode(' ', $runs[1][1]);
            check(strpos($open, 'nsenter --preserve-credentials -U -n -m -t 101 -- choom -n 1000 -- ') === 0 && strpos($open, '--session pikkumies --json open about:blank') !== false, $open);
            check($runs[1][2]['AGENT_BROWSER_SOCKET_DIR'] === BrowsePaths::dirs()['run'] && $runs[1][2]['AGENT_BROWSER_EXECUTABLE_PATH'] === BrowsePaths::chromium()
                && !isset($runs[1][2]['AGENT_BROWSER_IDLE_TIMEOUT_MS']), 'the daemon gets only the unit\'s environment');
            check($runs[3][1] === ['ss', '-ltnpH'], 'the isolation check runs in the host namespace before the guard starts');
            $s = BrowseState::read();
            foreach (['outer', 'inner', 'slirp', 'daemon', 'guard', 'watcher'] as $part) {
                check(isset($s[$part]['pid'], $s[$part]['started']), "state has $part");
            }
            check($s['net'] === 'net:[2]' && count($proc->oom) === 1, 'namespace recorded, every namespace process raised to oom 1000');
            $before = count($proc->calls);
            check($sup->up() === [true, 'already running'] && count($proc->calls) === $before, 'a second up starts nothing');
        });
    },
    'browse supervisor: a stored panel header (own, 0600) goes to the guard; a looser one does not' => function () {
        browseSupWorld(function ($sup, $proc, $t) {
            mkdir("$t/local/secrets", 0700);
            file_put_contents(BrowsePaths::authFile(), "Basic ZXhhbXBsZTpleGFtcGxl\n");
            chmod(BrowsePaths::authFile(), 0640);
            check(!BrowseSupervisor::authFileUsable(BrowsePaths::authFile(), BrowsePaths::uid()), '0640 is not used');
            chmod(BrowsePaths::authFile(), 0600);
            check($sup->up()[0], 'started');
            $guard = $proc->spawned()[2][1];
            check($guard[array_search('--auth-file', $guard, true) + 1] === BrowsePaths::authFile(), 'the file path, never its content: ' . json_encode($guard));
            check(strpos(json_encode($proc->calls), 'ZXhhbXBs') === false, 'the header never appears in any argv');
        });
    },
    'browse supervisor: where the panel accepts the agent, its own login goes to the guard in a 0600 file that is gone once the guard has read it (ADR-0079)' => function () {
        browseSupWorld(function ($sup, $proc, $t) {
            mkdir("$t/home/.lighttpd", 0751, true);
            file_put_contents("$t/home/.lighttpd.conf", "auth.require = (\n \"/user-exampleuser/\" =>\n    (\n        \"realm\" => \"Password protected area (exampleuser)\",\n"
                . "        \"require\" => \"user=exampleuser|user=pikkumies.agent\"\n    )\n)\n");
            file_put_contents("$t/home/.lighttpd/.htpasswd", "exampleuser:kept-as-is\n");
            mkdir("$t/local/secrets", 0700);
            file_put_contents(BrowsePaths::authFile(), "Basic ZXhhbXBsZTpleGFtcGxl\n"); // a stored header: the agent's own login wins
            chmod(BrowsePaths::authFile(), 0600);
            file_put_contents(BrowsePaths::file('panelAuth'), "stale\n"); // left by a killed start
            $sup->identity['home'] = "$t/home";
            check($sup->up()[0], 'started');
            $guard = $proc->spawned()[2][1];
            check($guard[array_search('--auth-file', $guard, true) + 1] === BrowsePaths::file('panelAuth'), 'the agent header file: ' . json_encode($guard));
            $secret = json_decode((string) file_get_contents("$t/local/secrets/panel-agent"), true)['password'];
            check($proc->authSeen === ['Basic ' . base64_encode("pikkumies.agent:$secret") . "\n", 0600], 'the agent login, 0600, at the guard\'s start: ' . json_encode($proc->authSeen));
            check(!file_exists(BrowsePaths::file('panelAuth')), 'deleted once the guard is ready');
            check(strpos(json_encode($proc->calls), $secret) === false && strpos(json_encode($proc->calls), base64_encode("pikkumies.agent:$secret")) === false, 'never in any argv');
            check(PanelHtpasswd::lines(file_get_contents("$t/home/.lighttpd/.htpasswd"))[0] === 'exampleuser:kept-as-is', 'account line kept');
        });
        browseSupWorld(function ($sup, $proc, $t) {
            mkdir("$t/home/.lighttpd", 0751, true);
            file_put_contents("$t/home/.lighttpd.conf", "auth.require = (\n \"/user-exampleuser/\" => ( \"require\" => \"user=exampleuser\" )\n)\n");
            file_put_contents("$t/home/.lighttpd/.htpasswd", "exampleuser:kept-as-is\n");
            $sup->identity['home'] = "$t/home";
            check($sup->up()[0] && $proc->authSeen === null, 'an older panel: no login passed, the browser still starts');
            check(file_get_contents("$t/home/.lighttpd/.htpasswd") === "exampleuser:kept-as-is\n" && !file_exists("$t/local/secrets/panel-agent"), 'nothing written');
        });
    },
    'browse supervisor: refusals before anything starts (memory, host name) and fail-closed stops after (leak, no guard)' => function () {
        browseSupWorld(function ($sup, $proc) {
            list($ok, $why) = $sup->up();
            check(!$ok && strpos($why, 'not starting the browser') !== false && $proc->calls === [], "memory gate, nothing started: $why");
        }, function () {
            return [['memory_max' => 1073741824.0, 'memory_current' => 943718400.0, 'memory_shared' => false], null];
        });
        browseSupWorld(function ($sup, $proc) {
            $sup->identity['fqdn'] = 'localhost';
            list($ok, $why) = $sup->up();
            check(!$ok && strpos($why, 'panel host name') !== false && $proc->calls === [], "no panel host, no guard, nothing started: $why");
        });
        browseSupWorld(function ($sup, $proc) {
            $proc->leak = true;
            list($ok, $why) = $sup->up();
            check(!$ok && strpos($why, 'shared network namespace') !== false && strpos($why, '127.0.0.1:36841') !== false, $why);
            check(browseAllStopped($proc) && count($proc->spawned()) === 2, 'holder and slirp stopped, no guard started, no state left');
        });
        browseSupWorld(function ($sup, $proc) {
            $proc->guardReady = false;
            list($ok, $why) = $sup->up();
            check(!$ok && strpos($why, 'guard did not attach') !== false && browseAllStopped($proc), "no ready file: $why");
            check(strpos((string) file_get_contents(BrowsePaths::file('log')), 'stopped: start failed') !== false, 'logged');
        });
    },
    'browse supervisor: the watcher stops everything when the guard is gone, or after 15 idle minutes' => function () {
        browseSupWorld(function ($sup, $proc) {
            check($sup->up()[0], 'started');
            $s = BrowseState::read();
            $proc->now = time();
            check($sup->tick($s['watcher']['pid']) === '' && count($proc->oom) === 2, 'all alive and recent: keep watching, oom raised again');
            check($sup->tick(12345) === 'another watcher took over', 'a stale watcher leaves');
            unset($proc->alive[$s['guard']['pid']]); // the guard exits (2) when its CDP connection drops
            check($sup->tick($s['watcher']['pid']) === 'guard stopped', 'guard gone');
            check(browseAllStopped($proc), 'every part killed, state gone');
            $signals = array_unique(array_map(function ($c) {
                return $c[2];
            }, array_filter($proc->calls, function ($c) {
                return $c[0] === 'kill';
            })));
            check(array_values($signals) === ['TERM'], 'asked to stop first (TERM); KILL only for what is still there: ' . json_encode($signals));
        });
        browseSupWorld(function ($sup, $proc) {
            check($sup->up()[0], 'started');
            $s = BrowseState::read();
            $proc->now = time() + BrowseSupervisor::IDLE + 60;
            check($sup->tick($s['watcher']['pid']) === 'idle' && browseAllStopped($proc), 'idle stop');
            check(strpos((string) file_get_contents(BrowsePaths::file('log')), 'stopped: idle for 16 minutes') !== false, 'logged');
        });
    },
    'browse cli: a call starts the browser, announces the open to the guard, and runs only inside the namespace' => function () {
        browseSupWorld(function ($sup, $proc) {
            ob_start();
            $code = BrowseCli::main(['--session', 'h_1', '--json', 'open', 'https://host.example.com/user-exampleuser/'], $sup);
            $out = ob_get_clean();
            check($code === 0 && strpos($out, '"success":true') !== false, "ran: $out");
            check(trim((string) file_get_contents(BrowsePaths::file('intent'))) === 'https://host.example.com/user-exampleuser/'
                && (fileperms(BrowsePaths::file('intent')) & 0777) === 0600, 'the intent, 0600');
            $last = end($proc->calls);
            check(array_slice($last[1], 0, 8) === ['nsenter', '--preserve-credentials', '-U', '-n', '-m', '-t', '101', '--']
                && array_slice($last[1], 9) === ['--session', 'pikkumies', '--json', 'open', 'https://host.example.com/user-exampleuser/'], json_encode($last[1]));
            ob_start();
            $code = BrowseCli::main(['--json', '--cdp', '9222', 'open', 'https://example.com'], $sup);
            $out = ob_get_clean();
            check($code === 1 && json_decode($out, true)['success'] === false && strpos($out, '--cdp') !== false, "refused: $out");
            $s = BrowseState::read();
            unset($proc->alive[$s['guard']['pid']]);
            $proc->guardReady = false;
            ob_start();
            $code = BrowseCli::main(['--json', 'snapshot'], $sup);
            $out = ob_get_clean();
            check($code === 1 && strpos($out, 'guard did not attach') !== false && browseAllStopped($proc), "guard gone and cannot come back: fail closed: $out");
        });
    },
];
