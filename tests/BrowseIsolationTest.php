<?php
/** tools/system/browse/BrowseIsolation.php: the namespace commands and the isolation self-check (ADR-0077). */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/browse.inc.php';

/** `ss -ltnpH` output in the shape iproute2 6.15 prints it (captured on the development host, addresses kept). */
function browseSsFixture($extra = '')
{
    return "LISTEN 0      4096       127.0.0.1:34223 0.0.0.0:*\n"
        . "LISTEN 0      128          0.0.0.0:22    0.0.0.0:*\n"
        . "LISTEN 0      4096       127.0.0.1:8080  0.0.0.0:*    users:((\"rtorrent main\",pid=4100,fd=7))\n"
        . "LISTEN 0      511        127.0.0.1:9000  0.0.0.0:*    users:((\"php-fpm\",pid=4200,fd=6),(\"php-fpm\",pid=4201,fd=6))\n" . $extra;
}

return [
    'browse isolation: ss -ltnpH lines parse into address and owning processes' => function () {
        $rows = BrowseIsolation::parseListeners(browseSsFixture());
        check(count($rows) === 4, json_encode($rows));
        check($rows[0] === ['local' => '127.0.0.1:34223', 'procs' => []], 'a listener of another account shows no process');
        check($rows[2]['procs'] === [['name' => 'rtorrent main', 'pid' => 4100]], 'a name with a space: ' . json_encode($rows[2]));
        check(count($rows[3]['procs']) === 2 && $rows[3]['procs'][1]['pid'] === 4201, 'several processes on one socket');
        check(BrowseIsolation::parseListeners("garbage\n\nState Recv-Q\n") === [], 'no LISTEN line, no row');
    },
    'browse isolation: a browser-stack listener in the host namespace is a leak; everything else is not' => function () {
        check(BrowseIsolation::leaks(BrowseIsolation::parseListeners(browseSsFixture())) === [], 'rtorrent and php-fpm are not the browser');
        foreach (['chrome', 'chromium', 'chrome_crashpad', 'headless_shell', 'agent-browser', 'agent-browser-l'] as $name) {
            $out = browseSsFixture("LISTEN 0      10         127.0.0.1:36841 0.0.0.0:*    users:((\"$name\",pid=5100,fd=83))\n");
            $leaks = BrowseIsolation::leaks(BrowseIsolation::parseListeners($out));
            check(count($leaks) === 1 && $leaks[0]['local'] === '127.0.0.1:36841', "$name: " . json_encode($leaks));
        }
        $byPid = BrowseIsolation::leaks(BrowseIsolation::parseListeners(browseSsFixture("LISTEN 0 10 127.0.0.1:45569 0.0.0.0:* users:((\"renamed\",pid=5200,fd=9))\n")), [5200]);
        check(count($byPid) === 1, 'the daemon pid listening is a leak whatever its name');
        check(!BrowseIsolation::isBrowserName('chromeless') && !BrowseIsolation::isBrowserName('xchrome'), 'only the stack names, not look-alikes');
    },
    'browse isolation: the self-check fails closed on a daemon outside the namespace, a failed ss, or a leak' => function () {
        $ok = BrowseIsolation::selfCheck('net:[2]', 'net:[2]', 'net:[1]', [0, browseSsFixture()], [5100]);
        check($ok === '', "isolated: $ok");
        check(BrowseIsolation::selfCheck('net:[1]', 'net:[2]', 'net:[1]', [0, ''], []) !== '', 'daemon in the host namespace');
        check(BrowseIsolation::selfCheck('', 'net:[2]', 'net:[1]', [0, ''], []) !== '', 'daemon namespace unreadable');
        check(BrowseIsolation::selfCheck('net:[2]', 'net:[2]', 'net:[1]', [1, ''], []) !== '', 'ss failed');
        $leak = BrowseIsolation::selfCheck('net:[2]', 'net:[2]', 'net:[1]', [0, browseSsFixture("LISTEN 0 10 127.0.0.1:36841 0.0.0.0:* users:((\"chrome\",pid=5100,fd=83))\n")], []);
        check(strpos($leak, '127.0.0.1:36841') !== false, $leak);
    },
    'browse isolation: the holder keeps its outer shell (slirp joins through it), passes values as arguments, and nsenter enters user, net and mount' => function () {
        $h = BrowseIsolation::holderArgv('/home/exampleuser/pikkumies/local/browse/resolv.conf', 1000, 1000);
        check(array_slice($h, 0, 6) === ['unshare', '--user', '--map-root-user', '--net', '--mount', 'sh'], json_encode($h));
        check(strpos($h[7], 'exec unshare') === false && strpos($h[7], 'unshare --user --map-user="$2" --map-group="$3" sleep infinity') !== false, 'no exec: ' . $h[7]);
        check(strpos($h[7], '/home/') === false && array_slice($h, 9) === ['/home/exampleuser/pikkumies/local/browse/resolv.conf', '1000', '1000'], 'paths are $1..$3, never spliced into the script');
        check(BrowseIsolation::nsenterArgv(42, ['ip', 'a']) === ['nsenter', '--preserve-credentials', '-U', '-n', '-m', '-t', '42', '--', 'ip', 'a'], 'nsenter');
        $s = BrowseIsolation::slirpArgv(41);
        check(in_array('--disable-host-loopback', $s, true) && in_array('--configure', $s, true) && array_slice($s, -2) === ['41', 'tap0'], json_encode($s));
        check(BrowseIsolation::resolvConf() === "nameserver 10.0.2.3\n", 'slirp resolver');
    },
];
