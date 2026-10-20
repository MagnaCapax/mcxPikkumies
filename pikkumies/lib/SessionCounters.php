<?php
/**
 * SessionCounters: what the agent in one tmux pane did since `pikkumies` started there, for the status line of the
 * `pikkumies` tmux session (ADR-0061): tool calls, lines of code added and removed, and the context the last model
 * request used out of the model's window. The hook units hooks/trace/session-counters (post_tool_call) and
 * hooks/trace/session-context (post_api_request) write it, keyed by the pane in TMUX_PANE; `pikkumies status-line
 * --pane=#{pane_id}` reads it. One small JSON file per pane under local/state/session-counters/, integers only: no
 * paths, commands, content or session ids. Outside tmux nothing is written.
 */
require_once __DIR__ . '/PathName.php';

class SessionCounters
{
    /** What tmux gives as TMUX_PANE and #{pane_id}. \z, not $: "$" would let "%3\n" through. */
    const PANE_RE = '/^%[0-9]{1,6}\z/';
    const PRUNE_DAYS = 7;
    const ZERO = ['since' => 0, 'tools' => 0, 'added' => 0, 'removed' => 0, 'ctx' => 0, 'ctxMax' => 0];

    public static function dir($localDir)
    {
        return $localDir . '/state/session-counters';
    }

    /** The counter file for a pane id such as "%3", or null when it is not one tmux gives. */
    public static function file($localDir, $pane)
    {
        return preg_match(self::PANE_RE, (string) $pane) ? self::dir($localDir) . '/pane' . substr($pane, 1) . '.json' : null;
    }

    /**
     * Adds $add to the pane's counters and sets $set over them, under an exclusive lock: tool calls and delegated
     * agents run side by side. Only a pane where an interactive `pikkumies` started (reset) has a file; anywhere else,
     * or when the file cannot be written, false. Never throws.
     */
    public static function update($localDir, $pane, array $add, array $set = [])
    {
        $f = self::file($localDir, $pane);
        $h = $f !== null && is_file($f) ? @fopen($f, 'r+') : false;
        if ($h === false || !flock($h, LOCK_EX)) {
            return false;
        }
        $c = self::clean(json_decode((string) stream_get_contents($h), true));
        foreach ($add + $set as $k => $n) {
            if (isset($c[$k])) { // a known counter only
                $c[$k] = (isset($set[$k]) ? 0 : $c[$k]) + (int) $n;
            }
        }
        ftruncate($h, 0);
        rewind($h);
        $ok = fwrite($h, json_encode($c)) !== false;
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
        return $ok;
    }

    /** Zero the pane's counters when `pikkumies` starts there; drop panes' files untouched for a week (its own is written again). */
    public static function reset($localDir, $pane, $now = null)
    {
        $now = $now === null ? time() : $now;
        $f = self::file($localDir, $pane);
        if ($f === null) {
            return false;
        }
        foreach ((array) glob(self::dir($localDir) . '/pane*.json') as $g) {
            if (@filemtime($g) < $now - self::PRUNE_DAYS * 86400) {
                @unlink($g);
            }
        }
        $old = umask(0077);
        @mkdir(dirname($f), 0700, true);
        $ok = @file_put_contents($f, json_encode(['since' => $now] + self::ZERO), LOCK_EX) !== false;
        umask($old);
        return $ok;
    }

    /** The pane's counters, or null when it has none (not in tmux, or pikkumies never started in that pane). */
    public static function read($localDir, $pane)
    {
        $f = self::file($localDir, $pane);
        $c = $f === null ? null : json_decode((string) @file_get_contents($f), true);
        return is_array($c) ? self::clean($c) : null;
    }

    /** Every known counter as an integer, whatever the file held. */
    private static function clean($c)
    {
        $out = self::ZERO;
        foreach (array_keys(self::ZERO) as $k) {
            $out[$k] = is_array($c) && isset($c[$k]) && is_numeric($c[$k]) ? (int) $c[$k] : 0;
        }
        return $out;
    }

    /** [memory files, of them saved today]: a save is named YYYYMMDD-<slug>.md with the UTC date (Lessons::save). */
    public static function memories(array $files, $today)
    {
        $n = 0;
        foreach ($files as $f) {
            $n += strncmp(PathName::base($f), $today . '-', 9) === 0 ? 1 : 0;
        }
        return [count($files), $n];
    }

    /**
     * `Memories: 42 +3 today · Ctx: 48.2k/1M 4% · Tools: 17 · Code: +120 -8`. The pane part only when the pane has
     * counters, Ctx only once a model request reported it. $short formats a number (CreditsFormat::short: rounded down).
     */
    public static function render($memTotal, $memToday, $c, $utf8, callable $short)
    {
        $parts = ['Memories: ' . $memTotal . ($memToday > 0 ? " +$memToday today" : '')];
        if (is_array($c)) {
            if ($c['ctx'] > 0) {
                $parts[] = 'Ctx: ' . $short($c['ctx']) . ($c['ctxMax'] > 0 ? '/' . $short($c['ctxMax']) . ' ' . intdiv($c['ctx'] * 100, $c['ctxMax']) . '%' : '');
            }
            $parts[] = 'Tools: ' . $c['tools'];
            $parts[] = 'Code: +' . $c['added'] . ' -' . $c['removed'];
        }
        return implode($utf8 ? ' · ' : ' | ', $parts);
    }
}
