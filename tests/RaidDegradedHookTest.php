<?php
/**
 * hooks/system/raid-degraded (ADR-0095): with root available in the host cache, a degraded, rebuilding or inactive
 * array in /proc/mdstat is named once per session per change; silent when healthy, without root, with root.disabled,
 * or without a usable session id. Runs the real hook in a throwaway tree, /proc/mdstat from PIKKUMIES_MDRAID_ROOT.
 */
if (!function_exists('raidHookTree')) {
    function raidHookTree($rootAvailable, array $config = [])
    {
        $tmp = testTempDir('pk-raidhook');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/server-admin/mdraid', 'hooks/system/raid-degraded'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/local", 0700, true);
        @mkdir("$tmp/fs/proc", 0700, true);
        @mkdir("$tmp/home", 0700, true);
        file_put_contents("$tmp/pikkumies/local/host.json", json_encode(['root' => ['available' => $rootAvailable, 'via' => $rootAvailable ? 'sudo' : 'none']]));
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        return $tmp;
    }

    /** Runs the hook with $mdstat as /proc/mdstat; returns the context or ''. */
    function raidHookRun($tree, $mdstat, $session = 's-1')
    {
        file_put_contents("$tree/fs/proc/mdstat", $mdstat);
        $env = ['HOME' => "$tree/home", 'PATH' => (string) getenv('PATH'), 'PIKKUMIES_MDRAID_ROOT' => "$tree/fs"];
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/pikkumies/hooks/system/raid-degraded/raid-degraded.php"),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fwrite($pipes[0], json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => $session, 'extra' => ['user_message' => 'hi']]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        check(trim($err) === '', 'the hook wrote to stderr: ' . $err);
        $d = json_decode($out, true);
        return is_array($d) && isset($d['context']) ? $d['context'] : (trim($out) === '' ? '' : 'UNPARSED: ' . $out);
    }
}

$healthy = "Personalities : [raid5]\nmd0 : active raid5 sdd[2] sdc[1] sdb[0]\n      100 blocks super 1.2 level 5, 512k chunk, algorithm 2 [3/3] [UUU]\n\nunused devices: <none>\n";
$degraded = "Personalities : [raid5]\nmd0 : active raid5 sdc[1] sdb[0]\n      100 blocks super 1.2 level 5, 512k chunk, algorithm 2 [3/2] [UU_]\n\nunused devices: <none>\n";
$rebuild = "Personalities : [raid5]\nmd0 : active raid5 sde[3] sdc[1] sdb[0]\n      100 blocks super 1.2 [3/2] [UU_]\n      [=>...]  recovery = 12.6% (1/10) finish=10min speed=1K/sec\n\n";
$inactive = "Personalities : [raid5]\nmd127 : inactive sdf[1](S)\n      100 blocks super 1.2\n\n";
return [
    'raid hook: a degraded array is named once per session, again on a change, and not once it is healthy' => function () use ($healthy, $degraded, $rebuild, $inactive) {
        $t = raidHookTree(true);
        try {
            check(raidHookRun($t, $healthy) === '', 'a healthy array says nothing');
            $c = raidHookRun($t, $degraded);
            check(strpos($c, 'md0 is degraded ([UU_])') !== false && strpos($c, 'mdraid status') !== false && strpos($c, 'recover-a-degraded-or-failed-array') !== false, 'degraded note: ' . $c);
            check(raidHookRun($t, $degraded) === '', 'the same state is told once per session');
            check(raidHookRun($t, $degraded, 's-2') !== '', 'another session is told too');
            check(strpos(raidHookRun($t, $rebuild), 'md0 is rebuilding (12.6%)') !== false, 'a change to rebuilding is told');
            check(strpos(raidHookRun($t, $inactive), 'md127 is inactive') !== false, 'an inactive array is told');
            check(raidHookRun($t, $healthy) === '', 'healthy again says nothing');
            check(raidHookRun($t, $degraded) !== '', 'degraded again after healthy is told again');
        } finally {
            testRmTree($t);
        }
    },
    'raid hook: silent without root in the host cache, with root.disabled, or without a session id' => function () use ($degraded) {
        foreach ([[false, []], [true, ['root' => ['disabled' => true]]]] as $case) {
            $t = raidHookTree($case[0], $case[1]);
            try {
                check(raidHookRun($t, $degraded) === '', 'must stay silent: ' . json_encode($case));
            } finally {
                testRmTree($t);
            }
        }
        $t = raidHookTree(true);
        try {
            check(raidHookRun($t, $degraded, '') === '' && raidHookRun($t, $degraded, '../x') === '', 'no usable session id: silent');
            unlink("$t/pikkumies/local/host.json");
            check(raidHookRun($t, $degraded) === '', 'no host cache: silent, never a probe');
        } finally {
            testRmTree($t);
        }
    },
    'raid hook: hook.md declares pre_llm_call, fails open and keeps a 2 second timeout' => function () {
        $md = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/hooks/system/raid-degraded/hook.md');
        check(strpos($md, "event: pre_llm_call\n") !== false && strpos($md, "failClosed: false\n") !== false && strpos($md, "timeout: 2\n") !== false, 'hook.md: ' . $md);
    },
];
