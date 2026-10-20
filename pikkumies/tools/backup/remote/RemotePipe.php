<?php
/**
 * RemotePipe: runs `a | b | c` as separate processes, each from an argv (never a shell string built from user text), so
 * every stage's own exit code is known (POSIX sh has no PIPESTATUS). Stage i's stdout feeds stage i+1 directly, except
 * after stage $count, whose output this process copies and counts: the bytes handed to ssh on a run, the bytes received
 * on a restore. stderr of each stage goes to a temporary file and only its tail is kept.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class RemotePipe
{
    /**
     * $stages: argv lists. $in: the first stage's stdin file; $out: the last stage's stdout file. $env adds variables
     * (null removes one); $cwd: the working directory. Returns ['codes' => [exit code per stage], 'bytes' => counted bytes, 'err' => [stderr tail per stage]].
     */
    public static function run(array $stages, $in, $out, $count = -1, array $env = [], $cwd = null)
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            if ($v === null) {
                unset($vars[$k]);
            } else {
                $vars[$k] = (string) $v;
            }
        }
        $stages = array_values($stages);
        $last = count($stages) - 1;
        $procs = $pipes = $errFiles = [];
        $prev = null;
        foreach ($stages as $i => $argv) {
            $errFiles[$i] = (string) tempnam(sys_get_temp_dir(), 'pk-remote-err');
            $io = [0 => $i === 0 ? ['file', $in, 'r'] : ($i - 1 === $count ? ['pipe', 'r'] : $prev),
                1 => $i === $last ? ['file', $out, 'w'] : ['pipe', 'w'], 2 => ['file', $errFiles[$i], 'w']];
            $p = proc_open('exec ' . ToolProc::line($argv), $io, $pipes[$i], $cwd, $vars);
            if (is_resource($prev)) {
                fclose($prev); // the child holds its own copy; ours would keep the pipe open after the writer ends
            }
            if (!is_resource($p)) {
                self::finish($procs, $pipes);
                throw new CliError('could not start ' . PathName::base((string) $argv[0]), 'check that it is installed and runnable');
            }
            $procs[$i] = $p;
            $prev = isset($pipes[$i][1]) && $i !== $count ? $pipes[$i][1] : null;
        }
        $bytes = $count >= 0 && $count < $last ? self::copy($pipes[$count][1], $pipes[$count + 1][0]) : 0;
        $codes = self::finish($procs, $pipes);
        $err = [];
        foreach ($errFiles as $i => $f) {
            $err[$i] = trim((string) @file_get_contents($f, false, null, max(0, (int) @filesize($f) - 2000)));
            @unlink($f);
        }
        return ['codes' => $codes, 'bytes' => $bytes, 'err' => $err];
    }

    /** Copies $from to $to until the end or a failed write (the reader died); closes both, so neither side waits. */
    private static function copy($from, $to)
    {
        $n = 0;
        while (!feof($from)) {
            $chunk = fread($from, 1048576);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            for ($off = 0, $len = strlen($chunk); $off < $len; $off += $w) {
                $w = @fwrite($to, substr($chunk, $off));
                if (!$w) {
                    break 2;
                }
            }
            $n += $len;
        }
        fclose($from);
        fclose($to);
        return $n;
    }

    private static function finish(array $procs, array $pipes)
    {
        foreach ($pipes as $set) {
            foreach ((array) $set as $s) {
                if (is_resource($s)) {
                    fclose($s);
                }
            }
        }
        $codes = [];
        foreach ($procs as $i => $p) {
            $codes[$i] = proc_close($p);
        }
        return $codes;
    }
}
