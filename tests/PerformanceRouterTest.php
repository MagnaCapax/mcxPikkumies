<?php
/**
 * The check verdicts (ADR-0035, PerfRouter): each class of the rule table fires on its own evidence and not on the
 * evidence that disproves it; the never-claims are caught; an I/O cap never turns into upgrade wording; memory is
 * never judged from the failure counter or peak usage; the symptom orders the verdicts; the route rule reads
 * persisting loss only.
 */
require_once __DIR__ . '/fixtures/performance/check-helpers.php';

/** [class, facts overrides that are its evidence, overrides that disprove it] */
function perfRtCases()
{
    $sess = function (array $one, $count = 1) {
        $base = ['service' => 'ssh', 'rtt_ms' => 20.0, 'rwnd_limited_pct' => 0.0, 'busy_ms' => 4000, 'retrans' => 0, 'delivery_mbit' => 50.0, 'window_bound_mbit' => 400.0, 'direction' => 'from server'];
        return ['visible' => true, 'reason' => null, 'active' => $count, 'sessions' => array_fill(0, $count, $one + $base)];
    };
    return [
        ['client_window', ['sessions' => $sess(['rwnd_limited_pct' => 70.0], 2)], ['sessions' => $sess(['rwnd_limited_pct' => 0.0], 2)]],
        ['single_stream_bdp', ['sessions' => $sess(['rtt_ms' => 120.0, 'delivery_mbit' => 9.5, 'window_bound_mbit' => 10.0])],
            ['sessions' => $sess(['rtt_ms' => 120.0, 'delivery_mbit' => 9.5, 'window_bound_mbit' => 10.0], 2)]],
        ['link_speed_low', ['host' => ['link_speed_mbit' => 100]], ['host' => ['link_speed_mbit' => 1000]]],
        ['account_io_cap', ['io' => ['own_rate' => ['8:0' => ['read_bps' => 100000000, 'write_bps' => 0]]]], ['io' => ['own_rate' => ['8:0' => ['read_bps' => 5000000, 'write_bps' => 0]]]]],
        ['account_memory_bound', ['memory' => ['anon_pct_of_limit' => 95.0, 'anon_pct_first_sample' => 93.0]], ['memory' => ['anon_pct_of_limit' => 95.0, 'anon_pct_first_sample' => 60.0]]],
        ['account_cpu_bound', ['cpu' => ['throttled_pct' => 35.0, 'own_use_cores' => 1.9]], ['cpu' => ['throttled_pct' => 0.0]]],
        ['traffic_limit', ['traffic' => [['used_pct' => 95.0, 'metric' => 'upload past 30 days']]], ['traffic' => [['used_pct' => 40.0]]]],
        ['own_workload', ['procs' => ['waiting_on_disk' => 2, 'groups' => ['media' => ['processes' => 2, 'rss_bytes' => 1, 'swap_bytes' => 0, 'pss_bytes' => null, 'waiting_on_disk' => 2]]]], ['procs' => ['waiting_on_disk' => 0]]],
        ['host_io_busy', ['host' => ['pressure' => ['io' => ['avg10' => 22.0]]]], ['host' => ['pressure' => ['io' => ['avg10' => 14.0, 'avg60' => 9.0]]]]],
        ['host_steal', ['host' => ['cpu_steal_pct' => 8.0]], ['host' => ['cpu_steal_pct' => 0.0]]],
        ['maintenance', ['host' => ['array_maintenance' => 'check']], ['host' => ['array_maintenance' => null]]],
    ];
}

/** facts with $over applied; lists (sessions, traffic) are replaced, not merged by index. */
function perfRtFacts(array $over)
{
    $f = perfBFacts();
    foreach ($over as $k => $v) {
        if ($k === 'sessions' || $k === 'traffic') {
            $f[$k] = $k === 'traffic' ? array_map(function ($t) {
                return $t + ['metric' => 'traffic', 'used_bytes' => 1.0, 'limit_bytes' => 1.0];
            }, $v) : $v;
        } else {
            $f[$k] = array_replace_recursive($f[$k], $v);
        }
    }
    return $f;
}

return [
    'perf router: with no evidence nothing fires and the verdict is nothing_found (app_side for an app symptom)' => function () {
        check(perfBClasses(perfBFacts()) === ['nothing_found'], 'neutral facts: ' . json_encode(perfBClasses(perfBFacts())));
        check(perfBClasses(perfBFacts(['symptom' => 'torrents'])) === ['app_side'], 'torrents with no finding must be app_side');
        $c = perfBClasses(perfRtFacts(['host' => ['cpu_steal_pct' => 9.0]]) + ['symptom' => 'torrents']);
        check(!in_array('app_side', $c, true) && !in_array('nothing_found', $c, true), 'app_side/nothing_found next to a finding: ' . json_encode($c));
    },
    'perf router: each class fires on its evidence and not on what disproves it' => function () {
        foreach (perfRtCases() as $case) {
            list($class, $fire, $disprove) = $case;
            $yes = perfBClasses(perfRtFacts($fire));
            check(in_array($class, $yes, true), "$class did not fire on its evidence: " . json_encode($yes));
            check($yes === [$class], "$class evidence fired other classes too: " . json_encode($yes));
            $no = perfBClasses(perfRtFacts($disprove));
            check(!in_array($class, $no, true), "$class fired on its disprover: " . json_encode($no));
        }
        $oom = perfBClasses(perfRtFacts(['memory' => ['oom_kills_during_check' => 1]]));
        check($oom === ['account_memory_bound'], 'an OOM kill during the check must be memory: ' . json_encode($oom));
        $high = perfBClasses(perfRtFacts(['memory' => ['platform_status' => 'HIGH']]));
        check($high === ['account_memory_bound'], 'userShowLimits memory status HIGH must be memory: ' . json_encode($high));
        $avg60 = perfBClasses(perfRtFacts(['host' => ['pressure' => ['io' => ['avg10' => 3.0, 'avg60' => 10.0]]]]));
        check($avg60 === ['host_io_busy'], 'sustained io full avg60 >= 10 must be host_io_busy: ' . json_encode($avg60));
    },
    'perf router: unknown evidence never fires a class (null is not 0, and not a limit either)' => function () {
        $f = perfRtFacts(['memory' => ['anon_pct_of_limit' => null, 'anon_pct_first_sample' => null, 'oom_kills_during_check' => null, 'platform_status' => null],
            'cpu' => ['throttled_pct' => null], 'io' => ['own_rate' => null], 'host' => ['cpu_steal_pct' => null, 'link_speed_mbit' => null,
                'pressure' => ['io' => ['avg10' => null, 'avg60' => null]]]]);
        $f['sessions'] = ['visible' => null, 'reason' => 'no SSH connection', 'sessions' => [], 'active' => null];
        $f['procs'] = null;
        check(perfBClasses($f) === ['nothing_found'], 'unknowns fired: ' . json_encode(perfBClasses($f)));
    },
    'perf router: every never-claim is caught, and no template or produced verdict breaks one' => function () {
        $bad = [
            'hardware verdict' => 'Your disk is failing.', 'another account' => 'A noisy neighbour is using the disk.',
            'cap called deliberate or a bug' => 'The cap was set deliberately.', 'oversold' => 'The server is oversold.',
            'promise of support or provider action' => 'Support will fix it today.', 'cancel, refund, move elsewhere' => 'Ask for a refund.',
            'price' => 'The upgrade costs 5 EUR.', 'product name' => 'Take the plan called Big.', 'disk type from the rotational flag' => 'This is an HDD.',
            'load average as CPU use' => 'Load average 8 means the CPU is busy.', 'PSI some as saturation' => 'The CPU is saturated.',
            'benchmark as the ceiling' => "That is the disk's maximum speed.", 'route verdict from mid-path loss' => 'The middle hop drops packets.',
            'home or ISP cause inferred' => 'Your ISP is the problem.', 'memory failure counter or peak usage as pressure' => 'Peak usage shows memory pressure.',
        ];
        check(array_keys($bad) === array_keys(PerfRouter::NEVER), 'every NEVER rule needs a sample here: ' . json_encode(array_keys(PerfRouter::NEVER)));
        foreach ($bad as $rule => $text) {
            $hits = PerfRouter::lint([$text]);
            check($hits && strpos($hits[0], $rule . ':') === 0, "never-claim '$rule' not caught in: $text (" . json_encode($hits) . ')');
        }
        foreach (PerfRouter::TEMPLATES as $class => $t) {
            check(PerfRouter::lint(array_values($t)) === [], "template $class breaks a never-claim: " . json_encode(PerfRouter::lint(array_values($t))));
        }
        foreach (perfRtCases() as $case) {
            foreach (PerfRouter::classify(perfRtFacts($case[1])) as $v) {
                check(PerfRouter::lint(array_merge([$v['summary'], $v['negation'], $v['disproved_by'], $v['next_step']], $v['evidence'])) === [], 'produced verdict breaks a never-claim: ' . json_encode($v));
            }
        }
        $threw = false;
        try {
            PerfRouter::verdict('host_io_busy', ['x'], ['next_step' => 'Support will look at the failing disk.']);
        } catch (LogicException $e) {
            $threw = true;
        }
        check($threw, 'a verdict with a never-claim must not be built');
    },
    'perf router: an I/O cap never yields upgrade wording; memory and CPU may when they are the measured cause' => function () {
        $upgrade = '/upgrade|bigger plan|larger plan|add-on/i';
        $io = PerfRouter::classify(perfRtFacts(perfRtCases()[3][1]));
        check($io[0]['class'] === 'account_io_cap', 'io case: ' . $io[0]['class']);
        check(!preg_match($upgrade, json_encode($io[0])), 'the I/O cap verdict suggests an upgrade: ' . json_encode($io[0]));
        check(strpos($io[0]['next_step'], 'ticket') !== false, 'the I/O cap verdict must point to a ticket when lower than the plan');
        foreach (['account_memory_bound', 'account_cpu_bound'] as $class) {
            check(preg_match($upgrade, PerfRouter::TEMPLATES[$class]['next_step']) === 1, "$class should name the bigger plan option");
        }
        foreach (['host_io_busy', 'host_steal', 'link_speed_low', 'maintenance', 'route_loss', 'client_window', 'single_stream_bdp', 'own_workload'] as $class) {
            check(!preg_match($upgrade, json_encode(PerfRouter::TEMPLATES[$class])), "$class suggests an upgrade for a cause the plan does not set");
        }
    },
    'perf router: a fixture with only the memory failure counter and peak usage high yields no memory class, and neither file is read' => function () {
        perfBNeedWrappers();
        $fx = perfFixture();
        try {
            foreach (['v1', 'v2'] as $mode) {
                $roots = perfFakeTree($fx['dir'] . "/tree-$mode", $mode);
                $slice = $mode === 'v1' ? '/sys/fs/cgroup/memory/user.slice/user-' . PERF_FIXTURE_UID . '.slice' : '/sys/fs/cgroup/user.slice/user-' . PERF_FIXTURE_UID . '.slice';
                $real = $roots['/sys'] . substr($slice, 4);
                perfWrite("$real/memory.failcnt", "987654\n");
                perfWrite("$real/memory.max_usage_in_bytes", "2147483648\n");
                perfWrite("$real/memory.peak", "2147483648\n");
                @unlink($fx['home'] . '/bin/userShowLimits');
                list($recs) = perfBCheck($fx, $roots);
                $classes = array_merge([perfBArea($recs, 'verdict')['class']], array_column(perfBArea($recs, 'verdict')['other_findings'], 'class'));
                check(!in_array('account_memory_bound', $classes, true), "$mode: failure counter / peak fired memory: " . json_encode($classes));
                foreach (perfBReadLog($fx) as $p) {
                    check(!preg_match('/failcnt|max_usage|memory\.peak/', $p), "$mode: read $p");
                }
                check(perfBArea($recs, 'memory')['anon_pct_of_limit'] === 25.0, "$mode: anon share " . json_encode(perfBArea($recs, 'memory')));
                @unlink($fx['dir'] . '/run/read.jsonl');
            }
        } finally {
            perfDone($fx);
        }
    },
    'perf router: the symptom orders the verdicts, every finding stays listed' => function () {
        $f = perfRtFacts(['io' => ['own_rate' => ['8:0' => ['read_bps' => 100000000, 'write_bps' => 0]]], 'host' => ['cpu_steal_pct' => 9.0]]);
        $f['sessions']['sessions'][0]['rwnd_limited_pct'] = 80.0;
        $capped = perfBClasses(['symptom' => 'capped'] + $f);
        check($capped[0] === 'account_io_cap' && count($capped) === 3, 'capped order: ' . json_encode($capped));
        $down = perfBClasses(['symptom' => 'slow-download'] + $f);
        check($down[0] === 'client_window' && count($down) === 3, 'slow-download order: ' . json_encode($down));
        $lag = perfBClasses(['symptom' => 'lag'] + $f);
        check($lag[0] === 'host_steal', 'lag order: ' . json_encode($lag));
        $all = perfBClasses($f);
        check($all === ['client_window', 'account_io_cap', 'host_steal'], 'table order: ' . json_encode($all));
    },
    'perf router: route loss counts only when it lasts to the last answering hop; a silent destination is not loss' => function () {
        $hops = function (array $loss) {
            return array_map(function ($l) {
                return ['loss_pct' => $l];
            }, $loss);
        };
        $v = PerfRouter::routeVerdict($hops([0, 0, 0, 0, 0, 0, 30, 32, 31]));
        check($v !== null && $v['class'] === 'route_loss' && strpos($v['evidence'][0], 'hop 7') !== false && strpos($v['evidence'][0], 'hop 9') !== false, 'persisting loss: ' . json_encode($v));
        check(strpos($v['next_step'], 'reverse') !== false, 'loss far from the server must ask for the reverse run: ' . $v['next_step']);
        $near = PerfRouter::routeVerdict($hops([0, 25, 26, 25]));
        check($near['class'] === 'route_loss' && strpos($near['next_step'], 'ticket') !== false, 'loss near the server must be a ticket: ' . json_encode($near));
        check(PerfRouter::routeVerdict($hops([0, 0, 80, 0, 0, 0])) === null, 'mid-path loss that does not persist must not be a verdict');
        check(PerfRouter::routeVerdict($hops([0, 0, 80, 0, null, 0])) === null, 'a hop that never answers in the middle is not loss');
        $silent = PerfRouter::routeVerdict($hops([0, 0, 0, 100]));
        check($silent['class'] === 'destination_silent' && strpos($silent['next_step'], 'hop is 3') !== false, 'silent destination: ' . json_encode($silent));
        $lossThenSilent = PerfRouter::routeVerdict($hops([0, 10, 12, null]));
        check($lossThenSilent['class'] === 'route_loss', 'loss to the last answering hop before a silent one: ' . json_encode($lossThenSilent));
        check(PerfRouter::routeVerdict($hops([0, 0, 0])) === null && PerfRouter::routeVerdict([]) === null, 'a clean route must give no verdict');
    },
];
