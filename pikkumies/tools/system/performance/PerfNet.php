<?php
/**
 * PerfNet: `net`, the route from this server to a target (README.md, ADR-0035). The dispatcher has checked the target
 * grammar; this class
 *  - resolves `ssh` to the client address of the user's own SSH connection (SSH_CONNECTION), and refuses when there is
 *    none, so the agent asks the user for an address instead of guessing one,
 *  - runs `mtr --report -n -c N`, then `traceroute -n`, then `ping -n -c N` when the one before cannot run, each in
 *    measure mode with a deadline (PerfProbe), and parses the report in-process,
 *  - reads the result by direction and persistence: this is the forward run (server to target); loss counts only
 *    when it starts at a hop and lasts to the last hop that answered. Loss at a middle hop that later hops do not
 *    show is that router limiting its replies, which gives no route verdict. A target that does not answer while
 *    the hops before it are clean is `destination_silent`, not loss,
 *  - prints how the user runs the other direction from their own computer (the wiki procedure).
 * No address, hostname or raw program output reaches the records: hops are numbers only.
 */
class PerfNet
{
    const TRACEROUTE_PROBES = 3; // probes per hop of the traceroute fallback (-q 3)

    const ASK = '--target ssh tests the route to the connection the user is on; --target <IP or hostname> tests another address';

    /** Records: reading (the verdict, if any), route (method and hops, no addresses), reverse (the user's own run). */
    public static function net(PerfProbe $probe, array $params)
    {
        $target = isset($params['target']) && is_string($params['target']) ? $params['target'] : '';
        if ($target === '') {
            throw new PerfUsageError('net needs --target; nothing was run', self::ASK);
        }
        $kind = 'hostname';
        if ($target === 'ssh') {
            $target = self::sshClient(getenv('SSH_CONNECTION'));
            if ($target === null) {
                throw new CliError('there is no SSH connection to test (SSH_CONNECTION is not set here); nothing was run',
                    'ask the user for the address to test (their own connection\'s public address), then --target <IP>');
            }
            $kind = 'ssh';
        } elseif (filter_var($target, FILTER_VALIDATE_IP) !== false) {
            $kind = 'ip';
        }
        $count = isset($params['count']) ? max(1, (int) $params['count']) : PerfLimits::NET_QUICK_COUNT;
        // test seam: the dispatcher never sets deadline_s (perfParams builds params from checked options only)
        $seam = isset($params['deadline_s']) ? max(1, (int) $params['deadline_s']) : null;
        $mtrDeadline = $seam !== null ? $seam : $count + PerfLimits::MTR_GRACE_S;
        if ($mtrDeadline > PerfLimits::NET_ETA_NOTICE_S) {
            fwrite(STDERR, "net: route test, $count cycle(s), about $mtrDeadline s at most (until " . gmdate('H:i:s', time() + $mtrDeadline) . " UTC); the result is printed when it ends\n");
        }

        $result = null;
        if (ToolProc::which('mtr') === null) {
            $probe->skipped[] = ['check' => 'mtr', 'reason' => 'mtr is not installed'];
        } else {
            $r = $probe->measure(['mtr', '--report', '-n', '-c', (string) $count, $target], $mtrDeadline);
            $hops = $r['code'] === 0 ? self::parseMtr($r['out']) : [];
            if ($hops) {
                $result = ['method' => 'mtr', 'hops' => $hops, 'io_class' => $r['io_class'], 'wall_s' => $r['wall_s']];
                $probe->addCost('packets_sent', $count * count($hops));
            } else {
                $probe->skipped[] = ['check' => 'mtr', 'reason' => self::failure($r, $mtrDeadline)];
            }
        }
        if ($result === null) {
            $result = self::traceroute($probe, $target, $seam);
        }
        if ($result === null) {
            $result = self::ping($probe, $target, $count, $seam);
        }
        if ($result === null) {
            throw new CliError('no route test could run here (mtr, traceroute and ping are missing or failed); nothing was measured',
                'the user can still run the test from their own computer: ' . self::reverse()['linux']);
        }

        $reading = isset($result['hops']) ? self::read($result['hops'], $result['method']) : self::readPing($result['end_to_end']);
        if ($reading['class'] !== null) {
            $probe->classes[] = $reading['class'];
        }
        $route = ['kind' => 'route', 'direction' => 'forward: this server to the target', 'target' => $kind === 'ssh' ? 'your SSH connection' : "the $kind given",
            'method' => $result['method'], 'count' => $result['method'] === 'traceroute' ? self::TRACEROUTE_PROBES : $count,
            'quick' => $result['method'] !== 'traceroute' && $count < PerfLimits::NET_WIKI_COUNT,
            'label' => $count < PerfLimits::NET_WIKI_COUNT || $result['method'] === 'traceroute' ? 'quick indication, not the wiki procedure (1000 cycles both ways)' : 'the wiki procedure\'s count (this direction)',
            'io_class' => $result['io_class'], 'wall_s' => $result['wall_s']];
        if (isset($result['hops'])) {
            $route['hops'] = $result['hops'];
        } else {
            $route['end_to_end'] = $result['end_to_end'];
        }
        return [['kind' => 'reading'] + $reading, $route, ['kind' => 'reverse'] + self::reverse()];
    }

    /** The client address of "client_ip client_port server_ip server_port", or null. */
    public static function sshClient($conn)
    {
        $first = is_string($conn) ? strtok(trim($conn), ' ') : false;
        return is_string($first) && filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : null;
    }

    /**
     * `mtr --report -n` output -> hops [hop, loss_pct, sent, avg_ms, stdev_ms, worst_ms, answered], in hop order.
     * The address column is matched and dropped. A hop that never answered (???, 100% loss) has null times.
     */
    public static function parseMtr($text)
    {
        $hops = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (!preg_match('/^\s*(\d+)\.\s*[|`]--\s+\S+\s+([0-9.]+)%?\s+(\d+)\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s*$/', $line, $m)) {
                continue; // header, Start:, extra ECMP address lines
            }
            $loss = (float) $m[2];
            $answered = $loss < 100.0;
            $hops[(int) $m[1]] = ['hop' => (int) $m[1], 'loss_pct' => $loss, 'sent' => (int) $m[3],
                'avg_ms' => $answered ? (float) $m[5] : null, 'stdev_ms' => $answered ? (float) $m[8] : null,
                'worst_ms' => $answered ? (float) $m[7] : null, 'answered' => $answered];
        }
        ksort($hops);
        return array_values($hops);
    }

    /** `traceroute -n` output -> the same hop shape; loss is the share of `*` among the probes of a hop. */
    public static function parseTraceroute($text)
    {
        $hops = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(.*)$/', $line, $m) || strpos($line, 'traceroute to') !== false) {
                continue;
            }
            preg_match_all('/([0-9.]+) ms/', $m[2], $times);
            $stars = substr_count($m[2], '*');
            $rtt = array_map('floatval', $times[1]);
            $sent = count($rtt) + $stars;
            if ($sent === 0) {
                continue;
            }
            $answered = $rtt !== [];
            $avg = $answered ? array_sum($rtt) / count($rtt) : null;
            $hops[(int) $m[1]] = ['hop' => (int) $m[1], 'loss_pct' => round(100.0 * $stars / $sent, 1), 'sent' => $sent,
                'avg_ms' => $answered ? round($avg, 3) : null, 'stdev_ms' => $answered ? round(self::stdev($rtt, $avg), 3) : null,
                'worst_ms' => $answered ? max($rtt) : null, 'answered' => $answered];
        }
        ksort($hops);
        return array_values($hops);
    }

    /** `ping -n -c N` summary -> [sent, received, loss_pct, avg_ms, stdev_ms, worst_ms] or null. */
    public static function parsePing($text)
    {
        if (!preg_match('/(\d+) packets transmitted, (\d+) (?:packets )?received.*?([0-9.]+)% packet loss/', (string) $text, $m)) {
            return null;
        }
        $out = ['sent' => (int) $m[1], 'received' => (int) $m[2], 'loss_pct' => (float) $m[3], 'avg_ms' => null, 'stdev_ms' => null, 'worst_ms' => null];
        if (preg_match('#= ([0-9.]+)/([0-9.]+)/([0-9.]+)/([0-9.]+) ms#', (string) $text, $t)) {
            $out['avg_ms'] = (float) $t[2];
            $out['worst_ms'] = (float) $t[3];
            $out['stdev_ms'] = (float) $t[4];
        }
        return $out;
    }

    /**
     * The direction-aware reading of a forward hop list: class (route_loss | destination_silent | null), reading,
     * evidence[], negation, disproved_by, next_step.
     *  - route_loss: loss starts at hop k and every answering hop after it up to the last answering one shows loss.
     *    When the target itself is silent the run must span two answering hops at least: the last answering hop alone
     *    may be one router limiting its replies, and nothing after it can show otherwise.
     *  - destination_silent: no reply after the last answering hop, and no route loss before it.
     *  - loss at middle hops that does not last: that router's reply rate limit; no verdict.
     */
    public static function read(array $hops, $method)
    {
        $answering = array_values(array_filter($hops, function ($h) {
            return $h['answered'];
        }));
        $last = $answering ? $answering[count($answering) - 1]['hop'] : 0;
        $silentAfter = count(array_filter($hops, function ($h) use ($last) {
            return $h['hop'] > $last;
        })) > 0;
        $run = [];
        for ($i = count($answering) - 1; $i >= 0 && $answering[$i]['loss_pct'] > 0; $i--) {
            array_unshift($run, $answering[$i]);
        }
        if ($silentAfter && count($run) < 2) {
            $run = [];
        }
        $runHops = array_column($run, 'hop');
        $limited = [];
        foreach ($answering as $h) {
            if ($h['loss_pct'] > 0 && !in_array($h['hop'], $runHops, true)) {
                $limited[] = 'hop ' . $h['hop'] . ': ' . $h['loss_pct'] . '% that later hops do not show (that router limiting its replies, not packet loss)';
            }
        }
        $low = $method === 'traceroute' ? ['confidence' => 'low: ' . self::TRACEROUTE_PROBES . ' probes per hop; mtr with more cycles is the real test'] : [];
        if (!$answering) {
            return ['class' => null, 'reading' => 'no_reply', 'evidence' => ['no hop answered'], 'negation' => 'not a route verdict: nothing answered at all',
                'disproved_by' => 'any hop answering', 'next_step' => 'the probes may be filtered here; run check, and ask the user for the reverse run'] + $low;
        }
        if ($run) {
            $k = $run[0]['hop'];
            $near = $k <= PerfLimits::NEAR_HOPS;
            $ev = ["loss starts at hop $k (" . $run[0]['loss_pct'] . '%) and lasts to hop ' . $run[count($run) - 1]['hop'] . ', the last that answered (' . $run[count($run) - 1]['loss_pct'] . '%)'];
            if ($silentAfter) {
                $ev[] = "the target did not answer after hop $last";
            }
            return ['class' => 'route_loss', 'reading' => 'route_loss', 'loss_starts_at_hop' => $k, 'last_answering_hop' => $last,
                'evidence' => array_merge($ev, $limited), 'negation' => 'loss at a middle hop that does not last is not this; only the run from hop ' . $k . ' to the end counts',
                'disproved_by' => 'the same test with more cycles showing no loss at the later hops',
                'next_step' => $near
                    ? "the loss starts within the first " . PerfLimits::NEAR_HOPS . " hops from this server: open a ticket with this output; add `check --share --with-ip` only if the user agrees to include the addresses"
                    : 'the loss starts further along the route; ask the user to run the reverse test from their own computer (the reverse record) and paste it: both directions together show where it starts'] + $low;
        }
        if ($silentAfter) {
            return ['class' => 'destination_silent', 'reading' => 'destination_silent', 'last_answering_hop' => $last,
                'evidence' => array_merge(["no reply after hop $last; the hops up to it show no lasting loss"], $limited),
                'negation' => 'not packet loss: the target does not answer these probes (for --target ssh: the router or firewall of your connection; many do not answer them)',
                'disproved_by' => 'the target answering ping',
                'next_step' => "say: the target does not answer ping; the last hop that answered is $last. For a speed problem the reverse run from the user's computer shows the other direction"] + $low;
        }
        if ($limited) {
            return ['class' => null, 'reading' => 'mid_path_rate_limit', 'evidence' => $limited,
                'negation' => 'no route verdict: the loss does not last to the target', 'disproved_by' => 'the later hops showing the loss too',
                'next_step' => 'no route problem in this direction; the reverse run from the user\'s computer covers the other one'] + $low;
        }
        return ['class' => null, 'reading' => 'clean', 'evidence' => ['no loss at any hop that answered'],
            'negation' => 'one direction only, and a short test misses loss that comes and goes', 'disproved_by' => 'loss in a longer run or in the reverse direction',
            'next_step' => 'for an intermittent problem: --count 1000 (about 17 minutes) and the user\'s reverse run'] + $low;
    }

    /** Ping has no hops: loss end to end says nothing about where it starts. */
    public static function readPing(array $e)
    {
        $base = ['class' => null, 'confidence' => 'no hops: mtr and traceroute could not run, so where loss starts is unknown'];
        if ($e['received'] === 0) {
            return $base + ['reading' => 'no_reply', 'evidence' => ['the target answered none of ' . $e['sent'] . ' pings'],
                'negation' => 'not proven loss: the target may not answer ping', 'disproved_by' => 'the target answering', 'next_step' => 'ask the user for the reverse run from their computer'];
        }
        if ($e['loss_pct'] > 0) {
            return $base + ['reading' => 'end_to_end_loss', 'evidence' => [$e['loss_pct'] . '% of ' . $e['sent'] . ' pings lost end to end'],
                'negation' => 'no route verdict: without hops the place it starts is unknown', 'disproved_by' => 'a longer run without loss',
                'next_step' => 'ask the user for the reverse run from their computer; a route verdict needs mtr'];
        }
        return $base + ['reading' => 'clean', 'evidence' => ['no loss end to end'], 'negation' => 'one direction only', 'disproved_by' => 'loss in a longer run',
            'next_step' => 'the reverse run from the user\'s computer covers the other direction'];
    }

    /** How the user runs the reverse direction (the wiki procedure, at least 1000 packets). No address is filled in. */
    public static function reverse()
    {
        return ['direction' => 'reverse: the user\'s computer to this server, run by the user',
            'windows' => 'install WinMTR, enter this server\'s hostname, Start, let it send at least 1000 packets (about 17 minutes), then copy the text and paste it here',
            'macos' => 'brew install mtr, then: sudo mtr -rwc 1000 <this server\'s hostname>, and paste the output here',
            'linux' => 'install mtr (Debian/Ubuntu: sudo apt install mtr), then: mtr -rwc 1000 <this server\'s hostname>, and paste the output here',
            'read_with' => 'the same rule: loss counts where it starts and lasts to the last hop'];
    }

    private static function traceroute(PerfProbe $probe, $target, $seam)
    {
        if (ToolProc::which('traceroute') === null) {
            $probe->skipped[] = ['check' => 'traceroute', 'reason' => 'traceroute is not installed'];
            return null;
        }
        $deadline = $seam !== null ? $seam : PerfLimits::TRACEROUTE_S;
        $r = $probe->measure(['traceroute', '-n', '-q', (string) self::TRACEROUTE_PROBES, '-w', '2', '-m', '30', $target], $deadline);
        $hops = $r['code'] === 0 ? self::parseTraceroute($r['out']) : [];
        if (!$hops) {
            $probe->skipped[] = ['check' => 'traceroute', 'reason' => self::failure($r, $deadline)];
            return null;
        }
        $probe->addCost('packets_sent', array_sum(array_column($hops, 'sent')));
        return ['method' => 'traceroute', 'hops' => $hops, 'io_class' => $r['io_class'], 'wall_s' => $r['wall_s']];
    }

    private static function ping(PerfProbe $probe, $target, $count, $seam)
    {
        if (ToolProc::which('ping') === null) {
            $probe->skipped[] = ['check' => 'ping', 'reason' => 'ping is not installed'];
            return null;
        }
        $deadline = $seam !== null ? $seam : $count + PerfLimits::PING_GRACE_S;
        $r = $probe->measure(['ping', '-n', '-c', (string) $count, $target], $deadline);
        $e = in_array($r['code'], [0, 1], true) ? self::parsePing($r['out']) : null; // iputils ping exits 1 when replies were missing
        if ($e === null) {
            $probe->skipped[] = ['check' => 'ping', 'reason' => self::failure($r, $deadline)];
            return null;
        }
        $probe->addCost('packets_sent', $e['sent']);
        return ['method' => 'ping', 'end_to_end' => $e, 'io_class' => $r['io_class'], 'wall_s' => $r['wall_s']];
    }

    /** Why a method gave nothing, without its output (which can hold addresses). */
    private static function failure(array $r, $deadline)
    {
        if ($r['timed_out']) {
            return "stopped at its $deadline s deadline";
        }
        return $r['code'] === 0 ? 'its output had no result in the expected form' : 'it could not run here (exit ' . $r['code'] . ')';
    }

    private static function stdev(array $v, $mean)
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += ($x - $mean) * ($x - $mean);
        }
        return count($v) > 1 ? sqrt($sum / count($v)) : 0.0;
    }
}
