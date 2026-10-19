<?php
/**
 * ConfigureTiers: who makes a settings change (ADR-0059). Every key of Config::defaults(), host.* and arr.<app>.config
 * has a rule, and the change from the current value to the new one is one of three tiers:
 * - free: the agent makes it when the user asks (the tightening direction, or harmless either way);
 * - approve: the orchestrator's ask policy decides first (the config-change hook, ADR-0073): it runs when the user's
 *   own words already asked for it, and otherwise is blocked until the user says yes in the chat; with no evidence of
 *   the user's words (cron and other unattended runs) a setting that deletes history stays blocked, every other
 *   setting in this tier runs;
 * - person: the configure tool refuses; the person edits local/config.php or uses an install flag.
 * The one decision the configure tool, the config-change hook and Config::set (which refuses the person's changes for
 * every caller) use. A safety prompt, not security: the agent's user can edit local/config.php anyway (AGENTS.md).
 */
class ConfigureTiers
{
    const FREE = 'free';
    const APPROVE = 'approve';
    const PERSON = 'person';

    /**
     * key => rule: ['any', tier]; ['bool', tier when set true, tier when set false]; ['list', tier when a name is added,
     * tier when a name is removed; a change that does both takes the stricter]; ['retention'] (shorter asks: Hermes deletes the history; 0 = forever, the longest);
     * ['channel'] (release free, git/<branch> asks: unreleased code); ['every', tier when more often, tier when less often or
     * off] for hooks.every.<unit name>, filled in by rule() from the unit (ADR-0067).
     */
    const RULES = [
        'provider.endpoint' => ['any', 'person'],
        'provider.keyFile' => ['any', 'person'],
        'provider.virsilipasKeyFile' => ['any', 'person'],
        'provider.model' => ['any', 'approve'],
        'root.disabled' => ['bool', 'free', 'person'],
        'skills.disabled' => ['list', 'free', 'free'],
        'tools.disabled' => ['list', 'approve', 'free'],
        'searchFirst.disabled' => ['bool', 'person', 'free'],
        'halt.disabled' => ['bool', 'person', 'free'],
        'lowBalance.disabled' => ['bool', 'approve', 'free'],
        'feedback.ask' => ['bool', 'approve', 'free'],
        'fileopsCheck.disabled' => ['bool', 'person', 'free'],
        'memoryCheck.disabled' => ['bool', 'person', 'free'],
        'hooks.reword' => ['bool', 'free', 'approve'],
        'hooks.llm.disabled' => ['bool', 'person', 'free'],
        'hooks.async.disabled' => ['bool', 'person', 'free'],
        'hooks.mutationCheck.holdsPerTurn' => ['any', 'person'],
        'hooks.model' => ['any', 'approve'],
        'autoUpdate.disabled' => ['bool', 'person', 'free'],
        'cron.disabled' => ['bool', 'approve', 'approve'],
        'update.channel' => ['channel'],
        'hermes.lazyInstalls' => ['bool', 'person', 'free'],
        'agent.name' => ['any', 'free'],
        'privacy.perfLogging' => ['any', 'free'],
        'privacy.telemetry' => ['any', 'free'],
        'sessions.retentionDays' => ['retention'],
        'terms.accepted' => ['any', 'person'],
        'ssh.allow' => ['list', 'approve', 'free'],
        'ssh.deny' => ['list', 'free', 'person'],
        'ssh.blockUnknown' => ['bool', 'free', 'approve'],
        // On sends each search query to DuckDuckGo: the person's own `pikkumies websearch on`, never the agent (ADR-0082).
        'web.search' => ['bool', 'person', 'free'],
    ];
    /** Keys by pattern: host.* overrides what detection found; arr.<app>.config only names where an app keeps its config. */
    const PATTERNS = ['/^host\./' => ['any', 'person'], '/^arr\.[a-z][a-z0-9-]{0,31}\.config\z/' => ['any', 'free']];
    /**
     * hooks.every.<unit name> (ADR-0067): a guard unit is a safety check, so more often is free and less often or off is the
     * person's; any other unit may call the model on the user's credits, so less often or off is free and more often asks.
     */
    const EVERY = '/^hooks\.every\.([a-z0-9][a-z0-9-]{0,63})\z/';
    /** What an asked change does, for the person reading Hermes' prompt; a switch has [when set true, when set false]. */
    const WHY = [
        'provider.model' => 'changes the model every session uses, and so what a session costs',
        'hooks.model' => 'changes the model the safety hooks\' judges use; one that does not answer them well silently turns those judges off',
        'tools.disabled' => 'turns off whole tool categories',
        'lowBalance.disabled' => 'hides the low-credits notice',
        'feedback.ask' => ['lets Pikkumies now and then ask, in a session, whether you want to send Pulsed Media feedback; the question itself sends nothing', null],
        'cron.disabled' => ['stops the per-minute ticker, so the scheduled jobs you set up in Hermes stop running until it is turned back on',
            'lets Hermes\' scheduled jobs run unattended, every minute, from your crontab'],
        'hooks.reword' => [null, 'stops the model turning a question with no local hit into search terms, so such a question is searched with the words as typed'],
        'update.channel' => 'makes updates follow every change on that branch, code that has not been through a release',
        'sessions.retentionDays' => 'makes Hermes delete ended sessions older than that at its next start',
        'ssh.allow' => 'lets the agent connect over SSH to these hosts without asking you each time (it still says why)',
        'ssh.blockUnknown' => [null, 'lets the agent ask you about SSH hosts that are not on the allow list, instead of refusing them'],
        'hooks.every' => 'runs that background check more often, and each run may call the model on your credits',
    ];

    /** What changing $key to $new does, for the question; '' when there is nothing to add. */
    public static function why($key, $new)
    {
        $why = isset(self::WHY[$key]) ? self::WHY[$key] : (preg_match(self::EVERY, (string) $key) ? self::WHY['hooks.every'] : '');
        if (is_array($why)) {
            $why = $new === true ? $why[0] : $why[1];
        }
        return (string) $why;
    }

    /** The rule for $key, or null when it has none (then nobody but the person changes it). */
    public static function rule($key)
    {
        if (isset(self::RULES[$key])) {
            return self::RULES[$key];
        }
        foreach (self::PATTERNS as $re => $rule) {
            if (is_string($key) && preg_match($re, $key) === 1) {
                return $rule;
            }
        }
        $unit = is_string($key) && preg_match(self::EVERY, $key, $m) ? self::intervalUnit($m[1]) : null;
        return $unit === null ? null : ($unit['category'] === 'guard' ? ['every', self::FREE, self::PERSON] : ['every', self::APPROVE, self::FREE]);
    }

    /** The shipped interval unit named $name, or null (loaded only for an every key: scratch copies of this file need not carry it). */
    private static function intervalUnit($name)
    {
        require_once __DIR__ . '/HookInterval.php';
        foreach (HookInterval::units() as $u) {
            if ($u['name'] === $name) {
                return $u;
            }
        }
        return null;
    }

    /** The tier of changing $key from $old to $new. No change at all is free; a key without a rule is the person's. */
    public static function classify($key, $old, $new)
    {
        if (is_array($old) && is_array($new)) {
            sort($old);
            sort($new);
        }
        if ($old === $new) {
            return self::FREE;
        }
        $rule = self::rule($key);
        if ($rule === null) {
            return self::PERSON;
        }
        switch ($rule[0]) {
            case 'any':
                return $rule[1];
            case 'bool':
                return $new === true ? $rule[1] : $rule[2];
            case 'list':
                // Replacing [a] with [b] adds and removes: the stricter tier wins, or a deny entry could be swapped away freely.
                $order = [self::FREE => 0, self::APPROVE => 1, self::PERSON => 2];
                $added = array_diff((array) $new, (array) $old) ? $rule[1] : self::FREE;
                $removed = array_diff((array) $old, (array) $new) ? $rule[2] : self::FREE;
                return $order[$added] >= $order[$removed] ? $added : $removed;
            case 'retention':
                // 0 keeps sessions forever: the longest a retention can be, so 0 -> N shortens it and N -> 0 lengthens it.
                $forever = function ($days) {
                    return (int) $days === 0 ? PHP_INT_MAX : (int) $days;
                };
                return $forever($new) >= $forever($old) ? self::FREE : self::APPROVE;
            case 'channel':
                return $new === 'release' ? self::FREE : self::APPROVE;
            case 'every':
                // Unset is the unit's own default; 0 is off, the least often a check can run.
                $unit = self::intervalUnit(substr($key, strlen('hooks.every.')));
                $seconds = function ($v) use ($unit) {
                    $v = $v === null ? (int) $unit['meta']['every'] : (int) $v;
                    return $v === 0 ? PHP_INT_MAX : $v;
                };
                return $seconds($new) < $seconds($old) ? $rule[1] : $rule[2];
        }
        return self::PERSON;
    }
}
