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
];
