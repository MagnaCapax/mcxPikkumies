<?php
/**
 * HookSpawn: runs one hook unit as its own `php` process with stdin and a deadline (ADR-0030), for hooks/orchestrator.php
 * and the interval units' worker (hooks/worker.php, ADR-0067). Moved unchanged from the orchestrator.
 */
class HookSpawn
{
    const OUTPUT_MAX = 1048576;          // a unit printing more than this is not giving a hook answer

    /** Run one unit: ['code' => int|null, 'stdout', 'stderr', 'timedOut' => bool, 'error' => string]. */
    public static function run($script, $stdin, $timeout)
    {
        $r = ['code' => null, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'error' => ''];
        // exec: the shell becomes php, so a timeout kills the hook itself.
        $p = @proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            $r['error'] = 'could not start';
            return $r;
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $deadline = microtime(true) + $timeout;
        $written = 0;
        $open = [0 => true, 1 => true, 2 => true];
        if ($stdin === '') {
            fclose($pipes[0]);
            $open[0] = false;
        }
        while ($open[1] || $open[2]) { // the hook closed both outputs: done, whatever it left unread
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $r['timedOut'] = true;
                break;
            }
            $read = [];
            foreach ([1, 2] as $i) {
                if ($open[$i]) {
                    $read[] = $pipes[$i];
                }
            }
            $write = $open[0] ? [$pipes[0]] : [];
            $except = null;
            if (@stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1000000)) === false) {
                usleep(10000); // interrupted by a signal; the deadline still holds
                continue;
            }
            if ($write) {
                $n = @fwrite($pipes[0], substr($stdin, $written, 65536));
                $written += $n ? $n : 0;
                if (!$n || $written >= strlen($stdin)) {
                    fclose($pipes[0]); // done, or the hook stopped reading (writable but nothing written: a broken pipe)
                    $open[0] = false;
                }
            }
            foreach ($read as $pipe) {
                $i = $pipe === $pipes[1] ? 1 : 2;
                $chunk = fread($pipe, 65536);
                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    fclose($pipe);
                    $open[$i] = false;
                    continue;
                }
                $key = $i === 1 ? 'stdout' : 'stderr';
                if (strlen($r[$key]) < self::OUTPUT_MAX) {
                    $r[$key] .= $chunk;
                }
            }
        }
        // Pipes closed: wait for the exit, still within the deadline.
        while (!$r['timedOut']) {
            $status = proc_get_status($p);
            if (!$status['running']) {
                $r['code'] = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $r['timedOut'] = true;
                break;
            }
            usleep(5000);
        }
        if ($r['timedOut']) {
            proc_terminate($p, 9);
        }
        foreach ($open as $i => $isOpen) {
            if ($isOpen) {
                fclose($pipes[$i]);
            }
        }
        proc_close($p);
        if (strlen($r['stdout']) >= self::OUTPUT_MAX) {
            $r['stdout'] = 'output too large'; // not a JSON object, so a failClosed unit blocks
        }
        return $r;
    }
}
