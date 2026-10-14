<?php
/**
 * MediaExec: runs one fixed command (docker, the PMSS media installer) with a time limit and a
 * clean environment, and returns its exit code and output. Arguments are always escaped by the
 * caller's array; nothing from a server or a title ever becomes part of a command.
 */
class MediaExec
{
    /** $argv: program and arguments. Returns [exit code (-1 on timeout), stdout+stderr]. */
    public static function run(array $argv, array $env, $timeout, $cwd = null)
    {
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1'; // proc_open's 'redirect' needs PHP 7.4
        $env += ['PATH' => (string) getenv('PATH'), 'LANG' => 'C'];
        $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($p)) {
            return [127, 'could not start ' . $argv[0]];
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $read = [$pipes[1]];
            $w = null;
            $e = null;
            if (@stream_select($read, $w, $e, 0, 200000) > 0) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $out .= strlen($out) < 1048576 ? $chunk : '';
                    continue;
                }
            }
            if (feof($pipes[1])) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p, 9);
                fclose($pipes[1]);
                proc_close($p);
                return [-1, $out];
            }
        }
        fclose($pipes[1]);
        return [proc_close($p), $out];
    }
}
