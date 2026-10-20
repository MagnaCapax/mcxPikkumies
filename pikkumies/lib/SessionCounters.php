<?php
/**
 * SessionCounters: what the agent in one tmux pane did since `pikkumies` started there, for the status line of the
 * `pikkumies` tmux session (ADR-0061): tool calls, lines of code added and removed, and the context the last model
 * request used out of the model's window. The hook units hooks/trace/session-counters (post_tool_call) and
 * hooks/trace/session-context (post_api_request) write it, keyed by the pane in TMUX_PANE; `pikkumies status-line
 * --pane=#{pane_id}` reads it. One small JSON file per pane under local/state/session-counters/, integers only: no
 * paths, commands, content or session ids. Outside tmux nothing is written.
 *
 * A run file counts one interactive start across everything it starts (ADR-0096): the launcher makes a random
 * PIKKUMIES_RUN key, zeroes run-<key>.json (resetRun) and exports the key to Hermes, so delegated and nested agents add
 * to the same file. hooks/trace/session-context adds every model request's tokens; goal-review and the exit survey read
 * it. Integers only, under the same lock; the four keys of RUN_ZERO are always there, a reader may add its own.
 */
require_once __DIR__ . '/PathName.php';

class SessionCounters
{
    /** What tmux gives as TMUX_PANE and #{pane_id}. \z, not $: "$" would let "%3\n" through. */
    const PANE_RE = '/^%[0-9]{1,6}\z/';
    const PRUNE_DAYS = 7;
    const ZERO = ['since' => 0, 'tools' => 0, 'added' => 0, 'removed' => 0, 'ctx' => 0, 'ctxMax' => 0];
    /** A run key: 16 lowercase hex characters (bin2hex(random_bytes(8))). */
    const RUN_RE = '/^[0-9a-f]{16}\z/';
    const RUN_ZERO = ['since' => 0, 'tokens' => 0, 'reqs' => 0, 'asked' => 0];
    /** A counter name a reader may add to a run file. */
    const RUN_KEY_RE = '/^[a-zA-Z][a-zA-Z0-9]{0,31}\z/';

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
        return self::change(self::file($localDir, $pane), function ($c) use ($add, $set) {
            $c = self::clean($c);
            foreach ($add + $set as $k => $n) {
                if (isset($c[$k])) { // a known counter only
                    $c[$k] = (isset($set[$k]) ? 0 : $c[$k]) + (int) $n;
                }
            }
            return $c;
        });
    }

    /** Zero the pane's counters when `pikkumies` starts there; drop panes' files untouched for a week (its own is written again). */
    public static function reset($localDir, $pane, $now = null)
    {
        $now = $now === null ? time() : $now;
        return self::zero(self::file($localDir, $pane), 'pane*.json', ['since' => $now] + self::ZERO, $now);
    }

    /** The pane's counters, or null when it has none (not in tmux, or pikkumies never started in that pane). */
    public static function read($localDir, $pane)
    {
        $f = self::file($localDir, $pane);
        $c = $f === null ? null : json_decode((string) @file_get_contents($f), true);
        return is_array($c) ? self::clean($c) : null;
    }

    /** The run file for a key from PIKKUMIES_RUN, or null when it is not one the launcher makes. */
    public static function runFile($localDir, $run)
    {
        return preg_match(self::RUN_RE, (string) $run) ? self::dir($localDir) . '/run-' . $run . '.json' : null;
    }

    /** Zero the run's counters when an interactive `pikkumies` starts; drop run files untouched for a week. */
    public static function resetRun($localDir, $run, $now = null)
    {
        $now = $now === null ? time() : $now;
        return self::zero(self::runFile($localDir, $run), 'run-*.json', ['since' => (int) $now] + self::RUN_ZERO, $now);
    }

    /**
     * Adds $add to the run's counters and sets $set over them, under an exclusive lock (delegated agents write side by
     * side). Integers only; a key that is not a counter name is dropped. Only a run a launcher zeroed has a file:
     * anywhere else false. Never throws.
     */
    public static function updateRun($localDir, $run, array $add, array $set = [])
    {
        return self::change(self::runFile($localDir, $run), function ($c) use ($add, $set) {
            $c = self::cleanRun($c);
            foreach ($add + $set as $k => $n) {
                if (preg_match(self::RUN_KEY_RE, (string) $k) && is_numeric($n)) {
                    $c[$k] = (isset($set[$k]) || !isset($c[$k]) ? 0 : $c[$k]) + (int) $n;
                }
            }
            return $c;
        });
    }

    /** The run's counters as integers (the four of RUN_ZERO always, and any a reader added), or null when it has none. */
    public static function readRun($localDir, $run)
    {
        $f = self::runFile($localDir, $run);
        $c = $f === null ? null : json_decode((string) @file_get_contents($f), true);
        return is_array($c) ? self::cleanRun($c) : null;
    }

    /**
     * The tokens one request used, for the run file: usage.total_tokens when it is an integer from 0 to 10 000 000, else
     * prompt_tokens plus output_tokens (each such an integer, either may be missing); null when neither says anything.
     * Hermes: total_tokens = prompt_tokens + output_tokens (agent/usage_pricing.py CanonicalUsage).
     */
    public static function requestTokens(array $p)
    {
        $u = isset($p['extra']['usage']) && is_array($p['extra']['usage']) ? $p['extra']['usage'] : [];
        $int = function ($k) use ($u) {
            return isset($u[$k]) && is_int($u[$k]) && $u[$k] >= 0 && $u[$k] <= 10000000 ? $u[$k] : null;
        };
        if ($int('total_tokens') !== null) {
            return $int('total_tokens');
        }
        if ($int('prompt_tokens') === null && $int('output_tokens') === null) {
            return null;
        }
        return (int) $int('prompt_tokens') + (int) $int('output_tokens');
    }

    /**
     * Rewrites an existing counter file with $fn(decoded content) under LOCK_EX; false when there is none, it is a symlink
     * (or its directory is: a link must never let a hook write some other file), or it fails.
     */
    private static function change($f, callable $fn)
    {
        $h = $f !== null && is_file($f) && !is_link($f) && !is_link(dirname($f)) ? @fopen($f, 'r+') : false;
        if ($h === false || !flock($h, LOCK_EX)) {
            return false;
        }
        $c = $fn(json_decode((string) stream_get_contents($h), true));
        ftruncate($h, 0);
        rewind($h);
        $ok = fwrite($h, json_encode($c)) !== false;
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
        return $ok;
    }

    /**
     * Writes $zero to $f (0600, directory 0700) after removing files matching $glob untouched for PRUNE_DAYS: through a new
     * temporary file and a rename, so a symlink at $f is replaced, never written through. A symlinked directory: false.
     */
    private static function zero($f, $glob, array $zero, $now)
    {
        if ($f === null || is_link(dirname($f))) {
            return false;
        }
        foreach ((array) glob(dirname($f) . '/' . $glob) as $g) {
            if (@filemtime($g) < $now - self::PRUNE_DAYS * 86400) {
                @unlink($g);
            }
        }
        $old = umask(0077);
        @mkdir(dirname($f), 0700, true);
        $tmp = $f . '.tmp-' . getmypid();
        @unlink($tmp);
        $h = is_link(dirname($f)) ? false : @fopen($tmp, 'x'); // x: never through a link left at the temporary name
        $body = json_encode($zero);
        $ok = $h !== false && fwrite($h, $body) === strlen($body);
        if ($h !== false) {
            fclose($h);
        }
        $ok = $ok && @rename($tmp, $f);
        if (!$ok) {
            @unlink($tmp);
        }
        umask($old);
        return $ok;
    }

    /** A run file's counters as integers: RUN_ZERO's keys always, other counter names when numeric. */
    private static function cleanRun($c)
    {
        $out = self::RUN_ZERO;
        foreach (is_array($c) ? $c : [] as $k => $n) {
            if (preg_match(self::RUN_KEY_RE, (string) $k) && is_numeric($n)) {
                $out[$k] = (int) $n;
            }
        }
        return $out;
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
