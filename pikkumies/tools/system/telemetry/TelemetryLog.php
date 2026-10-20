<?php
/**
 * TelemetryLog: a local-only history of diagnostic and health measurements, one compact numeric
 * record per run (docs/adr/0086-local-only-telemetry-history-for-diagnostics-and-health-runs.md), the
 * way SMART history is kept for a drive: so later a chart can be drawn. One JSON Lines file per
 * source per UTC day, under `local/state/telemetry/<source>/YYYYMMDD.jsonl` (directory 0700, file
 * 0600) -- no sqlite dependency (PHP 7.3 on a PMSS host may lack pdo_sqlite); a later optional sqlite
 * export can read these same files without changing this format. Gated by `privacy.perfLogging`, the
 * local-only-metrics setting ADR-0041 already uses for the same promise (kept on this machine, never
 * sent); `privacy.telemetry` stays reserved for a future external sender (ADR-0032, AGENTS.md) and is
 * never read here -- see the ADR for why. Kept forever by default: nothing here deletes a line unless
 * `prune` is run by hand.
 *
 * A record is `{"ts": <unix time>, <field>: <number>, ...}`: no free text, no names, no identifiers,
 * at most MAX_FIELDS fields. record() never throws for a caller that is simply recording whatever it
 * has (missing or non-numeric values are dropped, not an error); it throws only for a programming
 * mistake (a bad source name). A caller still wraps every call in try/catch (recording must never
 * break the tool it rides along with) and, on any Throwable, calls noteFailure() so `list` can show it.
 *
 * Growth is bounded two ways (security review F9c). For a diagnostic-snapshot source (`stats` and
 * friends): a local run and a remote `serve` peer calling that same allowed verb in a loop get the
 * same limit, so neither can grow it faster than the other -- at most one write per source per
 * minute ($minIntervalS). record()'s `$counter` flag (docs/adr/0085-tool-skill-and-verb-usage-counts-
 * kept-on-this-machine.md) exempts a source whose natural write rate is many small writes a minute by
 * design and which is never WRITABLE through `serve` (`record`/`prune` are never on its allow-list)
 * -- a usage count, not a repeated snapshot -- from that one check only. Reading a usage-* source
 * through `serve`'s `series`/`list` is a separate concern, fixed by hiddenFromServe() below (security
 * review F-TEL/F4). Every source alike, counter or not, never grows a single day's file past
 * $maxFileBytes; both limits are silent, counted drops, never a failure. Taking the per-source lock
 * never blocks (LOCK_NB with a short retry, security review F9b): a jammed source gives up and counts
 * a failure instead of stalling the caller.
 *
 * counts() reads field => [calls, ok, last_ts] back out of a counter source, for the `telemetry
 * usage` verb (ADR-0087); series() (bucketed mean/min/max) is for a snapshot source's numeric trend
 * instead.
 */
class TelemetryLog
{
    /**
     * Letters, digits, - and _, starting with a letter; short, so it is always a safe directory
     * name. `\z` (never `$`): `$` also matches just before a single trailing newline, so a name
     * like "storage\n" would otherwise pass (security review F9a).
     */
    const SOURCE_RE = '/^[a-z][a-z0-9_-]{0,31}\z/';
    const FIELD_RE = '/^[a-z][a-z0-9_]{0,31}\z/';
    const MAX_FIELDS = 32;
    /**
     * A single source-day file never grows past this (security review F9c): 1 MiB. A public static
     * property, not a const -- like Quota::$live elsewhere -- so a test can shrink it instead of
     * writing a megabyte of fixture data.
     */
    public static $maxFileBytes = 1048576;
    /**
     * At most one write per source per minute, whoever calls it -- local run or a remote `serve`
     * peer invoking an allowed verb such as `stats` repeatedly (security review F9c): growth is
     * bounded to the same rate real diagnostic runs produce, so there is nothing a caller's identity
     * needs to change. A public static property so a test can set it to 0.
     */
    public static $minIntervalS = 60;
    /** Non-blocking lock attempts before giving up (security review F9b): recording must never stall. */
    const LOCK_RETRIES = 5;
    const LOCK_RETRY_US = 20000;

    /** Where every source's telemetry lives: local/state/telemetry (Config::localDir() unless a test passes its own). */
    public static function dir($localDir = null)
    {
        return ($localDir !== null ? $localDir : Config::localDir()) . '/state/telemetry';
    }

    /**
     * True for a `usage-*` source read through a remote `serve` call (security review F-TEL/F4):
     * `tools/seedbox/services/serve.php` sets `PIKKUMIES_SERVE=1` in the environment of every tool
     * it runs, so this is true only there, never for a local run or a test unless it sets the same
     * variable on purpose. A peer `serve` was given access to may read a service account's
     * diagnostic history (`series`, `list`); it may never read what tools, skills or verbs ran on
     * this machine.
     */
    private static function hiddenFromServe($source)
    {
        return getenv('PIKKUMIES_SERVE') === '1' && is_string($source) && strpos($source, 'usage-') === 0;
    }

    /**
     * Appends one record for $source now, when privacy.perfLogging is on. $fields: name => number|bool;
     * a non-numeric value, an invalid name, the reserved `ts` (this call sets its own) and anything past
     * MAX_FIELDS are dropped silently. Returns true when written, false when nothing was written and
     * that is not a failure: perfLogging off, no valid field was left (e.g. this host has no PMSS quota
     * to report), $counter is false and the source was already written to within $minIntervalS (bounds
     * growth from any caller, local or a remote `serve` peer alike, F9c), or the day's file is already
     * at $maxFileBytes (F9c, checked either way) -- all three "not written, not a failure" cases still
     * bump the source's own `.dropped` counter so `list` can show them. Only when the write itself
     * failed (disk, permissions, or the lock could not be taken, F9b) does it call noteFailure() and
     * return false. Throws CliError only for a bad $source: every real call site here names its own
     * source as a fixed string, so this is a coding mistake, not a runtime condition to recover from.
     *
     * $counter: true for a source whose natural write rate is many small writes a minute by design --
     * a usage count, one tiny line per tool call, never a repeated point-in-time snapshot -- and which
     * is never writable through a remote `serve` peer (unlike a diagnostic verb such as `stats`, the
     * one F9c's per-minute cap exists for; `record` itself is never on `serve`'s allow-list). Such a
     * source skips $minIntervalS; the size cap still applies, so growth is still bounded.
     */
    public static function record($source, array $fields, $localDir = null, array $config = null, $counter = false)
    {
        if (!is_string($source) || !preg_match(self::SOURCE_RE, $source)) {
            throw new CliError('not a valid telemetry source name: ' . Cli::cleanString((string) $source),
                'use letters, digits, - and _, starting with a letter, 32 characters or fewer');
        }
        $config = $config !== null ? $config : Config::load();
        if (empty($config['privacy']['perfLogging'])) {
            return false; // off by the user's own choice: not a failure
        }
        $clean = self::cleanFields($fields);
        if (!$clean) {
            return false; // nothing numeric to record this time: not a failure
        }
        $dir = self::dir($localDir) . '/' . $source;
        $result = self::append($dir, $clean, $counter);
        if ($result === 'failed') {
            self::noteFailure($source, $localDir);
        }
        return $result === 'ok';
    }

    /** name => number|bool -> name => int|float, at most MAX_FIELDS, bad names/values dropped, never throws. */
    private static function cleanFields(array $fields)
    {
        $clean = [];
        foreach ($fields as $k => $v) {
            if (count($clean) >= self::MAX_FIELDS) {
                break;
            }
            if (!is_string($k) || $k === 'ts' || !preg_match(self::FIELD_RE, $k)) {
                continue;
            }
            if (is_bool($v)) {
                $clean[$k] = $v ? 1 : 0;
            } elseif ((is_int($v) || is_float($v)) && is_finite($v)) {
                $clean[$k] = $v;
            }
            // strings, arrays, null and non-finite floats are dropped: no free text, no names, no NaN/INF
        }
        return $clean;
    }

    /**
     * One 0600 line appended to today's (UTC) file for $dir, under the source's own non-blocking
     * lock. Returns 'ok' (written), 'ratelimited' or 'toolarge' (not written, not a failure, counted
     * as a drop) or 'failed' (the write itself, or taking the lock, did not work). $counter: skip the
     * per-minute rate limit (record()'s doc comment says when); the size cap always applies.
     */
    private static function append($dir, array $clean, $counter = false)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return 'failed';
        }
        @chmod($dir, 0700);
        $now = time();
        $file = $dir . '/' . gmdate('Ymd', $now) . '.jsonl';
        $line = self::jsonLine(['ts' => $now] + $clean);
        $result = self::locked($dir, function () use ($dir, $file, $line, $now, $counter) {
            if (!$counter) {
                $lastFile = $dir . '/.last-write';
                $last = is_file($lastFile) ? (int) @file_get_contents($lastFile) : 0;
                if ($now - $last < self::$minIntervalS) {
                    self::bumpCounter($dir, '.dropped');
                    return 'ratelimited';
                }
            }
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) + strlen($line) > self::$maxFileBytes) {
                self::bumpCounter($dir, '.dropped');
                return 'toolarge';
            }
            if (!is_file($file)) {
                @touch($file);
                @chmod($file, 0600);
            }
            if (@file_put_contents($file, $line, FILE_APPEND) !== strlen($line)) {
                return 'failed';
            }
            if (!$counter && @file_put_contents($dir . '/.last-write', (string) $now) !== false) {
                @chmod($dir . '/.last-write', 0600);
            }
            return 'ok';
        });
        return $result === false ? 'failed' : $result; // false: locked() could not even take the lock (F9b)
    }

    /** Best-effort counter at <dir>/<name> (a plain integer, not a record: internal bookkeeping, never charted); never throws. */
    private static function bumpCounter($dir, $name)
    {
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            @chmod($dir, 0700);
            $file = $dir . '/' . $name;
            $n = is_file($file) ? (int) @file_get_contents($file) : 0;
            if (@file_put_contents($file, (string) ($n + 1)) !== false) {
                @chmod($file, 0600);
            }
        } catch (Throwable $e) {
            // a counter is itself best-effort: it must never escalate from inside a catch block
        }
    }

    /** The failure counter at <dir>/<source>/.failures; never throws, so a caller's catch block can always call it. */
    public static function noteFailure($source, $localDir = null)
    {
        if (!is_string($source) || !preg_match(self::SOURCE_RE, $source)) {
            return;
        }
        self::bumpCounter(self::dir($localDir) . '/' . $source, '.failures');
    }

    /**
     * ts, value (the bucket's mean), min, max, count, oldest first, for $field of $source over the last
     * $days days (1 to 3650, default 7), bucketed by hour or day (default hour; anything else is day).
     * ts is the bucket's own start (a multiple of 3600 or 86400, UTC). A field or a day with no numeric
     * value is simply absent from the series, never a zero. Behind a remote `serve` call
     * (`PIKKUMIES_SERVE=1`, security review F-TEL/F4), a `usage-*` source reads back empty, exactly
     * like a source that does not exist -- a peer `serve` was given can read a service account's
     * diagnostic history, never what tools, skills or verbs ran on this machine.
     */
    public static function series($source, $field, $days = 7, $bucket = 'hour', $localDir = null)
    {
        if (!is_string($source) || !preg_match(self::SOURCE_RE, $source)) {
            throw new CliError('not a valid telemetry source name: ' . Cli::cleanString((string) $source), 'run `list` for the sources on disk');
        }
        if (!is_string($field) || !preg_match(self::FIELD_RE, $field)) {
            throw new CliError('not a valid field name: ' . Cli::cleanString((string) $field), 'field names are letters, digits and _, starting with a letter');
        }
        if (self::hiddenFromServe($source)) {
            return [];
        }
        $days = max(1, min(3650, (int) $days));
        $step = $bucket === 'day' ? 86400 : 3600;
        $since = time() - $days * 86400;
        $dir = self::dir($localDir) . '/' . $source;
        $buckets = [];
        foreach (self::dayFiles($dir, gmdate('Ymd', $since)) as $file) {
            self::eachLine($file, function ($r) use (&$buckets, $field, $since, $step) {
                if (!isset($r['ts']) || !is_numeric($r['ts']) || (int) $r['ts'] < $since || !isset($r[$field]) || !is_numeric($r[$field])) {
                    return;
                }
                $key = intdiv((int) $r['ts'], $step) * $step;
                $v = (float) $r[$field];
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['sum' => 0.0, 'min' => $v, 'max' => $v, 'count' => 0];
                }
                $buckets[$key]['sum'] += $v;
                $buckets[$key]['min'] = min($buckets[$key]['min'], $v);
                $buckets[$key]['max'] = max($buckets[$key]['max'], $v);
                $buckets[$key]['count']++;
            });
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $ts => $b) {
            $out[] = ['ts' => $ts, 'value' => round($b['sum'] / $b['count'], 6), 'min' => $b['min'], 'max' => $b['max'], 'count' => $b['count']];
        }
        return $out;
    }

    /**
     * field => ['calls' => n, 'ok' => n, 'last_ts' => unix time], over the last $days days (1 to
     * 3650, default 30) of $source. Built for a usage-count source (docs/adr/0085-tool-skill-and-verb-
     * usage-counts-kept-on-this-machine.md), whose lines each hold `ts` plus exactly one other field,
     * value 1 (the call succeeded) or 0 (it failed): every non-`ts`, numeric key of every line in the
     * window adds one call, and its value (> 0 counted as one success) to that field's tally. An
     * unknown source is simply an empty result, never an error. Read a line at a time.
     */
    public static function counts($source, $days = 30, $localDir = null)
    {
        if (!is_string($source) || !preg_match(self::SOURCE_RE, $source)) {
            throw new CliError('not a valid telemetry source name: ' . Cli::cleanString((string) $source), 'run `list` for the sources on disk');
        }
        $days = max(1, min(3650, (int) $days));
        $since = time() - $days * 86400;
        $dir = self::dir($localDir) . '/' . $source;
        $out = [];
        foreach (self::dayFiles($dir, gmdate('Ymd', $since)) as $file) {
            self::eachLine($file, function ($r) use (&$out, $since) {
                if (!isset($r['ts']) || !is_numeric($r['ts']) || (int) $r['ts'] < $since) {
                    return;
                }
                foreach ($r as $field => $value) {
                    if ($field === 'ts' || !is_numeric($value)) {
                        continue;
                    }
                    if (!isset($out[$field])) {
                        $out[$field] = ['calls' => 0, 'ok' => 0, 'last_ts' => 0];
                    }
                    $out[$field]['calls']++;
                    $out[$field]['ok'] += (float) $value > 0 ? 1 : 0;
                    $out[$field]['last_ts'] = max($out[$field]['last_ts'], (int) $r['ts']);
                }
            });
        }
        return $out;
    }

    /**
     * One record per source directory on disk: source, days (day-files present), bytes, oldest,
     * newest, failures (a write that genuinely failed), dropped (a write skipped by the per-minute
     * rate limit or the per-file size cap -- not a failure, but visible so the cap's own effect can
     * be seen, F9c). Behind a remote `serve` call (`PIKKUMIES_SERVE=1`, security review F-TEL/F4),
     * every `usage-*` source is left out entirely -- not named, not counted, not in `bytes` --
     * exactly as if it were never written.
     */
    public static function listSources($localDir = null)
    {
        $out = [];
        foreach ((array) glob(self::dir($localDir) . '/*', GLOB_ONLYDIR) as $dir) {
            $source = basename($dir);
            if (!preg_match(self::SOURCE_RE, $source) || self::hiddenFromServe($source)) {
                continue; // not one of ours, or a usage-* source behind a remote serve call: never list or read it
            }
            $files = self::dayFiles($dir, '00000000');
            $bytes = 0;
            foreach ($files as $f) {
                $bytes += (int) @filesize($f);
            }
            $counter = function ($name) use ($dir) {
                $file = "$dir/$name";
                return is_file($file) ? (int) @file_get_contents($file) : 0;
            };
            $out[] = ['source' => $source, 'days' => count($files), 'bytes' => $bytes,
                'oldest' => $files ? basename($files[0], '.jsonl') : null, 'newest' => $files ? basename(end($files), '.jsonl') : null,
                'failures' => $counter('.failures'), 'dropped' => $counter('.dropped')];
        }
        return $out;
    }

    /**
     * Deletes day-files older than $keepDays across every source on disk; $keepDays <= 0 does nothing
     * (kept forever is the default -- this is opt-in, run by hand). Returns sources touched and files removed.
     */
    public static function prune($keepDays, $localDir = null)
    {
        $keepDays = (int) $keepDays;
        if ($keepDays <= 0) {
            return ['sources' => 0, 'files_removed' => 0];
        }
        $cutoff = gmdate('Ymd', time() - $keepDays * 86400);
        $touched = 0;
        $removed = 0;
        foreach ((array) glob(self::dir($localDir) . '/*', GLOB_ONLYDIR) as $dir) {
            $did = false;
            foreach (self::dayFiles($dir, '00000000') as $f) {
                if (basename($f, '.jsonl') < $cutoff && @unlink($f)) {
                    $removed++;
                    $did = true;
                }
            }
            $touched += $did ? 1 : 0;
        }
        return ['sources' => $touched, 'files_removed' => $removed];
    }

    /** The day-files of $dir (YYYYMMDD.jsonl) from $fromYmd (inclusive) to today, oldest first. */
    private static function dayFiles($dir, $fromYmd)
    {
        $files = (array) glob($dir . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].jsonl');
        sort($files);
        $out = [];
        foreach ($files as $f) {
            if (is_file($f) && basename($f, '.jsonl') >= $fromYmd) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /** Calls $visit(record) for each line of $file that decodes to a JSON object; an unreadable line is skipped. */
    private static function eachLine($file, callable $visit)
    {
        $h = @fopen($file, 'r');
        if ($h === false) {
            return;
        }
        while (($line = fgets($h)) !== false) {
            $r = json_decode($line, true);
            if (is_array($r)) {
                $visit($r);
            }
        }
        fclose($h);
    }

    /**
     * Runs $visit() holding $dir's own lock (created 0600); false when the lock file cannot be
     * opened, or cannot be taken within LOCK_RETRIES short, non-blocking tries (at most roughly
     * LOCK_RETRIES * LOCK_RETRY_US). Never a blocking flock(): a jammed source must never stall the
     * tool recording rides along with (security review F9b).
     */
    private static function locked($dir, callable $visit)
    {
        $h = @fopen($dir . '/.lock', 'c');
        if ($h === false) {
            return false;
        }
        @chmod($dir . '/.lock', 0600);
        $got = false;
        for ($try = 0; $try < self::LOCK_RETRIES; $try++) {
            if (flock($h, LOCK_EX | LOCK_NB)) {
                $got = true;
                break;
            }
            usleep(self::LOCK_RETRY_US);
        }
        if (!$got) {
            fclose($h);
            return false;
        }
        try {
            return $visit();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private static function jsonLine(array $r)
    {
        return json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
