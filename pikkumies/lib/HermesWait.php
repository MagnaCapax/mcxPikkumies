<?php
/**
 * HermesWait: a session started where the agent runtime is not built yet (a preinstalled PMSS account, or right after
 * `pikkumies install`) waits for the build in the foreground, with one progress line, and then starts; it never tells
 * the person to come back later (ADR-0033 addendum 2026-10-19). bin/pikkumies starts the build first with
 * HermesInstall::ensure(); a build that cannot start, or one that ends without a runtime, stops the wait with the reason.
 */
class HermesWait
{
    /** Seconds a build just started in the background may take to take its lock before it counts as not running. */
    const START_GRACE = 20;
    /** Seconds between checks. */
    const POLL = 2;
    /** Seconds between progress lines when the output is not a terminal (a terminal redraws one line every check). */
    const PLAIN_EVERY = 60;
    /** A build still holding its lock after this long is taken as hung (a few minutes is usual): the wait stops with the log's last line. */
    const MAX_WAIT = 1800;

    /**
     * '' once the runtime is ready, else why it is not. $status: HermesInstall::ensure()'s line; only a build started or
     * running ("... in the background ...") is waited for. $ready() and $building(): HermesInstall::bin() !== '' and
     * HermesInstall::building(). $write($text) prints; $log: the build log, whose last line the progress shows; $tty:
     * redraw one line in place. $sleep($seconds) and $now() are test seams (sleep and time by default).
     */
    public static function run($status, callable $ready, callable $building, callable $write, $log, $tty, callable $sleep = null, callable $now = null)
    {
        $sleep = $sleep ?: 'sleep';
        $now = $now ?: 'time';
        if (call_user_func($ready)) {
            return '';
        }
        if (strpos((string) $status, 'in the background') === false) {
            return (string) $status; // nothing is building (no room, no network, a left-over tree, skipped): nothing to wait for
        }
        // Clears the progress line and gives the reason with the log's last line.
        $stop = function ($why) use ($write, $tty, $log) {
            call_user_func($write, $tty ? "\r\033[K" : '');
            $last = HermesWait::lastLine($log);
            return $why . ($last !== '' ? ": $last" : '') . ' (log: ' . Config::shown($log) . ')';
        };
        $start = call_user_func($now);
        $seen = false;
        $shown = null;
        while (true) {
            clearstatcache();
            if (call_user_func($ready)) {
                call_user_func($write, ($tty ? "\r\033[K" : '') . 'The agent runtime is ready (' . self::elapsed(call_user_func($now) - $start) . ").\n");
                return '';
            }
            $running = call_user_func($building);
            $seen = $seen || $running;
            $elapsed = call_user_func($now) - $start;
            if (!$running && ($seen || $elapsed >= self::START_GRACE)) {
                clearstatcache();
                if (call_user_func($ready)) { // the build wrote its marker and let go of its lock between the two checks
                    continue;
                }
                return $stop('the build stopped without finishing; `pikkumies install` builds again');
            }
            if ($elapsed >= self::MAX_WAIT) {
                return $stop('the build is still running after ' . self::elapsed($elapsed) . ', longer than a build takes');
            }
            if ($tty || $shown === null || $elapsed - $shown >= self::PLAIN_EVERY) {
                $line = 'Setting up the agent runtime, first start only (usually a few minutes): ' . self::elapsed($elapsed);
                $last = self::lastLine($log);
                $line .= $last !== '' ? ' | ' . $last : '';
                call_user_func($write, $tty ? "\r\033[K" . self::cut($line, 150) : $line . "\n");
                $shown = $elapsed;
            }
            call_user_func($sleep, self::POLL);
        }
    }

    /** The last non-empty line of the log's tail (progress bars end lines with \r), with control characters removed. */
    public static function lastLine($log)
    {
        $size = is_file($log) ? (int) @filesize($log) : 0;
        $tail = $size > 0 ? (string) @file_get_contents($log, false, null, max(0, $size - 4096)) : '';
        // An escape sequence, then any other control character: the log is the build's output, and only plain text may reach the terminal.
        $tail = preg_replace(['/\x1b(\[[0-9;?]*[ -\/]*[@-~]|\][^\x07\x1b]*(\x07|\x1b\\\\)?|.)/s', '/[^\S\r\n]+/', '/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/'], ['', ' ', ''], $tail);
        foreach (array_reverse(preg_split('/[\r\n]+/', (string) $tail)) as $l) {
            if (trim($l) !== '') {
                return self::cut(trim($l), 80);
            }
        }
        return '';
    }

    private static function cut($text, $max)
    {
        return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
    }

    private static function elapsed($seconds)
    {
        $seconds = max(0, (int) $seconds);
        return ($seconds >= 60 ? intdiv($seconds, 60) . 'm' : '') . ($seconds % 60) . 's';
    }
}
