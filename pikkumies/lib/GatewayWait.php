<?php
/**
 * GatewayWait: a one-shot run that Hermes ended with "Gateway starting: deadline" (GH #12) did nothing: Hermes' client
 * waits a fixed 60 s for a cold gateway (hermes_cli/gateway_runtime.py DEFAULT_ENSURE_TIMEOUT), and a cold boot on a busy
 * disk takes far longer. Hermes never starts a second gateway while one is starting, so the same run, started again,
 * attaches to the boot in progress. This waits for that boot, and HaltRun then runs the command again (ADR-0113).
 *
 * The wait ends on what Hermes' files and socket say, never on a timer of ours. It is over ("ready") when the gateway's
 * control socket (gateway.sock, or the path in gateway.sock.path, gateway/control_socket.py) answers `identify` with a
 * state other than "starting", which is how Hermes' own client reads it, or ("gone") when nothing is starting any more.
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
    /** Seconds between checks. */
    const POLL = 2;
    /** Seconds between progress lines. */
    const EVERY = 60;
    /** Seconds one identify probe may take to connect and answer: the 2 s Hermes' own discovery allows it (discover_gateway_endpoint). */
    const PROBE = 2;

    /** Whether a finished run is the fixed-deadline failure with nothing done: no tokens spent and no tool called. */
    public static function triggered($error, $tokens, $tools)
    {
        return trim((string) $error) === self::ERROR && (int) $tokens === 0 && (int) $tools === 0;
    }

    /**
     * 'ready' or 'gone'. $write($line) prints one progress line (at once, then every EVERY seconds). $seams, for tests:
     * state($home) (identify's state, '' for no answer), held($lockFile), booting($home), sleep($seconds), now().
     */
    public static function run($home, callable $write, array $seams = [])
    {
        $state = isset($seams['state']) ? $seams['state'] : ['GatewayWait', 'state'];
        $held = isset($seams['held']) ? $seams['held'] : ['GatewayWait', 'lockHeld'];
        $booting = isset($seams['booting']) ? $seams['booting'] : ['GatewayWait', 'bootingProcess'];
        $sleep = isset($seams['sleep']) ? $seams['sleep'] : 'sleep';
        $now = isset($seams['now']) ? $seams['now'] : 'time';
        $start = call_user_func($now);
        $shown = null;
        while (true) {
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
        return is_array($answer) && isset($answer['state']) && is_string($answer['state']) ? $answer['state'] : '';
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
