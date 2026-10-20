<?php
/**
 * HookSpool: one session's records for the interval hook units (ADR-0067), in
 * local/state/async/transcript/<hash>.jsonl (directory 0700, file 0600); the hash is lib/HookRunCount.php's: one per run
 * and depth under the launcher's PIKKUMIES_RUN, else the sha1 of session_id (ADR-0096). append() numbers each record
 * (seq = the last one + 1, under the file's lock, so parallel tool calls never share a number), stamps ts and
 * scheduled (a Hermes cron session, id "cron_...", cron/scheduler.py), and then marks every unit that a record of its
 * wake kinds just reached (a flag file the scheduler checks instead of reading the spool). Past MAX bytes the oldest
 * tool records go first, then the oldest agent texts and user rows that are not the user's (subagent, synthetic), then
 * the oldest user records, down to half; the newest record
 * always stays, so numbering never restarts. This is a separate spool of arguments only, kept beside the command log (ADR-0044), never instead
 * of it. The agent's own account can edit it: it feeds safety prompts, never an allow.
 */
require_once __DIR__ . '/HookRecords.php';

class HookSpool
{
    const MAX = 1048576;
    /**
     * What shrink() sheds, pass by pass, oldest first: tool records; then agent texts and the user rows that are not the
     * user's (a delegated agent's prompt, a row Hermes added); then anything, so the user's own requests go last.
     */
    const SHED = [['"kind":"tool"'], ['"kind":"assistant"', '"kind":"reply"', '"subagent":true', '"synthetic":true'], ['']];

    public static function dir($localDir)
    {
        return "$localDir/state/async";
    }

    public static function file($localDir, $hash)
    {
        return self::dir($localDir) . "/transcript/$hash.jsonl";
    }

    /** A unit's own directory of state files for every session. */
    public static function unitDir($localDir, $id)
    {
        return self::dir($localDir) . '/' . str_replace('/', '-', $id);
    }

    /**
     * Store $records for the session in the spool $hash (null: the sha1 of $sessionId); $units are the interval units that
     * are on ([id => unit with 'wakes']). The session id still decides "scheduled". Returns how many were stored. Never
     * throws for a full disk or a missing directory: it stores nothing then.
     */
    public static function append($localDir, $sessionId, array $records, array $units, $hash = null)
    {
        $hash = $hash === null ? sha1((string) $sessionId) : (string) $hash;
        if (!is_string($sessionId) || $sessionId === '' || !$records || !preg_match('/^[0-9a-f]{40}\z/', $hash)) {
            return 0;
        }
        $file = self::file($localDir, $hash);
        $old = umask(0077);
        try {
            if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) || is_link($file)) { // a parallel call may have just made it
                return 0;
            }
            $fh = @fopen($file, 'c+');
            if ($fh === false || !flock($fh, LOCK_EX)) {
                return 0;
            }
            $seq = self::lastSeq($fh);
            $lines = '';
            $scheduled = strpos($sessionId, 'cron_') === 0;
            foreach ($records as $r) {
                $r = ['seq' => ++$seq, 'ts' => gmdate('Y-m-d\TH:i:s\Z')] + $r + ['scheduled' => $scheduled];
                $lines .= json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            }
            fseek($fh, 0, SEEK_END);
            $stored = fwrite($fh, $lines) === strlen($lines) ? count($records) : 0;
            if (fstat($fh)['size'] > self::MAX) {
                self::shrink($fh);
            }
            // The record first, then the flags (ADR-0067): a worker that sees a flag finds its record.
            foreach ($stored ? $units : [] as $id => $u) {
                if (self::wakes($u['wakes'], $records)) {
                    $dir = self::unitDir($localDir, $id);
                    if (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) {
                        @touch("$dir/$hash.wake");
                    }
                }
            }
            flock($fh, LOCK_UN);
            fclose($fh);
            return $stored;
        } finally {
            umask($old);
        }
    }

    /** The session's records numbered above $after, oldest first: [records, last seq, how many above $after were dropped]. */
    public static function read($localDir, $hash, $after)
    {
        $file = self::file($localDir, $hash);
        $fh = is_link($file) ? false : @fopen($file, 'r');
        if ($fh === false) {
            return [[], 0, 0];
        }
        flock($fh, LOCK_SH);
        $raw = (string) stream_get_contents($fh, self::MAX * 2);
        flock($fh, LOCK_UN);
        fclose($fh);
        $out = [];
        $last = 0;
        foreach (explode("\n", $raw) as $l) {
            $r = json_decode($l, true);
            if (is_array($r) && isset($r['seq']) && is_int($r['seq'])) {
                $last = max($last, $r['seq']);
                if ($r['seq'] > $after) {
                    $out[] = $r;
                }
            }
        }
        $after = $after > $last ? 0 : $after; // a spool started again (pruned): every record is new
        return [$out, $last, max(0, $last - $after - count($out))];
    }

    /** True when a record of one of $wakes ([] = any) is among $records; 'change' also means a call that may have. */
    public static function wakes(array $wakes, array $records)
    {
        foreach ($records as $r) {
            $kind = $r['kind'] === 'tool' && isset($r['change']) && $r['change'] !== false ? ['tool', 'change'] : [$r['kind']];
            if (!$wakes || array_intersect($wakes, $kind)) {
                return true;
            }
        }
        return false;
    }

    private static function lastSeq($fh)
    {
        $size = fstat($fh)['size'];
        fseek($fh, max(0, $size - 65536));
        $tail = (string) stream_get_contents($fh);
        $lines = array_values(array_filter(explode("\n", $tail), 'strlen'));
        $r = $lines ? json_decode(end($lines), true) : null;
        return is_array($r) && isset($r['seq']) && is_int($r['seq']) ? $r['seq'] : 0;
    }

    /** Down to half of MAX, shedding in SHED's order, oldest first; never the newest record. */
    private static function shrink($fh)
    {
        rewind($fh);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($fh)), 'strlen'));
        $size = array_sum(array_map('strlen', $lines)) + count($lines);
        foreach (self::SHED as $kinds) {
            foreach ($lines as $i => $l) {
                if ($size <= self::MAX / 2 || $i === count($lines) - 1) {
                    break;
                }
                $match = array_filter($kinds, function ($k) use ($l) { return $k === '' || strpos($l, $k) !== false; }); // JSON escapes quotes inside texts, so these cannot match a text
                if ($l !== '' && $match) {
                    $size -= strlen($l) + 1;
                    $lines[$i] = '';
                }
            }
        }
        $kept = implode("\n", array_filter($lines, 'strlen')) . "\n";
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $kept);
    }
}
