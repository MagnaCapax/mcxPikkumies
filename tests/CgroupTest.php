<?php
/** lib/Cgroup.php: cgroup v1/v2 effective CPU/memory reader, walking every ancestor and taking the minimum. */
function cgroupV2Fixture()
{
    $tmp = sys_get_temp_dir() . '/pk-cgv2-' . getmypid() . '-' . mt_rand();
    @mkdir("$tmp/user.slice/user-1000.slice/session.scope", 0700, true);
    file_put_contents("$tmp/cpu.max", "max 100000\n");
    file_put_contents("$tmp/memory.max", "max\n");
    file_put_contents("$tmp/user.slice/cpu.max", "max 100000\n");
    file_put_contents("$tmp/user.slice/memory.max", "max\n");
    file_put_contents("$tmp/user.slice/user-1000.slice/cpu.max", "150000 100000\n"); // 1.5 cores set here
    file_put_contents("$tmp/user.slice/user-1000.slice/memory.max", "536870912\n"); // 512 MiB set here
    file_put_contents("$tmp/user.slice/user-1000.slice/cpuset.cpus.effective", "0-1,3\n"); // 3 cores
    file_put_contents("$tmp/user.slice/user-1000.slice/session.scope/cpu.max", "max 100000\n"); // leaf: unlimited
    file_put_contents("$tmp/user.slice/user-1000.slice/session.scope/memory.max", "max\n");
    file_put_contents("$tmp/user.slice/user-1000.slice/session.scope/memory.current", "209715200\n"); // 200 MiB used
    $self = "$tmp/self-cgroup";
    file_put_contents($self, "0::/user.slice/user-1000.slice/session.scope\n");
    return [$tmp, $self];
}

function cgroupV1Fixture()
{
    $tmp = sys_get_temp_dir() . '/pk-cgv1-' . getmypid() . '-' . mt_rand();
    @mkdir("$tmp/cpu,cpuacct/user.slice/user-1000.slice/session.scope", 0700, true);
    @mkdir("$tmp/memory/user.slice/user-1000.slice/session.scope", 0700, true);
    @mkdir("$tmp/cpuset", 0700, true);
    file_put_contents("$tmp/cpu,cpuacct/cpu.cfs_quota_us", "-1\n");
    file_put_contents("$tmp/cpu,cpuacct/user.slice/cpu.cfs_quota_us", "-1\n");
    file_put_contents("$tmp/cpu,cpuacct/user.slice/user-1000.slice/cpu.cfs_quota_us", "150000\n");
    file_put_contents("$tmp/cpu,cpuacct/user.slice/user-1000.slice/cpu.cfs_period_us", "100000\n");
    file_put_contents("$tmp/cpu,cpuacct/user.slice/user-1000.slice/session.scope/cpu.cfs_quota_us", "-1\n");
    file_put_contents("$tmp/memory/memory.limit_in_bytes", "9223372036854771712\n");
    file_put_contents("$tmp/memory/user.slice/user-1000.slice/memory.limit_in_bytes", "536870912\n");
    file_put_contents("$tmp/memory/user.slice/user-1000.slice/session.scope/memory.limit_in_bytes", "9223372036854771712\n");
    file_put_contents("$tmp/memory/user.slice/user-1000.slice/session.scope/memory.usage_in_bytes", "104857600\n"); // 100 MiB used
    file_put_contents("$tmp/cpuset/cpuset.cpus", "0-3\n"); // 4 cores (no per-level nesting needed for this check)
    $self = "$tmp/self-cgroup";
    file_put_contents($self, "7:cpu,cpuacct:/user.slice/user-1000.slice/session.scope\n6:memory:/user.slice/user-1000.slice/session.scope\n5:cpuset:/\n");
    return [$tmp, $self];
}

return [
    'cgroup: format() tells v1 from v2 from unreadable' => function () {
        list(, $v2) = cgroupV2Fixture();
        list(, $v1) = cgroupV1Fixture();
        check(Cgroup::format($v2) === 'cgroupv2', 'v2 fixture not recognised');
        check(Cgroup::format($v1) === 'cgroupv1', 'v1 fixture not recognised');
        check(Cgroup::format('/nonexistent-self-cgroup') === null, 'unreadable self-cgroup must not guess a format');
    },
    'cgroup v2: a parent slice quota binds even though the leaf scope shows "max" (ancestor walk, minimum)' => function () {
        list($base, $self) = cgroupV2Fixture();
        check(Cgroup::cpuQuota($base, $self) === 1.5, 'the user-1000.slice 150000/100000 quota (1.5 cores) must win over the leaf\'s unlimited');
        check(Cgroup::memoryMax($base, $self) === 536870912.0, 'the user-1000.slice memory.max (512 MiB) must win over the leaf\'s unlimited');
        check(Cgroup::cpusetCount($base, $self) === 3, 'cpuset.cpus.effective "0-1,3" must count as 3 cores');
        check(Cgroup::memoryCurrent($base, $self) === 209715200.0, 'leaf memory.current (own usage) must be read, not an ancestor');
    },
    'cgroup v1: cfs_quota_us/cfs_period_us and memory.limit_in_bytes, each controller its own mount, LLONG_MAX sentinel is unlimited' => function () {
        list($base, $self) = cgroupV1Fixture();
        check(Cgroup::cpuQuota($base, $self) === 1.5, 'v1 150000/100000 at user-1000.slice must be read as 1.5 cores');
        check(Cgroup::memoryMax($base, $self) === 536870912.0, 'v1 memory.limit_in_bytes at user-1000.slice must be read as 512 MiB');
        check(Cgroup::cpusetCount($base, $self) === 4, 'v1 cpuset.cpus "0-3" must count as 4 cores');
        check(Cgroup::memoryCurrent($base, $self) === 104857600.0, 'v1 leaf memory.usage_in_bytes (own usage) must be read');
    },
    'cgroup: no limit anywhere in the chain (every level "max"/-1) is unlimited, never a false zero' => function () {
        $tmp = sys_get_temp_dir() . '/pk-cgunl-' . getmypid();
        @mkdir($tmp, 0700, true);
        file_put_contents("$tmp/cpu.max", "max 100000\n");
        $self = "$tmp/self-cgroup";
        file_put_contents($self, "0::/\n");
        check(Cgroup::cpuQuota($tmp, $self) === null, 'an all-"max" chain must report null, not 0');
        check(Cgroup::memoryMax($tmp, $self) === null, 'a chain with no memory.max file at all must report null');
    },
    'cgroup: rangeListCount parses Linux range-list syntax, and rejects malformed input' => function () {
        check(Cgroup::rangeListCount('0-1,3,5-7') === 6, '0-1,3,5-7 must be 6 cores (0,1,3,5,6,7)');
        check(Cgroup::rangeListCount('0') === 1, 'a single core must count as 1');
        check(Cgroup::rangeListCount('') === null, 'empty must be null, not 0');
        check(Cgroup::rangeListCount('3-1') === null, 'a backwards range must be rejected');
        check(Cgroup::rangeListCount('x') === null, 'non-numeric input must be rejected');
    },
    'cgroup: an unreadable /proc/self/cgroup gives no ancestor paths, never a false reading of the real root' => function () {
        check(Cgroup::cpuQuota('/sys/fs/cgroup', '/nonexistent-self-cgroup') === null, 'unreadable self-cgroup must not fabricate a quota');
        check(Cgroup::memoryMax('/sys/fs/cgroup', '/nonexistent-self-cgroup') === null, 'unreadable self-cgroup must not fabricate a memory limit');
    },
    'cgroup: a hybrid self-cgroup line listing 3 comma-joined controllers still matches a query for the LAST-listed one' => function () {
        $tmp = sys_get_temp_dir() . '/pk-cghybrid-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/cpuacct,cpu,cpuset", 0700, true);
        file_put_contents("$tmp/cpuacct,cpu,cpuset/cpuset.cpus", "0-3\n"); // 4 cores
        $self = "$tmp/self-cgroup";
        file_put_contents($self, "7:cpuacct,cpu,cpuset:/\n"); // "cpuset" is the LAST name in the comma list
        check(Cgroup::cpusetCount($tmp, $self) === 4, 'a query for the last-listed controller in a comma-joined hybrid line must still match: '
            . var_export(Cgroup::cpusetCount($tmp, $self), true));
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'cgroup: no line at all for the queried controller, and a completely empty (but existing) self-cgroup file, both return null without throwing' => function () {
        $tmp = sys_get_temp_dir() . '/pk-cgmissing-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/memory", 0700, true);
        file_put_contents("$tmp/memory/memory.limit_in_bytes", "536870912\n");
        $self = "$tmp/self-cgroup";
        file_put_contents($self, "6:memory:/\n"); // no cpu/cpuset line at all
        check(Cgroup::cpuQuota($tmp, $self) === null, 'a self-cgroup with no line for the queried controller must report null, not crash: '
            . var_export(Cgroup::cpuQuota($tmp, $self), true));
        file_put_contents($self, ''); // exists, but completely empty
        check(Cgroup::memoryMax($tmp, $self) === null, 'a completely empty (but existing) self-cgroup file must report null, not crash: '
            . var_export(Cgroup::memoryMax($tmp, $self), true));
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'cgroup: usageAtLimit() reads memory and pids use where the limit is set (the user slice), not the leaf, v2 and v1; memoryCurrent() keeps the leaf' => function () {
        list($base, $self) = cgroupV2Fixture();
        $slice = "$base/user.slice/user-1000.slice";
        file_put_contents("$slice/memory.current", "419430400\n"); // 400 MiB for the whole account
        file_put_contents("$slice/pids.max", "500\n");
        file_put_contents("$slice/pids.current", "123\n");
        file_put_contents("$slice/session.scope/pids.max", "max\n");
        $u = Cgroup::usageAtLimit($base, $self);
        check($u['memory_max'] === 536870912.0 && $u['memory_current'] === 419430400.0 && $u['memory_path'] === $slice, 'v2 memory at the slice: ' . json_encode($u));
        check($u['pids_max'] === 500 && $u['pids_current'] === 123 && $u['pids_path'] === $slice, 'v2 pids at the slice: ' . json_encode($u));
        check(Cgroup::memoryCurrent($base, $self) === 209715200.0, 'memoryCurrent() must still read the leaf (system-status relies on it)');
        file_put_contents("$base/user.slice/memory.max", "536870912\n"); // the same limit one level up: the account's own level wins the tie
        file_put_contents("$base/user.slice/memory.current", "629145600\n");
        $u = Cgroup::usageAtLimit($base, $self);
        check($u['memory_path'] === $slice && $u['memory_current'] === 419430400.0 && $u['memory_shared'] === false, 'on a tie the account\'s own slice: ' . json_encode($u));
        file_put_contents("$base/user.slice/memory.max", "268435456\n"); // a lower limit on user.slice, which every account shares
        $u = Cgroup::usageAtLimit($base, $self);
        check($u['memory_path'] === "$base/user.slice" && $u['memory_max'] === 268435456.0 && $u['memory_shared'] === true && $u['memory_current'] === null,
            'a shared level: its limit, never every account\'s usage shown as this one\'s: ' . json_encode($u));
        exec('rm -rf ' . escapeshellarg($base));
        list($base, $self) = cgroupV1Fixture();
        file_put_contents("$base/memory/user.slice/user-1000.slice/memory.usage_in_bytes", "314572800\n");
        @mkdir("$base/pids/user.slice/user-1000.slice/session.scope", 0700, true);
        file_put_contents("$base/pids/user.slice/user-1000.slice/pids.max", "300\n");
        file_put_contents("$base/pids/user.slice/user-1000.slice/pids.current", "42\n");
        file_put_contents($self, (string) file_get_contents($self) . "4:pids:/user.slice/user-1000.slice/session.scope\n");
        $u = Cgroup::usageAtLimit($base, $self);
        check($u['memory_current'] === 314572800.0 && $u['memory_path'] === "$base/memory/user.slice/user-1000.slice", 'v1 memory at the slice: ' . json_encode($u));
        check($u['pids_max'] === 300 && $u['pids_current'] === 42 && $u['pids_path'] === "$base/pids/user.slice/user-1000.slice", 'v1 pids in its own hierarchy: ' . json_encode($u));
        exec('rm -rf ' . escapeshellarg($base));
        $none = Cgroup::usageAtLimit('/nonexistent-cg', '/nonexistent-self-cgroup');
        check($none === ['memory_max' => null, 'memory_current' => null, 'memory_path' => null, 'memory_shared' => false, 'pids_max' => null, 'pids_current' => null, 'pids_path' => null, 'pids_shared' => false], 'unreadable: all null, never a guess');
    },
];
