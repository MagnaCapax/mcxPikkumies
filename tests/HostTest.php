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
    'host: unix socket owner check' => function () {
        $tmp = sys_get_temp_dir() . '/pk-sock-' . getmypid() . '.sock';
        @unlink($tmp);
        $s = @stream_socket_server("unix://$tmp");
        if ($s === false) {
            return; // unix sockets unavailable here
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : fileowner($tmp);
        check(Host::ownsSocket($tmp, $uid), 'own socket not recognised');
        check(!Host::ownsSocket($tmp, $uid + 1), 'foreign uid accepted');
        check(!Host::ownsSocket(__FILE__, $uid), 'a regular file is not a socket');
        fclose($s);
        @unlink($tmp);
    },
];
