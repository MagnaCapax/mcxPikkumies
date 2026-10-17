<?php
/**
 * performance net (PerfNet, ADR-0035): the mtr report parse (no addresses reach the output), the traceroute/ping
 * fallback chain, the direction-aware reading (loss counts where it starts and lasts to the last answering hop;
 * loss at a middle hop that does not last is rate limiting; a silent target is not loss), the reverse-run
 * instructions, the refusal without a target, and no orphan after a deadline.
 */
require_once __DIR__ . '/fixtures/performance/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/performance/PerfProbe.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/performance/PerfNet.php';

const PERF_NET_ADDRS = ['192.0.2.', '198.51.100.', '203.0.113.', 'fakehost'];

function perfNetNeedWrappers()
{
    foreach (['nice', 'ionice', 'timeout'] as $p) {
        if (ToolProc::which($p) === null) {
            skip("$p is not installed here");
        }
    }
}

/** A PATH holding only nice/ionice/timeout and the named fakes, so a real mtr/traceroute/ping can never be found. */
function perfNetIsolatedPath(array $fx, array $fakes)
{
    $dir = $fx['dir'] . '/isolated-' . mt_rand();
    mkdir($dir, 0700);
    foreach (['nice', 'ionice', 'timeout'] as $p) {
        symlink((string) ToolProc::which($p), "$dir/$p");
    }
    foreach ($fakes as $f) {
        symlink($fx['bin'] . "/$f", "$dir/$f");
    }
    return $dir;
}

function perfNetFixture($name)
{
    return __DIR__ . "/fixtures/performance/mtr/$name.txt";
}

function perfNetByKind(array $recs)
{
    $out = [];
    foreach ($recs as $r) {
        if (isset($r['kind'])) {
            $out[$r['kind']] = $r;
        }
    }
    return $out;
}

function perfNetNoAddress($text, $what)
{
    foreach (PERF_NET_ADDRS as $a) {
        check(strpos($text, $a) === false, "$what: '$a' reached the output: $text");
    }
}

/** Live pids whose command line carries $mark. */
function perfNetMarked($mark)
{
    $pids = [];
    foreach ((array) glob('/proc/[0-9]*/cmdline') as $f) {
        $c = @file_get_contents($f);
        if (is_string($c) && strpos($c, 'pk-perf-mark-' . $mark) !== false) {
            $pids[] = (int) basename(dirname($f));
        }
    }
    return $pids;
}

function perfNetWaitGone($mark, $seconds)
{
    $until = microtime(true) + $seconds;
    while (perfNetMarked($mark) && microtime(true) < $until) {
        usleep(100000);
    }
    return perfNetMarked($mark);
}

return [
    'performance net: mtr --report -n -c N is parsed into hop numbers only; no address or host name reaches the output' => function () {
        perfNetNeedWrappers();
        $fx = perfFixture();
        try {
            $log = $fx['dir'] . '/run/calls.jsonl';
            list($code, $recs, $out, $err) = perfRun($fx, ['net', '--target', '203.0.113.9'], ['call_log' => $log]);
            check($code === 0, "exit $code: $out $err");
            perfNetNoAddress($out, 'net');
            $k = perfNetByKind($recs);
            check(isset($k['reading'], $k['route'], $k['reverse']), 'records: ' . $out);
            check($k['route']['method'] === 'mtr' && $k['route']['count'] === 20 && $k['route']['quick'] === true && strpos($k['route']['label'], 'not the wiki procedure') !== false, 'route: ' . json_encode($k['route']));
            check(array_column($k['route']['hops'], 'hop') === [1, 2, 3], 'hops: ' . json_encode($k['route']['hops']));
            check($k['route']['hops'][2] == ['hop' => 3, 'loss_pct' => 0.0, 'sent' => 20, 'avg_ms' => 25.1, 'stdev_ms' => 0.6, 'worst_ms' => 27.0, 'answered' => true], 'hop 3: ' . json_encode($k['route']['hops'][2]));
            check($k['reading']['reading'] === 'clean' && $k['reading']['class'] === null, 'reading: ' . json_encode($k['reading']));
            $calls = perfFakeCalls($fx);
            check(count($calls) === 1 && $calls[0]['prog'] === 'mtr' && $calls[0]['argv'] === ['--report', '-n', '-c', '20', '203.0.113.9'], 'mtr argv: ' . json_encode($calls));
            $logged = cliLines((string) file_get_contents($log));
            check(array_slice($logged[0], 0, 9) === ['nice', '-n', '19', 'ionice', '-c2', '-n7', 'timeout', '-k', '2'] && $logged[0][9] === '30' && $logged[0][10] === 'mtr', 'mtr must run in measure mode with count+grace as its deadline: ' . json_encode($logged[0]));
            check(strpos($err, 'about 30 s') !== false, 'a run over 10 s must print its ETA first: ' . $err);
            $run = json_decode(trim((string) file_get_contents($fx['local'] . '/performance/runs.jsonl')), true);
            check($run['cost']['packets_sent'] === 60, 'packets: ' . json_encode($run['cost']));
        } finally {
            perfDone($fx);
        }
    },
    'performance net: --target ssh tests the client address of the SSH connection and never prints it' => function () {
        perfNetNeedWrappers();
        $fx = perfFixture();
        try {
            list($code, $recs, $out) = perfRun($fx, ['net', '--target', 'ssh', '--count', '5'], [], ['SSH_CONNECTION' => '203.0.113.77 50123 192.0.2.10 22']);
            check($code === 0, "exit $code: $out");
            $calls = perfFakeCalls($fx);
            check($calls[0]['argv'] === ['--report', '-n', '-c', '5', '203.0.113.77'], 'argv: ' . json_encode($calls[0]['argv']));
            check(strpos($out, '203.0.113.77') === false && strpos($out, '192.0.2.10') === false, 'the connection addresses reached the output');
            check(perfNetByKind($recs)['route']['target'] === 'your SSH connection', 'target label');
        } finally {
            perfDone($fx);
        }
    },
    'performance net: no target, or ssh without an SSH connection, refuses and tells the agent to ask the user; nothing runs' => function () {
        $fx = perfFixture();
        try {
            list($code, $recs) = perfRun($fx, ['net']);
            $last = $recs[count($recs) - 1];
            check($code === 2 && strpos($last['hint'], 'ask the user') !== false, 'dispatcher: ' . json_encode($last));
            list($code, $recs) = perfRun($fx, ['net', '--target', 'ssh'], [], ['SSH_CONNECTION' => null]);
            $last = $recs[count($recs) - 1];
            check($code === 1 && strpos($last['error'], 'no SSH connection') !== false && strpos($last['hint'], 'ask the user') !== false, 'ssh without SSH_CONNECTION: ' . json_encode($last));
            list($code, $recs) = perfRun($fx, ['net', '--target', 'ssh'], [], ['SSH_CONNECTION' => 'not-an-ip 1 2 3']);
            check($code === 1, 'a malformed SSH_CONNECTION must refuse, got ' . $code);
            check(perfFakeCalls($fx) === [], 'a program ran: ' . json_encode(perfFakeCalls($fx)));
            $p = new PerfProbe(['local_dir' => $fx['local'], 'home' => $fx['home'], 'uid' => PERF_FIXTURE_UID]);
            foreach ([[], ['target' => ''], ['target' => null]] as $params) {
                $threw = null;
                try {
                    PerfNet::net($p, $params);
                } catch (PerfUsageError $e) {
                    $threw = $e;
                }
                check($threw !== null && strpos($threw->hint, 'ask the user') !== false, 'PerfNet::net without a target must refuse: ' . json_encode($params));
            }
            check($p->calls === [], 'PerfNet ran something without a target');
        } finally {
            perfDone($fx);
        }
    },
    'performance net: loss that starts at hop k and lasts to the last hop is route_loss at k; earlier non-lasting loss is rate limiting' => function () {
        $far = PerfNet::read(PerfNet::parseMtr((string) file_get_contents(perfNetFixture('persist-far'))), 'mtr');
        check($far['class'] === 'route_loss' && $far['loss_starts_at_hop'] === 6 && $far['last_answering_hop'] === 8, 'far: ' . json_encode($far));
        check(strpos(implode(' ', $far['evidence']), 'hop 4: 30% that later hops do not show') !== false, 'hop 4 must be read as rate limiting: ' . json_encode($far['evidence']));
        check(strpos($far['next_step'], 'reverse') !== false && strpos($far['next_step'], 'ticket') === false, 'far loss: the reverse run, not a ticket: ' . $far['next_step']);
        $near = PerfNet::read(PerfNet::parseMtr((string) file_get_contents(perfNetFixture('persist-near'))), 'mtr');
        check($near['class'] === 'route_loss' && $near['loss_starts_at_hop'] === 2 && strpos($near['next_step'], 'ticket') !== false, 'near: ' . json_encode($near));
        $rl = PerfNet::read(PerfNet::parseMtr((string) file_get_contents(perfNetFixture('ratelimit'))), 'mtr');
        check($rl['class'] === null && $rl['reading'] === 'mid_path_rate_limit', 'mid-path loss that does not last must give no route verdict: ' . json_encode($rl));
        check(strpos(implode(' ', $rl['evidence']), 'hop 3') !== false && strpos(implode(' ', $rl['evidence']), 'hop 4') === false, 'a silent middle hop is not loss, hop 3 is the rate-limited one: ' . json_encode($rl['evidence']));
    },
    'performance net: a target that does not answer after clean hops is destination_silent, never route_loss' => function () {
        $ds = PerfNet::read(PerfNet::parseMtr((string) file_get_contents(perfNetFixture('dest-silent'))), 'mtr');
        check($ds['class'] === 'destination_silent' && $ds['last_answering_hop'] === 4, 'one lossy hop before a silent target is no route verdict: ' . json_encode($ds));
        check(strpos($ds['negation'], 'not packet loss') !== false && strpos($ds['next_step'], 'last hop that answered is 4') !== false, 'wording: ' . json_encode($ds));
        $hop = function ($n, $loss) {
            return ['hop' => $n, 'loss_pct' => (float) $loss, 'sent' => 20, 'avg_ms' => $loss < 100 ? 1.0 : null, 'stdev_ms' => null, 'worst_ms' => null, 'answered' => $loss < 100];
        };
        $clean = PerfNet::read([$hop(1, 0), $hop(2, 0), $hop(3, 100)], 'mtr');
        check($clean['class'] === 'destination_silent' && $clean['last_answering_hop'] === 2, 'clean then silent: ' . json_encode($clean));
        $two = PerfNet::read([$hop(1, 0), $hop(2, 20), $hop(3, 25), $hop(4, 100)], 'mtr');
        check($two['class'] === 'route_loss' && $two['loss_starts_at_hop'] === 2, 'loss over two answering hops before a silent target is route loss: ' . json_encode($two));
        check(PerfNet::read([$hop(1, 100), $hop(2, 100)], 'mtr')['reading'] === 'no_reply', 'nothing answered');
        check(PerfNet::read([$hop(1, 0), $hop(2, 5)], 'mtr')['class'] === 'route_loss', 'loss at the final answering hop only (target answered) is route loss at that hop');
    },
    'performance net: mtr missing or failing falls back to traceroute, then ping; each step says why it was skipped' => function () {
        perfNetNeedWrappers();
        $fx = perfFixture();
        try {
            $path = perfNetIsolatedPath($fx, ['traceroute', 'ping']);
            list($code, $recs, $out) = perfRun($fx, ['net', '--target', '203.0.113.9'], [], ['PATH' => $path]);
            $k = perfNetByKind($recs);
            check($code === 0 && $k['route']['method'] === 'traceroute' && count($k['route']['hops']) === 3, 'mtr missing -> traceroute: ' . $out);
            check(isset($k['reading']['confidence']) && strpos($k['reading']['confidence'], 'low') === 0, 'traceroute reading must be labelled low confidence');
            perfNetNoAddress($out, 'traceroute');
            $run = json_decode(trim((string) file_get_contents($fx['local'] . '/performance/runs.jsonl')), true);
            check($run['skipped'] === [['check' => 'mtr', 'reason' => 'mtr is not installed']], 'skipped: ' . json_encode($run['skipped']));
            $calls = perfFakeCalls($fx);
            check($calls[0]['prog'] === 'traceroute' && $calls[0]['argv'] === ['-n', '-q', '3', '-w', '2', '-m', '30', '203.0.113.9'], 'traceroute argv: ' . json_encode($calls));

            file_put_contents($fx['dir'] . '/tr.out', "traceroute to x\n 1  192.0.2.1  0.4 ms  0.5 ms  0.4 ms\n 2  * 198.51.100.1  9.0 ms  *\n 3  * * *\n");
            check(PerfNet::parseTraceroute((string) file_get_contents($fx['dir'] . '/tr.out'))[1] === ['hop' => 2, 'loss_pct' => 66.7, 'sent' => 3, 'avg_ms' => 9.0, 'stdev_ms' => 0.0, 'worst_ms' => 9.0, 'answered' => true], 'traceroute stars are loss');

            list($code, $recs, $out) = perfRun($fx, ['net', '--target', '203.0.113.9', '--count', '20'], [], ['PK_PERF_FAKE_MTR_RC' => '1', 'PK_PERF_FAKE_TRACEROUTE_RC' => '2']);
            $k = perfNetByKind($recs);
            check($code === 0 && $k['route']['method'] === 'ping' && $k['route']['end_to_end']['loss_pct'] == 0 && $k['route']['end_to_end']['avg_ms'] === 25.104, 'mtr + traceroute failing -> ping: ' . $out);
            check($k['reading']['class'] === null && strpos($k['reading']['confidence'], 'no hops') === 0, 'ping must give no route verdict: ' . json_encode($k['reading']));
            perfNetNoAddress($out, 'ping');

            list($code, $recs) = perfRun($fx, ['net', '--target', '203.0.113.9'], [], ['PK_PERF_FAKE_MTR_RC' => '1', 'PK_PERF_FAKE_TRACEROUTE_RC' => '2', 'PK_PERF_FAKE_PING_RC' => '2']);
            $last = $recs[count($recs) - 1];
            check($code === 1 && strpos($last['error'], 'no route test could run') === 0 && strpos($last['hint'], 'mtr -rwc 1000') !== false, 'all failing: ' . json_encode($last));
        } finally {
            perfDone($fx);
        }
    },
    'performance net: the reverse run is printed with the wiki procedure for each OS and no address filled in' => function () {
        perfNetNeedWrappers();
        $fx = perfFixture();
        try {
            file_put_contents($fx['dir'] . '/far.out', file_get_contents(perfNetFixture('persist-far')));
            list($code, $recs, $out) = perfRun($fx, ['net', '--target', '203.0.113.9'], [], ['PK_PERF_FAKE_MTR_OUT' => $fx['dir'] . '/far.out']);
            $rev = perfNetByKind($recs)['reverse'];
            check($code === 0 && strpos($rev['windows'], 'WinMTR') !== false && strpos($rev['windows'], '1000') !== false, 'windows: ' . json_encode($rev));
            check(strpos($rev['macos'], "sudo mtr -rwc 1000 <this server's hostname>") !== false && strpos($rev['linux'], "mtr -rwc 1000 <this server's hostname>") !== false, 'mac/linux: ' . json_encode($rev));
            check(strpos($rev['direction'], 'reverse') === 0 && strpos(perfNetByKind($recs)['route']['direction'], 'forward') === 0, 'directions must be named');
            perfNetNoAddress($out, 'reverse');
            check(!preg_match('/\bISP\b|hardware|noisy|neighbou?r|oversold|your router is/i', $out), 'a never-claim reached the output: ' . $out);
            $run = json_decode(trim((string) file_get_contents($fx['local'] . '/performance/runs.jsonl')), true);
            check($run['classes'] === ['route_loss'], 'the class must reach the run log: ' . json_encode($run['classes']));
        } finally {
            perfDone($fx);
        }
    },
    'performance net: an mtr that forks a child and never ends is stopped at a 1 s deadline with no marked process left' => function () {
        perfNetNeedWrappers();
        if (!is_dir('/proc/self')) {
            skip('no /proc here');
        }
        $fx = perfFixture();
        $mark = 'no' . getmypid() . mt_rand();
        try {
            $env = ['PATH' => $fx['bin'] . ':' . getenv('PATH'), 'PK_PERF_FAKE_MODE' => 'hang', 'PK_PERF_FAKE_MARK' => $mark, 'PK_PERF_FAKE_LOG' => $fx['log']];
            // The oracle sees a survivor: a timeout that kills only its child leaves the marked grandchild running.
            $left = testWithEnv($env, function () use ($fx, $mark) {
                ToolProc::run(['timeout', '--foreground', '1', $fx['bin'] . '/mtr']);
                $until = microtime(true) + 3;
                while (!perfNetMarked($mark) && microtime(true) < $until) {
                    usleep(50000);
                }
                return perfNetMarked($mark);
            });
            foreach ($left as $pid) {
                posix_kill($pid, 9);
            }
            check($left !== [], 'control: the oracle did not see the grandchild a child-only timeout leaves behind');
            check(perfNetWaitGone($mark, 3) === [], 'control survivor could not be removed');

            $t = microtime(true);
            $result = testWithEnv($env, function () use ($fx) {
                $p = new PerfProbe(['local_dir' => $fx['local'], 'home' => $fx['home'], 'uid' => PERF_FIXTURE_UID]);
                try {
                    PerfNet::net($p, ['target' => '192.0.2.9', 'count' => 1, 'deadline_s' => 1]);
                } catch (CliError $e) {
                    // the fallbacks may fail on a slow machine; the deadline and the orphans are what this test is about
                }
                return $p;
            });
            $wall = microtime(true) - $t;
            check($result->skipped && $result->skipped[0] === ['check' => 'mtr', 'reason' => 'stopped at its 1 s deadline'], 'mtr must have hit its deadline: ' . json_encode($result->skipped));
            check($wall < 8, "the 1 s deadline did not hold: $wall s");
            $survivors = perfNetWaitGone($mark, 3);
            foreach ($survivors as $pid) {
                posix_kill($pid, 9);
            }
            check($survivors === [], 'a marked process outlived the deadline: ' . json_encode($survivors));
        } finally {
            foreach (perfNetMarked($mark) as $pid) {
                posix_kill($pid, 9);
            }
            perfDone($fx);
        }
    },
];
