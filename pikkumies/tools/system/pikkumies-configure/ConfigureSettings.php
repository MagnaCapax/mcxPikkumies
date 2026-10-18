<?php
/**
 * ConfigureSettings: what the pikkumies-configure tool shows and changes (ADR-0032). It reads the
 * merged config and writes only Config::SETTABLE through Config::set. terms.accepted and every other
 * key are the person's: refused here with the way the person changes them.
 */
class ConfigureSettings
{
    /** The user settings, in display order, with one line each for `review`. */
    const INFO = [
        'agent.name' => 'What the agent calls itself. Empty: not named yet, the agent is "Pikkumies".',
        'privacy.perfLogging' => 'Whether Pikkumies keeps performance logs on this machine: today the memory searches (the words searched and how many results each found) and lesson saves, kept on this machine only, never sent.',
        'privacy.telemetry' => 'Whether Pikkumies may send performance telemetry. A setting only: nothing is sent yet, no sender exists.',
        'sessions.retentionDays' => 'Days of ended Hermes sessions to keep; older ones are pruned at start. 0 keeps them forever.',
        'terms.accepted' => 'Whether the person accepted the terms of service and privacy policy. Only the person sets it.',
    ];

    /** Key prefixes this tool never sets, with why; the first match wins. */
    const REFUSED = [
        'terms.' => 'only the person accepts the terms: they are asked at the first interactive start, or install with `pikkumies install --accept-terms`',
        '' => 'this setting is the person\'s to change, by hand in local/config.php; this tool sets only ',
    ];

    /** One record per setting: key and its effective value. */
    public static function show(array $config)
    {
        $out = [];
        foreach (array_keys(self::INFO) as $key) {
            $out[] = ['key' => $key, 'value' => self::value($config, $key)];
        }
        return $out;
    }

    public static function get(array $config, $key)
    {
        if (!isset(self::INFO[$key])) {
            throw new CliError("unknown setting: $key", 'settings: ' . implode(', ', array_keys(self::INFO)));
        }
        return ['key' => $key, 'value' => self::value($config, $key)];
    }

    /** Parse and write one setting; refuses every key outside Config::SETTABLE. */
    public static function set($key, $text, $file = null)
    {
        if (!isset(Config::SETTABLE[$key])) {
            throw new CliError("$key is not changed by this tool; nothing was changed", self::refusal($key));
        }
        try {
            $value = Config::set($key, Config::parse($key, $text), $file);
        } catch (InvalidArgumentException $x) {
            throw new CliError($x->getMessage() . '; nothing was changed', 'see: pikkumies-configure.php review');
        }
        return ['key' => $key, 'value' => $value, 'note' => 'takes effect when the next session starts'];
    }

    public static function refusal($key)
    {
        foreach (self::REFUSED as $prefix => $why) {
            if ($prefix === '' || strpos($key, $prefix) === 0) {
                return $prefix === '' ? $why . implode(', ', array_keys(Config::SETTABLE)) : $why;
            }
        }
        return '';
    }

    /** Every setting with its value, what it means, whether this tool may set it, and any warning. */
    public static function review(array $config, $stateDb = null)
    {
        $out = [];
        foreach (self::INFO as $key => $means) {
            $r = ['key' => $key, 'value' => self::value($config, $key), 'means' => $means, 'settable' => isset(Config::SETTABLE[$key])];
            $warning = self::warning($config, $key, $stateDb);
            if ($warning !== '') {
                $r['warning'] = $warning;
            }
            $out[] = $r;
        }
        return $out;
    }

    private static function warning(array $config, $key, $stateDb)
    {
        $v = self::value($config, $key);
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

    private static function value(array $config, $key)
    {
        list($section, $name) = explode('.', $key, 2);
        return isset($config[$section][$name]) ? $config[$section][$name] : null;
    }
}
