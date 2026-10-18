<?php
/**
 * FileopsList: the quick text list the fileops check (hooks/guard/fileops-check/, ADR-0048) writes before a permission,
 * owner, attribute or rename change, so a mistake can be checked against what was there. Paths come from
 * FileopsClassify words: expand() resolves one against the call's workdir and the home, and expands a glob per path
 * component with opendir and fnmatch (never PHP glob(), which has no limit); walk() lists every entry under the
 * targets with lstat (a symlink is listed, never followed), only under the real home. Both share one context: a
 * deadline and an entry counter, so neither can run long or list a whole disk. write() stores the list as gzipped
 * JSON Lines, local/logs/fileops/<sha1 of the tool call id>.jsonl.gz (dir 0700, file 0600, temporary name + rename),
 * and keeps the directory to sessions.retentionDays and MAX_BYTES.
 */
class FileopsList
{
    const CAP = 50000;
    const SECONDS = 2.0;
    const ATTR_SECONDS = 2.0;
    const ATTR_ARGS_MAX = 131072; // bytes of paths handed to one lsattr
    const MAX_BYTES = 52428800;   // 50 MiB of lists kept
    const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** The shared limits: ['deadline', 'count', 'cap', 'truncated']. */
    public static function context($cap = self::CAP, $seconds = self::SECONDS)
    {
        return ['deadline' => microtime(true) + $seconds, 'count' => 0, 'cap' => (int) $cap, 'truncated' => false];
    }

    /**
     * One word as absolute paths: ['paths' => [...], 'relative' => bool]. ~ and ~/ are $home; a relative word needs
     * $workdir (absolute), else it is reported relative and gives no path. ~user is not resolved.
     */
    public static function expand(array $word, $workdir, $home, array &$ctx)
    {
        $w = $word['w'];
        $p = $word['p'];
        if ($word['t'] && ($w === '~' || strpos($w, '~/') === 0)) {
            if ($home === '') {
                return ['paths' => [], 'relative' => false];
            }
            list($w, $p) = [$home . substr($w, 1), addcslashes($home, '*?[\\') . substr($p, 1)];
        } elseif ($w === '' || $w[0] === '~') {
            return ['paths' => [], 'relative' => false];
        } elseif ($w[0] !== '/') {
            if ($workdir === '' || $workdir[0] !== '/') {
                return ['paths' => [], 'relative' => true];
            }
            list($w, $p) = [rtrim($workdir, '/') . '/' . $w, addcslashes(rtrim($workdir, '/'), '*?[\\') . '/' . $p];
        }
        return ['paths' => $word['g'] ? self::glob($p, $ctx) : [$w], 'relative' => false];
    }

    /** The paths an absolute fnmatch pattern names, one component at a time; stops with 'truncated' at the limits. */
    private static function glob($pattern, array &$ctx)
    {
        $bases = [''];
        foreach (explode('/', $pattern) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $next = [];
            if (!preg_match('/(?<!\\\\)[*?[]/', $part)) {
                $literal = preg_replace('/\\\\(.)/s', '$1', $part);
                foreach ($bases as $b) {
                    $next[] = "$b/$literal";
                }
            } else {
                foreach ($bases as $b) {
                    $h = @opendir($b === '' ? '/' : $b);
                    while ($h && ($e = readdir($h)) !== false) {
                        if ($e === '.' || $e === '..') {
                            continue;
                        }
                        if (++$ctx['count'] > $ctx['cap'] || microtime(true) > $ctx['deadline']) {
                            $ctx['truncated'] = true;
                            closedir($h);
                            return $next;
                        }
                        if (fnmatch($part, $e, FNM_PERIOD)) { // like the shell: * does not match a leading dot
                            $next[] = "$b/$e";
                        }
                    }
                    $h ? closedir($h) : null;
                }
            }
            $bases = $next;
        }
        return array_values(array_filter($bases, function ($f) {
            return file_exists($f) || is_link($f);
        }));
    }

    /** True when $path (resolved) is $under or inside it. */
    public static function within($path, $under)
    {
        return $path === $under || strpos($path, rtrim($under, '/') . '/') === 0;
    }

    /**
     * Every entry under the targets that resolve under $home (realpath), each target once; lstat only, so symlinks
     * are listed as 'l' and never followed. Entries: ['p', 't' => f|d|l|o, 'm' => octal mode, 'u', 'g', 's', 'mt'].
     */
    public static function walk(array $paths, $home, array &$ctx)
    {
        $roots = [];
        foreach ($paths as $p) {
            $r = realpath($p);
            if ($r !== false && $home !== false && $home !== '' && self::within($r, $home)) {
                $roots[$r] = true;
            }
        }
        $stack = [];
        foreach (array_keys($roots) as $r) {
            $inner = false;
            foreach (array_keys($roots) as $o) {
                $inner = $inner || ($o !== $r && self::within($r, $o));
            }
            $inner ? null : array_unshift($stack, $r);
        }
        $types = [0100000 => 'f', 0040000 => 'd', 0120000 => 'l'];
        $entries = [];
        while ($stack) {
            if ($ctx['count'] >= $ctx['cap'] || microtime(true) > $ctx['deadline']) {
                $ctx['truncated'] = true;
                break;
            }
            $p = array_pop($stack);
            $st = @lstat($p);
            if (!$st) {
                continue;
            }
            $ctx['count']++;
            $t = isset($types[$st['mode'] & 0170000]) ? $types[$st['mode'] & 0170000] : 'o';
            $entries[] = ['p' => $p, 't' => $t, 'm' => sprintf('%04o', $st['mode'] & 07777), 'u' => $st['uid'], 'g' => $st['gid'], 's' => $st['size'], 'mt' => $st['mtime']];
            $h = $t === 'd' ? @opendir($p) : false;
            $kids = [];
            while ($h && ($e = readdir($h)) !== false) {
                if ($e !== '.' && $e !== '..') {
                    $kids[] = ($p === '/' ? '' : $p) . '/' . $e;
                }
                if (count($kids) + count($stack) + $ctx['count'] > $ctx['cap']) {
                    $ctx['truncated'] = true; // a huge directory: stop reading it at the cap
                    break;
                }
            }
            $h ? closedir($h) : null;
            rsort($kids);
            $stack = array_merge($stack, $kids);
        }
        return $entries;
    }

    /** Add lsattr's flags as 'a' to files and directories: one lsattr, at most ATTR_ARGS_MAX bytes of paths, ATTR_SECONDS. */
    public static function addAttributes(array &$entries)
    {
        $cmd = 'exec lsattr -d --';
        $index = [];
        foreach ($entries as $i => $e) {
            $arg = ' ' . escapeshellarg($e['p']);
            if (($e['t'] === 'f' || $e['t'] === 'd') && strpos($e['p'], "\n") === false && strlen($cmd) + strlen($arg) <= self::ATTR_ARGS_MAX) {
                $cmd .= $arg;
                $index[$e['p']] = $i;
            }
        }
        $proc = $index ? @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes) : false;
        if (!is_resource($proc)) {
            return;
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + self::ATTR_SECONDS;
        while (!feof($pipes[1]) && ($left = $deadline - microtime(true)) > 0) {
            $read = [$pipes[1]];
            $none = null;
            if (@stream_select($read, $none, $none, 0, (int) min(200000, $left * 1000000)) && ($chunk = fread($pipes[1], 65536)) !== false) {
                $out .= $chunk;
            }
        }
        fclose($pipes[1]);
        proc_terminate($proc, 9); // done or out of time; lsattr has exited in the first case
        proc_close($proc);
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^(\S+) (.+)$/', $line, $m) && isset($index[$m[2]])) {
                $entries[$index[$m[2]]]['a'] = $m[1];
            }
        }
    }

    /** Write the list to $dir/$name.jsonl.gz and prune the directory; returns the file, or false when it could not be written. */
    public static function write($dir, $name, array $header, array $entries, $retentionDays)
    {
        if (!function_exists('gzencode') || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
            return false;
        }
        $body = json_encode($header, self::JSON) . "\n";
        foreach ($entries as $e) {
            $body .= json_encode($e, self::JSON) . "\n";
        }
        $gz = gzencode($body, 6);
        $file = "$dir/$name.jsonl.gz";
        $tmp = "$file.tmp-" . getmypid();
        $old = umask(0077);
        $ok = $gz !== false && @file_put_contents($tmp, $gz) === strlen($gz) && @chmod($tmp, 0600) && @rename($tmp, $file);
        umask($old);
        if (!$ok) {
            @unlink($tmp);
            return false;
        }
        self::prune($dir, (int) $retentionDays, self::MAX_BYTES, $file);
        return $file;
    }

    /** Delete lists older than $retentionDays (0 = keep), then the oldest until the directory holds at most $maxBytes; never $keep. */
    public static function prune($dir, $retentionDays, $maxBytes, $keep = '')
    {
        $files = [];
        foreach ((array) glob("$dir/*.jsonl.gz") as $f) {
            $st = @stat($f);
            if ($st && $f !== $keep && $retentionDays > 0 && $st['mtime'] < time() - $retentionDays * 86400) {
                @unlink($f);
            } elseif ($st) {
                $files[$f] = $st;
            }
        }
        uasort($files, function ($a, $b) {
            return $a['mtime'] - $b['mtime'];
        });
        $total = array_sum(array_column($files, 'size'));
        foreach ($files as $f => $st) {
            if ($total <= $maxBytes) {
                break;
            }
            if ($f !== $keep && @unlink($f)) {
                $total -= $st['size'];
            }
        }
    }
}
