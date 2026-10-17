<?php
/**
 * check reads only this account (ADR-0035 privacy rules P1, P2, P3, P6): the own-slice oracle on cgroup v1, v2 and
 * hybrid trees whose other account (and the aggregate user.slice files) carry FOREIGN-SENTINEL; the session query
 * filtered in the kernel by the SSH client address; no raw ss row in the output; the --share allowlist; the SSH
 * address only with --share --with-ip; other users' processes never counted; command lines reduced to the
 * whitelisted stream flags; no account or server name from userShowLimits.
 */
require_once __DIR__ . '/fixtures/performance/check-helpers.php';

/** Limit files may be read at the slice's ancestors (P1); every other cgroup file only inside the own slice. */
const PERF_PV_LIMIT_FILES = '/^(memory\.max|memory\.limit_in_bytes|cpu\.max|cpu\.cfs_quota_us|cpu\.cfs_period_us|io\.max|blkio\.throttle\.(read|write)_(bps|iops)_device)$/';

/** Usage files at the aggregate user.slice and at the root, carrying the sentinel: reading any of them is a P1 breach. */
function perfPvPlantAggregates(array $roots, $mode)
{
    $cg = $roots['/sys'] . '/fs/cgroup';
    $dirs = $mode === 'v1' ? ["$cg/memory/user.slice", "$cg/memory", "$cg/cpu,cpuacct/user.slice", "$cg/blkio/user.slice"]
        : ($mode === 'v2' ? ["$cg/user.slice", $cg] : ["$cg/unified/user.slice", "$cg/memory/user.slice", "$cg/cpu,cpuacct/user.slice", "$cg/blkio/user.slice"]);
    foreach ($dirs as $d) {
        foreach (['memory.stat', 'memory.current', 'memory.usage_in_bytes', 'memory.events', 'cpu.stat', 'cpuacct.usage', 'io.stat', 'blkio.throttle.io_service_bytes',
            'cpu.pressure', 'io.pressure', 'memory.pressure', 'memory.oom_control'] as $f) {
            perfWrite("$d/$f", PERF_FIXTURE_SENTINEL . "\n");
        }
    }
}

function perfPvAssertOwnOnly(array $fx, $out, $label)
{
    check(strpos($out, PERF_FIXTURE_SENTINEL) === false, "$label: the sentinel reached the output");
    check(perfBReadLog($fx, 'refused') === [], "$label: the code asked for a refused path: " . json_encode(perfBReadLog($fx, 'refused')));
    $log = is_file($fx['dir'] . '/run/read.jsonl') ? (string) file_get_contents($fx['dir'] . '/run/read.jsonl') : '';
    check(strpos($log, PERF_FIXTURE_SENTINEL) === false && strpos($log, 'user-' . PERF_FIXTURE_FOREIGN_UID) === false, "$label: the read log names the other account");
    foreach (perfBReadLog($fx) as $p) {
        if (strpos($p, '/sys/fs/cgroup') !== 0) {
            continue;
        }
        check(strpos($p, '/user-' . PERF_FIXTURE_UID . '.slice/') !== false || preg_match(PERF_PV_LIMIT_FILES, basename($p)), "$label: read a usage file outside the own slice: $p");
    }
}

return [
    'perf privacy: own-slice oracle, v1/v2/hybrid: no other account, no aggregate usage file, the sentinel nowhere; ancestor limits still bind' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            foreach (['v1', 'v2', 'hybrid'] as $mode) {
                $roots = perfFakeTree($fx['dir'] . "/tree-$mode", $mode);
                perfPvPlantAggregates($roots, $mode);
                @unlink($fx['dir'] . '/run/read.jsonl');
                list($recs) = perfBCheck($fx, $roots);
                perfPvAssertOwnOnly($fx, perfBJson($recs), $mode);
                $mem = perfBArea($recs, 'memory');
                check($mem['limit_bytes'] === 2147483648.0 && $mem['anon_bytes'] === 536870912.0, "$mode: own memory figures: " . json_encode($mem));
                check(count(array_filter(perfBReadLog($fx), function ($p) {
                    return strpos($p, '/user-' . PERF_FIXTURE_UID . '.slice/') !== false;
                })) >= 4, "$mode: the own slice was not read");
                $cg = $roots['/sys'] . '/fs/cgroup' . ($mode === 'v2' ? '' : '/memory');
                perfWrite("$cg/user.slice/" . ($mode === 'v2' ? 'memory.max' : 'memory.limit_in_bytes'), "1073741824\n");
                list($recs) = perfBCheck($fx, $roots);
                check(perfBArea($recs, 'memory')['limit_bytes'] === 1073741824.0, "$mode: a tighter ancestor limit must bind: " . json_encode(perfBArea($recs, 'memory')['limit_bytes']));
            }
        } finally {
            perfDone($fx);
        }
    },
    'perf privacy: through the dispatcher too: check prints no sentinel, no SSH address, no account or server name' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            perfPvPlantAggregates($roots, 'v2');
            list($code, $recs, $out) = perfRun($fx, ['check'], ['roots' => $roots, 'read_log' => $fx['dir'] . '/run/read.jsonl'], ['SSH_CONNECTION' => PERF_B_SSH]);
            check($code === 0 && count($recs) >= 2, "check exited $code: $out");
            perfPvAssertOwnOnly($fx, $out, 'dispatcher');
            foreach (['198.51.100.7', '192.0.2.10', '50123', 'fake-user-name', 'fake-host.example'] as $s) {
                check(strpos($out, $s) === false, "check printed $s");
            }
        } finally {
            perfDone($fx);
        }
    },
    'perf privacy: no cgroup of the account on a shared server: nothing under cgroupfs is read; on a server of its own the machine is labelled so' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            perfPvPlantAggregates($roots, 'v2');
            perfWrite($roots['/proc'] . '/self/cgroup', "0::/\n");
            list($recs) = perfBCheck($fx, $roots); // userShowLimits answers: PMSS, shared
            $acc = perfBArea($recs, 'account');
            check($acc['scope'] === null && $acc['source'] === 'userShowLimits' && isset($acc['unavailable_reason']), 'shared, no segment: ' . json_encode($acc));
            check(!array_filter(perfBReadLog($fx), function ($p) {
                return strpos($p, '/sys/fs/cgroup') === 0;
            }), 'a shared server without the segment read cgroupfs: ' . json_encode(perfBReadLog($fx)));
            check(in_array('account cgroup', array_column(perfBArea($recs, 'skipped')['skipped'], 'check'), true), 'the account cgroup must be listed as skipped');
            // a server of its own: no userShowLimits, one human account in passwd
            unlink($fx['home'] . '/bin/userShowLimits');
            perfWrite($fx['dir'] . '/etc/passwd', "root:x:0:0::/root:/bin/sh\nme:x:" . PERF_FIXTURE_UID . ':' . PERF_FIXTURE_UID . '::' . $fx['home'] . ":/bin/sh\n");
            $own = $roots + ['/etc' => $fx['dir'] . '/etc'];
            perfWrite($roots['/sys'] . '/fs/cgroup/memory.stat', "anon 1000\nfile 2000\n");
            list($recs) = perfBCheck($fx, $own);
            $acc = perfBArea($recs, 'account');
            check($acc['scope'] === 'machine' && $acc['source'] === 'cgroup', 'own server, root cgroup: ' . json_encode($acc));
            check(perfBArea($recs, 'memory')['anon_bytes'] === 1000.0, 'machine figures not read at the root');
            // two human accounts: shared again, nothing under cgroupfs
            mkdir($fx['dir'] . '/otherhome', 0700);
            perfWrite($fx['dir'] . '/etc/passwd', "me:x:" . PERF_FIXTURE_UID . ':' . PERF_FIXTURE_UID . '::' . $fx['home'] . ":/bin/sh\nyou:x:5000:5000::" . $fx['dir'] . "/otherhome:/bin/sh\n");
            @unlink($fx['dir'] . '/run/read.jsonl');
            list($recs) = perfBCheck($fx, $own);
            check(perfBArea($recs, 'account')['scope'] === null && perfBArea($recs, 'account')['source'] === 'unavailable', 'two accounts must count as shared');
            check(!array_filter(perfBReadLog($fx), function ($p) {
                return strpos($p, '/sys/fs/cgroup') === 0;
            }), 'a shared server read cgroupfs');
            check(strpos(perfBJson($recs), 'otherhome') === false && strpos(perfBJson($recs), '5000') === false, 'the other account from passwd reached the output');
        } finally {
            perfDone($fx);
        }
    },
    'perf privacy: the session query is filtered in the kernel by the SSH client address, and never runs without one' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            perfBCheck($fx, $roots);
            $ss = array_values(array_filter(perfFakeCalls($fx), function ($c) {
                return $c['prog'] === 'ss';
            }));
            check(count($ss) === 1 && $ss[0]['argv'] === ['-tniH', 'dst', '198.51.100.7'], 'ss argv: ' . json_encode($ss));
            unlink($fx['log']);
            perfBCheck($fx, $roots, [], ['SSH_CONNECTION' => '2001:db8::7 50123 2001:db8::1 22']);
            $ss = array_values(array_filter(perfFakeCalls($fx), function ($c) {
                return $c['prog'] === 'ss';
            }));
            check(count($ss) === 1 && $ss[0]['argv'] === ['-tniH', 'dst', '[2001:db8::7]'], 'ss argv (IPv6): ' . json_encode($ss));
            unlink($fx['log']);
            foreach ([null, '', 'garbage', '-oX 1 2 3'] as $conn) {
                list($recs) = perfBCheck($fx, $roots, [], ['SSH_CONNECTION' => $conn]);
                check(!in_array('ss', array_column(perfFakeCalls($fx), 'prog'), true), 'ss ran without a client address (' . var_export($conn, true) . ')');
                check(perfBArea($recs, 'sessions')['visible'] === null, 'sessions without an address must be unknown, not empty');
            }
        } finally {
            perfDone($fx);
        }
    },
    'perf privacy: no raw ss row, address or client port is printed; an empty answer is "not visible here"' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            file_put_contents($fx['dir'] . '/run/ss.out', "ESTAB 0 0 192.0.2.10:22 203.0.113.99:41000\n\t rawtokencc wscale:7,7 rtt:80.5/2 mss:1448 cwnd:10 bytes_acked:5000000 busy:3000ms rwnd_limited:2900ms(96.7%) delivery_rate 1.4Mbps retrans:0/1\n"
                . "ESTAB 0 0 192.0.2.10:443 203.0.113.99:41001\n\t rawtokencc rtt:81/2 mss:1448 cwnd:20 bytes_received:9000000 busy:0ms delivery_rate 900Kbps\n");
            list($recs) = perfBCheck($fx, $roots, [], ['PK_PERF_FAKE_SS_OUT' => $fx['dir'] . '/run/ss.out']);
            $out = perfBJson($recs);
            foreach (['203.0.113.99', '41000', '41001', '192.0.2.10', 'rawtokencc', 'ESTAB', 'wscale', '198.51.100.7'] as $s) {
                check(strpos($out, $s) === false, "check printed raw ss text: $s");
            }
            $s = perfBArea($recs, 'sessions');
            check($s['visible'] === true && $s['active'] === 1 && count($s['sessions']) === 2, 'sessions: ' . json_encode($s));
            check($s['sessions'][0]['service'] === 'ssh' && $s['sessions'][0]['rwnd_limited_pct'] === 96.7 && $s['sessions'][0]['rtt_ms'] === 80.5
                && $s['sessions'][1]['service'] === 'http' && $s['sessions'][1]['direction'] === 'to server', 'parsed: ' . json_encode($s['sessions']));
            file_put_contents($fx['dir'] . '/run/empty.out', '');
            list($recs) = perfBCheck($fx, $roots, [], ['PK_PERF_FAKE_SS_OUT' => $fx['dir'] . '/run/empty.out']);
            $s = perfBArea($recs, 'sessions');
            check($s['visible'] === false && strpos($s['reason'], 'not visible here') === 0, 'empty ss answer: ' . json_encode($s));
        } finally {
            perfDone($fx);
        }
    },
    'perf privacy: --share keeps only its allowlist, and the SSH address appears only with --share --with-ip' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            perfBProc($roots, 5001, PERF_FIXTURE_UID, 'rtorrent main');
            list($plain) = perfBCheck($fx, $roots);
            check(perfBArea($plain, 'memory')['by_app'] !== null && perfBArea($plain, 'cost') !== null, 'control: the full view has by_app and cost');
            check(strpos(perfBJson($plain), '198.51.100.7') === false, 'the full view printed the SSH address');
            list($share) = perfBCheck($fx, $roots, ['share' => true]);
            check(count($share) <= count(PerfRouter::SHARE_FIELDS), 'share records: ' . count($share));
            foreach ($share as $r) {
                check(isset(PerfRouter::SHARE_FIELDS[$r['area']]), 'share printed area ' . $r['area']);
                $extra = array_diff(array_keys($r), PerfRouter::SHARE_FIELDS[$r['area']]);
                check(!$extra, "share {$r['area']} printed fields outside the allowlist: " . implode(', ', $extra));
            }
            check(perfBArea($share, 'next_checks') === null && perfBArea($share, 'cost') === null && !isset(perfBArea($share, 'memory')['by_app']), 'share kept app names or cost');
            check(perfBArea($share, 'verdict')['view'] === 'share', 'the share view must say so');
            check(strpos(perfBJson($share), '198.51.100.7') === false && !isset(perfBArea($share, 'sessions')['connection_ip']), '--share without --with-ip printed the SSH address');
            list($ip) = perfBCheck($fx, $roots, ['share' => true, 'with_ip' => true]);
            check(perfBArea($ip, 'sessions')['connection_ip'] === '198.51.100.7', '--share --with-ip must add the address: ' . json_encode(perfBArea($ip, 'sessions')));
            list($noShare) = perfBCheck($fx, $roots, ['share' => false, 'with_ip' => true]);
            check(strpos(perfBJson($noShare), '198.51.100.7') === false, 'with_ip without share printed the address');
        } finally {
            perfDone($fx);
        }
    },
    'perf privacy: other users\' processes are never counted or named; command lines give only the whitelisted stream flags' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
            perfBProc($roots, 5001, PERF_FIXTURE_UID, 'rtorrent main', 'D', 204800);
            perfBProc($roots, 5002, PERF_FIXTURE_FOREIGN_UID, PERF_FIXTURE_SENTINEL, 'D', 9999999, ['secret-binary', PERF_FIXTURE_SENTINEL]);
            perfBProc($roots, 5003, PERF_FIXTURE_UID, 'rclone', 'S', 10240, ['rclone', 'copy', 'secretremote:TOPSECRET/dir', '--transfers', '1', '--password=hunter2', '--multi-thread-streams=0']);
            perfBProc($roots, 5004, PERF_FIXTURE_UID, 'lftp', 'S', 512, ['lftp', '-e', 'pget -n 1 PRIVATEFILE.mkv; quit', 'sftp://u@h']);
            list($recs) = perfBCheck($fx, $roots);
            $out = perfBJson($recs);
            foreach ([PERF_FIXTURE_SENTINEL, 'TOPSECRET', 'secretremote', 'hunter2', 'PRIVATEFILE', 'secret-binary', 'sftp://'] as $s) {
                check(strpos($out, $s) === false, "check printed $s");
            }
            $mem = perfBArea($recs, 'memory');
            $groups = array_column($mem['by_app'], null, 'app');
            check(array_sum(array_column($mem['by_app'], 'processes')) === 3, 'own processes counted: ' . json_encode($mem['by_app']));
            check($groups['rtorrent']['rss_bytes'] === 204800 * 1024 && $groups['rtorrent']['waiting_on_disk'] === 1, 'rtorrent group: ' . json_encode($groups));
            check(perfBArea($recs, 'io')['waiting_on_disk'] === 1, 'the other user\'s D-state process was counted');
            $hints = perfBArea($recs, 'sessions')['stream_hints'];
            $want = [['app' => 'rclone', 'flag' => '--transfers', 'value' => 1], ['app' => 'rclone', 'flag' => '--multi-thread-streams', 'value' => 0], ['app' => 'lftp', 'flag' => 'pget -n', 'value' => 1]];
            check($hints === $want, 'stream hints: ' . json_encode($hints));
        } finally {
            perfDone($fx);
        }
    },
];
