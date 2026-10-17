<?php
/**
 * PerfSessions: the user's own transfer sessions (ADR-0035, privacy rule P2). The only sockets asked for are those
 * to the address the user's own SSH connection comes from (SSH_CONNECTION), filtered in the kernel by `ss` itself
 * (`ss -tniH dst <address>`); with no SSH connection nothing is asked. Each row is parsed here and only figures are
 * kept: the service (by the server's own port), round trip, how much of the busy time the receiver's window was the
 * limit, retransmits, delivery rate and the rate one window per round trip allows. Raw rows, addresses and the
 * client's port never leave this class (the address only with check --share --with-ip, from the router).
 * When the kernel shows no socket at all to that address, not even the SSH connection itself, the host hides
 * connections: "not visible here".
 */
class PerfSessions
{
    const SHOWN = 10;   // sessions listed, busiest first
    const PORT_SERVICE = [22 => 'ssh', 21 => 'ftp', 990 => 'ftp', 80 => 'http', 443 => 'http'];

    /** The client address of the SSH connection, or null ($conn: the SSH_CONNECTION text; null reads the environment). */
    public static function clientIp($conn = null)
    {
        $conn = $conn === null ? getenv('SSH_CONNECTION') : $conn;
        $f = preg_split('/\s+/', trim((string) $conn));
        return count($f) === 4 && filter_var($f[0], FILTER_VALIDATE_IP) !== false ? $f[0] : null;
    }

    /** The ss argv for $ip: established TCP, info, no header, filtered in the kernel to that destination. */
    public static function argv($ip)
    {
        return ['ss', '-tniH', 'dst', strpos($ip, ':') !== false ? "[$ip]" : $ip];
    }

    /**
     * ['visible' => true|false|null, 'reason' => why not checked or not visible, 'sessions' => [...], 'active' => n].
     * visible null: not checked (no SSH connection, ss missing or failed).
     */
    public static function read(PerfProbe $probe, $conn = null)
    {
        $ip = self::clientIp($conn);
        if ($ip === null) {
            return ['visible' => null, 'reason' => 'no SSH connection to take your address from; sessions were not checked', 'sessions' => [], 'active' => null];
        }
        if (ToolProc::which('ss') === null) {
            return ['visible' => null, 'reason' => 'ss (iproute2) is not installed here', 'sessions' => [], 'active' => null];
        }
        $r = $probe->scan(self::argv($ip), PerfLimits::CHECK_WINDOW_S);
        if ($r['code'] !== 0) {
            return ['visible' => null, 'reason' => 'ss did not answer' . ($r['timed_out'] ? ' in time' : ''), 'sessions' => [], 'active' => null];
        }
        $rows = self::parse($r['out']);
        if (!$rows) {
            return ['visible' => false, 'reason' => 'not visible here (host privacy setting)', 'sessions' => [], 'active' => null];
        }
        usort($rows, function ($x, $y) {
            return ($y['delivery_mbit'] ?: 0) <=> ($x['delivery_mbit'] ?: 0);
        });
        $active = count(array_filter($rows, function ($s) {
            return $s['busy_ms'] !== null && $s['busy_ms'] > 0;
        }));
        return ['visible' => true, 'reason' => null, 'sessions' => array_slice($rows, 0, self::SHOWN), 'active' => $active, 'total' => count($rows)];
    }

    /** `ss -tniH` output -> one array of figures per socket; nothing of the raw text is kept. */
    public static function parse($out)
    {
        $rows = [];
        $cur = null;
        foreach (explode("\n", (string) $out) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] !== ' ' && $line[0] !== "\t") {
                if ($cur !== null) {
                    $rows[] = $cur;
                }
                $f = preg_split('/\s+/', trim($line));
                $cur = null;
                if (count($f) >= 5 && preg_match('/:(\d+)$/', $f[3], $lp)) {
                    $port = (int) $lp[1];
                    $cur = ['service' => isset(self::PORT_SERVICE[$port]) ? self::PORT_SERVICE[$port] : 'other', 'state' => strtolower(preg_replace('/[^A-Za-z-]/', '', $f[0])),
                        'rtt_ms' => null, 'rwnd_limited_pct' => null, 'busy_ms' => null, 'retrans' => null, 'delivery_mbit' => null,
                        'window_bound_mbit' => null, 'direction' => null];
                }
                continue;
            }
            if ($cur !== null) {
                $cur = self::info($cur, $line);
            }
        }
        if ($cur !== null) {
            $rows[] = $cur;
        }
        return $rows;
    }

    private static function info(array $s, $line)
    {
        if (preg_match('/\brtt:([0-9.]+)\//', $line, $m)) {
            $s['rtt_ms'] = (float) $m[1];
        }
        if (preg_match('/\brwnd_limited:\d+ms\(([0-9.]+)%\)/', $line, $m)) {
            $s['rwnd_limited_pct'] = (float) $m[1];
        }
        if (preg_match('/\bbusy:(\d+)ms/', $line, $m)) {
            $s['busy_ms'] = (int) $m[1];
        }
        if (preg_match('/\bretrans:\d+\/(\d+)/', $line, $m)) {
            $s['retrans'] = (int) $m[1];
        } elseif ($s['retrans'] === null && preg_match('/\bbytes_sent:\d+/', $line)) {
            $s['retrans'] = 0; // ss prints retrans only once there was one; the session's counters are here, so none
        }
        if (preg_match('/\bdelivery_rate ([0-9.]+)([KMG]?)bps/', $line, $m)) {
            $s['delivery_mbit'] = round(self::bits($m[1], $m[2]) / 1e6, 2);
        }
        $cwnd = preg_match('/\bcwnd:(\d+)/', $line, $m) ? (float) $m[1] : null;
        $mss = preg_match('/\bmss:(\d+)/', $line, $m) ? (float) $m[1] : null;
        $sndWnd = preg_match('/\bsnd_wnd:(\d+)/', $line, $m) ? (float) $m[1] : null;
        $window = $cwnd !== null && $mss !== null ? $cwnd * $mss : null;
        if ($sndWnd !== null) {
            $window = $window === null ? $sndWnd : min($window, $sndWnd);
        }
        if ($window !== null && $s['rtt_ms'] !== null && $s['rtt_ms'] > 0) {
            $s['window_bound_mbit'] = round($window * 8 / ($s['rtt_ms'] / 1000) / 1e6, 2);
        }
        $sent = preg_match('/\bbytes_acked:(\d+)/', $line, $m) ? (float) $m[1] : null;
        $recv = preg_match('/\bbytes_received:(\d+)/', $line, $m) ? (float) $m[1] : null;
        if ($sent !== null || $recv !== null) {
            $s['direction'] = (float) $sent >= (float) $recv ? 'from server' : 'to server';
        }
        return $s;
    }

    private static function bits($n, $unit)
    {
        $mul = ['' => 1, 'K' => 1e3, 'M' => 1e6, 'G' => 1e9];
        return (float) $n * $mul[$unit];
    }
}
