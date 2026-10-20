<?php
/**
 * HookFeed: the orchestrator's side of the interval hook units (ADR-0067), in four calls, each of which never throws
 * and does nothing while no interval unit is on:
 * - start(): at pre_llm_call, post_llm_call and post_tool_call, appends this event's records to the session's spool;
 * - contexts(): at pre_llm_call, the pending contexts (and notices not delivered as holds), and a prune of old state;
 * - hold(): at pre_tool_call, when no unit blocked or asked, a pending notice may hold the call once;
 * - kick(): at post_tool_call and post_llm_call, after the answer is printed, starts the due units in the background.
 *   A one-shot run (HookAsync::ONESHOT_ENV) still records at post_llm_call but starts nothing there: its process ends
 *   after the turn, so a background result could never be shown, and a check judged at the end of the turn
 *   (lib/HaltRun.php) would find the run already taken.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HookPending.php';
require_once __DIR__ . '/PathName.php';

class HookFeed
{
    const RECORD_EVENTS = ['pre_llm_call', 'post_llm_call', 'post_tool_call'];
    const KEEP_DAYS = 7;

    /** The feed for this event (units on, session), or null; records this event's records. */
    public static function start($event, array $payload)
    {
        try {
            $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
            $config = Config::load();
            $units = $session === '' ? [] : HookInterval::on($config);
            if (!$units) {
                return null;
            }
            $feed = ['event' => $event, 'session' => $session, 'hash' => HookAsync::sessionHash($session), 'units' => $units, 'config' => $config];
            if (in_array($event, self::RECORD_EVENTS, true)) {
                HookSpool::append(Config::localDir(), $session, HookRecords::forEvent($event, $payload), $units);
            }
            return $feed;
        } catch (\Throwable $e) {
            HookLog::add($event, 'async', 0, 'async-record-error');
            return null;
        }
    }

    /** pre_llm_call: the pending texts as one context ('' when none); $payload's conversation verifies quoted requests. */
    public static function contexts($feed, array $payload = [])
    {
        if ($feed === null) {
            return '';
        }
        try {
            self::prune(Config::localDir(), $feed['config'], $feed['units']);
            $history = isset($payload['extra']['conversation_history']) && is_array($payload['extra']['conversation_history']) ? $payload['extra']['conversation_history'] : [];
            return HookPending::contexts(Config::localDir(), $feed['units'], $feed['hash'], $history);
        } catch (\Throwable $e) {
            HookLog::add('pre_llm_call', 'async', 0, 'async-deliver-error');
            return '';
        }
    }

    /** pre_tool_call: a block holding the call, or null. Called only when the merged answer was no opinion. */
    public static function hold($feed, array $payload)
    {
        if ($feed === null) {
            return null;
        }
        try {
            $extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
            return HookPending::hold(Config::localDir(), $feed['units'], $feed['hash'], isset($payload['tool_name']) ? (string) $payload['tool_name'] : '',
                isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [], isset($extra['turn_id']) && is_scalar($extra['turn_id']) ? (string) $extra['turn_id'] : '');
        } catch (\Throwable $e) {
            HookLog::add('pre_tool_call', 'async', 0, 'async-deliver-error');
            return null;
        }
    }

    /** post_tool_call, post_llm_call: start the due units' workers. */
    public static function kick($feed)
    {
        if ($feed === null || !in_array($feed['event'], ['post_tool_call', 'post_llm_call'], true)
            || ($feed['event'] === 'post_llm_call' && (string) getenv(HookAsync::ONESHOT_ENV) !== '')) {
            return [];
        }
        try {
            return HookAsync::kick(Config::localDir(), $feed['units'], $feed['session'], Config::root() . '/hooks/worker.php');
        } catch (\Throwable $e) {
            HookLog::add($feed['event'], 'async', 0, 'async-start-failed');
            return [];
        }
    }

    /**
     * Files of local/state/async older than min(KEEP_DAYS, sessions.retentionDays when above 0) days are removed; a
     * context or notice removed before it was shown is logged as pruned-undelivered for its unit, so doctor counts it.
     */
    public static function prune($localDir, array $config, array $units = [])
    {
        $ids = [];
        foreach (array_keys($units) as $id) {
            $ids[PathName::base(HookSpool::unitDir($localDir, $id))] = $id;
        }
        $days = isset($config['sessions']['retentionDays']) && (int) $config['sessions']['retentionDays'] > 0 ? min(self::KEEP_DAYS, (int) $config['sessions']['retentionDays']) : self::KEEP_DAYS;
        $cut = time() - $days * 86400;
        foreach ((array) glob(HookSpool::dir($localDir) . '/*/*') as $file) {
            if (is_file($file) && !is_link($file) && @filemtime($file) < $cut && @unlink($file) && preg_match('/\.(context|notice)\.json$/', $file)) {
                $dir = PathName::base(dirname($file));
                HookLog::add('async', isset($ids[$dir]) ? $ids[$dir] : $dir, 0, 'pruned-undelivered');
            }
        }
    }
}
