<?php
/**
 * MoveProc: MoveRunner's process and answer handling. proc() runs one argv through lib/ToolProc.php line() and `exec`
 * (PHP 7.3 proc_open takes a string), stdin from a string, and kills it after the timeout (a unit spawns nothing
 * long-lived); decode() reads a unit's JSON Lines answer and throws its `ok: false` as a CliError.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MoveProc
{
    const MAX_OUT = 8388608;

    public static function decode(array $r, $what)
    {
        $records = [];
        $status = null;
        foreach (explode("\n", (string) $r['stdout']) as $l) {
            $d = json_decode($l, true);
            if (!is_array($d)) {
                continue;
            }
            if (array_key_exists('ok', $d)) {
                $status = $d;
            } else {
                $records[] = $d;
            }
        }
        if ($status === null) {
            $err = trim(substr((string) $r['stderr'], -300));
            throw new CliError("$what: no answer (exit {$r['rc']})" . ($err !== '' ? ": $err" : ''), 'check the service with `services test <nick>` or the client with its own `stats`');
        }
        if ($status['ok'] !== true) {
            throw new CliError("$what: " . (isset($status['error']) ? $status['error'] : 'failed'), isset($status['hint']) ? (string) $status['hint'] : '');
        }
        return ['records' => $records, 'status' => $status, 'bytes' => strlen((string) $r['stdout'])];
    }

    /** One process from an argv; killed after $timeout seconds. */
    public static function proc(array $argv, $stdin, $timeout)
    {
        $setsid = ToolProc::which('setsid'); // its own process group, so a kill reaches anything it started
        $p = proc_open('exec ' . ToolProc::line($setsid !== null ? array_merge([$setsid, '-w'], $argv) : $argv), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            throw new CliError('could not start ' . PathName::base((string) $argv[1]), 'check the PHP installation');
        }
        fwrite($pipes[0], (string) $stdin);
        fclose($pipes[0]);
        $out = ['', ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $s) {
            stream_set_blocking($s, false);
        }
        $end = microtime(true) + $timeout;
        while ($open && microtime(true) < $end) {
            $read = array_values($open);
            $w = $e = null;
            if (@stream_select($read, $w, $e, 1) === false) {
                break;
            }
            foreach ($open as $k => $s) {
                $c = fread($s, 65536);
                if (($c === '' || $c === false) && feof($s)) {
                    unset($open[$k]);
                } elseif (is_string($c) && strlen($out[$k - 1]) < self::MAX_OUT) {
                    $out[$k - 1] .= $c;
                }
            }
        }
        if ($open) {
            $pid = (int) proc_get_status($p)['pid'];
            if ($setsid !== null && $pid > 1 && function_exists('posix_kill')) {
                posix_kill(-$pid, 9); // the whole group: the unit and any child it left
            }
            proc_terminate($p, 9);
        }
        foreach ($pipes as $s) {
            if (is_resource($s)) {
                fclose($s);
            }
        }
        $rc = proc_close($p);
        return ['rc' => $open ? 124 : $rc, 'stdout' => $out[0], 'stderr' => $open ? "no answer within {$timeout}s" : $out[1]];
    }
}
