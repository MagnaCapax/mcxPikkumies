<?php
/**
 * ConfigureSettings: what the pikkumies-configure tool shows (ADR-0032) and the text form of a change (ADR-0059). The
 * change itself, who may make it, its model check and its log are ConfigureChange's; every key here, also those the
 * person changes, is shown with its value, what it means and which change the agent may make.
 */
require_once __DIR__ . '/ConfigureChange.php';

class ConfigureSettings
{
    /** The settings, in display order, with one line each for `review`. */
    const INFO = [
        'agent.name' => 'What the agent calls itself. Empty: not named yet, the agent is "Pikkumies".',
        'privacy.perfLogging' => 'Whether Pikkumies keeps performance logs, on this machine only and never sent: today the time and result counts of each memory search (per tier, and per documentation source by name), its words only when it found no lesson, and the time of each lesson save (local/state/memory-metrics/). Off stops new lines; files already written stay until deleted. With sessions.retentionDays above 0, lines older than that are pruned.',
        'privacy.telemetry' => 'Whether Pikkumies may send performance telemetry. A setting only: nothing is sent yet, no sender exists.',
        'sessions.retentionDays' => 'Days of ended Hermes sessions to keep; older ones are pruned at start. 0 keeps them forever.',
        'update.channel' => 'What `pikkumies update` follows: release (the newest release, the default) or git/<branch> (every change on that branch as it lands, for example git/main).',
        'provider.model' => 'The model every session uses. Empty: the first model the endpoint lists. Checked against the endpoint\'s model list before it is written.',
        'hooks.model' => 'The model the hooks\' judges use (search rewording, the halt guard). Empty: the light model chosen at start.',
        'tools.disabled' => 'Tool categories left out, with their skills and their rules (for example email, or email/imap).',
        'skills.disabled' => 'Shipped skills hidden, for example after copying one under a new name (skills disable|enable <name>).',
        'root.disabled' => 'Hides root\'s tools on a server where this account has root.',
        'searchFirst.disabled' => 'Turns off the first-turn search of the docs and lessons and its gate.',
        'halt.disabled' => 'Turns off the halt guard, which keeps a one-shot run going until the task is done.',
        'lowBalance.disabled' => 'Turns off the once-per-session low-credits notice.',
        'fileopsCheck.disabled' => 'Turns off the re-check of bulk chmod, chown and mv.',
        'hooks.reword' => 'Lets the model turn a question with no local hit into search terms, once.',
        'hooks.llm.disabled' => 'No hook ever calls the model (no rewording, no halt judging).',
        'autoUpdate.disabled' => 'Stops the daily `pikkumies update` from the crontab.',
        'cron.disabled' => 'Stops the per-minute ticker that runs Hermes\' scheduled jobs.',
        'hermes.lazyInstalls' => 'Lets Hermes download optional packages and a browser while it runs.',
        'ssh.allow' => 'SSH hosts the agent may connect to without asking you (names, addresses or patterns such as *.home.example). Each connection still says why.',
        'ssh.deny' => 'SSH hosts the agent must never connect to; this list wins over ssh.allow. Only you remove a host from it.',
        'ssh.blockUnknown' => 'Refuses SSH hosts on neither list instead of asking you about them.',
        'provider.endpoint' => 'The AI endpoint. Empty: the one in the key file.',
        'provider.keyFile' => 'The AI key file read first.',
        'provider.virsilipasKeyFile' => 'The key file the platform writes, read after keyFile.',
        'terms.accepted' => 'Whether the person accepted the terms of service and privacy policy. Only the person sets it.',
    ];
    /** What each rule's tiers read as in `review`. */
    const TIER_WORDS = ['free' => 'the agent may', 'approve' => 'the person is asked', 'person' => 'only the person'];

    /** One record per setting: key and its effective value. */
    public static function show(array $config)
    {
        $out = [];
        foreach (array_keys(self::INFO) as $key) {
            $out[] = ['key' => $key, 'value' => ConfigureChange::value($config, $key)];
        }
        return $out;
    }

    public static function get(array $config, $key)
    {
        if (!isset(self::INFO[$key])) {
            throw new CliError("unknown setting: $key", 'settings: ' . implode(', ', array_keys(self::INFO)));
        }
        return ['key' => $key, 'value' => ConfigureChange::value($config, $key)];
    }

    /** Parse the text form of a value and make the change (ConfigureChange::apply). */
    public static function set($key, $text, $file = null, array $config = null, $log = null)
    {
        if (ConfigureTiers::rule($key) === null) {
            throw new CliError("$key is not a Pikkumies setting; nothing was changed", 'review lists the settings');
        }
        if (Config::type($key) === null) {
            throw new CliError("$key: this one is yours to change; nothing was changed", ConfigureChange::how($key));
        }
        try {
            $value = Config::parse($key, $text);
        } catch (InvalidArgumentException $x) {
            throw new CliError($x->getMessage() . '; nothing was changed', 'see: pikkumies-configure.php review');
        }
        return ConfigureChange::apply($config === null ? Config::fileConfig($file) : $config, $key, $value, $file, $log);
    }

    /** Every setting with its value, what it means, which change the agent may make (`changes`), and any warning. */
    public static function review(array $config, $stateDb = null)
    {
        $out = [];
        foreach (self::INFO as $key => $means) {
            $r = ['key' => $key, 'value' => ConfigureChange::value($config, $key), 'means' => $means, 'settable' => Config::type($key) !== null, 'changes' => self::changes($key)];
            $warning = self::warning($config, $key, $stateDb);
            if ($warning !== '') {
                $r['warning'] = $warning;
            }
            $out[] = $r;
        }
        return $out;
    }

    /** ConfigureTiers' rule for $key in words: "turning it off (true): only the person; back on (false): the agent may". */
    public static function changes($key)
    {
        $rule = ConfigureTiers::rule($key);
        $w = self::TIER_WORDS;
        switch ($rule[0]) {
            case 'bool':
                // A *.disabled key set true turns something off: say so, never "on" for a switch that disables.
                return substr($key, -9) === '.disabled' ? "turning it off (true): {$w[$rule[1]]}; back on (false): {$w[$rule[2]]}"
                    : "on (true): {$w[$rule[1]]}; off (false): {$w[$rule[2]]}";
            case 'list':
                return "adding: {$w[$rule[1]]}; removing: {$w[$rule[2]]}";
            case 'retention':
                return "longer, or 0 (forever): {$w['free']}; shorter: {$w['approve']}";
            case 'channel':
                return "release: {$w['free']}; git/<branch>: {$w['approve']}";
        }
        return "any change: {$w[$rule[1]]}";
    }

    private static function warning(array $config, $key, $stateDb)
    {
        $v = ConfigureChange::value($config, $key);
        if ($key === 'sessions.retentionDays' && $v === 0) {
            $size = $stateDb !== null && is_file($stateDb) ? ' (now ' . round(filesize($stateDb) / 1048576, 1) . ' MB)' : '';
            return 'sessions are kept forever, so Hermes\' session store local/hermes/state.db' . $size . ' keeps growing and counts against the disk quota; a number of days lets Hermes prune old ended sessions';
        }
        if ($key === 'agent.name' && $v === '') {
            return 'not named yet; the next interactive start asks for a name';
        }
        if ($key === 'terms.accepted' && $v !== true) {
            return 'not accepted yet; the next interactive session asks the person';
        }
        return '';
    }
}
