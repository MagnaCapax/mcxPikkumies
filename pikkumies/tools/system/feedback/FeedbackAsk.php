<?php
/**
 * FeedbackAsk (ADR-0056 amendments 2026-10-19 and 2026-10-20, ADR-0097): when Pikkumies may ask about feedback at all.
 * Off unless the person turned on feedback.ask. Two askers share one budget: the occasional line at the end of an answer
 * (hooks/system/feedback-ask, due()) and the rating at exit (FeedbackSurvey). A run is the interactive `pikkumies` that
 * set PIKKUMIES_RUN; its counters (lib/SessionCounters.php) survive compaction, which draws a new session id. A run is
 * eligible after 45 minutes AND 500,000 tokens, in the person's own session, never twice; the budget is the mtime of
 * local/state/feedback-ask/next ("no ask before then"), claimed before anything is shown, so a state directory that
 * cannot be written means silence. offerOnce() is the first start's one question. Nothing here sends anything.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/NestedAgent.php';
require_once dirname(__DIR__, 3) . '/lib/SessionCounters.php';

class FeedbackAsk
{
    /** Percent of eligible runs whose answers get the mid-session line; the rest are left to the exit rating. A starting value. */
    const RATE = 20;
    /** A run is eligible after this many minutes of wall time AND this many tokens (every request's total, delegated agents too). */
    const MIN_MINUTES = 45;
    const MIN_TOKENS = 500000;
    /** No ask for this many days after an answered ask (the mid-session line, or a rating) and after a dismissed rating. */
    const SNOOZE_ANSWERED_DAYS = 14;
    const SNOOZE_DISMISSED_DAYS = 30;
    /** The 2026-10-19 marker `asked` (its mtime the last ask) is still honoured for one release, with the answered snooze. */
    const LEGACY_MARKER = 'asked';
    /** Hermes' platform for a person's own session (hermes_cli/oneshot.py, tui_gateway); cron, gateways and delegated agents are never asked. */
    const PLATFORMS = ['cli', 'tui'];
    /** The one sentence the turn gets. It asks for a question only; a send still runs only when the person's own request covers it (ADR-0101). */
    const SENTENCE = 'Pikkumies feedback, which the user turned on: once, at the end of this answer and after doing what they asked, '
        . 'add one short line asking whether they would like to send Pulsed Media feedback about this session with /feedback. '
        . 'If they say no, drop it and do not ask again. If they say never ask again, turn the feedback.ask setting off with the '
        . 'pikkumies-configure skill. Only if they say yes, follow the feedback skill.';
    const QUESTION = "Pikkumies can now and then ask, during a session or when it closes, how it is doing or whether you want to send Pulsed Media feedback.\n"
        . "Asking sends nothing; a rating is sent only when you press its number. Turn it off any time with the pikkumies-configure skill.\n"
        . 'Turn it on? [y/N]: ';

    public static function stateDir($stateDir = null)
    {
        return $stateDir !== null ? $stateDir : Config::localDir() . '/state/feedback-ask';
    }

    /** The state directory under a local directory (tests), or the install's. */
    private static function askDir($localDir)
    {
        return self::stateDir($localDir === null ? null : "$localDir/state/feedback-ask");
    }

    /** This process's run id from PIKKUMIES_RUN (16 hex digits, set by bin/pikkumies at an interactive start), or ''. */
    public static function runId($raw = null)
    {
        $raw = (string) ($raw === null ? getenv('PIKKUMIES_RUN') : $raw);
        return preg_match(SessionCounters::RUN_RE, $raw) ? $raw : '';
    }

    /** The run's fixed draw for the mid-session line: the first 16 bits of sha256(run id), mod 100, below RATE. */
    public static function sampled($run)
    {
        return hexdec(substr(hash('sha256', (string) $run), 0, 4)) % 100 < self::RATE;
    }

    /** True when the shared budget allows an ask at $now: `next` not in the future, the legacy marker older than the snooze. */
    public static function budgetOpen($now, $localDir = null)
    {
        $dir = self::askDir($localDir);
        clearstatcache();
        $next = @filemtime("$dir/next");
        $legacy = @filemtime("$dir/" . self::LEGACY_MARKER);
        return ($next === false || $next <= $now) && ($legacy === false || $legacy <= $now - self::SNOOZE_ANSWERED_DAYS * 86400);
    }

    /**
     * True when this run may be asked at $now: feedback.ask on, depth 1 or less, a run file whose ask is unused, 45 minutes
     * since it started AND 500,000 tokens, and the budget open. An agent started by an agent: no person reads the question
     * there. --yolo, the default run mode, is no reason to stay silent: the person opted in to the question (feedback.ask).
     */
    public static function eligible(array $config, $run, $now, $localDir = null)
    {
        if (empty($config['feedback']['ask']) || NestedAgent::depth() > 1 || self::runId($run) === '') {
            return false;
        }
        $c = SessionCounters::readRun($localDir === null ? Config::localDir() : $localDir, $run);
        return is_array($c) && (int) $c['asked'] === 0 && (int) $c['since'] > 0 && $now - (int) $c['since'] >= self::MIN_MINUTES * 60
            && (int) $c['tokens'] >= self::MIN_TOKENS && self::budgetOpen($now, $localDir);
    }

    /** True when this turn's answer should end with the line: a person's own session, an eligible run, in the sampled fifth. */
    public static function due(array $config, array $payload, $now, $localDir = null, $run = null)
    {
        $extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
        $platform = isset($extra['platform']) && is_string($extra['platform']) ? $extra['platform'] : '';
        $run = self::runId($run);
        return in_array($platform, self::PLATFORMS, true) && $run !== '' && self::sampled($run) && self::eligible($config, $run, $now, $localDir);
    }

    /**
     * Claims the run's one ask and the budget for $days, under a lock, before anything is shown: true only when the budget
     * was still open, `next` now says now + $days and the run file says asked. Two runs ending at once ask once.
     */
    public static function claim($now, $run, $days, $localDir = null)
    {
        $local = $localDir === null ? Config::localDir() : $localDir;
        $dir = self::askDir($localDir);
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir) || is_link("$dir/next") || self::runId($run) === '') {
            return false;
        }
        $lock = @fopen("$dir/next.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return false;
        }
        try {
            $c = SessionCounters::readRun($local, $run);
            if (!is_array($c) || (int) $c['asked'] !== 0 || !self::budgetOpen($now, $localDir)) {
                return false;
            }
            return self::snooze($now, $days, $localDir) && SessionCounters::updateRun($local, $run, [], ['asked' => 1]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Sets the budget to now + $days (an answer after the claim changes how long); true when written. */
    public static function snooze($now, $days, $localDir = null)
    {
        $dir = self::askDir($localDir);
        return !is_link("$dir/next") && @touch("$dir/next", (int) $now + (int) $days * 86400);
    }

    /**
     * The first interactive start's question, on a terminal only (bin/pikkumies). Asked once ever: the marker is claimed
     * before asking, so two starts at once ask once, and Enter, any other answer or end of input count as asked.
     * Returns true for y/yes, false for anything else, null when not asked (offered before, already on, no state dir).
     */
    public static function offerOnce(array $config, callable $read, callable $write, $stateDir = null)
    {
        $dir = self::stateDir($stateDir);
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) {
            return null;
        }
        $marker = @fopen("$dir/offered", 'x'); // 'x' fails when it exists: offered before
        if ($marker === false) {
            return null;
        }
        fclose($marker);
        if (!empty($config['feedback']['ask'])) {
            return null;
        }
        $write(self::QUESTION);
        $line = $read();
        return $line !== false && in_array(strtolower(trim((string) $line)), ['y', 'yes'], true);
    }
}
