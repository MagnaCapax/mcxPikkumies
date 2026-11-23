<?php
/**
 * GatewayWait: a one-shot run that Hermes ended with "Gateway starting: deadline" (GH #12) did nothing: Hermes' client
 * waits a fixed 60 s for a cold gateway (hermes_cli/gateway_runtime.py DEFAULT_ENSURE_TIMEOUT), and a cold boot on a busy
 * disk takes far longer. Hermes never starts a second gateway while one is starting, so the same run, started again,
 * attaches to the boot in progress. This waits for that boot, and HaltRun then runs the command again (ADR-0113).
 *
 * The wait ends on what Hermes' files and socket say, never on a timer of ours. It is over ("ready") when the gateway's
 * control socket (gateway.sock, or the path in gateway.sock.path, gateway/control_socket.py) answers `identify` with a
 * result state other than "starting" (any other state is for Hermes' own client to act on when the command runs again), or ("gone") when nothing is starting any more.
 * Something is starting while gateway.lock is held by another process (the flock Hermes takes for the gateway's whole
 * life, gateway/status.py _try_acquire_file_lock), or while this account has a `gateway run` process for this HERMES_HOME
 * that has not taken the lock yet (the client spawns the gateway, and it imports before it locks). A wedged boot ends by
 * Hermes' own startup watchdog, which ends the process and so the wait.
 */
require_once __DIR__ . '/HermesWait.php';
require_once __DIR__ . '/StreamProgress.php';

class GatewayWait
{
    /** The error text of the failure this handles, as Hermes reports it (hermes_cli/gateway_client.py). */
    const ERROR = 'Gateway starting: deadline';
    /** The second failure shape (GH #12 finding 4): the client's WebSocket open timed out after ensure said the gateway could be attached. */
    const CONNECT = 'Gateway connection failed; no local fallback';
    /** Seconds between checks. */
    const POLL = 2;
    /** Seconds between progress lines. */
    const EVERY = 60;
    /** Seconds one identify probe may take to connect and answer: the 2 s Hermes' own discovery allows it (discover_gateway_endpoint). */
    const PROBE = 2;

    /** Whether a finished run is one of the two gateway failures with nothing done: no tokens spent and no tool called. */
    public static function triggered($error, $tokens, $tools)
    {
        return in_array(trim((string) $error), [self::ERROR, self::CONNECT], true) && (int) $tokens === 0 && (int) $tools === 0;
    }

    /**
     * 'ready' or 'gone'. $write($line) prints one progress line (at once, then every EVERY seconds). $seams, for tests:
     * state($home) (identify's state, '' for no answer), held($lockFile), booting($home), sleep($seconds), now(); and stop(),
     * true when the caller has been told to stop (an Eternal's SIGTERM or SIGHUP, ADR-0116): the wait then ends as 'gone'.
     */
    public static function run($home, callable $write, array $seams = [])
    {
        $state = isset($seams['state']) ? $seams['state'] : ['GatewayWait', 'state'];
        $held = isset($seams['held']) ? $seams['held'] : ['GatewayWait', 'lockHeld'];
        $booting = isset($seams['booting']) ? $seams['booting'] : ['GatewayWait', 'bootingProcess'];
        $sleep = isset($seams['sleep']) ? $seams['sleep'] : 'sleep';
        $now = isset($seams['now']) ? $seams['now'] : 'time';
        $stop = isset($seams['stop']) ? $seams['stop'] : function () { return false; };
        $start = call_user_func($now);
        $shown = null;
        while (true) {
            if (call_user_func($stop)) {
                return 'gone';
            }
            clearstatcache();
            $s = (string) call_user_func($state, $home);
            if ($s !== '' && $s !== 'starting') {
                return 'ready';
            }
            if (!call_user_func($held, $home . '/gateway.lock') && !call_user_func($booting, $home)) {
                $s = (string) call_user_func($state, $home); // it may have answered between the two looks
                return $s !== '' && $s !== 'starting' ? 'ready' : 'gone';
            }
            $elapsed = call_user_func($now) - $start;
            if ($shown === null || $elapsed - $shown >= self::EVERY) {
                $last = self::logLine($home);
                call_user_func($write, 'Waiting for the agent gateway to finish starting (' . self::elapsed($elapsed) . ')' . ($last !== '' ? ' | ' . $last : ''));
                $shown = $elapsed;
            }
            call_user_func($sleep, self::POLL);
        }
    }

    /** True when another process holds the flock on $lockFile; the probe lock is let go at once. */
    public static function lockHeld($lockFile)
    {
        $h = @fopen($lockFile, 'r');
        if ($h === false) {
            return false;
        }
        $free = @flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return !$free;
    }

    /** The `state` the gateway at $home answers to `identify` on its control socket, '' when it does not answer. */
    public static function state($home)
    {
        $path = $home . '/gateway.sock';
        if (!file_exists($path)) {
            $path = trim((string) @file_get_contents($home . '/gateway.sock.path')); // the pointer, for a long home path
            if ($path === '' || $path[0] !== '/') {
                return '';
            }
        }
        $s = @stream_socket_client('unix://' . $path, $errno, $errstr, self::PROBE);
        if ($s === false) {
            return '';
        }
        stream_set_timeout($s, self::PROBE);
        fwrite($s, '{"protocol":1,"verb":"identify","id":1}' . "\n");
        $line = fgets($s, 524289);
        fclose($s);
        $answer = is_string($line) ? json_decode($line, true) : null;
        // The answer is {"ok":true,"protocol":1,"id":1,"result":{...,"state":...}}: the state is inside result (hermes_cli/
        // gateway_runtime_discovery.py _identify_response, gateway/run_bootstrap.py _identify_runtime). A top-level state is no answer.
        $result = is_array($answer) && isset($answer['ok'], $answer['result']) && $answer['ok'] === true && is_array($answer['result']) ? $answer['result'] : [];
        return isset($result['state']) && is_string($result['state']) ? $result['state'] : '';
    }

    /** Whether this account has a `gateway run` process whose HERMES_HOME is $home (found in /proc; own processes only). */
    public static function bootingProcess($home, $proc = '/proc')
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $real = realpath($home);
        foreach ((array) glob("$proc/[0-9]*", GLOB_ONLYDIR) as $dir) {
            if (@fileowner($dir) !== $uid) {
                continue;
            }
            $args = explode("\0", (string) @file_get_contents("$dir/cmdline"));
            $at = array_search('gateway', $args, true);
            if ($at === false || !isset($args[$at + 1]) || $args[$at + 1] !== 'run') {
                continue;
            }
            foreach (explode("\0", (string) @file_get_contents("$dir/environ")) as $var) {
                // The spawned gateway gets its home resolved (hermes_cli/gateway_runtime_start.py), so a symlinked home differs in spelling.
                if (strncmp($var, 'HERMES_HOME=', 12) === 0 && (substr($var, 12) === $home || ($real !== false && realpath(substr($var, 12)) === $real))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Hermes' text when the connection to the gateway ended in the middle of a turn (hermes_cli/gateway_client.py, GatewayClient._read). */
    const LOST = 'Gateway disconnected; turn outcome is unknown';

    /** Whether a run's error is that text. The run may have done work: it is never run again (ADR-0122). */
    public static function lostTurn($error)
    {
        return strncmp(trim((string) $error), self::LOST, strlen(self::LOST)) === 0;
    }

    /** Where agent.log stands now, [size, inode]: the gateway's close line of a run is looked for after it. */
    public static function logMark($home)
    {
        clearstatcache(true, $home . '/logs/agent.log'); // stat() is cached: the second run of a session must not see the first one's size
        $st = @stat($home . '/logs/agent.log');
        return is_array($st) ? [(int) $st['size'], (int) $st['ino']] : [0, 0];
    }

    /**
     * The lines that say what is known after a lost turn, for stderr: that the question was not run again, the session ID
     * and how to look at it, whether a gateway answers now, and the gateway's own `ws closed` line from agent.log (the
     * tui_gateway logger is not in gateway.log, Hermes hermes_logging.py) when exactly one was written since $mark. The line
     * carries no session tag, so with two or more, or none, nothing is attributed. $seams as in run(): state, held, booting.
     */
    public static function lostReport($home, $sessionId, array $mark, array $seams = [])
    {
        $lines = ['The connection to the agent gateway ended while the question was running, and Hermes does not say which side ended it. '
            . 'The question was not asked again: the work may be finished, still running or cut short, and asking again pays for it again.'];
        $lines[] = $sessionId !== ''
            ? 'Session ' . $sessionId . ': look at what it holds with `pikkumies --resume ' . $sessionId . '` and send no new text.'
            : 'No session ID was printed.';
        if ($home === '') {
            $lines[] = 'Gateway state and log: not determined (the agent home is not known here).';
            return $lines;
        }
        try {
            $lines[] = 'Gateway now: ' . self::stateWords($home, $seams) . '.';
            $lines[] = 'Gateway log: ' . self::closeWords($home, $mark) . '.';
        } catch (\Throwable $e) {
            $lines[] = 'Gateway state and log: not determined.';
        }
        return $lines;
    }

    private static function stateWords($home, array $seams)
    {
        $state = isset($seams['state']) ? $seams['state'] : ['GatewayWait', 'state'];
        $held = isset($seams['held']) ? $seams['held'] : ['GatewayWait', 'lockHeld'];
        $booting = isset($seams['booting']) ? $seams['booting'] : ['GatewayWait', 'bootingProcess'];
        $s = (string) call_user_func($state, $home);
        if ($s !== '') {
            return 'answering (state ' . StreamProgress::safe($s, 40) . ')';
        }
        return call_user_func($held, $home . '/gateway.lock') || call_user_func($booting, $home)
            ? 'a gateway process exists and does not answer' : 'no gateway is running';
    }

    private static function closeWords($home, array $mark)
    {
        $log = $home . '/logs/agent.log';
        clearstatcache(true, $log);
        $st = @stat($log);
        if ($st === false || ((int) $st['ino'] !== $mark[1] && $mark[1] !== 0) || (int) $st['size'] < $mark[0]) {
            return $st === false ? 'not determined (no agent.log)' : 'not determined (agent.log was rotated during the run)';
        }
        $h = @fopen($log, 'rb');
        if ($h === false || fseek($h, $mark[0]) !== 0) {
            return 'not determined (agent.log cannot be read)';
        }
        $closes = [];
        $inLong = false;
        while (($l = fgets($h, 8192)) !== false) {
            $whole = substr($l, -1) === "\n";
            if (!$inLong && strpos($l, ' ws closed peer=') !== false) {
                $closes[] = $l;
            }
            $inLong = !$whole; // the rest of an over-long line is not a line of its own
        }
        fclose($h);
        if (count($closes) === 1) {
            $line = StreamProgress::safe(trim($closes[0]), 200);
            return $line === null ? 'one close line, not shown' : $line;
        }
        return count($closes) === 0
            ? 'no close line since the run started (a gateway that was killed writes none, and the line may not be written yet)'
            : count($closes) . ' close lines since the run started; which one is this run\'s is not determined';
    }

    /** The gateway log's last line, redacted and cut; '' for none. */
    private static function logLine($home)
    {
        $line = HermesWait::lastLine($home . '/logs/gateway.log');
        $safe = $line === '' ? '' : StreamProgress::safe($line, 80);
        return $safe === null ? '' : $safe;
    }

    private static function elapsed($seconds)
    {
        $seconds = max(0, (int) $seconds);
        return ($seconds >= 60 ? intdiv($seconds, 60) . 'm' : '') . ($seconds % 60) . 's';
    }
}
