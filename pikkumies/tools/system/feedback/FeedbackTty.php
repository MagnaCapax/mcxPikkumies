<?php
/**
 * FeedbackTty (ADR-0097): the terminal side of the exit rating. Hermes may leave the terminal raw (a crash, a kill), and
 * the person may have typed into it before the line appears. prepare() saves the terminal's mode, throws away what is
 * already waiting (a stray "1" meant for Hermes must never answer a line nobody has read), and sets canonical mode
 * with echo; the closure it returns puts the saved mode back. readLine() reads byte by byte against one deadline and
 * ends on \r or \n, so a raw terminal, a half-typed answer or silence never holds exit past the timeout.
 * stty runs on our own terminal (its stdin), through ToolProc's quoting; without stty the read still keeps its deadline.
 */
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';

class FeedbackTty
{
    /** An answer longer than this is not one of 1 2 3 0 n: stop reading, it is dismissed. */
    const MAX = 64;

    /** Saves the mode, drains pending input, sets canonical mode; returns the closure that restores the saved mode. */
    public static function prepare($stream = null)
    {
        $stream = $stream ?: STDIN;
        $saved = self::stty(['-g'], $stream);
        $saved = preg_match('/^[0-9A-Fa-f:]{1,1024}$/D', $saved) ? $saved : '';
        if ($saved !== '') {
            self::stty(['-icanon', 'min', '0', 'time', '0'], $stream); // a half-typed line becomes readable, so it is drained too
        }
        self::drain($stream);
        if ($saved !== '') {
            self::stty(['sane'], $stream);
        }
        return function () use ($saved, $stream) {
            if ($saved !== '') {
                self::stty([$saved], $stream);
            }
        };
    }

    /** Reads and discards whatever input is already waiting, without blocking. */
    public static function drain($stream)
    {
        $blocking = stream_get_meta_data($stream)['blocked'];
        stream_set_blocking($stream, false);
        for ($i = 0; $i < 1024 && ($c = fread($stream, 4096)) !== false && $c !== ''; $i++) {
        }
        stream_set_blocking($stream, $blocking);
    }

    /** One answer typed within $timeout seconds, without its line end; false on silence, end of input or a deadline hit mid-line. */
    public static function readLine($stream, $timeout)
    {
        stream_set_read_buffer($stream, 0); // one byte at a time from the terminal: nothing waits in PHP's buffer unseen by select
        $end = microtime(true) + $timeout;
        $buf = '';
        while (strlen($buf) <= self::MAX && ($left = $end - microtime(true)) > 0) {
            $r = [$stream];
            $w = $e = null;
            if (@stream_select($r, $w, $e, (int) $left, (int) (($left - (int) $left) * 1000000)) !== 1) {
                return false;
            }
            $c = fread($stream, 1);
            if ($c === false || $c === '') {
                return false;
            }
            if ($c === "\r" || $c === "\n") {
                return $buf;
            }
            $buf .= $c;
        }
        return false;
    }

    /** stty with $args on $stream as its stdin; its stdout, '' when stty is missing or fails. */
    private static function stty(array $args, $stream)
    {
        $bin = ToolProc::which('stty');
        if ($bin === null) {
            return '';
        }
        $p = @proc_open('exec ' . ToolProc::line(array_merge([$bin], $args)), [0 => $stream, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            return '';
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        return proc_close($p) === 0 ? $out : '';
    }
}
