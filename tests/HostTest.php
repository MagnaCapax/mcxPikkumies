<?php
/** lib/Host.php: host family and the owner check of local listeners. */
function hostTcpTable(array $rows)
{
    $t = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n";
    foreach ($rows as $i => $r) {
        list($addr, $port, $state, $uid) = $r;
        $t .= sprintf("%4d: %s:%04X 00000000:0000 %s 00000000:00000000 00:00000000 00000000 %5d        0 %d 1\n", $i, $addr, $port, $state, $uid, 1000 + $i);
    }
    return $t;
}

return [
    'host: pmss first, then hostname suffix, else generic' => function () {
        $tmp = sys_get_temp_dir() . '/pk-host-' . getmypid();
        @mkdir("$tmp/config", 0700, true);
        check(Host::family("$tmp/config", 'x.feralhosting.com') === 'pmss', 'pmss marker must win');
        check(Host::family("$tmp/none", 'zeus.feralhosting.com') === 'feral', 'feral not found');
        check(Host::family("$tmp/none", 'u.s.usbx.me') === 'ultra', 'ultra not found');
        check(Host::family("$tmp/none", 'box.whatbox.ca') === 'whatbox', 'whatbox not found');
        check(Host::family("$tmp/none", 'notwhatbox.ca') === 'generic', 'suffix must match a whole label');
        check(Host::family("$tmp/none", 'myserver.example') === 'generic', 'generic fallback');
    },
    'host: a listener on the port owned by another account is refused' => function () {
        $mine = hostTcpTable([['0100007F', 8080, '0A', 1001]]);
        check(Host::ownsListener(8080, 1001, [$mine, '']) === 'own', 'own listener not recognised');
        $theirs = hostTcpTable([['0100007F', 8080, '0A', 1002]]);
        check(Host::ownsListener(8080, 1001, [$theirs, '']) === 'other', 'foreign listener not refused');
        check(Host::ownsListener(9090, 1001, [$mine, '']) === 'none', 'missing listener not reported');
        check(Host::ownsListener(8080, 1001, [false, false]) === 'unknown', 'unreadable /proc/net must not look like no listener');
        $v6 = hostTcpTable([['00000000000000000000000001000000', 8080, '0A', 1002]]);
        check(Host::ownsListener(8080, 1001, [$mine, $v6]) === 'other', 'foreign ::1 listener missed');
        $lo2 = hostTcpTable([['0200007F', 8080, '0A', 1002]]);
        check(Host::ownsListener(8080, 1001, [$mine, $lo2]) === 'other', 'a foreign listener on 127.0.0.2 was missed (all of 127/8 is local)');
        $mapped = hostTcpTable([['0000000000000000FFFF00000100007F', 8080, '0A', 1002]]);
        check(Host::ownsListener(8080, 1001, [$mine, $mapped]) === 'other', 'a foreign IPv4-mapped listener was missed');
        $established = hostTcpTable([['0100007F', 8080, '01', 1002]]);
        check(Host::ownsListener(8080, 1001, [$mine, $established]) === 'own', 'a non-LISTEN socket must not count');
        $public = hostTcpTable([['0A00020F', 8080, '0A', 1002]]);
        check(Host::ownsListener(8080, 1001, [$mine . substr($public, strpos($public, "\n") + 1), '']) === 'own', 'a listener on another address must not count');
        check(Host::ownsListener(8080, 1001, [$public, ''], ['15.2.0.10']) === 'other', 'a foreign listener on the address connected to was missed');
        check(Host::ownsListener(8080, [1001, 1002], [$theirs, '']) === 'own', 'an allowed owner uid was refused');
        check(Host::procAddresses('127.0.0.1') === ['0100007F', '0000000000000000FFFF00000100007F'], 'IPv4 /proc notation wrong');
        check(Host::procAddresses('::1') === ['00000000000000000000000001000000'], 'IPv6 /proc notation wrong');
    },
    'host: TABLE drives family() for every provider row, by its first suffix (ADR-0023)' => function () {
        foreach (Host::TABLE as $key => $row) {
            if ($row['type'] !== 'provider' || !$row['suffixes']) {
                continue;
            }
            check(Host::family('/nonexistent-pmss-marker', 'box.' . $row['suffixes'][0]) === $key, "$key not matched by its own suffix {$row['suffixes'][0]}");
        }
        check(Host::family('/nonexistent-pmss-marker', 'x.bytesized-hosting.com') === 'bytesized', 'bytesized second suffix (bytesized-hosting.com) not matched');
        check(Host::family('/nonexistent-pmss-marker', 'box.seedbox.vip') === 'generic', 'rapidseedbox must have no suffix row (seedbox.vip is an app-proxy domain, not a shell hostname)');
        check(!array_key_exists('rapidseedbox', Host::TABLE), 'TABLE must not carry a rapidseedbox row');
    },
    'host: ultra matches by its app-stats marker even with no usbx.me hostname' => function () {
        $tmp = sys_get_temp_dir() . '/pk-marker-' . getmypid();
        @mkdir("$tmp/bin", 0700, true);
        touch("$tmp/bin/app-stats");
        chmod("$tmp/bin/app-stats", 0755);
        $home = getenv('HOME');
        $path = getenv('PATH');
        putenv('HOME=' . $tmp);
        putenv('PATH=/nonexistent-path-dir');
        try {
            check(Host::family('/nonexistent-pmss-marker', 'myserver.example') === 'ultra', 'app-stats in ~/bin must be found even off PATH');
        } finally {
            putenv('HOME=' . $home);
            putenv('PATH=' . $path);
            @unlink("$tmp/bin/app-stats");
            @rmdir("$tmp/bin");
            @rmdir($tmp);
        }
    },
    'host: stack() finds swizzin/quickbox/saltbox by marker, pmss first, else none' => function () {
        $tmp = sys_get_temp_dir() . '/pk-stack-' . getmypid();
        @mkdir("$tmp/pmss/config", 0700, true);
        check(Host::stack("$tmp/pmss/config", $tmp) === 'pmss', 'pmss marker must win over any stack row');
        check(Host::stack("$tmp/none/config", $tmp) === 'none', 'no marker present must report none');
        foreach (['swizzin' => '/etc/swizzin', 'quickbox' => '/srv/rutorrent', 'saltbox' => '/srv/git/saltbox'] as $name => $marker) {
            $root = "$tmp-$name";
            @mkdir($root . $marker, 0700, true);
            check(Host::stack("$root/no-pmss-here", $root) === $name, "$name not found by its own marker $marker");
        }
    },
    'host: logGlobs() reads TABLE for the detected stack, falling back to family' => function () {
        check(Host::logGlobs('pmss', 'pmss') === ['.lighttpd/access.log', '.lighttpd/error.log', 'rTorrentLog'], 'pmss log globs wrong');
        check(Host::logGlobs('generic', 'none') === [], 'no family/stack row must give no globs');
        check(Host::logGlobs('feral', 'none') === [], 'feral carries no documented account-owned log path');
    },
    'host: every TABLE row documents its own source, and verified is a date only where the orchestrator re-checked it this session' => function () {
        foreach (Host::TABLE as $key => $row) {
            check(is_string($row['source']) && strncmp($row['source'], 'http', 4) === 0, "$key: source must be a URL");
            check($row['verified'] === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['verified']) === 1, "$key: verified must be null or YYYY-MM-DD");
        }
        // whatbox, bytesized, hostingbydesign and seedhost: the hostname text was fetched from each one's own page on 2026-10-15 (ADR-0027).
        foreach (['feral', 'ultra', 'whatbox', 'bytesized', 'hostingbydesign', 'seedhost'] as $key) {
            check(Host::TABLE[$key]['verified'] === '2026-10-15', "$key must be marked verified (orchestrator spot-checked it at source)");
        }
    },
    'host: unix socket owner check' => function () {
        $tmp = sys_get_temp_dir() . '/pk-sock-' . getmypid() . '.sock';
        @unlink($tmp);
        $s = @stream_socket_server("unix://$tmp");
        if ($s === false) {
            skip('unix sockets unavailable here');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : fileowner($tmp);
        check(Host::ownsSocket($tmp, $uid), 'own socket not recognised');
        check(!Host::ownsSocket($tmp, $uid + 1), 'foreign uid accepted');
        check(!Host::ownsSocket(__FILE__, $uid), 'a regular file is not a socket');
        fclose($s);
        @unlink($tmp);
    },
    'host: dockerSocket() only trusts a unix:// DOCKER_HOST owned by the asked-about uid; tcp:// and another uid\'s socket are refused' => function () {
        $tmp = sys_get_temp_dir() . '/pk-docksock-' . getmypid() . '.sock';
        @unlink($tmp);
        $s = @stream_socket_server("unix://$tmp");
        if ($s === false) {
            skip('unix sockets unavailable here');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : fileowner($tmp);
        testWithEnv(['DOCKER_HOST' => "unix://$tmp"], function () use ($tmp, $uid) {
            check(Host::dockerSocket($uid) === $tmp, 'an owned unix:// DOCKER_HOST must be used');
            check(Host::dockerSocket($uid + 1) === '', 'a socket owned by a DIFFERENT uid than the one asked about must be refused: '
                . Host::dockerSocket($uid + 1));
        });
        testWithEnv(['DOCKER_HOST' => 'tcp://127.0.0.1:2375'], function () use ($uid) {
            check(Host::dockerSocket($uid) === '', 'tcp:// DOCKER_HOST must never be used (ADR-0019: only this account\'s own unix socket)');
        });
        fclose($s);
        @unlink($tmp);
    },
    'host: family() also matches a marker found via a PATH directory executable, not only ~/bin' => function () {
        $tmp = sys_get_temp_dir() . '/pk-pathmarker-' . getmypid();
        @mkdir($tmp, 0700, true);
        touch("$tmp/app-stats");
        chmod("$tmp/app-stats", 0755);
        testWithEnv(['HOME' => '/nonexistent-pk-home-for-marker-test', 'PATH' => $tmp], function () {
            check(Host::family('/nonexistent-pmss-marker', 'myserver.example') === 'ultra', 'app-stats found via a PATH directory '
                . '(not ~/bin) must still match');
        });
        @unlink("$tmp/app-stats");
        @rmdir($tmp);
    },
    'host: ownsSocket() reports false for a nonexistent path and for an existing FIFO (not a socket)' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        check(Host::ownsSocket('/pk-does-not-exist-anywhere-xyz', $uid) === false, 'a nonexistent path must not be an owned socket');
        if (!function_exists('posix_mkfifo')) {
            skip('posix_mkfifo unavailable here');
        }
        $fifo = sys_get_temp_dir() . '/pk-fifo-' . getmypid();
        @unlink($fifo);
        if (!posix_mkfifo($fifo, 0600)) {
            skip('mkfifo failed on this filesystem');
        }
        check(Host::ownsSocket($fifo, $uid) === false, 'a FIFO must not be mistaken for a unix socket');
        @unlink($fifo);
    },
    'host: procAddresses() returns [] for an unparseable IP, never a bogus always-matching hex string' => function () {
        check(Host::procAddresses('not-an-ip') === [], 'an unparseable IP must yield no addresses, never a wrong/always-matching one');
        check(Host::procAddresses('') === [], 'an empty string must yield no addresses');
    },
    '[contract] host: every TABLE marker is an absolute path or a bare safe executable name (no relative traversal, no shell metacharacters)' => function () {
        foreach (Host::TABLE as $key => $row) {
            foreach ($row['markers'] as $marker) {
                check($marker === '' || $marker[0] === '/' || preg_match('/^[\w.-]+$/', $marker) === 1,
                    "$key: marker '$marker' is neither an absolute path nor a bare safe executable name");
            }
        }
    },
    'host: ownsListener() skips a short line (e.g. /proc/net/tcp\'s own header row) instead of matching its stray tokens' => function () {
        $header = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n";
        check(Host::ownsListener(8080, 1001, [$header, '']) === 'none', 'a short header-shaped line must never be read as a listener: '
            . Host::ownsListener(8080, 1001, [$header, '']));
    },
    'host: every TABLE row carries a docker key: null, or PMSS\'s facts with ports, dropped ports, a recovery link, source and verified (ADR-0034)' => function () {
        foreach (Host::TABLE as $key => $row) {
            check(array_key_exists('docker', $row), "$key: no docker key");
            check($row['docker'] === null || is_array($row['docker']), "$key: docker must be null or a facts array");
        }
        $d = Host::TABLE['pmss']['docker'];
        check($d['daemon'] === 'platform' && $d['ports'] === [38001, 49999] && $d['inbound_dropped'] === [8080, 8112], 'pmss docker facts: ' . json_encode($d));
        check(strncmp($d['source'], 'https://', 8) === 0 && strncmp($d['recovery'], 'https://', 8) === 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['verified']) === 1, 'source, recovery and verified');
        check(!preg_match('/\bpkill\b|\bkill\b/', json_encode($d)), 'the recovery is a link, never a kill command');
        check(Host::dockerFacts('generic', 'pmss') === $d && Host::dockerFacts('feral', 'none') === null && Host::dockerFacts('generic', 'none') === null, 'dockerFacts() follows logGlobs(): stack, then family');
    },
    'host: dockerSocket() falls back to /var/run/docker.sock only for uid 0, and tries candidates in order, through a symlinked directory too' => function () {
        check(Host::dockerSocketCandidates(0) === ['/run/user/0/docker.sock', '/var/run/docker.sock'], 'uid 0: its runtime dir, then root\'s own daemon');
        check(Host::dockerSocketCandidates(1000) === ['/run/user/1000/docker.sock'], 'any other uid never gets root\'s socket');
        $dir = testTempDir('pk-docksock-cand');
        try {
            mkdir("$dir/real");
            $s = @stream_socket_server("unix://$dir/real/docker.sock");
            if ($s === false) {
                skip('unix sockets unavailable here');
            }
            symlink("$dir/real", "$dir/run"); // /var/run -> /run on most systems
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            testWithEnv(['DOCKER_HOST' => null], function () use ($dir, $uid) {
                check(Host::dockerSocket($uid, ["$dir/missing.sock", "$dir/run/docker.sock"]) === "$dir/run/docker.sock", 'the first owned candidate, reached through a symlinked directory');
                check(Host::dockerSocket($uid + 1, ["$dir/run/docker.sock"]) === '', 'a candidate owned by another uid is refused');
                check(Host::dockerSocket($uid, []) === '', 'no candidates, no socket');
            });
            testWithEnv(['DOCKER_HOST' => 'tcp://127.0.0.1:2375'], function () use ($dir, $uid) {
                check(Host::dockerSocket($uid, ["$dir/run/docker.sock"]) === '', 'a tcp:// DOCKER_HOST wins over candidates and is refused');
            });
            fclose($s);
        } finally {
            testRmTree($dir);
        }
    },
];
