<?php
/**
 * WebSearch: the person's switch for Hermes' web search (ADR-0082). Off by default: Hermes names no search backend and its
 * keyless fallback stays off (ADR-0021), so nothing the agent searches for leaves the machine. `pikkumies websearch on` sets
 * web.search, and every start from then pins ddgs (DuckDuckGo through the ddgs package: no key, no account) in the managed
 * Hermes config; `off` takes the pin out at the next start. The agent never turns it on: Config::set refuses that change for
 * every caller (ConfigureTiers), and this verb refuses `on` from inside an agent, as `configure undo` does. A safety prompt,
 * not security: the agent's user can edit local/config.php anyway (AGENTS.md).
 */
class WebSearch
{
    const COMMAND = 'pikkumies websearch on';

    /** The person's on or off: [ok, what happened]. $file: test seam for local/config.php. */
    public static function turn($on, $file = null)
    {
        if ($on && NestedAgent::depth() >= 1) {
            return [false, 'not changed: `' . self::COMMAND . '` is yours to run in your own shell, not from inside an agent'];
        }
        try {
            Config::setWebSearch($on, $file);
        } catch (Exception $x) {
            return [false, 'not changed: ' . $x->getMessage()];
        }
        if (!$on) {
            return [true, 'turned off; from the next session nothing is searched online'];
        }
        // A Hermes build from before ADR-0082 lacks the ddgs package; joining it is additive, so a repeat is harmless.
        $pkg = HermesInstall::bin() === '' || HermesInstall::addExtra(HermesInstall::WEB_SEARCH_EXTRA) ? ''
            : '; the ddgs package did not install (see ' . Config::shown(HermesInstall::localDir() . '/install.log') . '), so searches fail until `' . self::COMMAND . '` is run again';
        return [true, 'turned on; from the next session each web search sends its query to DuckDuckGo' . $pkg];
    }

    /** One line for `pikkumies websearch status`. */
    public static function status(array $config)
    {
        return !empty($config['web']['search'])
            ? 'web search: on (DuckDuckGo, no key); each search sends its query there. `pikkumies websearch off` turns it off'
            : 'web search: off; nothing is searched online. `' . self::COMMAND . '` turns it on (DuckDuckGo, no key)';
    }
}
