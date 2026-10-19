<?php
/**
 * MetricsLog: the memory metrics' monthly JSON Lines files (ADR-0041), <kind>-YYYYMM.jsonl (UTC) in
 * one 0700 directory. Lines are appended and pruned under one lock (.lock), and read a line at a
 * time, so a large file is never loaded whole. DocSources::appendMetric decides whether to write.
 * Lines written before ADR-0041 was amended carry more (paths, mode, ms, topic, the words of every search): the first
 * append or prune of this code drops those fields once from every file (scrubDue, marker .scrubbed-1). Like pruning,
 * a rewrite drops lines that do not parse or carry no ts; nothing reads them.
 */
class MetricsLog
{
    /** Seconds between prunes: retention is kept to within a day. */
    const PRUNE_EVERY = 86400;
    /** The fields a line of each kind keeps; a search keeps its words only when it found no lesson (ADR-0041). */
    const KEEP = ['searches' => ['ts', 'tiers', 'hits', 'sources', 'pages_per_tier', 'words'], 'saves' => ['ts']];

    /** Append one line to <kind>-YYYYMM.jsonl (created 0600), pruning first when due; true when written. */
    public static function append($dir, $kind, array $rec, $retentionDays)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return false;
        }
        @chmod($dir, 0700);
        $ts = isset($rec['ts']) ? (int) $rec['ts'] : time();
        $file = $dir . '/' . $kind . '-' . gmdate('Ym', $ts) . '.jsonl';
        $line = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        return self::locked($dir, function () use ($dir, $file, $line, $retentionDays) {
            self::scrubDue($dir);
            self::pruneDue($dir, $retentionDays, time());
            if (!is_file($file)) {
                @touch($file);
                @chmod($file, 0600); // before the first line
            }
            return @file_put_contents($file, $line, FILE_APPEND) === strlen($line);
        });
    }

    /** The one-time scrub when due; then, with $retentionDays > 0, drop lines older than that (at most once a PRUNE_EVERY). */
    public static function prune($dir, $retentionDays, $now)
    {
        if (is_dir($dir)) {
            self::locked($dir, function () use ($dir, $retentionDays, $now) {
                self::scrubDue($dir);
                self::pruneDue($dir, $retentionDays, $now);
            });
        }
    }

    /** Calls $visit(record, raw line) for each line of $file whose ts >= $since, in file order; unreadable lines are skipped. */
    public static function each($file, $since, callable $visit)
    {
        $h = @fopen($file, 'r');
        if ($h === false) {
            return;
        }
        while (($line = fgets($h)) !== false) {
            $r = json_decode($line, true);
            if (is_array($r) && isset($r['ts']) && (int) $r['ts'] >= $since) {
                $visit($r, rtrim($line, "\n") . "\n");
            }
        }
        fclose($h);
    }

    /** The metrics files of $kind ('*' for all), oldest month first. */
    public static function files($dir, $kind)
    {
        $files = (array) glob($dir . '/' . $kind . '-[0-9][0-9][0-9][0-9][0-9][0-9].jsonl');
        sort($files);
        return $files;
    }

    /** The month (YYYYMM) a metrics file holds, from its name. */
    public static function month($file)
    {
        return substr(basename($file, '.jsonl'), -6);
    }

    /** Run $visit holding the directory's lock; false when the lock file cannot be opened. */
    private static function locked($dir, callable $visit)
    {
        $h = @fopen($dir . '/.lock', 'c');
        if ($h === false) {
            return false;
        }
        @chmod($dir . '/.lock', 0600);
        flock($h, LOCK_EX);
        try {
            return $visit();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** Under the lock: files of months before the cutoff's are deleted, the cutoff's month loses its older lines. */
    private static function pruneDue($dir, $retentionDays, $now)
    {
        $mark = $dir . '/.pruned';
        clearstatcache();
        $last = is_file($mark) ? (int) filemtime($mark) : 0;
        if ((int) $retentionDays <= 0 || ($last <= $now && $now - $last < self::PRUNE_EVERY)) {
            return;
        }
        @touch($mark, $now);
        $cutoff = $now - (int) $retentionDays * 86400;
        foreach (self::files($dir, '*') as $f) {
            if (self::month($f) < gmdate('Ym', $cutoff)) {
                @unlink($f);
            } elseif (self::month($f) === gmdate('Ym', $cutoff)) {
                self::dropOlder($f, $cutoff);
            }
        }
    }

    /** Under the lock, once: every line of a known kind keeps only its KEEP fields; the marker is set only when all went. */
    private static function scrubDue($dir)
    {
        $mark = $dir . '/.scrubbed-1';
        if (is_file($mark)) {
            return;
        }
        $ok = true;
        foreach (self::files($dir, '*') as $f) {
            $kind = substr(basename($f), 0, -strlen('-YYYYMM.jsonl'));
            if (isset(self::KEEP[$kind])) {
                $keep = array_flip(self::KEEP[$kind]);
                $ok = self::rewrite($f, 0, function ($r, $line) use ($keep) {
                    $s = array_intersect_key($r, $keep);
                    if (isset($s['words'], $r['hits'][1]) && (int) $r['hits'][1] > 0) {
                        unset($s['words']); // the search found a lesson: its words are not kept
                    }
                    return $s === $r ? $line : json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                }) && $ok;
            }
        }
        if ($ok) {
            @touch($mark);
            @chmod($mark, 0600);
        }
    }

    /** Rewrite $file without its lines older than $cutoff. */
    private static function dropOlder($file, $cutoff)
    {
        self::rewrite($file, $cutoff, function ($r, $line) {
            return $line;
        });
    }

    /** Rewrite $file with $map(record, line) of each line with ts >= $since, through a 0600 temporary file and a rename. */
    private static function rewrite($file, $since, callable $map)
    {
        $tmp = $file . '.prune';
        $out = @fopen($tmp, 'w');
        if ($out === false) {
            return false;
        }
        @chmod($tmp, 0600);
        $ok = true;
        self::each($file, $since, function ($r, $line) use ($out, &$ok, $map) {
            $line = $map($r, $line);
            $ok = $ok && fwrite($out, $line) === strlen($line);
        });
        fclose($out);
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
