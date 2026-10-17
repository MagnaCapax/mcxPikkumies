<?php
/**
 * Helpers for the tests/Performance*Test.php files: a throwaway HOME, local/ and bin/ (the fake programs of bin/ with
 * this PHP as their interpreter), fake /proc and cgroupfs trees for cgroup v1, v2 and hybrid hosts, and a runner
 * that includes performance.php with $perfSeams set, so nothing reads the real pikkumies/local/ or the real cgroupfs.
 *
 * The trees hold this account (PERF_FIXTURE_UID) and one other account (PERF_FIXTURE_FOREIGN_UID) whose every file
 * contains PERF_FIXTURE_SENTINEL: the privacy tests prove that string never reaches a read log or the output.
 */
const PERF_FIXTURE_UID = 4242;
const PERF_FIXTURE_FOREIGN_UID = 4243;
const PERF_FIXTURE_SENTINEL = 'FOREIGN-SENTINEL';
const PERF_FIXTURE_FAKES = ['userShowLimits', 'ss', 'mtr', 'traceroute', 'ping', 'dd', 'fio', 'iperf3', 'speedtest'];

/** A fixture dir: home/ (with bin/userShowLimits), local/, bin/ (every fake but userShowLimits), run/. */
function perfFixture()
{
    $dir = testTempDir('pk-perf');
    foreach (['home/bin', 'local', 'bin', 'run'] as $d) {
        mkdir("$dir/$d", 0700, true);
    }
    $src = __DIR__ . '/bin';
    foreach (['bin', 'home/bin'] as $d) {
        copy("$src/fakelib.php", "$dir/$d/fakelib.php");
    }
    foreach (PERF_FIXTURE_FAKES as $name) {
        $to = $name === 'userShowLimits' ? "$dir/home/bin/$name" : "$dir/bin/$name";
        file_put_contents($to, '#!' . PHP_BINARY . "\n" . file_get_contents("$src/$name"));
        chmod($to, 0700);
    }
    return ['dir' => $dir, 'home' => "$dir/home", 'local' => "$dir/local", 'bin' => "$dir/bin", 'log' => "$dir/run/fake.jsonl"];
}

function perfDone(array $fx)
{
    testRmTree($fx['dir']);
}

/** Calls the fakes made (prog, argv), in order. */
function perfFakeCalls(array $fx)
{
    return is_file($fx['log']) ? cliLines((string) file_get_contents($fx['log'])) : [];
}

function perfWrite($path, $text)
{
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $text);
}

/** The cgroup files of one account's slice: own values, or the sentinel in every file for the other account. */
function perfSliceFiles($dir, array $files, $foreign)
{
    foreach ($files as $name => $text) {
        perfWrite("$dir/$name", $foreign ? PERF_FIXTURE_SENTINEL . "\n" : $text);
    }
}

/**
 * Fake /proc and /sys under $root for $mode v1 | v2 | hybrid. Returns the PerfFs roots ['/proc' => ..., '/sys' => ...].
 * This process's cgroup is /user.slice/user-<uid>.slice/session-1.scope on every controller.
 */
function perfFakeTree($root, $mode, $uid = PERF_FIXTURE_UID, $foreignUid = PERF_FIXTURE_FOREIGN_UID)
{
    $proc = "$root/proc";
    $cg = "$root/sys/fs/cgroup";
    $self = "/user.slice/user-$uid.slice/session-1.scope";
    $v1 = "12:memory:$self\n11:cpu,cpuacct:$self\n10:blkio:$self\n9:pids:$self\n1:name=systemd:$self\n";
    perfWrite("$proc/self/cgroup", $mode === 'v2' ? "0::$self\n" : ($mode === 'hybrid' ? $v1 . "0::$self\n" : $v1));
    perfWrite("$proc/pressure/cpu", "some avg10=12.50 avg60=10.00 avg300=8.00 total=123456\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");
    perfWrite("$proc/pressure/io", "some avg10=20.00 avg60=15.00 avg300=12.00 total=223456\nfull avg10=3.20 avg60=2.10 avg300=1.80 total=103456\n");
    perfWrite("$proc/pressure/memory", "some avg10=0.50 avg60=0.40 avg300=0.30 total=3456\nfull avg10=0.10 avg60=0.05 avg300=0.02 total=1456\n");
    perfWrite("$proc/stat", "cpu  100000 500 40000 900000 20000 0 1000 3000 0 0\nprocs_running 3\nprocs_blocked 1\n");
    perfWrite("$proc/loadavg", "3.10 2.80 2.50 3/600 12345\n");
    perfWrite("$proc/meminfo", "MemTotal:       65536000 kB\nMemAvailable:   32768000 kB\nSwapTotal:       8388604 kB\nSwapFree:        8388604 kB\n");
    perfWrite("$proc/diskstats", "   9       0 md0 1000 0 80000 0 2000 0 160000 0 0 0 0 0 0 0 0 0 0\n   8       0 sda 5000 10 400000 25000 8000 20 640000 64000 0 30000 89000 0 0 0 0 0 0\n");
    perfWrite("$proc/mdstat", "Personalities : [raid5]\nmd0 : active raid5 sda1[0] sdb1[1] sdc1[2]\n      1000 blocks super 1.2 level 5, 512k chunk [3/3] [UUU]\n\nunused devices: <none>\n");
    perfWrite("$proc/self/mountinfo", "30 1 9:0 / /home rw,relatime shared:1 - ext4 /dev/md0 rw\n");
    perfWrite("$proc/net/dev", "Inter-|   Receive |  Transmit\n face |bytes packets errs drop|bytes packets errs drop\n  eth0: 1000 10 0 0 2000 20 0 0\n");
    perfWrite("$proc/net/tcp", PERF_FIXTURE_SENTINEL . "\n"); // the socket table lists every account's connections: never read
    $v1Files = [
        'memory' => ['memory.limit_in_bytes' => "2147483648\n", 'memory.usage_in_bytes' => "805306368\n", 'memory.stat' => "cache 268435456\nrss 536870912\ntotal_cache 268435456\ntotal_rss 536870912\n"],
        'cpu,cpuacct' => ['cpu.cfs_quota_us' => "200000\n", 'cpu.cfs_period_us' => "100000\n", 'cpu.stat' => "nr_periods 1000\nnr_throttled 50\nthrottled_time 5000000000\n"],
        'blkio' => ['blkio.throttle.read_bps_device' => "8:0 104857600\n", 'blkio.throttle.write_bps_device' => ''],
        'pids' => ['pids.max' => "2000\n", 'pids.current' => "120\n"],
    ];
    $v2Files = ['memory.max' => "2147483648\n", 'memory.current' => "805306368\n", 'memory.stat' => "anon 536870912\nfile 268435456\n",
        'memory.events' => "low 0\nhigh 0\nmax 0\noom 0\noom_kill 0\n", 'cpu.max' => "200000 100000\n", 'cpu.stat' => "usage_usec 100000000\nnr_periods 1000\nnr_throttled 50\nthrottled_usec 5000000\n",
        'io.max' => "8:0 rbps=104857600 wbps=max riops=max wiops=max\n", 'io.stat' => "8:0 rbytes=1000000 wbytes=2000000 rios=100 wios=200\n",
        'cpu.pressure' => "some avg10=1.00 avg60=0.50 avg300=0.20 total=100\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n",
        'io.pressure' => "some avg10=2.00 avg60=1.00 avg300=0.50 total=200\nfull avg10=1.00 avg60=0.50 avg300=0.20 total=100\n",
        'memory.pressure' => "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n",
        'pids.max' => "2000\n", 'pids.current' => "120\n"];
    foreach ([$uid => false, $foreignUid => true] as $who => $foreign) {
        $slice = "user.slice/user-$who.slice";
        if ($mode !== 'v2') {
            foreach ($v1Files as $ctrl => $files) {
                perfSliceFiles("$cg/$ctrl/$slice", $files, $foreign);
            }
        }
        if ($mode !== 'v1') {
            perfSliceFiles(($mode === 'hybrid' ? "$cg/unified" : $cg) . "/$slice", $v2Files, $foreign);
        }
    }
    return ['/proc' => $proc, '/sys' => "$root/sys"];
}

/**
 * Runs performance.php <args> with HOME and PATH (fakes first) set and $perfSeams = local_dir, home, uid plus
 * $seams. Returns [exit code, records, stdout, stderr].
 */
function perfRun(array $fx, array $args, array $seams = [], array $env = [])
{
    $script = dirname(__DIR__, 3) . '/pikkumies/tools/system/performance/performance.php';
    $env += ['PK_PERF_FAKE_LOG' => $fx['log']];
    $lines = '';
    foreach ($env as $k => $v) {
        $lines .= $v === null ? 'putenv(' . var_export($k, true) . ");\n" : 'putenv(' . var_export("$k=$v", true) . ");\n";
    }
    $seams += ['local_dir' => $fx['local'], 'home' => $fx['home'], 'uid' => PERF_FIXTURE_UID, 'fingerprint' => 'fixture-host|fixture-machine|' . PERF_FIXTURE_UID];
    file_put_contents($fx['dir'] . '/run.php', "<?php\n" . 'putenv("HOME=" . ' . var_export($fx['home'], true) . ");\n"
        . 'putenv("PATH=" . ' . var_export($fx['bin'], true) . ' . ":" . getenv("PATH"));' . "\n" . $lines
        . '$perfSeams = ' . var_export($seams, true) . ";\n"
        . 'require ' . var_export($script, true) . ";\n");
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fx['dir'] . '/run.php');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache();
    return [$code, cliLines($out), $out, $err];
}
