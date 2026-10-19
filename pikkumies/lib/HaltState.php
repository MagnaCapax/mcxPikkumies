<?php
/**
 * HaltState: the halt guard's per-session state file (ADR-0047), shared by hooks/halt/halt-snapshot/
 * (writes it at pre_llm_call), hooks/halt/halt-guard/ (reads and updates it at pre_verify) and
 * lib/HaltRun.php (the one-shot wrapper, reads and updates it after Hermes exits). One file per
 * session, local/state/halt/<sha1 of session_id>.json (dir 0700, file 0600, written through a
 * temporary name and a rename): {user_message, tail, asked, turn_started, continues, wrapup_sent,
 * last_judged_turn}. Also owns the halt-guard decision log, local/logs/halt-guard/YYYYMMDD.jsonl.
 */
require_once __DIR__ . '/Config.php';

class HaltState
{
    public static function dir()
    {
        return Config::localDir() . '/state/halt';
    }

    public static function file($sessionId)
    {
        return self::dir() . '/' . sha1((string) $sessionId) . '.json';
    }

    /**
     * A fresh snapshot for a new turn: $continues, $wrapup_sent and $last_judged_turn always start over. $asked: what the
     * user wrote or saw (AskReview::evidence), the only evidence the ask review reads (ADR-0073).
     */
    public static function writeSnapshot($sessionId, $userMessage, $tail, array $asked = [])
    {
        $new = !is_file(self::file($sessionId));
        self::put($sessionId, ['user_message' => (string) $userMessage, 'tail' => (string) $tail, 'asked' => array_values($asked), 'turn_started' => time(),
            'continues' => 0, 'wrapup_sent' => false, 'last_judged_turn' => 0]);
        if ($new) {
            self::prune();
        }
    }

    const KEEP_SECONDS = 172800; // a snapshot is only needed until its turn is judged; two days covers a turn left open overnight

    /** Deletes snapshots (and their lock files) not written for KEEP_SECONDS, so transcript tails do not pile up; run when a new session starts. */
    public static function prune($now = null)
    {
        $cut = ($now === null ? time() : $now) - self::KEEP_SECONDS;
        foreach ((array) glob(self::dir() . '/*.json*') as $f) {
            $m = @filemtime($f);
            if ($m !== false && $m < $cut) {
                @unlink($f);
            }
        }
    }

    /** The session's state, or null when there is none (or it is unreadable). */
    public static function load($sessionId)
    {
        $data = json_decode((string) @file_get_contents(self::file($sessionId)), true);
        return is_array($data) && isset($data['turn_started']) ? $data : null;
    }

    /** Stamp this turn as looked at by the live pre_verify hook, whatever verdict it reached. */
    public static function markJudged($sessionId, $turnStarted)
    {
        self::update($sessionId, function ($state) use ($turnStarted) {
            $state['last_judged_turn'] = $turnStarted;
            return $state;
        });
    }

    public static function bumpContinues($sessionId)
    {
        self::update($sessionId, function ($state) {
            $state['continues'] = (isset($state['continues']) ? (int) $state['continues'] : 0) + 1;
            return $state;
        });
    }

    public static function markWrapupSent($sessionId)
    {
        self::update($sessionId, function ($state) {
            $state['wrapup_sent'] = true;
            return $state;
        });
    }

    /** True once the live hook has already stamped the session's CURRENT turn (lib/HaltRun.php must not judge it again). */
    public static function alreadyJudgedThisTurn($sessionId)
    {
        $state = self::load($sessionId);
        return $state !== null && !empty($state['turn_started']) && isset($state['last_judged_turn'])
            && (int) $state['last_judged_turn'] === (int) $state['turn_started'];
    }

    /**
     * [{command, exit_code}, ...] from the command log (ADR-0044) for $sessionId since $sinceUnixTs,
     * oldest first; reads only the day of $sinceUnixTs and today's (a turn spanning UTC midnight is
     * the one case both are needed). Unreadable or missing files are silently skipped: evidence this
     * thin only ever makes the judge less sure, and an unsure answer already fails open.
     */
    public static function commandsSince($commandsLogDir, $sessionId, $sinceUnixTs)
    {
        $sinceIso = gmdate('Y-m-d\TH:i:s\Z', $sinceUnixTs);
        $out = [];
        foreach (array_unique([gmdate('Ymd', $sinceUnixTs), gmdate('Ymd')]) as $day) {
            $file = "$commandsLogDir/$day.jsonl";
            if (!is_file($file)) {
                continue;
            }
            foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
                if ($line === '') {
                    continue;
                }
                $r = json_decode($line, true);
                if (!is_array($r) || !isset($r['session_id'], $r['ts']) || $r['session_id'] !== $sessionId || $r['ts'] < $sinceIso) {
                    continue;
                }
                $out[] = ['command' => isset($r['command']) ? (string) $r['command'] : '', 'exit_code' => isset($r['exit_code']) ? $r['exit_code'] : null];
            }
        }
        return $out;
    }

    /**
     * One JSONL line per halt-guard decision, local/logs/halt-guard/YYYYMMDD.jsonl (0700/0600), never
     * the transcript or the final answer: {ts, session_id, source, attempt, verdict, questions}.
     * A new day's file prunes files older than $retentionDays (0 = keep forever, matching the command log).
     */
    public static function logDecision($sessionId, $source, $attempt, array $verdict, $retentionDays = 0)
    {
        $dir = Config::localDir() . '/logs/halt-guard';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
        $new = !is_file($file);
        $rec = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'session_id' => (string) $sessionId, 'source' => (string) $source,
            'attempt' => (int) $attempt, 'verdict' => isset($verdict['verdict']) ? $verdict['verdict'] : '',
            'questions' => isset($verdict['answers']) && is_array($verdict['answers']) ? $verdict['answers'] : []];
        $old = umask(0077);
        @file_put_contents($file, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
        if ($new && $retentionDays > 0) {
            $cut = gmdate('Ymd', time() - $retentionDays * 86400);
            foreach ((array) glob($dir . '/[0-9]*.jsonl') as $f) {
                if (basename($f, '.jsonl') < $cut) {
                    @unlink($f);
                }
            }
        }
    }

    /** Atomic full write of $state (dir 0700, file 0600, temp name + rename). */
    private static function put($sessionId, array $state)
    {
        $file = self::file($sessionId);
        @mkdir(dirname($file), 0700, true);
        $tmp = $file . '.tmp-' . getmypid();
        $body = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $body) === strlen($body) && @chmod($tmp, 0600) && @rename($tmp, $file);
        umask($old);
        if (!$ok) {
            @unlink($tmp);
        }
    }

    /** Read-modify-write under a lock; does nothing when there is no existing state to update. */
    private static function update($sessionId, callable $mutator)
    {
        $file = self::file($sessionId);
        @mkdir(dirname($file), 0700, true);
        $lock = @fopen($file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return;
        }
        try {
            $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (!is_array($state)) {
                return;
            }
            self::put($sessionId, $mutator($state));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
