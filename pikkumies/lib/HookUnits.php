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
     * agent/verify_hooks.py, ADR-0047); post_api_request is an observer after each model request, with the usage under
     * `extra` (agent/turn_response_intake.py, ADR-0061).
     */
    const EVENTS = ['pre_tool_call', 'pre_llm_call', 'post_tool_call', 'pre_verify', 'post_api_request'];
    const FIELDS = ['name', 'event', 'description', 'failClosed', 'timeout'];
    const MAX_TIMEOUT = 60;
    const MD_MAX = 65536; // bytes of hook.md read; a longer one is a broken unit
    /**
     * The tools a hook under hooks/tools/<tool>/ may name besides a Pikkumies unit (ADR-0054): every literal
     * registry.register(name=...) in Hermes v0.22.2 (d923a5eb4) tools/, without example_tool. A tool registered under a
     * computed name (Home Assistant, MCP servers, plugins) is not here, and a hook for one is refused until it is added.
     */
    const HERMES_TOOLS = 'annotate_preview browser_cdp browser_dialog browser_exec browser_vault clarify close_terminal computer_use cronjob_manage delegate_task desktop_preview desktop_project drive_preview execute_code feishu_doc_read focus_pane gui_tour image_generate manage_catalog manage_connections memory patch process_manage react_to_message read_file read_terminal read_window_below search_files session_search set_theme setup_beat show_tip skill_manage skills_list skill_view terminal text_to_speech todo_list video_analyze video_generate vision_analyze web_extract web_search write_file x_search';
    /**
     * Characters of an approve message the user is sure to see in full (ADR-0054). Hermes shows a hook's approve message
     * as the description of its approval panel: on a 24x80 terminal that is 7 rows of 64 columns (Hermes v0.22.2:
     * hermes_cli/cli_tui_mixin.py _PANEL_RESERVED_BELOW, _get_approval_display_fragments; hermes_cli/cli_render.py
     * _panel_box_width). That has four choices: the fifth, "view", appears only for a command over 70 characters
     * (cli_modal_mixin.py _approval_choices), and a hook's command is the short label "<tool> (plugin approval rule)"
     * (tools/approval.py request_tool_approval). 448 columns, less what word wrapping leaves empty at line ends. A merged question longer than this is blocked
     * instead of shown in part.
     */
    const APPROVE_SHOWN = 350;

    /**
     * $message's character count: Unicode characters, or a byte count on invalid UTF-8. The one counting a unit
     * uses both to decide whether a message fits self::APPROVE_SHOWN (approveShown(), below) and to state how much
     * of something it had to cut, such as an excerpt's real length (ADR-0020 2026-10-19 amendments).
     */
    public static function charCount($message)
    {
        $message = (string) $message;
        $chars = preg_match_all('/./su', $message);
        return $chars === false ? strlen($message) : $chars;
    }

    /**
     * Whether $message's character count fits self::APPROVE_SHOWN, the one limit every unit that builds its own
     * approve message checks against (ADR-0054, ADR-0020 2026-10-19 amendment): the user must see everything they
     * approve, so a unit checks the message it is about to send, not only the command inside it. Counted the way
     * HookAnswers::mergeApproves counts a merged question (Unicode characters; a byte count on invalid UTF-8).
     */
    public static function approveShown($message)
    {
        return self::charCount($message) <= self::APPROVE_SHOWN;
    }

    public static function hooksDir()
    {
        return Config::root() . '/hooks';
    }

    /**
     * Every unit with its parsed hook.md and problems (an empty 'errors' list means usable), by category then name.
     * Units sit at any depth (ADR-0055). Below hooks/<category>/ a directory with hook.md or <hook>.php, or with no
     * subdirectory, is a unit, also when its hook.md is missing or unreadable: a broken hook.md must show up as a
     * broken unit, never make the unit disappear (ADR-0030). A symlinked directory is a broken unit too.
     * A unit in hooks/tools/<tool>/ is a tool hook (ADR-0054): 'tool' is <tool>, 'toolCategory' the category of the
     * Pikkumies unit it names ('' for a Hermes tool); the tool units are read from $toolsDir (default: tools/ beside).
     */
    public static function all($hooksDir = null, $toolsDir = null)
    {
        $hooksDir = $hooksDir ?: self::hooksDir();
        $units = [];
        $toolUnits = null; // [name => category] of the tool units, read once and only when a tool hook exists
        foreach (Tree::units($hooksDir, 'hook.md', true) as $t) {
            $dir = $t['dir'];
            $name = $t['name'];
            $file = "$dir/hook.md";
            // Read with a cap: a huge hook.md (a local one is the user's) must not exhaust memory in the orchestrator.
            $text = is_file($file) ? (string) @file_get_contents($file, false, null, 0, self::MD_MAX + 1) : '';
            list($meta, $body) = self::frontmatter(strlen($text) > self::MD_MAX ? '' : $text);
            $u = ['category' => $t['category'], 'name' => $name, 'dir' => $dir, 'meta' => $meta, 'body' => $body,
                'script' => "$dir/$name.php", 'event' => isset($meta['event']) ? $meta['event'] : '',
                'failClosed' => isset($meta['failClosed']) && $meta['failClosed'] === 'true',
                'timeout' => isset($meta['timeout']) && ctype_digit($meta['timeout']) ? (int) $meta['timeout'] : 0,
                'errors' => $t['problem'] !== '' ? [$t['problem']] : [], 'tool' => '', 'toolCategory' => ''];
            if ($t['problem'] !== '') {
                // Tree could not read it (symlink, unreadable, too deep): that one reason, not the follow-on errors.
                $units[] = $u;
                continue;
            }
            if (strlen($text) > self::MD_MAX) {
                $u['errors'][] = 'hook.md is larger than ' . self::MD_MAX . ' bytes';
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
            if (Tree::within($t['category'], 'tools')) {
                if ($toolUnits === null) {
                    $toolUnits = self::toolUnits($toolsDir !== null ? $toolsDir : dirname($hooksDir) . '/tools');
                }
                $u['tool'] = (string) substr($t['category'], strlen('tools/'));
                $u['toolCategory'] = isset($toolUnits[$u['tool']]) ? $toolUnits[$u['tool']] : '';
                $u['errors'] = array_merge($u['errors'], self::toolErrors($u, $toolUnits));
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
        return self::byName($out);
    }

    /** Units in the orchestrator's run order: name, then category. */
    public static function byName(array $units)
    {
        usort($units, function ($a, $b) {
            return strcmp($a['name'], $b['name']) ?: strcmp($a['category'], $b['category']);
        });
        return $units;
    }

    /** [name => category] of the tool units under $toolsDir that Tree could read. */
    private static function toolUnits($toolsDir)
    {
        $out = [];
        foreach (Tree::units($toolsDir, 'skill.md') as $t) {
            if ($t['problem'] === '') {
                $out[$t['name']] = $t['category'];
            }
        }
        return $out;
    }

    /** A tool hook (ADR-0054) sits at hooks/tools/<tool>/<hook>/, runs before or after a call, and names a real tool. */
    private static function toolErrors(array $u, array $toolUnits)
    {
        $errors = [];
        if ($u['tool'] === '' || strpos($u['tool'], '/') !== false) {
            return ['a tool hook sits in hooks/tools/<tool>/<hook>/'];
        }
        if (!in_array($u['event'], ['pre_tool_call', 'post_tool_call'], true)) {
            $errors[] = 'a tool hook runs on pre_tool_call or post_tool_call';
        }
        if (!isset($toolUnits[$u['tool']]) && !in_array($u['tool'], explode(' ', self::HERMES_TOOLS), true)) {
            $errors[] = "tools/{$u['tool']} names neither a Pikkumies tool unit nor a Hermes tool";
        }
        return $errors;
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
