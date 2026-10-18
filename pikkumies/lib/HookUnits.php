<?php
/**
 * HookUnits: the hook units under hooks/<category>/<hook>/ (ADR-0030), laid out like the tool units
 * (ADR-0015). A unit is hook.md (frontmatter name, event, description, failClosed, timeout),
 * README.md and <hook>.php. Hermes runs one entry per event, hooks/orchestrator.php, which runs the
 * units of that event; hooks/INDEX.md (generated, gitignored) lists them.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Tree.php';

class HookUnits
{
    /**
     * The events the orchestrator runs: pre_tool_call and pre_llm_call are merged (hermes_cli/plugins.py,
     * agent/turn_context.py); post_tool_call is an observer whose answer Hermes ignores (model_tools.py, ADR-0044);
     * pre_verify's first continue answer is the one Hermes keeps the turn going on (agent/turn_stop_gates.py,
     * agent/verify_hooks.py, ADR-0047).
     */
    const EVENTS = ['pre_tool_call', 'pre_llm_call', 'post_tool_call', 'pre_verify'];
    const FIELDS = ['name', 'event', 'description', 'failClosed', 'timeout'];
    const MAX_TIMEOUT = 60;

    public static function hooksDir()
    {
        return Config::root() . '/hooks';
    }

    /**
     * Every unit with its parsed hook.md and problems (an empty 'errors' list means usable), by category then name.
     * Units sit at any depth (ADR-0055). Below hooks/<category>/ a directory with hook.md or <hook>.php, or with no
     * subdirectory, is a unit, also when its hook.md is missing or unreadable: a broken hook.md must show up as a
     * broken unit, never make the unit disappear (ADR-0030). A symlinked directory is a broken unit too.
     */
    public static function all($hooksDir = null)
    {
        $units = [];
        foreach (Tree::units($hooksDir ?: self::hooksDir(), 'hook.md', true) as $t) {
            $dir = $t['dir'];
            $name = $t['name'];
            $file = "$dir/hook.md";
            list($meta, $body) = self::frontmatter(is_file($file) ? (string) file_get_contents($file) : '');
            $u = ['category' => $t['category'], 'name' => $name, 'dir' => $dir, 'meta' => $meta, 'body' => $body,
                'script' => "$dir/$name.php", 'event' => isset($meta['event']) ? $meta['event'] : '',
                'failClosed' => isset($meta['failClosed']) && $meta['failClosed'] === 'true',
                'timeout' => isset($meta['timeout']) && ctype_digit($meta['timeout']) ? (int) $meta['timeout'] : 0,
                'errors' => $t['problem'] !== '' ? [$t['problem']] : []];
            if ($t['problem'] !== '') {
                // Tree could not read it (symlink, unreadable, too deep): that one reason, not the follow-on errors.
                $units[] = $u;
                continue;
            }
            $keys = array_keys($meta);
            sort($keys);
            $want = self::FIELDS;
            sort($want);
            if (!isset($meta['name']) || $meta['name'] !== $name || $keys !== $want) {
                $u['errors'][] = 'hook.md frontmatter must be exactly name (= directory), event, description, failClosed and timeout';
            }
            if (!in_array($u['event'], self::EVENTS, true)) {
                $u['errors'][] = 'event must be one of ' . implode(', ', self::EVENTS);
            }
            if (empty($meta['description'])) {
                $u['errors'][] = 'hook.md needs a description';
            }
            if (!isset($meta['failClosed']) || !in_array($meta['failClosed'], ['true', 'false'], true)) {
                $u['errors'][] = 'failClosed must be true or false';
            } elseif ($u['failClosed'] && $u['event'] !== 'pre_tool_call') {
                // Hermes honours fail_closed only where a block means something (agent/shell_hooks.py _BLOCKING_EVENTS).
                $u['errors'][] = 'failClosed true is only meaningful for pre_tool_call';
            }
            if ($u['timeout'] < 1 || $u['timeout'] > self::MAX_TIMEOUT) {
                $u['errors'][] = 'timeout must be whole seconds from 1 to ' . self::MAX_TIMEOUT;
            }
            if (!is_file($file) || !is_file("$dir/README.md") || !is_file($u['script'])) {
                $u['errors'][] = "hook.md, README.md and $name.php are required";
            }
            $units[] = $u;
        }
        return $units;
    }

    /**
     * The units that declare this event, in name order (the order the orchestrator runs them).
     * Units with problems are included: the orchestrator refuses a broken unit on pre_tool_call instead of
     * silently skipping it. For pre_tool_call that is every broken unit, whatever event it declares: a
     * broken hook.md cannot be trusted to say which event or failClosed it meant.
     */
    public static function forEvent($event, $hooksDir = null)
    {
        $out = [];
        foreach (self::all($hooksDir) as $u) {
            if ($u['event'] === $event || ($u['errors'] && $event === 'pre_tool_call')) {
                $out[] = $u;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($a['name'], $b['name']) ?: strcmp($a['category'], $b['category']);
        });
        return $out;
    }

    /** [event => units in name order] for every known event that has at least one unit. */
    public static function byEvent($hooksDir = null)
    {
        $out = [];
        foreach (self::EVENTS as $event) {
            $units = self::forEvent($event, $hooksDir);
            if ($units) {
                $out[$event] = $units;
            }
        }
        ksort($out);
        return $out;
    }

    /** Units::frontmatter, but keys may be camelCase (failClosed). */
    public static function frontmatter($text)
    {
        if (!preg_match("/\\A---\\n(.*?)\\n---\\n?(.*)\\z/s", str_replace("\r\n", "\n", $text), $m)) {
            return [[], $text];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match("/^([a-zA-Z][a-zA-Z0-9_-]*):\\s*(.*)$/", $line, $kv)) {
                $v = trim($kv[2]);
                $meta[$kv[1]] = preg_match("/^\"(.*)\"$/s", $v, $q) ? stripcslashes($q[1]) : $v;
            }
        }
        return [$meta, ltrim($m[2], "\n")];
    }

    public static function renderIndex(array $units)
    {
        $out = "# Pikkumies hooks\n\nGenerated by pikkumies; do not edit. One line per hook: the event it runs on, whether a failure blocks the call (fail closed), and what it does. hooks/orchestrator.php runs them; each has a README.md.\n";
        foreach (Tree::byCategory($units) as $category => $group) {
            $out .= "\n## $category\n";
            foreach ($group as $u) {
                $out .= "- **{$u['name']}** (`{$u['dir']}`): {$u['event']}, " . ($u['failClosed'] ? 'fail closed' : 'fail open')
                    . ", {$u['timeout']}s. {$u['meta']['description']}\n";
            }
        }
        return $out;
    }
}
