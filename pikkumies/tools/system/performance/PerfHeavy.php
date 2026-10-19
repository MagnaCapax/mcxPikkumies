<?php
/**
 * PerfHeavy: the `heavy` verb, one load test when the user asked for one (not asked about: ADR-0073; ADR-0035,
 * README.md "Runner"). Before anything loads the server, in this order:
 *  - the program is installed: fio, iperf3, or the Ookla speedtest binary (speedtest-cli and look-alikes are not
 *    used). Nothing is ever installed or downloaded to run it. A missing one is skipped (a record, exit 0).
 *  - disk-write only where this account does not share the server (PMSS, or more than one person's account, or an
 *    unreadable account list, all count as shared).
 *  - one run per test per hour: local/performance/heavy-last.json, a courtesy the account may edit, not a security limit.
 *  - the server is not under pressure (PerfProbe::heavyGate). Unknown pressure does not hold a test back; it is shown.
 *  - the test's traffic is at most HEAVY_TRAFFIC_MARGIN of the plan traffic left in every capped direction
 *    (userShowLimits on PMSS). Where the plan's traffic is not known it is not checked, and the result says so.
 * Every record says the result is your share now, not the ceiling: other accounts use the same disk and network.
 * Programs run in measure mode through PerfProbe (argv, nice 19, best-effort I/O 7, timeout -k 2); the download
 * runs through lib/Http.php with `discard` (counted, never kept).
 */
require_once __DIR__ . '/PerfProbe.php';

class PerfHeavy
{
    const LABEL = 'your share now, not the ceiling';
    /** Traffic estimates made before a run, to compare with the plan traffic left (provisional, design review 2026-10-17). */
    const LINE_BYTES_S = 1250000000;  // provisional: 10 Gbit/s, the upper bound used to estimate iperf3 and speedtest bytes each way
    const SPEEDTEST_S = 15;           // provisional [inferred]: the Ookla test runs about 10-15 s each way
    /** Time limits of the programs (timeout -k 2 in front of each). */
    const SPEEDTEST_DEADLINE_S = 90;  // provisional: whole speedtest run
    const WRITE_DEADLINE_S = 120;     // provisional: 256 MiB written and synced
    const IPERF_SLACK_S = 20;         // provisional: iperf3 connect and summary time on top of HEAVY_IPERF_S
    const FIO_SLACK_S = 15;           // provisional: fio setup on top of HEAVY_DISK_READ_S
    const VERSION_S = 5;              // provisional: `speedtest --version`
    const COOLDOWN_FILE = 'heavy-last.json';

    /** The program each test needs (null: the download runs in PHP). */
    const PROGRAMS = ['disk-read' => 'fio', 'disk-write' => 'dd', 'download' => null, 'iperf3' => 'iperf3', 'speedtest' => 'speedtest'];

    public static function heavy(PerfProbe $probe, array $params)
    {
        $test = (string) $params['test'];
        if (!array_key_exists($test, self::PROGRAMS)) {
            throw new PerfUsageError("unknown heavy test: $test", 'tests: ' . implode(', ', array_keys(self::PROGRAMS)));
        }
        $prog = self::PROGRAMS[$test];
        if ($prog !== null && ToolProc::which($prog) === null) {
            return self::skipped($probe, $test, "$prog is not installed on this machine; nothing was run",
                'Pikkumies never installs or downloads a test program; the light probes (disk, net) need nothing extra');
        }
        if ($test === 'speedtest') {
            $v = $probe->measure(['speedtest', '--version'], self::VERSION_S);
            if (!self::isOokla($v['out'] . "\n" . $v['err'])) {
                return self::skipped($probe, $test, 'the installed speedtest is not the Ookla binary; nothing was run',
                    'only the Ookla speedtest is used (speedtest-cli and other look-alikes are not); Pikkumies never installs one');
            }
        }
        if ($test === 'disk-write' && self::shared($probe)) {
            throw new CliError('disk-write runs only on a server this account does not share; nothing was written',
                'on a shared server a big write takes the disk from everyone; use heavy --test disk-read, or the light disk probe');
        }
        $left = self::cooldownLeft($probe, $test, time());
        if ($left > 0) {
            throw new CliError("$test ran less than an hour ago; one run per test per hour; nothing was run",
                'try again in ' . (int) ceil($left / 60) . ' minutes; the earlier result still stands');
        }
        $psi = $probe->psi();
        $why = PerfProbe::heavyGate($psi);
        if ($why !== null) {
            throw new CliError($why . '; nothing was run', 'run performance check to see the pressure, and try the test later');
        }
        $traffic = null;
        $est = self::estimate($test);
        if ($est['in'] + $est['out'] > 0) {
            $traffic = self::traffic(Detect::userShowLimitsData($probe->home), $est);
            if ($traffic['refusal'] !== null) {
                throw new CliError($traffic['refusal'] . '; nothing was run', 'the plan traffic left is in userShowLimits; a test is not worth a traffic limit');
            }
        }
        switch ($test) {
            case 'disk-read':
                $result = self::diskRead($probe);
                break;
            case 'disk-write':
                $result = self::diskWrite($probe);
                break;
            case 'download':
                $result = self::download($probe, (string) $params['url']);
                break;
            case 'iperf3':
                $result = self::iperf3($probe, (string) $params['target']);
                break;
            default:
                $result = self::speedtest($probe);
        }
        if ($result === null) {
            return self::skipped($probe, $test, 'no file of your own of ' . (PerfLimits::DISK_READ_FILE_MIN / 1048576) . ' MiB or more in the first three levels of your home to read; nothing was run',
                'a disk-read test only reads an existing file of yours (read-only); the light disk probe needs none');
        }
        self::cooldownMark($probe, $test, time());
        $rec = ['record' => 'heavy', 'test' => $test, 'ran' => true, 'label' => self::LABEL, 'result' => $result,
            'pressure_before' => $psi, 'pressure_after' => $probe->psi()];
        $unknown = array_keys(array_filter($psi, function ($p) {
            return $p['avg10'] === null || $p['avg60'] === null;
        }));
        if ($unknown) {
            $rec['pressure_note'] = 'pressure not readable here for ' . implode(', ', $unknown) . ' (unknown, not 0); the test was not held back by it';
        }
        if ($traffic !== null) {
            $rec['traffic'] = $traffic['known'] ? $traffic['directions'] : 'plan traffic not known here; not checked';
        }
        $rec['reading'] = self::LABEL . ': other accounts use the same disk and network at the same time, so this is what this account got now, not what the server can do';
        $rec['cost'] = $probe->cost();
        return [$rec];
    }

    /** Http options of the download test: one stream, counted and thrown away, at most HEAVY_DOWNLOAD_MAX or HEAVY_DOWNLOAD_S. */
    public static function downloadOptions()
    {
        return ['discard' => true, 'max' => PerfLimits::HEAVY_DOWNLOAD_MAX, 'timeout' => PerfLimits::HEAVY_DOWNLOAD_S];
    }

    /** Bytes a test may move, estimated before it runs: ['in' => to this server, 'out' => from this server]. */
    public static function estimate($test)
    {
        switch ($test) {
            case 'download':
                return ['in' => PerfLimits::HEAVY_DOWNLOAD_MAX, 'out' => 0];
            case 'iperf3':
                $b = PerfLimits::HEAVY_IPERF_S * self::LINE_BYTES_S;
                return ['in' => $b, 'out' => $b];
            case 'speedtest':
                $b = self::SPEEDTEST_S * self::LINE_BYTES_S;
                return ['in' => $b, 'out' => $b];
        }
        return ['in' => 0, 'out' => 0];
    }

    /**
     * The traffic check against userShowLimits' traffic section. A metric with a byte limit is a capped direction:
     * upload/outbound = out, inbound/download = in, anything else counts both. Returns ['known' => bool,
     * 'directions' => [[direction, left_bytes, test_bytes]], 'refusal' => text or null].
     */
    public static function traffic($usl, array $est)
    {
        $out = ['known' => false, 'directions' => [], 'refusal' => null];
        $sec = is_array($usl) && isset($usl['sections']['traffic']) && is_array($usl['sections']['traffic']) ? $usl['sections']['traffic'] : [];
        if (empty($sec['available']) || !isset($sec['metrics']) || !is_array($sec['metrics'])) {
            return $out;
        }
        $why = [];
        foreach ($sec['metrics'] as $m) {
            if (!is_array($m) || !isset($m['metric'], $m['limit'], $m['value']) || !is_numeric($m['limit']) || !is_numeric($m['value'])
                || (float) $m['limit'] <= 0 || (isset($m['unit']) && $m['unit'] !== 'bytes')) {
                continue;
            }
            $name = strtolower((string) $m['metric']);
            $dir = preg_match('/upload|outbound|egress/', $name) ? 'out' : (preg_match('/inbound|download|ingress/', $name) ? 'in' : 'both');
            $need = $dir === 'both' ? $est['in'] + $est['out'] : $est[$dir];
            $left = max(0, (float) $m['limit'] - (float) $m['value']);
            $out['known'] = true;
            $out['directions'][] = ['direction' => $dir === 'out' ? 'from this server' : ($dir === 'in' ? 'to this server' : 'both ways'),
                'left_bytes' => (int) $left, 'test_bytes' => (int) $need];
            if ($need > 0 && $need > PerfLimits::HEAVY_TRAFFIC_MARGIN * $left) {
                $why[] = sprintf('up to %.1f GiB %s, more than %d%% of the %.1f GiB plan traffic left', $need / 1073741824,
                    $dir === 'out' ? 'from this server' : ($dir === 'in' ? 'to this server' : 'both ways'), PerfLimits::HEAVY_TRAFFIC_MARGIN * 100, $left / 1073741824);
            }
        }
        $out['refusal'] = $why ? 'this test may move ' . implode('; ', $why) : null;
        return $out;
    }

    /** Whether this account shares the server. Unknown (the account list unreadable) counts as shared. */
    public static function shared(PerfProbe $probe)
    {
        $passwd = $probe->fs->path('/etc/passwd');
        if ($probe->fs->exists('/etc/seedbox')) {
            return true;
        }
        if ($passwd === null || !is_file($passwd) || !is_readable($passwd)) {
            return true;
        }
        return Detect::sharedTenancy(false, $passwd);
    }

    /** "Speedtest by Ookla" is what the Ookla binary prints for --version; speedtest-cli prints its own name. */
    public static function isOokla($versionText)
    {
        return preg_match('/\bSpeedtest by Ookla\b/i', (string) $versionText) === 1 && stripos((string) $versionText, 'speedtest-cli') === false;
    }

    /** Seconds until $test may run again (0: now). The file is the account's own; a broken one counts as empty. */
    public static function cooldownLeft(PerfProbe $probe, $test, $now)
    {
        $last = self::cooldownRead($probe);
        if (!isset($last[$test]) || !is_numeric($last[$test])) {
            return 0;
        }
        return max(0, (int) $last[$test] + PerfLimits::HEAVY_COOLDOWN_S - (int) $now);
    }

    private static function cooldownRead(PerfProbe $probe)
    {
        $file = $probe->stateDir() . '/' . self::COOLDOWN_FILE;
        $data = is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file, false, null, 0, 65536), true) : null;
        return is_array($data) ? $data : [];
    }

    private static function cooldownMark(PerfProbe $probe, $test, $now)
    {
        $last = self::cooldownRead($probe);
        $last[$test] = (int) $now;
        $file = $probe->stateDir() . '/' . self::COOLDOWN_FILE;
        if (is_link($file)) {
            return;
        }
        $old = umask(077);
        @file_put_contents($file, json_encode($last) . "\n", LOCK_EX);
        umask($old);
        @chmod($file, 0600);
    }

    private static function skipped(PerfProbe $probe, $test, $reason, $hint)
    {
        $probe->skipped[] = ['check' => "heavy $test", 'reason' => $reason];
        return [['record' => 'heavy', 'test' => $test, 'ran' => false, 'label' => self::LABEL, 'skipped' => $reason, 'hint' => $hint]];
    }

    /** fio argv for the read test: read-only, direct, a random then a sequential job, each half the time and half the bytes. */
    public static function fioArgv($file, $size)
    {
        $half = (int) (PerfLimits::HEAVY_DISK_READ_MAX / 2);
        return ['fio', '--readonly', '--direct=1', '--ioengine=psync', '--output-format=json',
            '--filename=' . str_replace(':', '\\:', $file), // fio reads ':' as a list of files
            '--size=' . (int) $size, '--runtime=' . (int) (PerfLimits::HEAVY_DISK_READ_S / 2), '--io_size=' . $half,
            '--name=randread', '--rw=randread', '--bs=4k',
            '--name=seqread', '--rw=read', '--bs=1M', '--stonewall'];
    }

    /** disk-read: fio on one existing file of the account's own, read-only. null when there is no such file. */
    private static function diskRead(PerfProbe $probe)
    {
        $find = $probe->scan(['find', $probe->home, '-maxdepth', '3', '-type', 'f', '-uid', (string) $probe->uid,
            '-size', '+' . (int) (PerfLimits::DISK_READ_FILE_MIN / 1048576) . 'M', '-print', '-quit'], PerfLimits::DISK_FIND_S);
        $file = trim((string) strtok((string) $find['out'], "\n"));
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $size = min((int) @filesize($file), PerfLimits::HEAVY_DISK_READ_MAX);
        $r = $probe->measure(self::fioArgv($file, $size), PerfLimits::HEAVY_DISK_READ_S + self::FIO_SLACK_S);
        $json = self::json($r['out']);
        if ($r['code'] !== 0 || !isset($json['jobs']) || !is_array($json['jobs'])) {
            throw new CliError('fio did not finish the read test: ' . self::firstLine($r['err'] !== '' ? $r['err'] : $r['out']),
                'some filesystems do not allow direct reads; the light disk probe still works');
        }
        $jobs = [];
        $total = 0;
        foreach ($json['jobs'] as $j) {
            $rd = isset($j['read']) && is_array($j['read']) ? $j['read'] : [];
            $pct = isset($rd['clat_ns']['percentile']) && is_array($rd['clat_ns']['percentile']) ? $rd['clat_ns']['percentile'] : [];
            $ms = function ($ns) {
                return is_numeric($ns) ? round($ns / 1e6, 2) : null;
            };
            $bytes = isset($rd['io_bytes']) ? (int) $rd['io_bytes'] : 0;
            $total += $bytes;
            $jobs[] = ['job' => isset($j['jobname']) ? Cli::cleanString((string) $j['jobname']) : null,
                'mb_s' => isset($rd['bw_bytes']) ? round($rd['bw_bytes'] / 1e6, 1) : null, 'iops' => isset($rd['iops']) ? round($rd['iops']) : null,
                'bytes' => $bytes, 'latency_ms_p50' => $ms(isset($pct['50.000000']) ? $pct['50.000000'] : null),
                'latency_ms_p95' => $ms(isset($pct['95.000000']) ? $pct['95.000000'] : null), 'latency_ms_max' => $ms(isset($rd['clat_ns']['max']) ? $rd['clat_ns']['max'] : null)];
        }
        $probe->addCost('bytes_read_direct', $total);
        return ['jobs' => $jobs, 'read_only' => true, 'file_bytes' => $size, 'io_class' => $r['io_class'], 'wall_s' => $r['wall_s']];
    }

    /** disk-write: HEAVY_DISK_WRITE_BYTES written to a scratch file and synced at the end, then removed. */
    private static function diskWrite(PerfProbe $probe)
    {
        $bytes = PerfLimits::HEAVY_DISK_WRITE_BYTES;
        $need = PerfLimits::DISK_QUOTA_FACTOR * $bytes;
        $q = Quota::fields($probe->home);
        if (!empty($q['over_quota']) || ($q['quota_free_bytes'] !== null && $q['quota_free_bytes'] < $need)) {
            throw new CliError('not enough quota left for the write test (it needs ' . ($need / 1048576) . ' MiB free); nothing was written', 'free some space first, or skip the test');
        }
        $file = $probe->scratchFile('heavy-write.bin');
        $free = @disk_free_space(dirname($file));
        if ($free !== false && $free < $need) {
            @unlink($file);
            throw new CliError('not enough free disk for the write test (it needs ' . ($need / 1048576) . ' MiB); nothing was written', 'skip the test');
        }
        try {
            $r = $probe->measure(['dd', 'if=/dev/zero', 'of=' . $file, 'bs=1M', 'count=' . (int) ($bytes / 1048576), 'conv=fsync'], self::WRITE_DEADLINE_S);
        } finally {
            @unlink($file);
        }
        if ($r['code'] !== 0 || !preg_match('/(\d+) bytes .*copied, ([0-9.]+) s/', $r['err'], $m) || (float) $m[2] <= 0) {
            throw new CliError('dd did not finish the write test: ' . self::firstLine($r['err']), $r['timed_out'] ? 'the disk is slow now; try later' : 'check free quota');
        }
        $probe->addCost('bytes_written', (int) $m[1]);
        return ['bytes' => (int) $m[1], 'seconds' => (float) $m[2], 'mb_s' => round($m[1] / $m[2] / 1e6, 1), 'synced' => true,
            'io_class' => $r['io_class'], 'wall_s' => $r['wall_s']];
    }

    /** download: one stream through Http with discard; the rate counts from the first byte. */
    private static function download(PerfProbe $probe, $url)
    {
        $r = Http::request('GET', $url, self::downloadOptions());
        if ($r['error'] !== '' && empty($r['bytes'])) {
            throw new CliError('the download did not start: ' . self::firstLine($r['error']), 'check the address (curl -sI <url>); nothing was measured');
        }
        if ($r['code'] >= 300 && $r['code'] < 400) {
            throw new CliError("the address redirects (HTTP {$r['code']}); nothing was measured", 'Pikkumies does not follow redirects: pass the final address (the Location header of curl -sI <url>)');
        }
        if ($r['code'] >= 400 || $r['code'] < 200) {
            throw new CliError("the server answered HTTP {$r['code']}; nothing was measured", 'check the address (curl -sI <url>)');
        }
        $probe->addCost('bytes_downloaded', $r['bytes']);
        $transfer = $r['total_time'] - $r['start_time'];
        $transfer = $transfer > 0 ? $transfer : $r['total_time'];
        $ended = $r['stopped'] === 'bytes' ? 'byte cap (' . (PerfLimits::HEAVY_DOWNLOAD_MAX / 1048576) . ' MiB)'
            : ($r['stopped'] === 'time' ? 'time cap (' . PerfLimits::HEAVY_DOWNLOAD_S . ' s)' : ($r['error'] !== '' ? 'broke off: ' . self::firstLine($r['error']) : 'whole file'));
        return ['streams' => 1, 'bytes' => $r['bytes'], 'seconds' => $r['total_time'], 'first_byte_s' => $r['start_time'],
            'mbit_s' => $transfer > 0 ? round($r['bytes'] * 8 / $transfer / 1e6, 1) : null, 'ended' => $ended,
            'note' => 'one stream: the round trip to that server limits it; a client with several streams can be faster'];
    }

    /** iperf3: HEAVY_IPERF_S each way against the user's target, from this server and (-R) to it. */
    private static function iperf3(PerfProbe $probe, $target)
    {
        $out = [];
        foreach (['from this server' => [], 'to this server' => ['-R']] as $dir => $extra) {
            $r = $probe->measure(array_merge(['iperf3', '-c', $target, '-t', (string) PerfLimits::HEAVY_IPERF_S, '-J'], $extra),
                PerfLimits::HEAVY_IPERF_S + self::IPERF_SLACK_S);
            $j = self::json($r['out']);
            $sum = isset($j['end']['sum_received']['bits_per_second']) ? $j['end']['sum_received'] : (isset($j['end']['sum_sent']) ? $j['end']['sum_sent'] : null);
            if (!is_array($sum) || !isset($sum['bits_per_second'])) {
                $err = isset($j['error']) && is_string($j['error']) ? $j['error'] : ($r['err'] !== '' ? $r['err'] : 'no result');
                $out[] = ['direction' => $dir, 'error' => self::firstLine($err)];
                continue;
            }
            $bytes = isset($sum['bytes']) ? (int) $sum['bytes'] : 0;
            if ($extra) {
                $probe->addCost('bytes_downloaded', $bytes);
            }
            $out[] = ['direction' => $dir, 'mbit_s' => round($sum['bits_per_second'] / 1e6, 1), 'bytes' => $bytes,
                'seconds' => isset($sum['seconds']) ? round((float) $sum['seconds'], 1) : null];
        }
        if (!array_filter($out, function ($d) {
            return isset($d['mbit_s']);
        })) {
            throw new CliError('iperf3 got no result: ' . $out[0]['error'], 'the target must run an iperf3 server the user may use');
        }
        return ['directions' => $out];
    }

    /** speedtest: the installed Ookla binary, numbers only (its server, ISP and address fields are left out). */
    private static function speedtest(PerfProbe $probe)
    {
        $r = $probe->measure(['speedtest', '--format=json', '--progress=no'], self::SPEEDTEST_DEADLINE_S);
        $j = null;
        foreach (array_reverse(explode("\n", (string) $r['out'])) as $line) {
            $d = json_decode(trim($line), true);
            if (is_array($d) && isset($d['download']['bandwidth']) && is_numeric($d['download']['bandwidth'])) {
                $j = $d;
                break;
            }
        }
        if ($j === null) {
            $text = $r['out'] . "\n" . $r['err'];
            if (stripos($text, 'license') !== false || stripos($text, 'gdpr') !== false) {
                throw new CliError('speedtest wants its licence accepted first; nothing was measured', 'the user runs `speedtest` once themselves and accepts it; Pikkumies does not accept it for them');
            }
            throw new CliError('speedtest gave no result: ' . self::firstLine($r['err'] !== '' ? $r['err'] : $r['out']), $r['timed_out'] ? 'it ran past ' . self::SPEEDTEST_DEADLINE_S . ' s; try later' : 'run it later');
        }
        $num = function ($v, $div = 1) {
            return is_numeric($v) ? round($v / $div, 1) : null;
        };
        if (isset($j['download']['bytes']) && is_numeric($j['download']['bytes'])) {
            $probe->addCost('bytes_downloaded', (int) $j['download']['bytes']);
        }
        return ['download_mbit_s' => $num($j['download']['bandwidth'] * 8, 1e6),
            'upload_mbit_s' => $num(isset($j['upload']['bandwidth']) && is_numeric($j['upload']['bandwidth']) ? $j['upload']['bandwidth'] * 8 : null, 1e6),
            'ping_ms' => $num(isset($j['ping']['latency']) ? $j['ping']['latency'] : null), 'jitter_ms' => $num(isset($j['ping']['jitter']) ? $j['ping']['jitter'] : null),
            'packet_loss_pct' => $num(isset($j['packetLoss']) ? $j['packetLoss'] : null), 'io_class' => $r['io_class'], 'wall_s' => $r['wall_s']];
    }

    /** The JSON object in a program's stdout (fio may print notes before it), or null. */
    private static function json($text)
    {
        $text = (string) $text;
        $at = strpos($text, '{');
        $d = $at === false ? null : json_decode(substr($text, $at), true);
        return is_array($d) ? $d : null;
    }

    private static function firstLine($text)
    {
        $line = trim((string) strtok(trim((string) $text), "\n"));
        return substr(Cli::cleanString($line), 0, Cli::TEXT_MAX);
    }
}
