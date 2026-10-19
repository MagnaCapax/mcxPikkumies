<?php
/**
 * FeedbackAsk (ADR-0056, amendment 2026-10-19): the opt-in, occasional question "would you like to send feedback?".
 * Off unless the person turned on feedback.ask. due() decides from the pre_llm_call payload whether this turn's answer
 * ends with the question; claim() records the ask, and only a recorded ask is spoken, so a state directory that cannot
 * be written means silence, never a question on every turn. offerOnce() is the first start's one terminal question that
 * lets the person turn it on. Neither sends anything: sending is /feedback's, behind its own check for the user's yes.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/NestedAgent.php';

class FeedbackAsk
{
    /** Percent of eligible sessions that are asked. A starting value, not measured; the operator tunes it. */
    const RATE = 20;
    /** At most one question in this many days, across all sessions. A starting value. */
    const COOLDOWN_DAYS = 7;
    /** A one-shot run has one user message; a conversation worth asking about has a few. A starting value. */
    const MIN_USER_MESSAGES = 4;
    /** Hermes' platform for a person's own session (hermes_cli/oneshot.py, tui_gateway); cron, gateways and delegated agents are never asked. */
    const PLATFORMS = ['cli', 'tui'];
    const SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/D';
    /** The one sentence the turn gets. It asks for a question only; a send still needs the person's yes in Hermes' prompt. */
    const SENTENCE = 'Pikkumies feedback, which the user turned on: once, at the end of this answer and after doing what they asked, '
        . 'add one short line asking whether they would like to send Pulsed Media feedback about this session with /feedback. '
        . 'If they say no, drop it and do not ask again. If they say never ask again, turn the feedback.ask setting off with the '
        . 'pikkumies-configure skill. Only if they say yes, follow the feedback skill.';
    const QUESTION = "Pikkumies can now and then ask, during a session, whether you want to send Pulsed Media feedback.\n"
        . "The question itself sends nothing, and you can turn it off at any time with the pikkumies-configure skill.\n"
        . 'Turn it on? [y/N]: ';

    public static function stateDir($stateDir = null)
    {
        return $stateDir !== null ? $stateDir : Config::localDir() . '/state/feedback-ask';
    }

    /** The session's fixed draw: the first 16 bits of sha256(session id), mod 100, below RATE (bias under 0.06%). */
    public static function sampled($sessionId)
    {
        return hexdec(substr(hash('sha256', (string) $sessionId), 0, 4)) % 100 < self::RATE;
    }

    /** True when this turn should end with the question. $now is a Unix time; the marker's mtime is the last ask. */
    public static function due(array $config, array $payload, $now, $stateDir = null)
    {
        if (empty($config['feedback']['ask'])) {
            return false;
        }
        $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
        $extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
        $platform = isset($extra['platform']) && is_string($extra['platform']) ? $extra['platform'] : '';
        // Approvals off, or an agent started by an agent: no person answers the send's prompt there, so no question either.
        if (!preg_match(self::SESSION_RE, $session) || !in_array($platform, self::PLATFORMS, true)
            || NestedAgent::depth() > 1 || NestedAgent::approvalsOff()) {
            return false;
        }
        $users = 0;
        foreach (isset($extra['conversation_history']) && is_array($extra['conversation_history']) ? $extra['conversation_history'] : [] as $m) {
            $users += is_array($m) && isset($m['role']) && $m['role'] === 'user' ? 1 : 0;
        }
        if ($users < self::MIN_USER_MESSAGES || !self::sampled($session)) {
            return false;
        }
        clearstatcache();
        $last = @filemtime(self::stateDir($stateDir) . '/asked');
        return $last === false || $last <= $now - self::COOLDOWN_DAYS * 86400;
    }

    /** Records an ask now; true only when the marker was written (the hook speaks only then). */
    public static function claim($now, $stateDir = null)
    {
        $dir = self::stateDir($stateDir);
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir) || is_link("$dir/asked")) {
            return false;
        }
        return @touch("$dir/asked", (int) $now);
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
