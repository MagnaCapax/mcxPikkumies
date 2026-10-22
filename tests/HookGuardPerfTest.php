<?php
/**
 * The delete guard on performance work (ADR-0020 amendment, perf plan): heavy tests and raw benchmarks ask
 * the user as a load on the shared server (rule_key pikkumies-load:); reads of another account's resource
 * files or of the connection tables are blocked with one generic message that names no path.
 * guardKindTable() is defined in HookGuardContainersTest.php; this file defines it too when run alone.
 * The dd command is built from parts so the literal text never appears in this file.
 */
if (!function_exists('guardKindTable')) {
    require __DIR__ . '/HookGuardContainersTest.php';
}

$p = 'php ~/pikkumies/tools/system/performance/performance.php';
$dd = 'd' . 'd if=/dev/zero of=~/pm-test.bin bs=1M count=1024';
$privacy = "Pikkumies privacy: reads only this account's own files and connections. Not run. Use performance check.";
$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$other = $uid === 1001 ? 1002 : 1001;
return [
    'perf guard: the heavy verb and raw benchmarks ask as a load test; light verbs and version checks stay silent' => function () use ($p, $dd) {
        guardKindTable([
            ["$p heavy --test disk-read", 'approve', 'load'],
            ["$p heavy --test iperf3 --target ssh", 'approve', 'load'],
            ["$p heavy --test fio", 'approve', 'load'],
            ['fio --name=t --rw=randread --size=1G --readonly', 'approve', 'load'],
            ['iperf3 -c 192.0.2.10 -R', 'approve', 'load'],
            ['speedtest --accept-license', 'approve', 'load'],
            ['speedtest-cli --simple', 'approve', 'load'],
            ['bash yabs.sh -i', 'approve', 'load'],
            ['stress-ng --cpu 4 --timeout 60', 'approve', 'load'],
            ['sysbench cpu run', 'approve', 'load'],
            ['bonnie++ -d ~/tmp', 'approve', 'load'],
            [$dd, 'approve', 'delete'], // writes over a named file: reviewed as a delete, never run as a load
            ['fio --name=w --rw=write --size=1G --filename=~/data/disk.img', 'approve', 'delete'],
            ['fio --name=r --rw=randread --size=1G --filename=~/data/disk.img --readonly', 'approve', 'load'],
            ["$p check", 'none'],
            ["$p check --symptom slow-download", 'none'],
            ["$p disk", 'none'],
            ["$p net --target ssh", 'none'],
            ["$p samples", 'none'],
            ['which fio', 'none'],
            ['iperf3 --version', 'none'],
            ['cat fio.log', 'none'],
        ]);
    },
    'perf guard: another account\'s or globbed slices, recursive walks of the tree and connection tables are blocked' => function () use ($other) {
        guardKindTable([
            ["cat /sys/fs/cgroup/memory/user.slice/user-$other.slice/memory.stat", 'block'],
            ['cat /sys/fs/cgroup/user.slice/user-*/memory.stat', 'block'],
            ['cat /sys/fs/cgroup/user.slice/user-[0-9]*/memory.current', 'block'],
            ['cd /sys/fs/cgroup/user.slice && cat */memory.stat', 'block'],
            ['find /sys/fs/cgroup -name memory.stat', 'block'],
            ['grep -r . /sys/fs/cgroup/user.slice', 'block'],
            ['ls -R /sys/fs/cgroup', 'block'],
            ["systemctl status user-$other.slice", 'block'],
            ['cat /proc/net/tcp', 'block'],
            ['cat /proc/net/tcp6', 'block'],
            ['cat /proc/self/net/udp', 'block'],
            ['ss -tn', 'block'],
            ['ss -tan', 'block'],
            ['netstat -tn', 'block'],
        ]);
        guardKindTable([
            ["print(open('/sys/fs/cgroup/user.slice/user-$other.slice/memory.current').read())", 'block'],
            ["import os\nfor d in os.listdir('/sys/fs/cgroup/user.slice'): print(d)", 'block'],
        ], 'execute_code');
    },
    'perf guard: the privacy block says the same generic thing every time and names no path' => function () use ($privacy, $other) {
        foreach (["cat /sys/fs/cgroup/user.slice/user-$other.slice/memory.stat", 'cat /proc/net/tcp', 'ss -tn'] as $cmd) {
            $d = guardKindAnswer($cmd);
            check(is_array($d) && $d['action'] === 'block' && $d['message'] === $privacy, "$cmd: not the generic privacy block: " . json_encode($d));
        }
        foreach (['cgroup', 'slice', '/proc', '/sys', 'readable', 'other account'] as $word) {
            check(stripos($privacy, $word) === false, "the privacy message explains a path: $word");
        }
    },
    'perf guard: the own slice, listening sockets, filtered sessions and interface counters stay silent' => function () use ($uid) {
        guardKindTable([
            ["cat /sys/fs/cgroup/user.slice/user-$uid.slice/memory.stat", 'none'],
            ['cat /sys/fs/cgroup/user.slice/user-$(id -u).slice/memory.max', 'none'],
            ['cat /proc/self/cgroup', 'none'],
            ['[ -f /sys/fs/cgroup/cgroup.controllers ] && echo v2', 'none'],
            ['ss -tlnp', 'none'],
            ['ss -tn dst 192.0.2.10', 'none'],
            ["ss -tni '( sport = :22 )'", 'none'],
            ['ss -s', 'none'],
            ['netstat -tlnp', 'none'],
            ['cat /proc/net/dev', 'none'],
        ]);
    },
];
