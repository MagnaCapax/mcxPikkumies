<?php
/**
 * MetricsLog: the memory metrics' monthly JSON Lines files (ADR-0041), <kind>-YYYYMM.jsonl (UTC) in
 * one 0700 directory. Lines are appended and pruned under one lock (.lock), and read a line at a
 * time, so a large file is never loaded whole. DocSources::appendMetric decides whether to write.
 */
class MetricsLog
{
    /** Seconds between prunes: retention is kept to within a day. */
    const PRUNE_EVERY = 86400;

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
            self::pruneDue($dir, $retentionDays, time());
            if (!is_file($file)) {
                @touch($file);
                @chmod($file, 0600); // before the first line
            }
            return @file_put_contents($file, $line, FILE_APPEND) === strlen($line);
        });
    }

    /** With $retentionDays > 0, drop lines older than that many days (at most once a PRUNE_EVERY). */
    public static function prune($dir, $retentionDays, $now)
    {
        if ((int) $retentionDays > 0 && is_dir($dir)) {
            self::locked($dir, function () use ($dir, $retentionDays, $now) {
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

    /** Rewrite $file without its lines older than $cutoff, through a 0600 temporary file and a rename. */
    private static function dropOlder($file, $cutoff)
    {
        $tmp = $file . '.prune';
        $out = @fopen($tmp, 'w');
        if ($out === false) {
            return;
        }
        @chmod($tmp, 0600);
        $ok = true;
        self::each($file, $cutoff, function ($r, $line) use ($out, &$ok) {
            $ok = $ok && fwrite($out, $line) === strlen($line);
        });
        fclose($out);
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
