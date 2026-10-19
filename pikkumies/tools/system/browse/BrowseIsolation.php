<?php
/**
 * BrowseIsolation: the namespace the browser runs in, and the check that it really is isolated.
 *
 * agent-browser 0.26 drives Chrome over an unauthenticated CDP port on 127.0.0.1 and always opens an unauthenticated
 * stream WebSocket next to it; it has no pipe mode. On a shared server any co-tenant can reach the host's 127.0.0.1,
 * so both run in a private network namespace of the account's own, made without root:
 * - outer: `unshare --user --map-root-user --net --mount`; it brings lo up and binds a resolv.conf naming slirp's
 *   resolver (10.0.2.3) over /etc/resolv.conf (a host resolver on 127.x is unreachable from inside). It stays alive:
 *   slirp4netns joins the namespace through this process's user namespace.
 * - inner: a nested `unshare --user --map-user=<uid> --map-group=<gid>` sleeper, so everything entered through it runs
 *   as the account's own uid and Chrome keeps its sandbox. Every command enters through it with nsenter.
 * - outbound: `slirp4netns --configure --disable-host-loopback`, so pages reach the panel on the host's public
 *   address and outside sites, but never the host's 127.0.0.1 (10.0.2.2 is not forwarded).
 * Verified on a Debian 13 development host (util-linux 2.41, slirp4netns 1.2.1, agent-browser 0.26.0): CDP and stream
 * ports listen only inside, the CLI reaches the daemon over its unix socket, outbound HTTPS and DNS work, host loopback
 * does not.
 */
class BrowseIsolation
{
    /** Process names (comm) of the browser stack; one of them listening in the host's network namespace is a leak. */
    const BROWSER_NAMES = ['agent-browser', 'chrome', 'chromium', 'chrome_crashpad', 'headless_shell'];
    /** slirp4netns' fixed addresses with --configure: the namespace's own address and its resolver. */
    const TAP_ADDRESS = '10.0.2.100';
    const RESOLVER = '10.0.2.3';

    public static function resolvConf()
    {
        return 'nameserver ' . self::RESOLVER . "\n";
    }

    /** The holder, started detached; its pid is the outer process. The script's values come in as $1..$3, never spliced. */
    public static function holderArgv($resolv, $uid, $gid)
    {
        return ['unshare', '--user', '--map-root-user', '--net', '--mount', 'sh', '-c',
            'ip link set lo up && mount --bind "$1" /etc/resolv.conf && unshare --user --map-user="$2" --map-group="$3" sleep infinity',
            'pikkumies-browse', $resolv, (string) (int) $uid, (string) (int) $gid];
    }

    /** $cmd run inside the namespace as the account: user, network and mount namespaces of the inner holder. */
    public static function nsenterArgv($innerPid, array $cmd)
    {
        return array_merge(['nsenter', '--preserve-credentials', '-U', '-n', '-m', '-t', (string) (int) $innerPid, '--'], $cmd);
    }

    public static function slirpArgv($outerPid)
    {
        return ['slirp4netns', '--configure', '--mtu=65520', '--disable-host-loopback', '--enable-sandbox', '--enable-seccomp', (string) (int) $outerPid, 'tap0'];
    }

    /**
     * `ss -ltnpH` lines -> [['local' => '127.0.0.1:36841', 'procs' => [['name' => 'chrome', 'pid' => 1528941], ...]], ...].
     * ss shows the users:(...) part only for processes the caller may see, which on a hidepid=2 host are its own.
     */
    public static function parseListeners($out)
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', (string) $out) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 5 || $f[0] !== 'LISTEN') {
                continue;
            }
            $procs = [];
            if (preg_match_all('/\("((?:[^"\\\\]|\\\\.)*)",pid=(\d+)/', $line, $m, PREG_SET_ORDER)) {
                foreach ($m as $p) {
                    $procs[] = ['name' => stripcslashes($p[1]), 'pid' => (int) $p[2]];
                }
            }
            $rows[] = ['local' => $f[3], 'procs' => $procs];
        }
        return $rows;
    }

    /** The listeners held by a browser-stack process name or by one of $pids: each is a leak into the shared netns. */
    public static function leaks(array $listeners, array $pids = [])
    {
        return array_values(array_filter($listeners, function ($row) use ($pids) {
            foreach ($row['procs'] as $p) {
                if (in_array($p['pid'], $pids, true) || self::isBrowserName($p['name'])) {
                    return true;
                }
            }
            return false;
        }));
    }

    public static function isBrowserName($name)
    {
        foreach (self::BROWSER_NAMES as $n) {
            if ($name === $n || strpos($name, $n . '-') === 0 || strpos($name, $n . '_') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * '' when isolated, else the failing check: the daemon must sit in the holder's network namespace, not the host's,
     * and `ss -ltnpH` in the host namespace must show no listener of the browser stack. $ss: [exit code, output];
     * an ss that fails is a failed check (fail closed).
     */
    public static function selfCheck($daemonNet, $holderNet, $hostNet, array $ss, array $pids)
    {
        if ($daemonNet === '' || $daemonNet !== $holderNet || $daemonNet === $hostNet) {
            return 'the browser daemon is not inside the private network namespace';
        }
        if ($ss[0] !== 0) {
            return 'ss -ltnpH failed, so the host network namespace could not be checked';
        }
        $leaks = self::leaks(self::parseListeners($ss[1]), $pids);
        if ($leaks) {
            return 'a browser process listens in the shared network namespace (' . implode(', ', array_map(function ($r) {
                return $r['local'];
            }, $leaks)) . ')';
        }
        return '';
    }

    /** This account's processes in network namespace $net (e.g. "net:[4026533331]"), for the stop path. */
    public static function members($net, $uid, $proc = '/proc')
    {
        $out = [];
        foreach ((array) glob("$proc/[0-9]*", GLOB_ONLYDIR) as $dir) {
            if (@fileowner($dir) === (int) $uid && @readlink("$dir/ns/net") === $net) {
                $out[] = (int) basename($dir);
            }
        }
        sort($out);
        return $out;
    }
}
