<?php
/**
 * The performance unit's foundations (ADR-0035): the dispatcher (argument checks, the lock, "not built yet"), PerfProbe
 * (timeout prefix, scratch, sweep, run log, PSI gate), PerfFs (the own-account reader), PerfLimits against the README
 * Budgets table, and tools/system/DOCTRINE.md against the README. The verbs themselves have their own tests.
 */
require_once __DIR__ . '/fixtures/performance/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/performance/PerfProbe.php';

const PERF_TEST_VERBS = ['check', 'net', 'disk', 'heavy', 'samples'];

function perfTestUnitDir()
{
    return dirname(__DIR__) . '/pikkumies/tools/system/performance';
}

/** Whether the verb's class file exists yet (then that stage's own tests cover the verb). */
function perfTestBuilt($verb)
{
    $class = ['check' => 'PerfRouter', 'net' => 'PerfNet', 'disk' => 'PerfDisk', 'heavy' => 'PerfHeavy', 'samples' => 'PerfSamples', 'tick' => 'PerfSamples'][$verb];
    return is_file(perfTestUnitDir() . "/$class.php");
}

function perfTestUid()
{
    return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
}

function perfTestNeedWrappers()
{
    foreach (['nice', 'ionice', 'timeout'] as $p) {
        if (ToolProc::which($p) === null) {
            skip("$p is not installed here");
        }
    }
}

return [
    'performance unit: --verbs are the five USAGE verbs, each answers --help, and only heavy is marked as loading the server' => function () {
        $fx = perfFixture();
        try {
            list($code, , $out) = perfRun($fx, ['--verbs']);
            check($code === 0 && array_values(array_filter(explode("\n", $out), 'strlen')) === PERF_TEST_VERBS, 'verbs: ' . $out);
            $usage = array_column(Units::verbs((string) file_get_contents(perfTestUnitDir() . '/USAGE.md')), 'changes', 'verb');
            check(array_keys($usage) === PERF_TEST_VERBS, 'USAGE verbs: ' . json_encode(array_keys($usage)));
            foreach ($usage as $verb => $changes) {
                check($changes === ($verb === 'heavy' ? 'loads' : 'no'), "$verb changes column is '$changes'");
                list($c) = perfRun($fx, [$verb, '--help']);
                check($c === 0, "$verb --help exited $c");
            }
        } finally {
            perfDone($fx);
        }
    },
    'performance unit: an unknown verb and every bad argument exit 2 and create nothing' => function () {
        $fx = perfFixture();
        try {
            $bad = [['nope'], [], ['check', 'extra'], ['check', '--symptom', 'slowness'], ['check', '--with-ip'], ['check', '--tick', '--share'],
                ['net'], ['net', '--target', '-oProxyCommand=x'], ['net', '--target', 'a b'], ['net', '--target', 'ssh', '--count', '0'],
                ['net', '--target', 'ssh', '--count', '1001'], ['net', '--target', 'ssh', '--count', '5x'], ['disk', '--share'],
                ['heavy'], ['heavy', '--test', 'stress'], ['heavy', '--test', 'download'], ['heavy', '--test', 'download', '--url', 'file:///etc/passwd'],
                ['heavy', '--test', 'iperf3'], ['heavy', '--test', 'iperf3', '--target', 'ssh'], ['heavy', '--test', 'disk-read', '--url', 'https://x.example/f'],
                ['samples', '--hours', '0'], ['samples', '--hours', '721'], ['samples', '--dry-run']];
            foreach ($bad as $args) {
                list($code, $recs) = perfRun($fx, $args);
                $last = $recs ? $recs[count($recs) - 1] : [];
                check($code === 2 && isset($last['ok']) && $last['ok'] === false, implode(' ', $args) . ": expected exit 2 with ok:false, got $code " . json_encode($last));
            }
            check(!file_exists($fx['local'] . '/performance'), 'a usage error created local/performance (it must run nothing)');
            check(perfFakeCalls($fx) === [], 'a usage error ran a program: ' . json_encode(perfFakeCalls($fx)));
        } finally {
            perfDone($fx);
        }
    },
    'performance unit: good arguments pass the checks (a verb not built yet then says so, exit 1)' => function () {
        $fx = perfFixture();
        try {
            $good = [['check'], ['check', '--symptom', 'capped', '--share', '--with-ip'], ['net', '--target', 'ssh'], ['net', '--target', '2001:db8::1', '--count', '1000'],
                ['net', '--target', 'host-1.example.net'], ['disk', '--no-read'], ['heavy', '--test', 'download', '--url', 'https://speed.example/1G.bin'],
                ['heavy', '--test', 'iperf3', '--target', '192.0.2.5'], ['heavy', '--test', 'speedtest'], ['samples', '--hours', '720']];
            foreach ($good as $args) {
                if (perfTestBuilt($args[0])) {
                    continue;
                }
                list($code, $recs) = perfRun($fx, $args);
                $last = $recs ? $recs[count($recs) - 1] : [];
                check($code === 1 && isset($last['error']) && strpos($last['error'], $args[0] . ' is not built yet') === 0, implode(' ', $args) . ": expected 'not built yet', got $code " . json_encode($last));
            }
            if (!perfTestBuilt('tick')) {
                list($code, , $out, $err) = perfRun($fx, ['check', '--tick']);
                check($code === 1 && $out === '' && strpos($err, 'not built yet') !== false, "tick not built: expected exit 1, no stdout, a stderr line; got $code '$out' '$err'");
            }
        } finally {
            perfDone($fx);
        }
    },
    'performance unit: a second run is refused while one holds the lock, and a scheduled tick then exits 0 printing nothing' => function () {
        $fx = perfFixture();
        try {
            mkdir($fx['local'] . '/performance', 0700, true);
            $h = fopen($fx['local'] . '/performance/run.lock', 'c');
            check(flock($h, LOCK_EX | LOCK_NB), 'test could not take the lock');
            foreach (['check', 'disk'] as $verb) {
                list($code, $recs) = perfRun($fx, [$verb]);
                $last = $recs ? $recs[count($recs) - 1] : [];
                check($code === 1 && isset($last['error']) && strpos($last['error'], 'another performance run is in progress') === 0, "$verb under the lock: got $code " . json_encode($last));
            }
            list($code, , $out, $err) = perfRun($fx, ['check', '--tick']);
            check($code === 0 && $out === '' && $err === '', "tick under the lock must be silent and exit 0; got $code '$out' '$err'");
            flock($h, LOCK_UN);
            fclose($h);
            list($code, $recs) = perfRun($fx, ['disk']);
            $last = $recs ? $recs[count($recs) - 1] : [];
            check(!isset($last['error']) || strpos($last['error'], 'another performance run') === false, 'the lock was not released with its holder: ' . json_encode($last));
        } finally {
            perfDone($fx);
        }
    },
    'performance probe: scratch is 0700, files 0600 and exclusive, and the run removes its own scratch' => function () {
        $fx = perfFixture();
        try {
            $p = new PerfProbe(['local_dir' => $fx['local'], 'uid' => perfTestUid()]);
            $dir = $p->scratch();
            check(substr(sprintf('%o', fileperms($dir)), -4) === '0700', 'scratch dir mode ' . sprintf('%o', fileperms($dir)));
            check(substr(sprintf('%o', fileperms(dirname($dir))), -4) === '0700', 'scratch root mode ' . sprintf('%o', fileperms(dirname($dir))));
            $f = $p->scratchFile('probe.bin');
            check(substr(sprintf('%o', fileperms($f)), -4) === '0600', 'scratch file mode ' . sprintf('%o', fileperms($f)));
            $threw = false;
            try {
                $p->scratchFile('probe.bin');
            } catch (CliError $e) {
                $threw = true;
            }
            check($threw, 'a second file of the same name must fail (created exclusively)');
            foreach (['../x', 'a/b', '', '..'] as $badName) {
                $threw = false;
                try {
                    $p->scratchFile($badName);
                } catch (CliError $e) {
                    $threw = true;
                }
                check($threw, "scratch file name '$badName' accepted");
            }
            $p->cleanup();
            check(!file_exists($dir), 'cleanup left the scratch dir');
        } finally {
            perfDone($fx);
        }
    },
    'performance probe: the sweep removes scratch of dead runs only, keeps live ones, and never follows a link' => function () {
        $fx = perfFixture();
        $sleeper = null;
        try {
            $root = $fx['local'] . '/performance/scratch';
            mkdir($root, 0700, true);
            $dead = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo getmypid();'), [1 => ['pipe', 'w']], $pp);
            $deadPid = (int) stream_get_contents($pp[1]);
            fclose($pp[1]);
            proc_close($dead);
            $sleeper = proc_open('exec sleep 30', [], $sp);
            $livePid = proc_get_status($sleeper)['pid'];
            check($deadPid > 0 && $livePid > 0 && $deadPid !== $livePid, 'test pids');
            $outside = $fx['dir'] . '/outside.txt';
            file_put_contents($outside, 'keep me');
            mkdir("$root/$deadPid/sub", 0700, true);
            file_put_contents("$root/$deadPid/sub/f", 'x');
            symlink($outside, "$root/$deadPid/link");
            mkdir($fx['dir'] . '/target', 0700);
            file_put_contents($fx['dir'] . '/target/keep.txt', 'keep');
            symlink($fx['dir'] . '/target', "$root/$deadPid/dirlink");
            mkdir("$root/$livePid", 0700);
            mkdir("$root/notapid", 0700);
            $p = new PerfProbe(['local_dir' => $fx['local'], 'uid' => perfTestUid()]);
            check($p->sweep() === 1, 'expected one stale dir removed');
            check(!file_exists("$root/$deadPid"), 'the dead run\'s scratch is still there');
            check(is_file($outside) && file_get_contents($outside) === 'keep me', 'the sweep followed a link out of scratch');
            check(is_file($fx['dir'] . '/target/keep.txt'), 'the sweep followed a directory link out of scratch');
            check(is_dir("$root/$livePid"), 'the sweep removed a live run\'s scratch');
            check(is_dir("$root/notapid"), 'the sweep removed a directory that is not a run\'s');
            $q = new PerfProbe(['local_dir' => $fx['local'], 'uid' => perfTestUid() + 1]);
            mkdir("$root/$deadPid", 0700);
            check($q->sweep() === 0 && is_dir("$root/$deadPid"), 'the sweep removed a directory owned by another uid');
        } finally {
            if (is_resource($sleeper)) {
                proc_terminate($sleeper);
                proc_close($sleeper);
            }
            perfDone($fx);
        }
    },
    'performance probe: every argv it runs starts with nice, the I/O class and timeout -k 2, and the deadline holds' => function () {
        perfTestNeedWrappers();
        $fx = perfFixture();
        try {
            $log = $fx['dir'] . '/run/calls.jsonl';
            $p = new PerfProbe(['local_dir' => $fx['local'], 'call_log' => $log]);
            $r1 = $p->scan(['true'], 3);
            $r2 = $p->measure(['true'], 4);
            $t = microtime(true);
            $r3 = $p->measure(['sleep', '20'], 1);
            check($r1['code'] === 0 && $r2['code'] === 0, 'true failed: ' . json_encode([$r1['err'], $r2['err']]));
            check($r3['timed_out'] && microtime(true) - $t < 6, 'the 1 s deadline did not stop sleep 20: ' . json_encode($r3));
            check($r1['io_class'] === 'idle' && $r2['io_class'] === 'best-effort/7', 'io_class: ' . $r1['io_class'] . ' ' . $r2['io_class']);
            $logged = cliLines((string) file_get_contents($log));
            check(count($logged) === 3 && $logged === $p->calls, 'call log does not match $calls');
            check($logged[0] === ['nice', '-n', '19', 'ionice', '-c3', 'timeout', '-k', '2', '3', 'true'], 'scan argv: ' . json_encode($logged[0]));
            check($logged[1] === ['nice', '-n', '19', 'ionice', '-c2', '-n7', 'timeout', '-k', '2', '4', 'true'], 'measure argv: ' . json_encode($logged[1]));
            foreach ($logged as $argv) {
                $i = array_search('timeout', $argv, true);
                check($i !== false && $argv[$i + 1] === '-k' && $argv[$i + 2] === '2' && ctype_digit($argv[$i + 3]) && (int) $argv[$i + 3] > 0, 'argv without the timeout prefix: ' . json_encode($argv));
            }
            foreach ([0, -1] as $s) {
                $threw = false;
                try {
                    PerfProbe::measureArgv(['true'], $s);
                } catch (CliError $e) {
                    $threw = true;
                }
                check($threw, "a program without a time limit ($s s) was accepted");
            }
        } finally {
            perfDone($fx);
        }
    },
    'performance probe: the run log is 0600 and carries a fingerprint hash, never the fingerprint itself' => function () {
        $fx = perfFixture();
        try {
            $p = new PerfProbe(['local_dir' => $fx['local'], 'fingerprint' => 'secret-host.example|machine|4242']);
            $p->classes[] = 'host_io_busy';
            $p->skipped[] = ['check' => 'sessions', 'reason' => 'not visible here'];
            $p->addCost('packets_sent', 20);
            $p->finish('check');
            $file = $fx['local'] . '/performance/runs.jsonl';
            check(substr(sprintf('%o', fileperms($file)), -4) === '0600', 'runs.jsonl mode ' . sprintf('%o', fileperms($file)));
            $text = (string) file_get_contents($file);
            $line = json_decode(trim($text), true);
            check(strpos($text, 'secret-host') === false, 'the run log holds the host name');
            check(preg_match('/^[0-9a-f]{16}$/', $line['fingerprint']) === 1 && $line['fingerprint'] === substr(hash('sha256', 'secret-host.example|machine|4242'), 0, 16), 'fingerprint: ' . $line['fingerprint']);
            check($line['verb'] === 'check' && $line['classes'] === ['host_io_busy'] && $line['skipped'][0]['check'] === 'sessions', 'record: ' . $text);
            check(array_keys($line['cost']) === ['wall_s', 'cpu_s', 'bytes_written', 'bytes_read_direct', 'packets_sent', 'bytes_downloaded'] && $line['cost']['packets_sent'] === 20, 'cost: ' . json_encode($line['cost']));
        } finally {
            perfDone($fx);
        }
    },
    'performance fs: own files read, another account\'s slice, globs, .. and socket tables refused and never logged (v1, v2, hybrid)' => function () {
        $fx = perfFixture();
        try {
            $own = PERF_FIXTURE_UID;
            $other = PERF_FIXTURE_FOREIGN_UID;
            $paths = [
                'v1' => ["/sys/fs/cgroup/memory/user.slice/user-$own.slice/memory.stat", "/sys/fs/cgroup/memory/user.slice/user-$other.slice/memory.stat"],
                'v2' => ["/sys/fs/cgroup/user.slice/user-$own.slice/memory.stat", "/sys/fs/cgroup/user.slice/user-$other.slice/memory.stat"],
                'hybrid' => ["/sys/fs/cgroup/unified/user.slice/user-$own.slice/io.pressure", "/sys/fs/cgroup/unified/user.slice/user-$other.slice/io.pressure"],
            ];
            foreach ($paths as $mode => $pair) {
                $roots = perfFakeTree($fx['dir'] . "/tree-$mode", $mode);
                $log = $fx['dir'] . "/run/read-$mode.jsonl";
                $fs = new PerfFs($roots, $own, $log);
                $got = $fs->read($pair[0]);
                check(is_string($got) && $got !== '' && strpos($got, PERF_FIXTURE_SENTINEL) === false, "$mode: own file not read: " . var_export($got, true));
                check(strpos((string) $fs->read('/proc/self/cgroup'), "user-$own.slice") !== false, "$mode: /proc/self/cgroup not read");
                $tries = [$pair[1], "/sys/fs/cgroup/memory/user.slice/user-*.slice/memory.stat", "/sys/fs/cgroup/user.slice/user-[0-9]*.slice/memory.stat",
                    "/sys/fs/cgroup/user.slice/user-$own.slice/../user-$other.slice/memory.stat", '/proc/net/tcp', '/proc/self/net/tcp6', 'relative/path'];
                foreach ($tries as $try) {
                    check($fs->read($try) === null && $fs->path($try) === null && !$fs->exists($try), "$mode: $try was not refused");
                }
                $text = (string) file_get_contents($log);
                check(strpos($text, PERF_FIXTURE_SENTINEL) === false, "$mode: the sentinel reached the read log");
                foreach ($fs->read as $read) {
                    check(strpos($read, "user-$other.slice") === false && strpos($read, '/net/tcp') === false, "$mode: read log holds a refused path: $read");
                }
                check(count($fs->refused) === count($tries) && substr_count($text, '"kind":"refused"') === count($tries), "$mode: refusals not logged: " . json_encode($fs->refused));
            }
        } finally {
            perfDone($fx);
        }
    },
    'performance probe: one PSI parser per resource (cpu some, io full, memory full), unknown is null, and the heavy gate thresholds' => function () {
        $fx = perfFixture();
        try {
            $roots = perfFakeTree($fx['dir'] . '/tree', 'v1');
            $p = new PerfProbe(['local_dir' => $fx['local'], 'roots' => $roots, 'uid' => PERF_FIXTURE_UID]);
            $psi = $p->psi();
            check($psi['cpu'] === ['level' => 'some', 'avg10' => 12.5, 'avg60' => 10.0], 'cpu must be read at some: ' . json_encode($psi['cpu']));
            check($psi['io'] === ['level' => 'full', 'avg10' => 3.2, 'avg60' => 2.1], 'io must be read at full: ' . json_encode($psi['io']));
            check($psi['memory'] === ['level' => 'full', 'avg10' => 0.1, 'avg60' => 0.05], 'memory must be read at full: ' . json_encode($psi['memory']));
            check(PerfProbe::heavyGate($psi) === null, 'fixture pressure must not refuse: ' . PerfProbe::heavyGate($psi));
            unlink($roots['/proc'] . '/pressure/io');
            $none = $p->psi();
            check($none['io']['avg10'] === null && $none['io']['avg60'] === null, 'a missing pressure file must be unknown (null), never 0');
            check(PerfProbe::heavyGate($none) === null, 'unknown pressure must not refuse the gate');
            $base = ['cpu' => ['avg10' => 0.0, 'avg60' => 0.0], 'io' => ['avg10' => 0.0, 'avg60' => 0.0], 'memory' => ['avg10' => 0.0, 'avg60' => 0.0]];
            $cases = [['io', 'avg10', 14.99, false], ['io', 'avg10', 15.0, true], ['io', 'avg60', 9.99, false], ['io', 'avg60', 10.0, true],
                ['memory', 'avg10', 9.9, false], ['memory', 'avg10', 10.0, true], ['cpu', 'avg10', 79.9, false], ['cpu', 'avg10', 80.0, true]];
            foreach ($cases as $c) {
                $x = $base;
                $x[$c[0]][$c[1]] = $c[2];
                check((PerfProbe::heavyGate($x) !== null) === $c[3], "gate on {$c[0]} {$c[1]}={$c[2]} should " . ($c[3] ? '' : 'not ') . 'refuse');
            }
            check(PerfProbe::parsePsi("some avg10=x avg60=1\nfull avg10=1.5 avg60=2 avg300=0 total=1\n") === ['full' => ['avg10' => 1.5, 'avg60' => 2.0]], 'parsePsi must drop lines that do not parse');
        } finally {
            perfDone($fx);
        }
    },
    'performance docs: every PerfLimits constant is in the README Budgets table with its value' => function () {
        $readme = (string) file_get_contents(perfTestUnitDir() . '/README.md');
        $rows = [];
        foreach (explode("\n", $readme) as $line) {
            if (preg_match('/^\|\s*`([A-Z0-9_]+)`\s*\|\s*([^|]+?)\s*\|/', $line, $m)) {
                $rows[$m[1]] = $m[2];
            }
        }
        $constants = (new ReflectionClass('PerfLimits'))->getConstants();
        check(count($constants) >= 30, 'PerfLimits has ' . count($constants) . ' constants');
        foreach ($constants as $name => $value) {
            check(isset($rows[$name]), "PerfLimits::$name is not in the README Budgets table");
            check($rows[$name] === (string) $value, "PerfLimits::$name is $value but the README says {$rows[$name]}");
        }
        check(!array_diff_key($rows, $constants), 'the README lists constants PerfLimits does not have: ' . implode(', ', array_keys(array_diff_key($rows, $constants))));
    },
    'performance docs: tools/system/DOCTRINE.md is at most 1200 bytes and the README quotes every line of it' => function () {
        $file = dirname(__DIR__) . '/pikkumies/tools/system/DOCTRINE.md';
        check(filesize($file) <= 1200, 'DOCTRINE.md is ' . filesize($file) . ' bytes');
        $readme = (string) file_get_contents(perfTestUnitDir() . '/README.md');
        $lines = array_filter(array_map('trim', explode("\n", (string) file_get_contents($file))), 'strlen');
        check(count($lines) >= 5, 'DOCTRINE.md lines missing');
        foreach ($lines as $line) {
            check(strpos($readme, $line) !== false, "README.md does not quote the DOCTRINE line: $line");
        }
    },
    'performance unit: no file reads or names the memory failure counter or peak usage (they are not pressure)' => function () {
        $hits = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(perfTestUnitDir(), FilesystemIterator::SKIP_DOTS)) as $f) {
            if (preg_match('/failcnt|max_usage/i', (string) file_get_contents((string) $f))) {
                $hits[] = $f->getFilename();
            }
        }
        check(!$hits, 'failcnt/max_usage found in: ' . implode(', ', $hits));
    },
    'performance docs: public text says only that it reads your own account, never that other accounts are readable' => function () {
        $files = array_merge((array) glob(perfTestUnitDir() . '/*.md'), [dirname(__DIR__) . '/pikkumies/tools/system/DOCTRINE.md', dirname(__DIR__) . '/docs/adr/0035-performance-diagnostics.md']);
        foreach ($files as $f) {
            $text = strtolower((string) file_get_contents($f));
            check(!preg_match('/(other|another|foreign|neighbou?r)[a-z\' ]{0,40}(files?|counters?|slices?|cgroups?)[a-z ]{0,30}(are|is) (world[- ])?readable|world[- ]readable/', $text), basename($f) . ' says other accounts\' files are readable');
        }
        $readme = strtolower((string) file_get_contents(perfTestUnitDir() . '/README.md'));
        check(strpos($readme, 'reads only your own account') !== false, 'README must say it reads only your own account');
    },
    'performance fixtures: every fake program logs its argv, prints its default output, and takes a test\'s output and exit code' => function () {
        $fx = perfFixture();
        try {
            foreach (PERF_FIXTURE_FAKES as $name) {
                $bin = $name === 'userShowLimits' ? $fx['home'] . "/bin/$name" : $fx['bin'] . "/$name";
                $out = testWithEnv(['PK_PERF_FAKE_LOG' => $fx['log']], function () use ($bin) {
                    return (string) shell_exec(escapeshellarg($bin) . ' --json 2>&1');
                });
                check(trim($out) !== '', "$name printed nothing");
            }
            check(array_column(perfFakeCalls($fx), 'prog') === PERF_FIXTURE_FAKES, 'fake log: ' . json_encode(perfFakeCalls($fx)));
            file_put_contents($fx['dir'] . '/run/mtr.out', "custom\n");
            $out = testWithEnv(['PK_PERF_FAKE_MTR_OUT' => $fx['dir'] . '/run/mtr.out', 'PK_PERF_FAKE_MTR_RC' => '3'], function () use ($fx) {
                exec(escapeshellarg($fx['bin'] . '/mtr') . ' --report', $lines, $rc);
                return [$lines, $rc];
            });
            check($out === [['custom'], 3], 'override: ' . json_encode($out));
        } finally {
            perfDone($fx);
        }
    },
];
