<?php
/**
 * Asker: runs commands in a sandbox (ADR-0039): one question through `pikkumies run chat --format stream-json`
 * with a timeout, the stream parsed into a record for Checks, and every process left behind killed afterwards.
 */
class Asker
{
    /**
     * Run $argv (strings) in HOME with stdout and stderr to files; killed after $timeout s. Afterwards every process it
     * left behind is killed too, unless $kill is false (the install, whose runtime build goes on in the background).
     * Returns [exit code, timed out].
     */
    public static function run(Sandbox $box, array $argv, $overlay, $outFile, $errFile, $timeout, $kill = true)
    {
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $argv));
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, $box->home, Targets::env($box, $overlay));
        if (!is_resource($p)) {
            return [-1, false];
        }
        $end = microtime(true) + $timeout;
        while (($st = proc_get_status($p)) && $st['running'] && microtime(true) < $end) {
            usleep(200000);
        }
        $timedOut = $st && $st['running'];
        if ($timedOut) {
            proc_terminate($p, 9);
        }
        if ($kill || $timedOut) {
            self::killStray($box->home);
        }
        proc_close($p);
        return [$timedOut || !$st ? -1 : (int) $st['exitcode'], $timedOut];
    }

    /** The pikkumies command of the sandbox with $args. */
    public static function pikkumies(Sandbox $box, array $args)
    {
        return array_merge([PHP_BINARY, $box->root . '/checkout/pikkumies/bin/pikkumies'], $args);
    }

    /** The memory tool's refresh for the family the overlay forces (synchronous; the install's background fill was killed). */
    public static function refresh(Sandbox $box, $overlay)
    {
        $tool = $box->root . '/checkout/pikkumies/tools/memory/pikkumies/pikkumies.php';
        return self::run($box, [PHP_BINARY, $tool, 'refresh', '--limit', '0'], $overlay, $box->root . '/refresh.out', $box->root . '/refresh.err', 3600);
    }

    /** Copy a fixture directory into HOME. */
    public static function setup(Sandbox $box, $dir)
    {
        exec('cp -R ' . escapeshellarg(rtrim($dir, '/') . '/.') . ' ' . escapeshellarg($box->home . '/'), $o, $code);
        return $code === 0;
    }

    /** Ask one question: parseStream()'s record plus 'seconds'; 'error' also says when it timed out or Hermes failed. */
    public static function ask(Sandbox $box, $question, $overlay, $maxTurns, $timeout)
    {
        $start = microtime(true);
        $argv = self::pikkumies($box, array_merge(['run', 'chat', '--query=' . $question, '--format', 'stream-json'], $maxTurns !== '' ? ['--max-turns', $maxTurns] : []));
        list(, $timedOut) = self::run($box, $argv, $overlay, $box->root . '/raw.jsonl', $box->root . '/raw.err', $timeout);
        $r = self::parseStream($box->root . '/raw.jsonl');
        $r['seconds'] = (int) round(microtime(true) - $start);
        $r['error'] = $timedOut ? "timed out after $timeout s" : ($r['error'] !== '' ? $r['error'] : ($r['exit'] ? 'Hermes exit code ' . $r['exit'] : ''));
        if (strpos($r['error'], 'no stream-json from Hermes') === 0) {
            // Hermes' own stderr says WHY nothing was on stdout (e.g. "unrecognized arguments"); without it a QA
            // report only ever shows "other" (QaVerdict.php), never the cause.
            $r['error'] .= self::stderrTail($box->root . '/raw.err');
        }
        return $r;
    }

    /** The last ~400 bytes of $path (Hermes' stderr), with anything key-shaped masked; '' when empty or missing. */
    public static function stderrTail($path, $take = 400)
    {
        if (!is_file($path)) {
            return '';
        }
        $size = filesize($path);
        $raw = trim((string) ($size > $take ? @file_get_contents($path, false, null, $size - $take) : @file_get_contents($path)));
        if ($raw === '') {
            return '';
        }
        $masked = preg_replace('/\bBearer\s+\S+/i', 'Bearer [masked]', $raw);
        $masked = preg_replace('/\b(?:vsk|sk)-[A-Za-z0-9._-]+/i', '[masked-key]', $masked);
        return '; stderr: ' . $masked;
    }

    /** Kill every process of this account whose environment has HOME=$home (a sandbox home); the count killed. */
    public static function killStray($home)
    {
        $needle = "\0HOME=" . $home . "\0";
        $pids = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $d) {
            $pid = (int) basename($d);
            if ($pid !== getmypid() && @fileowner($d) === posix_geteuid() && strpos("\0" . @file_get_contents("$d/environ") . "\0", $needle) !== false) {
                posix_kill($pid, 15);
                $pids[] = $pid;
            }
        }
        if ($pids) {
            usleep(500000);
            foreach ($pids as $pid) {
                @posix_kill($pid, 9);
            }
        }
        return count($pids);
    }

    /**
     * Hermes' stream-json output -> ['tools' => [['name', 'input']], 'text', 'tokens', 'exit', 'error']. The first line
     * must be system/init; otherwise this Hermes (or pikkumies, refusing to start it) gave no stream.
     */
    public static function parseStream($file)
    {
        $r = ['tools' => [], 'text' => '', 'tokens' => 0, 'exit' => null, 'error' => ''];
        $lines = array_values(array_filter(array_map('trim', is_file($file) ? file($file) : []), 'strlen'));
        $first = $lines ? json_decode($lines[0], true) : null;
        if (!is_array($first) || !isset($first['type'], $first['subtype']) || $first['type'] !== 'system' || $first['subtype'] !== 'init') {
            $r['error'] = 'no stream-json from Hermes; first line: ' . ($lines ? substr($lines[0], 0, 200) : '(none)');
            return $r;
        }
        // A halt-guard resume adds a whole init..result stream per extra turn (HaltRun passes --format to each). Every
        // turn's answer is graded together and every turn's tokens count (each turn is its own Hermes run, whose
        // result reports that run's total_tokens); exit and error come from the last turn.
        $deltas = '';
        $texts = [];
        $result = null;
        foreach ($lines as $line) {
            $j = json_decode($line, true);
            $type = is_array($j) && isset($j['type']) ? $j['type'] : '';
            if ($type === 'tool_use') {
                $r['tools'][] = ['name' => isset($j['name']) ? (string) $j['name'] : 'unknown', 'input' => isset($j['input']) ? $j['input'] : null];
            } elseif ($type === 'text' && isset($j['text'])) {
                $deltas .= $j['text'];
            } elseif ($type === 'result') {
                $result = $j;
                $texts[] = isset($j['text']) && $j['text'] !== '' ? (string) $j['text'] : $deltas;
                $r['tokens'] += isset($j['tokens']['total']) ? (int) $j['tokens']['total'] : 0;
                $deltas = '';
            }
        }
        if ($deltas !== '') {
            $texts[] = $deltas;   // text after the last result: a turn that was cut off
        }
        $r['text'] = implode("\n\n", $texts);
        if ($result === null || $deltas !== '') {
            $r['error'] = 'no result record: the run was cut off';
            return $r;
        }
        $r['exit'] = isset($result['exit_code']) ? (int) $result['exit_code'] : null;
        $r['error'] = isset($result['error']) ? (string) $result['error'] : '';
        return $r;
    }

    /** Hermes' tag or commit in the sandbox, '' before it is built. */
    public static function hermesVersion(Sandbox $box)
    {
        exec('git -C ' . escapeshellarg($box->root . '/checkout/pikkumies/hermes') . ' describe --tags --always 2>/dev/null', $o, $code);
        return $code === 0 && $o ? trim($o[0]) : '';
    }
}
