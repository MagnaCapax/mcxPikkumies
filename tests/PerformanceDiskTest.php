<?php
/**
 * performance disk (PerfDisk, ADR-0035): 16 synced 4 KiB writes in scratch with dd, 64 direct 4 KiB random reads of one
 * existing own file of 64 MiB or more with fio --readonly, found by a scan-mode find; the EINVAL (no O_DIRECT) skip; the
 * quota refusal before anything is written; measure mode on the probes; no absolute verdict and no disk-type claim.
 */
require_once __DIR__ . '/fixtures/performance/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/performance/PerfProbe.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/performance/PerfDisk.php';

const PERF_DISK_MEASURE = ['nice', '-n', '19', 'ionice', '-c2', '-n7', 'timeout', '-k', '2'];
const PERF_DISK_SCAN = ['nice', '-n', '19', 'ionice', '-c3', 'timeout', '-k', '2'];

function perfDiskNeedWrappers()
{
    foreach (['nice', 'ionice', 'timeout', 'find'] as $p) {
        if (ToolProc::which($p) === null) {
            skip("$p is not installed here");
        }
    }
}

/** A fake `quota -s` in the fixture bin: used, soft and hard as quota prints them (K/M/G). */
function perfDiskQuota(array $fx, $used, $soft, $hard)
{
    $text = "Disk quotas for user u (uid 4242):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
        . "       /dev/md0  $used  $soft  $hard            1000       0       0\n";
    file_put_contents($fx['bin'] . '/quota', '#!' . PHP_BINARY . "\n<?php echo " . var_export($text, true) . ";\n");
    chmod($fx['bin'] . '/quota', 0700);
}

/** A file of $bytes under the fixture home: written out (allocated), or sparse (holes only, as an unfinished torrent). */
function perfDiskBigFile(array $fx, $rel, $bytes, $sparse = false)
{
    $path = $fx['home'] . '/' . $rel;
    @mkdir(dirname($path), 0700, true);
    $h = fopen($path, 'w');
    if ($sparse) {
        ftruncate($h, $bytes);
    } else {
        $chunk = str_repeat("x", 1048576);
        for ($left = $bytes; $left > 0; $left -= 1048576) {
            fwrite($h, $left >= 1048576 ? $chunk : substr($chunk, 0, $left));
        }
    }
    fclose($h);
    return $path;
}

function perfDiskRun(array $fx, array $args, array $env = [])
{
    $log = $fx['dir'] . '/run/calls-' . mt_rand() . '.jsonl';
    $roots = perfFakeTree($fx['dir'] . '/tree', 'v2');
    list($code, $recs, $out, $err) = perfRun($fx, array_merge(['disk'], $args), ['call_log' => $log, 'roots' => $roots], $env);
    return [$code, $recs, $out, $err, is_file($log) ? cliLines((string) file_get_contents($log)) : []];
}

function perfDiskCall(array $logged, $prog)
{
    foreach ($logged as $argv) {
        if (in_array($prog, $argv, true)) {
            return $argv;
        }
    }
    return null;
}

function perfDiskLint($out)
{
    check(!preg_match('/\b(hdd|ssd|nvme|rotational|spinning|healthy|failing|degraded|fast|slow)\b/i', $out), 'a disk-type or verdict word reached the output: ' . $out);
}

return [
    'performance disk: 16 synced writes in scratch and 64 direct reads of an own big file, probes in measure mode, find in scan mode' => function () {
        perfDiskNeedWrappers();
        $fx = perfFixture();
        try {
            perfDiskQuota($fx, '100G', '1024G', '1280G');
            perfDiskBigFile($fx, 'media/sub/Secret:Show.mkv', 67108864);
            list($code, $recs, $out, $err, $logged) = perfDiskRun($fx, []);
            check($code === 0, "exit $code: $out $err");
            $d = $recs[0];
            check($d['kind'] === 'disk' && $d['writes'] === 16 && $d['write_commit_ms_mean'] == 3.0, 'write: ' . json_encode($d));
            check($d['reads'] === 64 && $d['read_p50_ms'] == 1.2 && $d['read_p95_ms'] == 4.1 && $d['read_max_ms'] == 9.1, 'read: ' . json_encode($d));
            check($d['io_class'] === 'best-effort/7' && $d['server_io_pressure'] == ['level' => 'full', 'avg10' => 3.2, 'avg60' => 2.1], 'pressure beside the result: ' . json_encode($d['server_io_pressure']));
            check(strpos($d['note'], 'no absolute verdict') === 0, 'note: ' . $d['note']);
            check(strpos($out, 'Secret') === false && strpos($out, 'media/sub') === false && strpos($out, $fx['home']) === false, 'the file name or path reached the output');
            perfDiskLint($out);

            $dd = perfDiskCall($logged, 'dd');
            check($dd !== null && array_slice($dd, 0, 9) === PERF_DISK_MEASURE && ctype_digit($dd[9]) && $dd[10] === 'dd', 'dd must run in measure mode: ' . json_encode($dd));
            foreach (['bs=4096', 'count=16', 'oflag=dsync'] as $a) {
                check(in_array($a, $dd, true), "dd argv lacks $a: " . json_encode($dd));
            }
            $of = substr((string) current(array_filter($dd, function ($a) {
                return strpos($a, 'of=') === 0;
            })), 3);
            check(strpos($of, $fx['local'] . '/performance/scratch/') === 0, 'dd must write into scratch only: ' . $of);
            $fio = perfDiskCall($logged, 'fio');
            check($fio !== null && array_slice($fio, 0, 9) === PERF_DISK_MEASURE && $fio[10] === 'fio', 'fio must run in measure mode: ' . json_encode($fio));
            foreach (['--readonly', '--direct=1', '--rw=randread', '--bs=4096', '--io_size=262144'] as $a) {
                check(in_array($a, $fio, true), "fio argv lacks $a: " . json_encode($fio));
            }
            check(in_array('--filename=' . $fx['home'] . '/media/sub/Secret\\:Show.mkv', $fio, true), 'fio must get the found file with its colon escaped: ' . json_encode($fio));
            $find = perfDiskCall($logged, 'find');
            check($find !== null && array_slice($find, 0, 8) === PERF_DISK_SCAN && $find[8] === '5' && $find[9] === 'find', 'find must run in scan mode with a 5 s limit: ' . json_encode($find));
            foreach (['-xdev', '-maxdepth', '-print', '+67108863c'] as $a) {
                check(in_array($a, $find, true), "find argv lacks $a: " . json_encode($find));
            }
            check(!file_exists($of), 'the probe file was left behind');
            $run = json_decode(trim((string) file_get_contents($fx['local'] . '/performance/runs.jsonl')), true);
            check($run['cost']['bytes_written'] === 65536 && $run['cost']['bytes_read_direct'] === 262144, 'cost: ' . json_encode($run['cost']));
            check($d['previous'] === null, 'first run has no previous');
            list(, $recs2) = perfDiskRun($fx, []);
            check($recs2[0]['previous']['same_server'] === true && $recs2[0]['previous']['write_commit_ms_mean'] == 3.0, 'the second run must show the first: ' . json_encode($recs2[0]['previous']));
            check(substr(sprintf('%o', fileperms($fx['local'] . '/performance/disk-last.json')), -4) === '0600', 'disk-last.json mode');
        } finally {
            perfDone($fx);
        }
    },
    'performance disk: --no-read writes only; no big own file means the reads are skipped with the reason' => function () {
        perfDiskNeedWrappers();
        $fx = perfFixture();
        try {
            perfDiskQuota($fx, '100G', '1024G', '1280G');
            perfDiskBigFile($fx, 'media/big.bin', 67108864);
            list($code, $recs, , , $logged) = perfDiskRun($fx, ['--no-read']);
            check($code === 0 && perfDiskCall($logged, 'find') === null && perfDiskCall($logged, 'fio') === null && perfDiskCall($logged, 'dd') !== null, '--no-read: ' . json_encode($logged));
            check($recs[0]['reads'] === 0 && $recs[0]['read_p50_ms'] === null, 'no-read fields: ' . json_encode($recs[0]));
            $fx2 = perfFixture();
            try {
                perfDiskQuota($fx2, '100G', '1024G', '1280G');
                perfDiskBigFile($fx2, 'small.bin', 67108863);
                perfDiskBigFile($fx2, 'a/b/c/d/deep.bin', 67108864); // four levels down: beyond -maxdepth 3
                perfDiskBigFile($fx2, 'media/unfinished.mkv', 134217728, true); // sparse: reading holes measures nothing
                $outside = $fx2['dir'] . '/outside.bin';
                $h = fopen($outside, 'w');
                ftruncate($h, 134217728);
                fclose($h);
                symlink($outside, $fx2['home'] . '/link.bin');
                list($code, $recs, , , $logged) = perfDiskRun($fx2, []);
                check($code === 0 && perfDiskCall($logged, 'fio') === null, 'no qualifying file, but fio ran: ' . json_encode($logged));
                $run = json_decode(trim((string) file_get_contents($fx2['local'] . '/performance/runs.jsonl')), true);
                check(strpos(json_encode($run['skipped']), 'no fully written (not sparse) file of your own of 64 MiB') !== false, 'skipped: ' . json_encode($run['skipped']));
            } finally {
                perfDone($fx2);
            }
        } finally {
            perfDone($fx);
        }
    },
    'performance disk: a filesystem without direct reads (EINVAL) skips the reads with that reason and keeps the write result' => function () {
        perfDiskNeedWrappers();
        $fx = perfFixture();
        try {
            perfDiskQuota($fx, '100G', '1024G', '1280G');
            perfDiskBigFile($fx, 'media/big.bin', 67108864);
            file_put_contents($fx['dir'] . '/fio.out', "fio: looks like your file system does not support direct=1/buffered=0\nfio: pid=0, err=22/file:filesetup.c:805, func=open(/x), error=Invalid argument\n");
            list($code, $recs, $out) = perfDiskRun($fx, [], ['PK_PERF_FAKE_FIO_OUT' => $fx['dir'] . '/fio.out', 'PK_PERF_FAKE_FIO_RC' => '1']);
            check($code === 0 && $recs[0]['write_commit_ms_mean'] == 3.0 && $recs[0]['read_p50_ms'] === null && $recs[0]['reads'] === 0, 'einval: ' . $out);
            $run = json_decode(trim((string) file_get_contents($fx['local'] . '/performance/runs.jsonl')), true);
            check(strpos(json_encode($run['skipped']), 'O_DIRECT') !== false, 'the EINVAL reason must be named: ' . json_encode($run['skipped']));
            check(PerfDisk::parseFio('{"jobs":[{"error":22,"read":{"total_ios":0}}]}') === ['error' => 'einval'], 'fio json error 22 is EINVAL');
            check(PerfDisk::parseFio("fio: unrecognized option '--x'\n") === null, 'another fio failure is not EINVAL');
        } finally {
            perfDone($fx);
        }
    },
    'performance disk: refused before anything is written when free quota is under twice the probe bytes or the hard limit is reached' => function () {
        perfDiskNeedWrappers();
        $fx = perfFixture();
        try {
            perfDiskBigFile($fx, 'media/big.bin', 67108864);
            foreach ([['1048476K', '1048576K', '1310720K', true], ['1280G', '1024G', '1280G', true], ['1048376K', '1048576K', '1310720K', false]] as $c) {
                @unlink($fx['log']);
                perfDiskQuota($fx, $c[0], $c[1], $c[2]);
                list($code, $recs) = perfDiskRun($fx, []);
                $last = $recs[count($recs) - 1];
                if ($c[3]) {
                    check($code === 1 && strpos($last['error'], 'the disk probe was not run') !== false, "used {$c[0]} of {$c[1]}: expected a refusal, got $code " . json_encode($last));
                    check(perfFakeCalls($fx) === [], "used {$c[0]}: a program ran: " . json_encode(perfFakeCalls($fx)));
                    check(!is_dir($fx['local'] . '/performance/scratch') || count((array) scandir($fx['local'] . '/performance/scratch')) === 2, 'a refused probe made scratch files');
                } else {
                    check($code === 0, "used {$c[0]} of {$c[1]} (200 KiB free) must run, got $code " . json_encode($last));
                }
            }
            $need = 2 * 65536;
            check(PerfDisk::quotaRefusal(['quota_free_bytes' => $need - 1], 65536) !== null && PerfDisk::quotaRefusal(['quota_free_bytes' => $need], 65536) === null, 'factor boundary');
            check(PerfDisk::quotaRefusal(['quota_used_bytes' => 10, 'quota_hard_bytes' => 10, 'quota_free_bytes' => null], 65536) !== null, 'hard limit reached');
            check(PerfDisk::quotaRefusal(['quota_free_bytes' => null, 'quota_used_bytes' => null, 'quota_hard_bytes' => null, 'fs_free_bytes' => null], 65536) === null, 'unknown quota must not refuse');
        } finally {
            perfDone($fx);
        }
    },
];
