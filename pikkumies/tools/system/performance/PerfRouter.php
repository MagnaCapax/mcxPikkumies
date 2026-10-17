<?php
/**
 * PerfRouter: `check` and the rule table that names what it found (ADR-0035, README.md "Rules").
 * check reads the account (PerfAccount) and the server (PerfHost) twice, CHECK_WINDOW_S apart, the account's own
 * processes (PerfProcs) and transfer sessions (PerfSessions) once, and userShowLimits on PMSS; then classify() runs
 * the fixed rules over those facts. Every verdict is a template: class, summary, the evidence (measured figures),
 * negation (what it does not mean), disproved_by, next_step. The model narrates these; it does not diagnose from
 * raw numbers. Every text the router can print is checked against the never-claims (lint()): a template that
 * breaks one is a bug, raised before anything is printed.
 * Output: at most CHECK_MAX_RECORDS records (verdict, account, cpu, memory, io, host, sessions, next_checks, skipped,
 * cost). --share keeps a fixed list of fields per record (shareView); --with-ip adds the SSH client address.
 * The previous check's figures (local/performance/last-check.json, 0600) are shown beside this one's, and a
 * different machine fingerprint says the previous check ran on another server.
 */
class PerfRouter
{
    /** Rule table order: the order of verdicts when the symptom does not say otherwise. */
    const TEMPLATES = [
        'client_window' => [
            'summary' => "Your computer's receive window limits this transfer.",
            'negation' => "The server's sending side is not what limits this session.",
            'disproved_by' => "A slow transfer whose receive-window-limited share is near 0%.",
            'next_step' => 'On your side: exclude the transfer from antivirus or firewall inspection, raise the network buffers of your operating system, or use a client that opens several streams.',
        ],
        'single_stream_bdp' => [
            'summary' => 'One stream over a long round trip: it cannot go faster than one window per round trip.',
            'negation' => 'Not a cap of the server or of the account.',
            'disproved_by' => 'A run with several streams that is no faster.',
            'next_step' => 'Use several streams: rclone --multi-thread-streams, lftp pget -n, or parallel transfers in FileZilla.',
        ],
        'link_speed_low' => [
            'summary' => "The server's network interface runs at a low speed.",
            'negation' => 'This is the speed the interface reports, not a verdict on the hardware.',
            'disproved_by' => 'An interface speed of 1000 Mbit/s or more.',
            'next_step' => 'Open a ticket with the check --share output.',
        ],
        'account_io_cap' => [
            'summary' => 'The account has a disk I/O cap and its disk use was at the cap during the check.',
            'negation' => 'Why the cap is set to this value is not known here.',
            'disproved_by' => 'No cap file, or your own disk rate well below the cap.',
            'next_step' => 'This is the cap and its effect. If the value is lower than your plan says, open a ticket with the check --share output.',
        ],
        'account_memory_bound' => [
            'summary' => "The account's memory limit is the constraint.",
            'negation' => 'Cache is not counted: the kernel gives cache back when programs need the memory.',
            'disproved_by' => 'No out-of-memory kill and memory in use below 90% of the limit.',
            'next_step' => 'Reduce the workload (fewer jobs at once, smaller caches), or a bigger plan or a memory add-on: on PMSS the upgrade options in the client area, elsewhere your provider.',
        ],
        'account_cpu_bound' => [
            'summary' => "The account's CPU quota is the constraint: its processes were held back.",
            'negation' => 'The server itself may be idle; this is the quota of the account.',
            'disproved_by' => 'A throttled share near 0%.',
            'next_step' => 'Lighten the CPU work (Direct Play instead of transcoding, fewer jobs at once), or a bigger plan, since CPU is the deciding limit here.',
        ],
        'traffic_limit' => [
            'summary' => "The plan's traffic is at or near its limit.",
            'negation' => 'Only the direction with a limit shown here is limited.',
            'disproved_by' => 'Traffic well under its limit.',
            'next_step' => "This is the plan's traffic limit; the options are in the client area or with your provider.",
        ],
        'own_workload' => [
            'summary' => 'Your own processes were waiting on the disk.',
            'negation' => 'Not a fault of the server.',
            'disproved_by' => 'The app is idle and it is still slow.',
            'next_step' => 'Pause that job or let it finish: a library scan or a backup ends on its own. Do not stop the default apps for this.',
        ],
        'host_io_busy' => [
            'summary' => "The server's disks are busy.",
            'negation' => 'Busy is not broken: this says nothing about the hardware.',
            'disproved_by' => 'Disk pressure (io full) below 15 over 10 seconds and below 10 over 60 seconds.',
            'next_step' => 'Open a ticket with the check --share output. On PMSS, the Diagnose button in the client area checks the account and the server.',
        ],
        'host_steal' => [
            'summary' => 'The virtual server waits for the physical machine it runs on (CPU steal).',
            'negation' => 'This is time the hypervisor did not give this machine; nothing here says more about its cause.',
            'disproved_by' => 'Steal at 0%.',
            'next_step' => 'Open a ticket with the check --share output.',
        ],
        'maintenance' => [
            'summary' => 'A disk array check or resync is running.',
            'negation' => 'It is routine upkeep and says nothing bad about the disks.',
            'disproved_by' => 'No check or resync running.',
            'next_step' => 'Wait for it to end; transfers can be slower until then.',
        ],
        'route_loss' => [
            'summary' => 'Packets are lost on the route, from one hop to the end.',
            'negation' => 'Loss at one middle hop that does not continue is that router limiting its replies, not packet loss.',
            'disproved_by' => 'The loss is gone at later hops.',
            'next_step' => 'Open a ticket with check --share --with-ip and the net output.',
        ],
        'destination_silent' => [
            'summary' => 'The last hop does not answer; the hops before it are clean.',
            'negation' => 'Not packet loss.',
            'disproved_by' => 'The destination answers.',
            'next_step' => 'Your router does not answer ping; read the route up to the last hop that answers.',
        ],
        'app_side' => [
            'summary' => 'Nothing in the account, the server or the sessions explains it; the app itself is the next place to look.',
            'negation' => 'Not proven to be the app either.',
            'disproved_by' => "The app's own limits are unset.",
            'next_step' => "Look at the app's own settings and limits: next_checks lists the verbs.",
        ],
        'nothing_found' => [
            'summary' => 'No limit or load was found.',
            'negation' => 'This tool sees only your own account.',
            'disproved_by' => 'n/a',
            'next_step' => 'The skipped list shows what could not be read here. If it stays slow, open a ticket with the check --share output.',
        ],
    ];

    /** Verdict order per symptom (classes not named keep table order after these). */
    const SYMPTOM_ORDER = [
        'slow-download' => ['client_window', 'single_stream_bdp', 'link_speed_low', 'host_io_busy', 'account_io_cap', 'traffic_limit'],
        'slow-upload' => ['traffic_limit', 'client_window', 'single_stream_bdp', 'link_speed_low', 'host_io_busy', 'account_io_cap'],
        'capped' => ['account_io_cap', 'traffic_limit', 'account_cpu_bound', 'link_speed_low', 'single_stream_bdp', 'client_window'],
        'stalls' => ['account_memory_bound', 'own_workload', 'host_io_busy', 'maintenance', 'account_io_cap'],
        'torrents' => ['account_io_cap', 'host_io_busy', 'own_workload', 'account_memory_bound', 'account_cpu_bound', 'traffic_limit'],
        'lag' => ['account_cpu_bound', 'host_steal', 'account_memory_bound', 'host_io_busy'],
        'everything' => [],
    ];

    /**
     * The never-claims (README.md): text the router must never print. Matched case-insensitively against every
     * summary, evidence line, negation, disproved_by and next step. The memory failure counter and peak usage are
     * matched by pattern so that their file names never appear in this unit.
     */
    const NEVER = [
        'hardware verdict' => '/\b(failing|degraded|dying|faulty|worn out)\b|\b(hardware|disk|drive|array)s? (is|are) (bad|broken)\b/i',
        'another account' => '/\bnois(y|ier)\b|\bneighbou?rs?\b|\b(other|another)\s+(accounts?|users?|customers?|tenants?)\b|\b\d+\s+(accounts|users|customers)\b/i',
        'cap called deliberate or a bug' => '/\b(deliberate(ly)?|on purpose|intentional(ly)?|bug|buggy|misconfigured)\b/i',
        'oversold' => '/oversold|oversubscri|overcommit/i',
        'promise of support or provider action' => '/\b(support|the provider|we|they|staff)\s+(will|shall|are going to)\b|\bwill be (fixed|resolved|refunded)\b/i',
        'cancel, refund, move elsewhere' => '/\bcancel|\brefund|\bmove (elsewhere|away)|\bswitch (provider|host)/i',
        'price' => '/[\x{20AC}$\x{00A3}]\s?\d|\b\d+([.,]\d+)?\s?(eur|usd|euros?|dollars?)\b|\bprices?\b|\bper month\b|\/mo\b/iu',
        'product name' => '/\b(plan|product|package)\s+(called|named)\b/i',
        'disk type from the rotational flag' => '/\b(hdd|ssd|nvme|spinning|rotational|solid[- ]state)\b/i',
        'load average as CPU use' => '/load average[^.]{0,40}\b(cpu|busy|usage|use)\b/i',
        'PSI some as saturation' => '/saturat/i',
        'benchmark as the ceiling' => '/\b(server|disk|hardware|link|network)(\'s)?\s+(ceiling|maximum speed|max speed|top speed|full speed)\b|\bcan do at most\b/i',
        'route verdict from mid-path loss' => '/\b(middle|mid-path|intermediate) hop\b[^.]{0,40}\b(drops|loses|is losing)\b/i',
        'home or ISP cause inferred' => '/\b(isp|internet provider|home (network|line|connection)|wi-?fi)\b[^.]{0,40}\b(is|are)\s+(the\s+)?(cause|problem|fault|faulty|to blame)\b/i',
        'memory failure counter or peak usage as pressure' => '/fail\W?c(ou)?nt|max[\W_]?usage|failure count|peak (memory )?usage/i',
    ];

    /** Units next_checks may point to: [category/unit, verb, why]; listed only when the unit is in this pikkumies. */
    const NEXT = [
        'rtorrent' => [['torrent-clients/rtorrent', 'traffic', "the client's own speed caps"], ['torrent-clients/rtorrent', 'stats', 'peers and transfer rates']],
        'torrent' => [['torrent-clients/qbittorrent', 'stats', 'peers and transfer rates'], ['torrent-clients/deluge', 'stats', 'peers and transfer rates']],
        'media' => [['media-servers/plex', 'sessions', 'who is streaming and whether it transcodes'], ['media-servers/jellyfin', 'sessions', 'who is streaming and whether it transcodes'], ['media-servers/emby', 'sessions', 'who is streaming and whether it transcodes']],
        'arr' => [['arr/sonarr', 'health', "the app's own health checks"], ['arr/radarr', 'health', "the app's own health checks"]],
    ];

    /** The previous check's figures, kept for the next one. */
    const LAST_FILE = 'last-check.json';

    /** check (PerfRouter::check is the verb; --tick goes to PerfSamples::tick in the dispatcher). */
    public static function check(PerfProbe $probe, array $params)
    {
        $params += ['symptom' => 'everything', 'share' => false, 'with_ip' => false];
        $usl = Detect::userShowLimitsData($probe->home);
        $layout = PerfAccount::layout($probe, $usl !== null);
        $a1 = PerfAccount::sample($probe, $layout);
        $h1 = PerfHost::sample($probe);
        PerfHost::wait();
        $a2 = PerfAccount::sample($probe, $layout);
        $h2 = PerfHost::sample($probe);
        $procs = PerfProcs::walk($probe);
        $conn = getenv('SSH_CONNECTION');
        $sessions = PerfSessions::read($probe, $conn === false ? '' : $conn);

        $acc = PerfAccount::summary($layout, $a1, $a2, $usl);
        $host = PerfHost::summary($probe, $h1, $h2);
        $facts = ['symptom' => $params['symptom'], 'memory' => $acc['memory'], 'cpu' => $acc['cpu'], 'io' => $acc['io'],
            'traffic' => $acc['account']['traffic'], 'host' => $host, 'procs' => $procs, 'sessions' => $sessions];
        $verdicts = self::classify($facts);
        $skipped = self::skipped($layout, $host, $acc, $procs, $sessions);
        $probe->classes = array_column($verdicts, 'class');
        $probe->skipped = $skipped;

        $previous = self::previous($probe);
        self::remember($probe, $verdicts, $host);
        $host['previous'] = $previous;

        $lead = array_shift($verdicts);
        $byApp = [];
        if ($procs !== null) {
            foreach (array_slice($procs['groups'], 0, PerfLimits::MEM_TOP_GROUPS, true) as $g => $v) {
                $byApp[] = ['app' => $g] + $v;
            }
        }
        $sessRec = ['area' => 'sessions', 'visible' => $sessions['visible'], 'reason' => $sessions['reason'], 'active' => $sessions['active'],
            'sessions' => $sessions['sessions'], 'stream_hints' => $procs === null ? null : $procs['stream_hints']];
        if ($params['share'] && $params['with_ip']) {
            $sessRec['connection_ip'] = PerfSessions::clientIp($conn === false ? '' : $conn);
        }
        $records = [
            ['area' => 'verdict', 'class' => $lead['class']] + $lead + ['symptom' => $params['symptom'], 'other_findings' => $verdicts],
            ['area' => 'account'] + $acc['account'],
            ['area' => 'cpu'] + $acc['cpu'],
            ['area' => 'memory'] + $acc['memory'] + ['by_app' => $procs === null ? null : $byApp],
            ['area' => 'io'] + $acc['io'] + ['waiting_on_disk' => $procs === null ? null : $procs['waiting_on_disk']],
            ['area' => 'host'] + $host,
            $sessRec,
            ['area' => 'next_checks', 'checks' => self::nextChecks($params['symptom'], $procs, $lead['class'])],
            ['area' => 'skipped', 'skipped' => $skipped],
            ['area' => 'cost'] + $probe->cost(),
        ];
        if ($params['share']) {
            $records = self::shareView($records, $params['with_ip']);
        }
        return array_slice($records, 0, PerfLimits::CHECK_MAX_RECORDS);
    }

    /** Fields each record keeps in the --share view (a ticket paste); everything else is dropped. next_checks and cost are left out. */
    const SHARE_FIELDS = [
        'verdict' => ['area', 'view', 'class', 'summary', 'evidence', 'negation', 'disproved_by', 'next_step', 'symptom', 'other_findings'],
        'account' => ['area', 'source', 'cgroup', 'scope', 'unavailable_reason', 'own_pressure', 'traffic', 'platform_limits'],
        'cpu' => ['area', 'quota_cores', 'own_use_cores', 'throttled_pct'],
        'memory' => ['area', 'limit_bytes', 'in_use_bytes', 'anon_bytes', 'cache_bytes', 'anon_pct_of_limit', 'oom_kills_during_check', 'platform_status', 'platform_oom_kills_lifetime'],
        'io' => ['area', 'caps', 'own_rate', 'waiting_on_disk'],
        'host' => ['area', 'pressure', 'cpu_busy_pct', 'cpu_steal_pct', 'procs_running', 'procs_blocked', 'disk_await_ms', 'disk_util_pct', 'disk_device',
            'swap_in_pages_s', 'swap_out_pages_s', 'array_maintenance', 'link_speed_mbit', 'link_errors_during_check'],
        'sessions' => ['area', 'visible', 'reason', 'active', 'sessions'],
        'skipped' => ['area', 'skipped'],
    ];

    /** The --share view: only SHARE_FIELDS; the address only with --with-ip. */
    public static function shareView(array $records, $withIp)
    {
        $out = [];
        foreach ($records as $r) {
            $area = isset($r['area']) ? $r['area'] : '';
            if (!isset(self::SHARE_FIELDS[$area])) {
                continue;
            }
            if ($area === 'verdict') {
                $r['view'] = 'share';
            }
            $keep = self::SHARE_FIELDS[$area];
            if ($area === 'sessions' && $withIp) {
                $keep[] = 'connection_ip';
            }
            $f = [];
            foreach ($keep as $k) {
                if (array_key_exists($k, $r)) {
                    $f[$k] = $r[$k];
                }
            }
            $out[] = $f;
        }
        return $out;
    }

    /** Verdicts for $facts, ordered by the symptom; never empty (app_side or nothing_found when no rule fires). */
    public static function classify(array $f)
    {
        $f += ['symptom' => 'everything', 'memory' => [], 'cpu' => [], 'io' => [], 'traffic' => [], 'host' => [], 'procs' => null, 'sessions' => []];
        $found = [];
        foreach (array_keys(self::TEMPLATES) as $class) {
            $method = 'rule' . str_replace(' ', '', ucwords(str_replace('_', ' ', $class)));
            if (method_exists(__CLASS__, $method)) {
                $evidence = call_user_func([__CLASS__, $method], $f);
                if ($evidence) {
                    $found[$class] = $evidence;
                }
            }
        }
        if (!$found) {
            $class = in_array($f['symptom'], ['torrents', 'lag'], true) ? 'app_side' : 'nothing_found';
            $found[$class] = [$class === 'app_side' ? 'No account, server or session finding during the check, and the symptom is the app\'s (' . $f['symptom'] . ').' : 'None of the rules found its evidence during the check.'];
        }
        $order = isset(self::SYMPTOM_ORDER[$f['symptom']]) ? self::SYMPTOM_ORDER[$f['symptom']] : [];
        $rank = array_flip(array_merge($order, array_diff(array_keys(self::TEMPLATES), $order)));
        uksort($found, function ($x, $y) use ($rank) {
            return $rank[$x] - $rank[$y];
        });
        $out = [];
        foreach ($found as $class => $evidence) {
            $out[] = self::verdict($class, $evidence);
        }
        return $out;
    }

    /** One verdict from its template and evidence; the text is linted (a never-claim here is a bug). */
    public static function verdict($class, array $evidence, array $override = [])
    {
        if (!isset(self::TEMPLATES[$class])) {
            throw new LogicException("unknown verdict class $class");
        }
        $v = ['class' => $class] + array_merge(self::TEMPLATES[$class], $override) + ['evidence' => []];
        $v = ['class' => $class, 'summary' => $v['summary'], 'evidence' => array_values(array_map('strval', $evidence)),
            'negation' => $v['negation'], 'disproved_by' => $v['disproved_by'], 'next_step' => $v['next_step']];
        $bad = self::lint(array_merge([$v['summary'], $v['negation'], $v['disproved_by'], $v['next_step']], $v['evidence']));
        if ($bad) {
            throw new LogicException("verdict $class breaks a never-claim: " . implode('; ', $bad));
        }
        return $v;
    }

    /** The never-claims that $texts break, as "rule: text" lines; [] when clean. */
    public static function lint(array $texts)
    {
        $out = [];
        foreach ($texts as $t) {
            foreach (self::NEVER as $rule => $re) {
                if (preg_match($re, (string) $t)) {
                    $out[] = "$rule: $t";
                }
            }
        }
        return $out;
    }

    // ---- rules: each returns its evidence lines, or [] when its evidence is not there ----

    private static function ruleClientWindow(array $f)
    {
        $ev = [];
        foreach (self::activeSessions($f) as $s) {
            if ($s['rwnd_limited_pct'] !== null && $s['rwnd_limited_pct'] >= PerfLimits::RWND_LIMITED_PCT) {
                $ev[] = "Your {$s['service']} session: the receive window was the limit for {$s['rwnd_limited_pct']}% of its busy time (round trip " . self::fmt($s['rtt_ms']) . ' ms).';
            }
        }
        return $ev;
    }

    private static function ruleSingleStreamBdp(array $f)
    {
        $active = self::activeSessions($f);
        if (count($active) !== 1) {
            return [];
        }
        $s = $active[0];
        if ($s['rtt_ms'] === null || $s['rtt_ms'] < PerfLimits::BDP_RTT_MS || $s['delivery_mbit'] === null || !$s['window_bound_mbit']
            || 100 * $s['delivery_mbit'] / $s['window_bound_mbit'] < PerfLimits::NEAR_CAP_PCT) {
            return [];
        }
        $ev = ["One active stream ({$s['service']}), round trip {$s['rtt_ms']} ms, rate {$s['delivery_mbit']} Mbit/s against {$s['window_bound_mbit']} Mbit/s that one window per round trip allows."];
        foreach ((array) (isset($f['procs']['stream_hints']) ? $f['procs']['stream_hints'] : []) as $h) {
            if ($h['value'] <= 1) {
                $ev[] = "{$h['app']} runs with {$h['flag']} {$h['value']}.";
            }
        }
        return $ev;
    }

    private static function ruleLinkSpeedLow(array $f)
    {
        $speed = isset($f['host']['link_speed_mbit']) ? $f['host']['link_speed_mbit'] : null;
        return $speed !== null && $speed <= PerfLimits::LINK_SPEED_LOW_MBIT ? ["The default-route interface reports $speed Mbit/s."] : [];
    }

    private static function ruleAccountIoCap(array $f)
    {
        $ev = [];
        $rates = isset($f['io']['own_rate']) && is_array($f['io']['own_rate']) ? $f['io']['own_rate'] : [];
        foreach (isset($f['io']['caps']) ? $f['io']['caps'] : [] as $cap) {
            $dev = $cap['device'];
            foreach (['rbps' => ['read_bps', 'read'], 'wbps' => ['write_bps', 'write']] as $k => $rk) {
                $rate = isset($rates[$dev][$rk[0]]) ? $rates[$dev][$rk[0]] : null;
                if (isset($cap[$k]) && $rate !== null && 100 * $rate / $cap[$k] >= PerfLimits::NEAR_CAP_PCT) {
                    $ev[] = "Device $dev: {$rk[1]} cap " . self::mb($cap[$k]) . ' MB/s, your ' . $rk[1] . ' rate ' . self::mb($rate) . ' MB/s during the check.';
                }
            }
        }
        return $ev;
    }

    private static function ruleAccountMemoryBound(array $f)
    {
        $m = $f['memory'] + ['oom_kills_during_check' => null, 'platform_status' => null, 'anon_pct_of_limit' => null, 'anon_pct_first_sample' => null];
        $ev = [];
        if ($m['oom_kills_during_check'] !== null && $m['oom_kills_during_check'] > 0) {
            $ev[] = "{$m['oom_kills_during_check']} out-of-memory kill(s) in the account during the check.";
        }
        if ($m['platform_status'] !== null && preg_match('/^(high|critical)/i', $m['platform_status'])) {
            $ev[] = "userShowLimits reports the memory status as {$m['platform_status']}.";
        }
        if ($m['anon_pct_of_limit'] !== null && $m['anon_pct_first_sample'] !== null && $m['anon_pct_of_limit'] >= PerfLimits::MEM_ANON_PCT && $m['anon_pct_first_sample'] >= PerfLimits::MEM_ANON_PCT) {
            $ev[] = "Memory in use (not cache) was {$m['anon_pct_first_sample']}% and {$m['anon_pct_of_limit']}% of the limit in the two samples.";
        }
        return $ev;
    }

    private static function ruleAccountCpuBound(array $f)
    {
        $c = $f['cpu'] + ['throttled_pct' => null, 'own_use_cores' => null, 'quota_cores' => null];
        if ($c['throttled_pct'] === null || $c['throttled_pct'] < PerfLimits::CPU_THROTTLE_PCT) {
            return [];
        }
        $ev = ["{$c['throttled_pct']}% of the CPU periods during the check were throttled by the account's quota."];
        if ($c['own_use_cores'] !== null && $c['quota_cores'] !== null) {
            $ev[] = "Own CPU use {$c['own_use_cores']} cores against a quota of {$c['quota_cores']}.";
        }
        return $ev;
    }

    private static function ruleTrafficLimit(array $f)
    {
        $ev = [];
        foreach ((array) $f['traffic'] as $t) {
            if ($t['used_pct'] !== null && $t['used_pct'] >= PerfLimits::TRAFFIC_NEAR_PCT) {
                $ev[] = "{$t['metric']}: {$t['used_pct']}% of its limit used.";
            }
        }
        return $ev;
    }

    private static function ruleOwnWorkload(array $f)
    {
        if (empty($f['procs']['waiting_on_disk'])) {
            return [];
        }
        $groups = [];
        foreach ($f['procs']['groups'] as $g => $v) {
            if ($v['waiting_on_disk'] > 0) {
                $groups[] = "$g: {$v['waiting_on_disk']}";
            }
        }
        return ["{$f['procs']['waiting_on_disk']} of your processes were waiting on the disk (" . implode(', ', $groups) . ').'];
    }

    private static function ruleHostIoBusy(array $f)
    {
        $io = isset($f['host']['pressure']['io']) ? $f['host']['pressure']['io'] : [];
        $ev = [];
        if (isset($io['avg10']) && $io['avg10'] >= PerfLimits::PSI_IO_FULL_AVG10) {
            $ev[] = "Server disk pressure (io full) {$io['avg10']} over the last 10 seconds (burst threshold " . PerfLimits::PSI_IO_FULL_AVG10 . ').';
        }
        if (isset($io['avg60']) && $io['avg60'] >= PerfLimits::PSI_IO_FULL_AVG60) {
            $ev[] = "Server disk pressure (io full) {$io['avg60']} over the last 60 seconds (sustained threshold " . PerfLimits::PSI_IO_FULL_AVG60 . ').';
        }
        return $ev;
    }

    private static function ruleHostSteal(array $f)
    {
        $steal = isset($f['host']['cpu_steal_pct']) ? $f['host']['cpu_steal_pct'] : null;
        return $steal !== null && $steal >= PerfLimits::STEAL_PCT ? ["CPU steal $steal% during the check."] : [];
    }

    private static function ruleMaintenance(array $f)
    {
        $m = isset($f['host']['array_maintenance']) ? $f['host']['array_maintenance'] : null;
        return $m !== null ? ["/proc/mdstat shows an array $m running."] : [];
    }

    /** Sessions that moved data during the check (busy time above 0). */
    private static function activeSessions(array $f)
    {
        if (empty($f['sessions']['visible']) || empty($f['sessions']['sessions'])) {
            return [];
        }
        return array_values(array_filter($f['sessions']['sessions'], function ($s) {
            return $s['busy_ms'] !== null && $s['busy_ms'] > 0;
        }));
    }

    /**
     * The route verdict from mtr-like hops (for the net verb): [['loss_pct' => float|null], ...] in hop order; null
     * loss = no reply at all. route_loss when loss starts at a hop and every answering hop after it to the last one
     * that answers loses too; destination_silent when the last hop answers nothing and every answering hop before
     * it is clean; null otherwise (loss that does not persist is a router limiting its replies). $nearServer: hops
     * after the server that still count as its network (the next step differs).
     */
    public static function routeVerdict(array $hops, $nearServer = 3)
    {
        $loss = [];
        foreach (array_values($hops) as $i => $h) {
            $loss[$i + 1] = isset($h['loss_pct']) && $h['loss_pct'] !== null ? (float) $h['loss_pct'] : 100.0;
        }
        if (!$loss) {
            return null;
        }
        $answering = array_keys(array_filter($loss, function ($l) {
            return $l < 100;
        }));
        if (!$answering) {
            return null;
        }
        $last = max($answering);
        $n = count($loss);
        if ($loss[$last] > 0) {
            $start = $last;
            foreach (array_reverse($answering) as $k) {
                if ($loss[$k] <= 0) {
                    break;
                }
                $start = $k;
            }
            $next = $start <= $nearServer ? self::TEMPLATES['route_loss']['next_step']
                : 'The loss starts further along the route: run the reverse test from your computer (instructions printed) and paste the result, then read it the same way.';
            return self::verdict('route_loss', ["Loss starts at hop $start ({$loss[$start]}%) and lasts to hop $last ({$loss[$last]}%), the last that answers."], ['next_step' => $next]);
        }
        if ($last < $n && $loss[$n] >= 100) {
            return self::verdict('destination_silent', ["Hop $n does not answer; hops up to $last answer without loss."],
                ['next_step' => "Your router does not answer ping; the last answering hop is $last."]);
        }
        return null;
    }

    /** next_checks: other units' verbs to try, for the symptom and the apps running; only units present here. */
    private static function nextChecks($symptom, $procs, $lead)
    {
        $out = [];
        $add = function ($unit, $verb, $why) use (&$out) {
            if (is_file(dirname(__DIR__, 2) . "/$unit/USAGE.md")) {
                $out[] = ['unit' => basename($unit), 'verb' => $verb, 'why' => $why];
            }
        };
        if (in_array($symptom, ['slow-download', 'slow-upload', 'lag', 'everything'], true)) {
            $out[] = ['unit' => 'performance', 'verb' => 'net --target ssh', 'why' => 'the route to your connection; ask the user first'];
        }
        if (in_array($symptom, ['stalls', 'torrents'], true)) {
            $out[] = ['unit' => 'performance', 'verb' => 'disk', 'why' => 'a light disk probe compared with the server\'s disk wait'];
        }
        $groups = $procs === null ? null : array_keys($procs['groups']);
        $want = in_array($symptom, ['torrents', 'everything'], true) || $lead === 'app_side' ? ['rtorrent', 'torrent'] : [];
        if (in_array($symptom, ['lag', 'everything'], true) || $lead === 'app_side') {
            $want = array_merge($want, ['media', 'arr']);
        }
        foreach ($want as $g) {
            if ($groups === null || in_array($g, $groups, true)) {
                foreach (self::NEXT[$g] as $n) {
                    $add($n[0], $n[1], $n[2]);
                }
            }
        }
        return $out;
    }

    /** [check, reason] for every area that could not be read; an unknown value is listed here, never shown as 0. */
    private static function skipped(array $layout, array $host, array $acc, $procs, array $sessions)
    {
        $s = [];
        if ($layout['scope'] === null) {
            $s[] = ['check' => 'account cgroup', 'reason' => (string) $layout['reason']];
        }
        if ($acc['memory']['limit_bytes'] === null) {
            $s[] = ['check' => 'memory limit', 'reason' => 'no memory limit found for the account (none set, or not readable)'];
        }
        if ($acc['cpu']['throttled_pct'] === null) {
            $s[] = ['check' => 'CPU throttling', 'reason' => 'no CPU periods counted during the check (no quota, or the counters are not readable)'];
        }
        if ($acc['io']['own_rate'] === null) {
            $s[] = ['check' => 'own disk rate', 'reason' => "the account's disk byte counters are not readable here"];
        }
        $psi = $host['pressure'];
        if ($psi['io']['avg10'] === null && $psi['cpu']['avg10'] === null && $psi['memory']['avg10'] === null) {
            $s[] = ['check' => 'server pressure', 'reason' => '/proc/pressure is not readable (PSI off or an older kernel)'];
        }
        if ($host['cpu_busy_pct'] === null) {
            $s[] = ['check' => 'server CPU', 'reason' => '/proc/stat could not be read, or no CPU time passed between the two samples'];
        }
        if ($host['disk_device'] === null) {
            $s[] = ['check' => 'disk wait', 'reason' => 'the device behind your home directory was not found, or keeps no time figures'];
        }
        if ($host['swap_in_pages_s'] === null) {
            $s[] = ['check' => 'swap activity', 'reason' => '/proc/vmstat could not be read'];
        }
        if ($host['link_speed_mbit'] === null) {
            $s[] = ['check' => 'link speed', 'reason' => 'no default route, or the interface does not report its speed'];
        }
        if ($procs === null) {
            $s[] = ['check' => 'own processes', 'reason' => '/proc could not be listed'];
        }
        if ($sessions['visible'] !== true) {
            $s[] = ['check' => 'sessions', 'reason' => (string) $sessions['reason']];
        }
        return $s;
    }

    /** The previous check: when, on the same machine or not, its classes and a few figures; null when none. */
    private static function previous(PerfProbe $probe)
    {
        $raw = $probe->fs->read($probe->stateDir() . '/' . self::LAST_FILE, 65536);
        $p = $raw === null ? null : json_decode($raw, true);
        if (!is_array($p) || !isset($p['fingerprint'], $p['ts'])) {
            return null;
        }
        $same = $p['fingerprint'] === $probe->fingerprint();
        return ['at' => (string) $p['ts'], 'same_server' => $same, 'note' => $same ? null : 'the previous check ran on another server (or as another account)',
            'classes' => isset($p['classes']) ? (array) $p['classes'] : [], 'figures' => isset($p['figures']) ? (array) $p['figures'] : []];
    }

    private static function remember(PerfProbe $probe, array $verdicts, array $host)
    {
        $file = $probe->stateDir() . '/' . self::LAST_FILE;
        $data = ['ts' => gmdate('c'), 'fingerprint' => $probe->fingerprint(), 'classes' => array_column($verdicts, 'class'),
            'figures' => ['io_full_avg10' => $host['pressure']['io']['avg10'], 'cpu_busy_pct' => $host['cpu_busy_pct'], 'cpu_steal_pct' => $host['cpu_steal_pct'], 'disk_await_ms' => $host['disk_await_ms']]];
        $tmp = $file . '.' . getmypid();
        $old = umask(077);
        $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n") !== false;
        umask($old);
        if ($ok) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    }

    private static function fmt($v)
    {
        return $v === null ? 'unknown' : (string) $v;
    }

    private static function mb($bps)
    {
        return (string) round($bps / 1048576, 1);
    }
}
