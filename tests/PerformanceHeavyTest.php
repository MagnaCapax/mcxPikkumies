<?php
/**
 * The performance unit's `heavy` verb (PerfHeavy, ADR-0035): the pressure gate, disk-write only on a server this account
 * does not share, the plan-traffic margin, the hourly courtesy cooldown, an uninstalled program skipped (speedtest: the
 * Ookla binary only), fio read-only on an existing own file, the single-stream download through Http discard, and the
 * label "your share now, not the ceiling" on every record. Fixture trees and fake programs only: PATH holds the fakes and
 * the few coreutils the runner needs, so a real fio, iperf3 or speedtest on this machine is never found or run.
 */
require_once __DIR__ . '/fixtures/performance/harness.php';
require_once __DIR__ . '/fixtures/performance/discard-server.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/performance/PerfHeavy.php';

if (!function_exists('hvFixture')) {
    function hvUid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** Copies a fake from tests/fixtures/performance/heavy/ into the fixture's bin/ under the name $as. */
    function hvInstall(array $fx, $src, $as)
    {
        file_put_contents($fx['bin'] . "/$as", '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . "/fixtures/performance/heavy/$src"));
        chmod($fx['bin'] . "/$as", 0700);
    }

    /**
     * perfFixture() plus: sys/ with only nice, ionice, timeout and find; a v2 /proc tree (pressure below every gate);
     * /etc with one person's account and no /etc/seedbox (a server not shared); the Ookla speedtest fake; a 65 MiB sparse
     * file of the account's own in HOME with a ':' in its name.
     */
    function hvFixture()
    {
        $fx = perfFixture();
        mkdir($fx['dir'] . '/sys', 0700);
        foreach (['nice', 'ionice', 'timeout', 'find'] as $p) {
            $w = ToolProc::which($p);
            if ($w === null) {
                perfDone($fx);
                skip("$p is not installed here");
            }
            symlink($w, $fx['dir'] . "/sys/$p");
        }
        $fx['roots'] = perfFakeTree($fx['dir'] . '/tree', 'v2');
        $fx['roots']['/etc'] = $fx['dir'] . '/etc';
        perfWrite($fx['dir'] . '/etc/passwd', "root:x:0:0:root:/root:/bin/sh\nme:x:1000:1000::" . $fx['home'] . ":/bin/sh\n");
        hvInstall($fx, 'speedtest-ookla', 'speedtest');
        $fx['big'] = $fx['home'] . '/big:file.bin';
        $h = fopen($fx['big'], 'w');
        ftruncate($h, 65 * 1048576 + 1);
        fclose($h);
        $fx['env'] = ['PATH' => $fx['bin'] . ':' . $fx['dir'] . '/sys'];
        $fx['calls'] = $fx['dir'] . '/run/calls.jsonl';
        return $fx;
    }

    /** performance.php heavy <args> in the fixture; returns perfRun's [code, records, stdout, stderr]. */
    function hvRun(array $fx, array $args, array $env = [])
    {
        return perfRun($fx, array_merge(['heavy'], $args), ['roots' => $fx['roots'], 'uid' => hvUid(), 'call_log' => $fx['calls']], $env + $fx['env']);
    }

    /** argv of each call the fake $prog got. */
    function hvCalls(array $fx, $prog)
    {
        return array_values(array_map(function ($c) {
            return $c['argv'];
        }, array_filter(perfFakeCalls($fx), function ($c) use ($prog) {
            return $c['prog'] === $prog;
        })));
    }

    /** The heavy records of a run (the status line left out); each must carry the label. */
    function hvRecords(array $recs, $what)
    {
        $heavy = array_values(array_filter($recs, function ($r) {
            return isset($r['record']) && $r['record'] === 'heavy';
        }));
        check($heavy, "$what: no heavy record: " . json_encode($recs));
        foreach ($heavy as $r) {
            check(isset($r['label']) && $r['label'] === 'your share now, not the ceiling', "$what: record without the label: " . json_encode($r));
        }
        return $heavy;
    }

    function hvLastError(array $recs)
    {
        $last = $recs ? $recs[count($recs) - 1] : [];
        return isset($last['error']) ? (string) $last['error'] : '';
    }

    /** A userShowLimits --json output with the given traffic metrics, as the PMSS tool prints them. */
    function hvUsl(array $fx, array $metrics)
    {
        $file = $fx['dir'] . '/run/usl.json';
        $m = [];
        foreach ($metrics as $name => $vl) {
            $m[] = ['metric' => $name, 'value' => $vl[0], 'limit' => $vl[1], 'unit' => 'bytes', 'percent' => null, 'source' => 'traffic-data'];
        }
        file_put_contents($file, json_encode(['user' => 'fake-user-name', 'host' => 'fake-host.example', 'generated_at' => 1792224000,
            'sections' => ['traffic' => ['available' => true, 'metrics' => $m]]]) . "\n");
        return ['PK_PERF_FAKE_USERSHOWLIMITS_OUT' => $file];
    }
}

const HV_GIB = 1073741824;
const HV_TIB = 1099511627776;

return [
    'performance heavy: the pressure gate refuses on io full avg10 >= 15 or avg60 >= 10, memory full avg10 >= 10, cpu some avg10 >= 80, and runs nothing' => function () {
        $fx = hvFixture();
        try {
            $cases = [['io', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=15.00 avg60=1.00 avg300=0.00 total=0\n", 'io full avg10'],
                ['io', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=1.00 avg60=10.00 avg300=0.00 total=0\n", 'io full avg60'],
                ['memory', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=10.00 avg60=0.00 avg300=0.00 total=0\n", 'memory full avg10'],
                ['cpu', "some avg10=80.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n", 'cpu some avg10']];
            $pressure = $fx['roots']['/proc'] . '/pressure';
            $keep = [];
            foreach (['io', 'memory', 'cpu'] as $res) {
                $keep[$res] = file_get_contents("$pressure/$res");
            }
            foreach ($cases as $c) {
                file_put_contents("$pressure/{$c[0]}", $c[1]);
                list($code, $recs) = hvRun($fx, ['--test', 'disk-read']);
                check($code === 1 && strpos(hvLastError($recs), $c[2]) !== false && strpos(hvLastError($recs), 'nothing was run') !== false,
                    "{$c[2]} at the threshold did not refuse: $code " . json_encode($recs));
                file_put_contents("$pressure/{$c[0]}", $keep[$c[0]]);
            }
            check(hvCalls($fx, 'fio') === [], 'a refused test ran fio: ' . json_encode(hvCalls($fx, 'fio')));
            check(!is_file($fx['local'] . '/performance/heavy-last.json'), 'a refused test started the cooldown');
            file_put_contents("$pressure/io", "some avg10=95.00 avg60=90.00 avg300=0.00 total=0\nfull avg10=14.99 avg60=9.99 avg300=0.00 total=0\n");
            list($code, $recs) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 0 && count(hvCalls($fx, 'fio')) === 1, 'io some 95 with io full just below the anchors must run (io is read at full): ' . $code . ' ' . json_encode($recs));
            $rec = hvRecords($recs, 'below the gate')[0];
            check($rec['pressure_before']['io'] === ['level' => 'full', 'avg10' => 14.99, 'avg60' => 9.99] && isset($rec['pressure_after']['cpu']), 'pressure not shown: ' . json_encode($rec));
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: unknown pressure (no PSI files) does not hold a test back and is shown as null with a note' => function () {
        $fx = hvFixture();
        try {
            foreach (['cpu', 'io', 'memory'] as $res) {
                unlink($fx['roots']['/proc'] . "/pressure/$res");
            }
            list($code, $recs) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 0, 'unknown pressure refused the test: ' . json_encode($recs));
            $rec = hvRecords($recs, 'unknown PSI')[0];
            check($rec['ran'] === true && $rec['pressure_before']['io']['avg10'] === null && $rec['pressure_before']['memory']['avg60'] === null, 'unknown not null: ' . json_encode($rec['pressure_before']));
            check(isset($rec['pressure_note']) && strpos($rec['pressure_note'], 'unknown, not 0') !== false, 'no note on unknown pressure: ' . json_encode($rec));
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: disk-write is refused where the server is shared (PMSS, two people\'s accounts, an unreadable account list) and writes only in scratch otherwise' => function () {
        $fx = hvFixture();
        try {
            $etc = $fx['dir'] . '/etc';
            $passwd = (string) file_get_contents("$etc/passwd");
            mkdir($fx['dir'] . '/other-home', 0700);
            $shared = [
                'PMSS' => function () use ($etc) {
                    mkdir("$etc/seedbox", 0700);
                },
                'two people' => function () use ($etc, $passwd, $fx) {
                    rmdir("$etc/seedbox");
                    file_put_contents("$etc/passwd", $passwd . 'you:x:1001:1001::' . $fx['dir'] . "/other-home:/bin/sh\n");
                },
                'no account list' => function () use ($etc) {
                    unlink("$etc/passwd");
                },
            ];
            foreach ($shared as $what => $setUp) {
                $setUp();
                list($code, $recs) = hvRun($fx, ['--test', 'disk-write']);
                check($code === 1 && strpos(hvLastError($recs), 'does not share') !== false, "$what: disk-write not refused: $code " . json_encode($recs));
            }
            check(hvCalls($fx, 'dd') === [], 'a refused disk-write ran dd: ' . json_encode(hvCalls($fx, 'dd')));
            file_put_contents("$etc/passwd", $passwd);
            list($code, $recs) = hvRun($fx, ['--test', 'disk-write']);
            check($code === 0, 'disk-write on a server not shared failed: ' . json_encode($recs));
            $rec = hvRecords($recs, 'disk-write')[0];
            $dd = hvCalls($fx, 'dd');
            check(count($dd) === 1, 'dd calls: ' . json_encode($dd));
            $of = array_values(array_filter($dd[0], function ($a) {
                return strpos($a, 'of=') === 0;
            }));
            check(count($of) === 1 && strpos($of[0], 'of=' . $fx['local'] . '/performance/scratch/') === 0, 'dd wrote outside scratch: ' . json_encode($dd[0]));
            check(in_array('conv=fsync', $dd[0], true) && in_array('bs=1M', $dd[0], true) && in_array('count=256', $dd[0], true), 'dd is not a synced 256 MiB write: ' . json_encode($dd[0]));
            check(!file_exists(substr($of[0], 3)), 'the write test left its file');
            check($rec['result']['synced'] === true && $rec['result']['bytes'] === 65536 && $rec['result']['io_class'] === 'best-effort/7', 'result: ' . json_encode($rec['result']));
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: a test whose traffic is over 10% of the plan traffic left in the capped direction is refused; the uncapped direction is not' => function () {
        $fx = hvFixture();
        $srv = null;
        try {
            // PMSS caps upload only: iperf3 and speedtest move about 12.5 / 18.75 GB each way at the 10 Gbit/s estimate.
            $env = hvUsl($fx, ['upload past 30 days' => [5 * HV_TIB - 100 * HV_GIB, 5 * HV_TIB], 'inbound past 30 days' => [HV_TIB, null]]);
            foreach (['iperf3' => ['--test', 'iperf3', '--target', '192.0.2.5'], 'speedtest' => ['--test', 'speedtest']] as $what => $args) {
                list($code, $recs) = hvRun($fx, $args, $env);
                check($code === 1 && strpos(hvLastError($recs), 'plan traffic left') !== false && strpos(hvLastError($recs), 'from this server') !== false,
                    "$what with 100 GiB of upload left was not refused: $code " . json_encode($recs));
            }
            check(hvCalls($fx, 'iperf3') === [] && count(hvCalls($fx, 'speedtest')) === 1, 'a refused test ran its program (only speedtest --version may run): ' . json_encode(perfFakeCalls($fx)));
            $env = hvUsl($fx, ['upload past 30 days' => [5 * HV_TIB - 200 * HV_GIB, 5 * HV_TIB], 'inbound past 30 days' => [HV_TIB, null]]);
            list($code, $recs) = hvRun($fx, ['--test', 'iperf3', '--target', '192.0.2.5'], $env);
            check($code === 0 && count(hvCalls($fx, 'iperf3')) === 2, 'iperf3 with 200 GiB left must run both ways: ' . json_encode($recs));
            $rec = hvRecords($recs, 'iperf3')[0];
            check($rec['traffic'] === [['direction' => 'from this server', 'left_bytes' => 200 * HV_GIB, 'test_bytes' => 12500000000]], 'traffic shown: ' . json_encode($rec['traffic']));
            if (!function_exists('curl_init')) {
                skip('curl extension missing (the download half of this test)');
            }
            $srv = perfDiscardServer();
            $env = hvUsl($fx, ['upload past 30 days' => [5 * HV_TIB - HV_GIB, 5 * HV_TIB], 'inbound past 30 days' => [HV_TIB, null]]);
            list($code, $recs) = hvRun($fx, ['--test', 'download', '--url', $srv[1] . '/bytes?n=1048576'], $env);
            check($code === 0, 'a download (inbound) was refused by the upload cap: ' . json_encode($recs));
            unlink($fx['local'] . '/performance/heavy-last.json');
            $env = hvUsl($fx, ['inbound past 30 days' => [10 * HV_GIB - 4 * HV_GIB, 10 * HV_GIB]]);
            list($code, $recs) = hvRun($fx, ['--test', 'download', '--url', $srv[1] . '/bytes?n=1048576'], $env);
            check($code === 1 && strpos(hvLastError($recs), 'to this server') !== false, 'a download over 10% of 4 GiB inbound left was not refused: ' . json_encode($recs));
        } finally {
            if ($srv !== null) {
                perfDiscardServerDone($srv);
            }
            perfDone($fx);
        }
    },
    'performance heavy: the traffic margin boundary (exactly 10% passes, a byte more refuses), and unknown plan traffic is shown, not checked' => function () {
        $usl = function ($left) {
            return ['sections' => ['traffic' => ['available' => true, 'metrics' => [
                ['metric' => 'upload past 30 days', 'value' => 1000, 'limit' => 1000 + $left, 'unit' => 'bytes'],
                ['metric' => 'inbound past 30 days', 'value' => 5, 'limit' => null, 'unit' => 'bytes']]]]];
        };
        $est = ['in' => 7, 'out' => 100];
        check(PerfHeavy::traffic($usl(1000), $est)['refusal'] === null, '100 bytes of 1000 left (10%) must pass');
        check(PerfHeavy::traffic($usl(999), $est)['refusal'] !== null, '100 bytes of 999 left (over 10%) must refuse');
        check(PerfHeavy::traffic($usl(0), $est)['refusal'] !== null, 'no traffic left must refuse');
        check(PerfHeavy::traffic($usl(10), ['in' => 7, 'out' => 0])['refusal'] === null, 'a test moving nothing in the capped direction must pass');
        $total = ['sections' => ['traffic' => ['available' => true, 'metrics' => [['metric' => 'traffic past 30 days', 'value' => 0, 'limit' => 1069, 'unit' => 'bytes']]]]];
        check(PerfHeavy::traffic($total, $est)['refusal'] !== null && PerfHeavy::traffic($total, ['in' => 6, 'out' => 100])['refusal'] === null, 'a limit without a direction counts both ways');
        foreach ([null, [], ['sections' => ['traffic' => ['available' => false, 'metrics' => []]]]] as $none) {
            $t = PerfHeavy::traffic($none, $est);
            check($t['known'] === false && $t['refusal'] === null, 'unknown traffic must not refuse: ' . json_encode($t));
        }
        $fx = hvFixture();
        try {
            unlink($fx['home'] . '/bin/userShowLimits');
            list($code, $recs) = hvRun($fx, ['--test', 'iperf3', '--target', '192.0.2.5']);
            check($code === 0 && hvRecords($recs, 'no userShowLimits')[0]['traffic'] === 'plan traffic not known here; not checked', 'no userShowLimits: ' . json_encode($recs));
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: one run per test per hour (courtesy file, 0600), per test, and an expired entry lets it run again' => function () {
        $fx = hvFixture();
        try {
            list($code) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 0, 'first disk-read failed');
            $file = $fx['local'] . '/performance/heavy-last.json';
            check(is_file($file) && substr(sprintf('%o', fileperms($file)), -4) === '0600', 'cooldown file missing or not 0600');
            list($code, $recs) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 1 && strpos(hvLastError($recs), 'less than an hour ago') !== false, 'second disk-read within the hour not refused: ' . json_encode($recs));
            check(count(hvCalls($fx, 'fio')) === 1, 'the refused run ran fio');
            list($code) = hvRun($fx, ['--test', 'iperf3', '--target', '192.0.2.5']);
            check($code === 0, 'the cooldown of disk-read held back iperf3');
            $last = json_decode((string) file_get_contents($file), true);
            check(isset($last['disk-read'], $last['iperf3']), 'cooldown file: ' . json_encode($last));
            $last['disk-read'] = time() - 3601;
            file_put_contents($file, json_encode($last));
            list($code) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 0 && count(hvCalls($fx, 'fio')) === 2, 'an entry older than an hour still refused');
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: an uninstalled program is skipped (exit 0, nothing run, no cooldown); speedtest is the Ookla binary only, never speedtest-cli' => function () {
        $fx = hvFixture();
        try {
            foreach (['iperf3' => ['--test', 'iperf3', '--target', '192.0.2.5'], 'fio' => ['--test', 'disk-read']] as $prog => $args) {
                unlink($fx['bin'] . "/$prog");
                list($code, $recs) = hvRun($fx, $args);
                $rec = hvRecords($recs, "no $prog")[0];
                check($code === 0 && $rec['ran'] === false && strpos($rec['skipped'], "$prog is not installed") === 0, "missing $prog not skipped: $code " . json_encode($recs));
            }
            unlink($fx['bin'] . '/speedtest');
            hvInstall($fx, 'speedtest-sivel', 'speedtest-cli');
            list($code, $recs) = hvRun($fx, ['--test', 'speedtest']);
            check($code === 0 && strpos(hvRecords($recs, 'speedtest-cli only')[0]['skipped'], 'speedtest is not installed') === 0, 'speedtest-cli alone was not skipped: ' . json_encode($recs));
            hvInstall($fx, 'speedtest-sivel', 'speedtest');
            list($code, $recs) = hvRun($fx, ['--test', 'speedtest']);
            check($code === 0 && strpos(hvRecords($recs, 'sivel as speedtest')[0]['skipped'], 'not the Ookla binary') !== false, 'a speedtest-cli installed as speedtest was used: ' . json_encode($recs));
            $calls = perfFakeCalls($fx);
            check(!array_filter($calls, function ($c) {
                return $c['prog'] === 'speedtest-cli' || ($c['prog'] === 'speedtest' && $c['argv'] !== ['--version']);
            }), 'speedtest-cli ran, or the look-alike ran past --version: ' . json_encode($calls));
            check(!is_file($fx['local'] . '/performance/heavy-last.json'), 'a skipped test started the cooldown');
            $runs = cliLines((string) file_get_contents($fx['local'] . '/performance/runs.jsonl'));
            check($runs && $runs[count($runs) - 1]['skipped'][0]['check'] === 'heavy speedtest', 'the skip is not in the run log: ' . json_encode($runs));
            hvInstall($fx, 'speedtest-ookla', 'speedtest');
            list($code, $recs, $out) = hvRun($fx, ['--test', 'speedtest']);
            $rec = hvRecords($recs, 'ookla')[0];
            check($code === 0 && $rec['ran'] === true && $rec['result']['download_mbit_s'] == 936 && $rec['result']['upload_mbit_s'] == 880 && $rec['result']['ping_ms'] == 1.9, 'Ookla result: ' . json_encode($rec));
            check(hvCalls($fx, 'speedtest')[count(hvCalls($fx, 'speedtest')) - 1] === ['--format=json', '--progress=no'], 'speedtest argv: ' . json_encode(hvCalls($fx, 'speedtest')));
            foreach (['FAKE-ISP-NAME', '192.0.2.77', 'fake-speed-server', '198.51.100.99'] as $leak) {
                check(strpos($out, $leak) === false, "speedtest output passes on $leak");
            }
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: disk-read runs fio --readonly --direct=1 on an existing own file (found with find -uid, scan mode), at most 30 s and 1 GiB' => function () {
        $fx = hvFixture();
        try {
            list($code, $recs) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 0, 'disk-read failed: ' . json_encode($recs));
            $rec = hvRecords($recs, 'disk-read')[0];
            $fio = hvCalls($fx, 'fio');
            check(count($fio) === 1, 'fio calls: ' . json_encode($fio));
            $a = $fio[0];
            check(in_array('--readonly', $a, true) && in_array('--direct=1', $a, true), 'fio without --readonly/--direct=1: ' . json_encode($a));
            check(in_array('--filename=' . str_replace(':', '\\:', $fx['big']), $a, true), 'fio not on the own file (":" escaped): ' . json_encode($a));
            check(in_array('--rw=randread', $a, true) && in_array('--rw=read', $a, true) && !preg_grep('/^--rw=.*(write|rw)/', $a), 'fio jobs: ' . json_encode($a));
            $runtime = (int) substr((string) current(preg_grep('/^--runtime=/', $a)), 10);
            $io = (int) substr((string) current(preg_grep('/^--io_size=/', $a)), 10);
            $size = (int) substr((string) current(preg_grep('/^--size=/', $a)), 7);
            check($runtime * 2 <= PerfLimits::HEAVY_DISK_READ_S && $io * 2 <= PerfLimits::HEAVY_DISK_READ_MAX && $size === 65 * 1048576 + 1, "fio budget: runtime $runtime, io_size $io, size $size");
            $calls = cliLines((string) file_get_contents($fx['calls']));
            $find = array_values(array_filter($calls, function ($c) {
                return in_array('find', $c, true);
            }));
            check(count($find) === 1 && array_slice($find[0], 0, 5) === ['nice', '-n', '19', 'ionice', '-c3'] && in_array('-uid', $find[0], true)
                && $find[0][array_search('-uid', $find[0], true) + 1] === (string) hvUid() && in_array('-quit', $find[0], true), 'find: ' . json_encode($find));
            $fioRun = array_values(array_filter($calls, function ($c) {
                return in_array('fio', $c, true);
            }));
            check(array_slice($fioRun[0], 0, 6) === ['nice', '-n', '19', 'ionice', '-c2', '-n7'] && $fioRun[0][6] === 'timeout', 'fio not in measure mode: ' . json_encode($fioRun));
            check($rec['result']['read_only'] === true && $rec['result']['jobs'][0]['mb_s'] == 2.6 && $rec['result']['jobs'][0]['latency_ms_p95'] == 4.1, 'result: ' . json_encode($rec['result']));
            unlink($fx['big']);
            unlink($fx['local'] . '/performance/heavy-last.json');
            list($code, $recs) = hvRun($fx, ['--test', 'disk-read']);
            check($code === 0 && strpos(hvRecords($recs, 'no file')[0]['skipped'], 'no file of your own') === 0 && count(hvCalls($fx, 'fio')) === 1, 'no own file: ' . json_encode($recs));
        } finally {
            perfDone($fx);
        }
    },
    'performance heavy: download is one stream through Http discard, capped at 512 MiB or 60 s, counted, and a redirect is not followed' => function () {
        check(PerfHeavy::downloadOptions() === ['discard' => true, 'max' => 536870912, 'timeout' => 60], 'download options: ' . json_encode(PerfHeavy::downloadOptions()));
        check(PerfLimits::HEAVY_DOWNLOAD_MAX === 536870912 && PerfLimits::HEAVY_DOWNLOAD_S === 60, 'download budget constants changed');
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
        $fx = hvFixture();
        $srv = perfDiscardServer();
        try {
            list($code, $recs) = hvRun($fx, ['--test', 'download', '--url', $srv[1] . '/bytes?n=' . (3 * 1048576)]);
            check($code === 0, 'download failed: ' . json_encode($recs));
            $rec = hvRecords($recs, 'download')[0];
            check($rec['result']['bytes'] === 3 * 1048576 && $rec['result']['streams'] === 1 && $rec['result']['ended'] === 'whole file' && $rec['result']['mbit_s'] > 0, 'result: ' . json_encode($rec['result']));
            check($rec['cost']['bytes_downloaded'] === 3 * 1048576, 'cost: ' . json_encode($rec['cost']));
            unlink($fx['local'] . '/performance/heavy-last.json');
            list($code, $recs) = hvRun($fx, ['--test', 'download', '--url', $srv[1] . '/moved']);
            check($code === 1 && strpos(hvLastError($recs), 'redirects') !== false, 'a redirect was not refused: ' . json_encode($recs));
            check(!is_file($fx['local'] . '/performance/heavy-last.json'), 'a download that moved nothing started the cooldown');
        } finally {
            perfDiscardServerDone($srv);
            perfDone($fx);
        }
    },
    'performance heavy: every test\'s record says "your share now, not the ceiling" and the iperf3 result has both directions' => function () {
        $fx = hvFixture();
        try {
            foreach ([['--test', 'disk-read'], ['--test', 'disk-write'], ['--test', 'iperf3', '--target', '192.0.2.5'], ['--test', 'speedtest']] as $args) {
                list($code, $recs) = hvRun($fx, $args);
                check($code === 0, implode(' ', $args) . ' failed: ' . json_encode($recs));
                $rec = hvRecords($recs, implode(' ', $args))[0];
                check(strpos($rec['reading'], 'your share now, not the ceiling') === 0 && strpos($rec['reading'], 'not what the server can do') !== false, 'reading: ' . $rec['reading']);
                if ($args[1] === 'iperf3') {
                    check(array_column($rec['result']['directions'], 'direction') === ['from this server', 'to this server'] && $rec['result']['directions'][0]['mbit_s'] == 936, 'iperf3: ' . json_encode($rec['result']));
                    check(hvCalls($fx, 'iperf3') === [['-c', '192.0.2.5', '-t', '10', '-J'], ['-c', '192.0.2.5', '-t', '10', '-J', '-R']], 'iperf3 argv: ' . json_encode(hvCalls($fx, 'iperf3')));
                }
            }
        } finally {
            perfDone($fx);
        }
    },
];
