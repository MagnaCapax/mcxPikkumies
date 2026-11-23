<?php
/**
 * RunBound: the step bound Pikkumies puts on every Hermes run, and the time limit on a one-shot's resumes (ADR-0126, GH #30).
 *
 * Hermes' `agent.max_turns` (hermes_cli/config_defaults.py "agent", off by default) stops a turn after that many model
 * requests and asks the model for a last answer from what it has. Pikkumies pins it in the managed layer
 * (HermesConfig::writeConfig), so a one-shot run, a support job's run, a halt-guard resume and an interactive turn all carry
 * it; `pikkumies run chat --max-turns N` still wins for one call. Hermes' `agent.run_budget_seconds` is per session and only
 * a notice, so it is NOT pinned: it would reach every interactive turn and Eternal window. The time limit is Pikkumies' own
 * and only HaltRun uses it: a one-shot that has used its time is not resumed.
 *
 * run.maxTurns and run.budgetSeconds come from local/config.php, 0 = no bound. They are not settable by the agent
 * (Config::SETTABLE): a bound the agent could raise is not a bound.
 */
class RunBound
{
    /** Model requests per turn, 0 = no bound. */
    public static function maxTurns(array $config)
    {
        return self::value($config, 'maxTurns');
    }

    /** Seconds the whole one-shot may run before HaltRun stops resuming it; 0 = no bound. */
    public static function budgetSeconds(array $config)
    {
        return self::value($config, 'budgetSeconds');
    }

    /** The managed Hermes config's `agent:` section for the step bound; '' when it is off. */
    public static function yaml(array $config)
    {
        $turns = self::maxTurns($config);
        return $turns ? "agent:\n  max_turns: $turns\n" : '';
    }

    /** A positive whole number is the bound, an int 0 switches it off; anything else (a typo, a string) is the default, never "no bound". */
    private static function value(array $config, $key)
    {
        $v = isset($config['run'][$key]) ? $config['run'][$key] : null;
        if (is_int($v) && $v >= 0) {
            return $v;
        }
        return Config::defaults()['run'][$key];
    }
}
