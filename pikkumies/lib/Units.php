<?php
/**
 * Units: the tool units under tools/<category>/<tool>/ (ADR-0015), at any depth (tools/email/imap/<tool>/,
 * ADR-0055), and what generate() makes of them: local/generated/skills/<category>/<tool>/SKILL.md for Hermes,
 * and tools/INDEX.md for the agent. Both are generated and gitignored. A unit is skill.md (frontmatter name +
 * description), USAGE.md (its verbs table is the one list of verbs), README.md, and <tool>.php if it has a tool.
 */
require_once __DIR__ . '/Tree.php';
require_once __DIR__ . '/Links.php';

class Units
{
    const VERBS_HEADER = '| verb | arguments | changes | what it does |';

    // Names Hermes v0.21.5 (tag v2026.9.24) already uses: registered tools, toolsets and built-in slash
    // commands. A skill with one of these names competes with the built-in. Hermes now updates on its own
    // (ADR-0033): a new built-in name shows up as a collision in ContractTest's installed-source test.
    const RESERVED_NAMES = 'a2a agents annotate_preview apply_layout approvals approve battery bg blueprint branch browser browser_cdp browser_dialog browser_exec browser_vault_enter_code browser_vault_fill browser_vault_list browser_vault_save_login browser_vault_unlock btw bundles busy clarify clear close_terminal code_execution codex-runtime commands compress computer_use config connections context context_engine copy cron cronjob cronjob_manage curator debug delegate_task delegation deny desktop_preview desktop_project diff drive_preview egress execute_code export fast feishu_doc_read file focus focus_pane footer goal gui_tour handoff hatch heartbeat help history homeassistant image image_gen image_generate import indicator init insights journey kanban learn login loop manage_catalog manage_connections meet memory moa model new palette paste patch pause personality pet plan platform platforms plugins process_manage profile prompt queue quit react_to_message read_file read_terminal read_window_below reasoning redraw refine relay reload reload-mcp reload-skills restart resume retry review rollback save search_files sessions session_search sethome show_tip skill_manage skills skills_list skill_view skin snapshot spotify start status statusbar steer stop stt subgoal subscription suggestions terminal text_to_speech timestamps title todo todo_list tools toolsets topic topup tts undo update usage verbose version video video_analyze video_gen video_generate vision vision_analyze voice wake web web_extract web_search whoami worktree write_file x_search yolo yuanbao';

    public static function toolsDir()
    {
        return Config::root() . '/tools';
    }

    public static function generatedDir()
    {
        return Config::localDir() . '/generated/skills';
    }

    /**
     * Every unit with its parsed skill, verbs and problems (an empty 'errors' list means usable), by category
     * then name. A name Hermes already uses, or the second unit with one name, is an error: Hermes would
     * refuse an ambiguous name (tools/skills_tool.py).
     */
    public static function all($toolsDir = null)
    {
        $units = [];
        $seen = [];
        $reserved = explode(' ', self::RESERVED_NAMES);
        foreach (Tree::units($toolsDir ?: self::toolsDir(), 'skill.md') as $t) {
            $dir = $t['dir'];
            $name = $t['name'];
            list($meta, $body) = self::frontmatter((string) @file_get_contents("$dir/skill.md"));
            $usage = is_file("$dir/USAGE.md") ? (string) file_get_contents("$dir/USAGE.md") : null;
            $u = ['category' => $t['category'], 'name' => $name, 'dir' => $dir, 'meta' => $meta, 'body' => $body,
                'verbs' => $usage === null ? [] : self::verbs($usage), 'script' => is_file("$dir/$name.php") ? "$dir/$name.php" : '', 'errors' => []];
            if ($t['problem'] !== '') {
                // Tree could not read it (symlink, unreadable, too deep): that one reason, not the follow-on errors.
                $u['errors'] = [$t['problem']];
                $units[] = $u;
                continue;
            }
            if (!isset($meta['name']) || $meta['name'] !== $name || array_diff(array_keys($meta), ['name', 'description'])) {
                $u['errors'][] = 'skill.md frontmatter must be exactly name (= directory) and description';
            }
            if (empty($meta['description'])) {
                $u['errors'][] = 'skill.md needs a description';
            }
            if ($usage === null || !is_file("$dir/README.md")) {
                $u['errors'][] = 'USAGE.md and README.md are required';
            } elseif ($u['script'] !== '' && !$u['verbs']) {
                $u['errors'][] = 'USAGE.md has no verbs table (' . self::VERBS_HEADER . ')';
            }
            if (in_array($name, $reserved, true)) {
                $u['errors'][] = 'the name is one Hermes already uses (tool, toolset or command); rename the unit';
            } elseif (isset($seen[$name])) {
                $u['errors'][] = "the name is already used by tools/{$seen[$name]}; Hermes cannot load an ambiguous skill name";
            } elseif (!$u['errors']) {
                $seen[$name] = "{$u['category']}/$name";
            }
            $units[] = $u;
        }
        return $units;
    }

    /** True when $category is switched off: it, or a category it lies in, is listed (tools.disabled = path prefixes). */
    public static function isDisabled($category, array $disabled)
    {
        foreach ($disabled as $p) {
            $p = trim((string) $p, '/');
            if ($p !== '' && Tree::within($category, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Category rules for the agent: the DOCTRINE.md of every category directory (at any depth) that is not
     * disabled and still has at least one enabled unit below it, as [category path => text], sorted. They go
     * into SOUL.md (ADR-0029); a disabled category's rules disappear with its skills.
     */
    public static function doctrines(array $disabled = [], $toolsDir = null)
    {
        $scan = Tree::scan($toolsDir ?: self::toolsDir(), 'skill.md');
        $out = [];
        foreach ($scan['categories'] as $category => $dir) {
            $text = is_file("$dir/DOCTRINE.md") ? trim((string) @file_get_contents("$dir/DOCTRINE.md")) : '';
            if ($text === '' || self::isDisabled($category, $disabled)) {
                continue;
            }
            foreach ($scan['units'] as $u) {
                if (Tree::within($u['category'], $category) && !self::isDisabled($u['category'], $disabled)) {
                    $out[$category] = $text;
                    break;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** "---\nkey: value\n---\nbody" -> [[key => value], body]. Values may be "quoted". */
    public static function frontmatter($text)
    {
        if (!preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', str_replace("\r\n", "\n", $text), $m)) {
            return [[], $text];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z][a-z0-9_-]*):\s*(.*)$/', $line, $kv)) {
                $v = trim($kv[2]);
                $meta[$kv[1]] = preg_match('/^"(.*)"$/s', $v, $q) ? stripcslashes($q[1]) : $v;
            }
        }
        return [$meta, ltrim($m[2], "\n")];
    }

    /** Rows of the first "| verb | ... |" table; a literal pipe inside a cell is written \|. */
    public static function verbs($usage)
    {
        $verbs = [];
        $in = false;
        foreach (explode("\n", $usage) as $line) {
            $line = trim($line);
            if (!$in) {
                $in = (bool) preg_match('/^\|\s*verb\s*\|/i', $line);
                continue;
            }
            if (preg_match('/^\|[\s|:-]+\|$/', $line)) {
                continue; // separator row
            }
            if ($line === '' || $line[0] !== '|') {
                break;
            }
            $cells = array_map(function ($c) {
                return trim(str_replace('\|', '|', $c));
            }, preg_split('/(?<!\\\\)\|/', substr($line, 1, -1)));
            if (count($cells) >= 4) {
                $verbs[] = ['verb' => trim($cells[0], '` '), 'args' => $cells[1], 'changes' => $cells[2], 'does' => $cells[3]];
            }
        }
        return $verbs;
    }

    public static function renderSkill(array $u)
    {
        $out = "---\nname: " . $u['name'] . "\ndescription: " . json_encode($u['meta']['description'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n---\n"
            . "<!-- Generated from tools/{$u['category']}/{$u['name']}/skill.md. To change it, copy it under a new name into your own skills (ADR-0007). -->\n\n"
            . rtrim($u['body']) . "\n";
        if ($u['verbs']) {
            $out .= "\n## Verbs\n\n";
            if ($u['script'] !== '') {
                $out .= "Run: `php {$u['script']} <verb> [arguments]`. Output is JSON Lines; the last line says `ok` or gives `error` and `hint`. Add `--human` to show it to the user.\n";
            }
            $out .= "Full usage with examples: `{$u['dir']}/USAGE.md`\n\n" . self::VERBS_HEADER . "\n|---|---|---|---|\n";
            foreach ($u['verbs'] as $v) {
                $out .= '| ' . implode(' | ', array_map(function ($c) {
                    return str_replace('|', '\|', $c);
                }, [$v['verb'], $v['args'], $v['changes'], $v['does']])) . " |\n";
            }
        } else {
            $out .= "\nFull usage: `{$u['dir']}/USAGE.md`\n";
        }
        return Links::stripAdrLinks($out); // the copy sits under local/, where a relative ADR link points nowhere (ADR-0060)
    }

    public static function renderIndex(array $units)
    {
        $out = "# Pikkumies tools\n\nGenerated by pikkumies; do not edit. One line per tool: what it is for, and its verbs (* = changes something). Run a tool with `--help`, or read its USAGE.md.\n";
        foreach (Tree::byCategory($units) as $category => $group) {
            $out .= "\n## $category\n";
            foreach ($group as $u) {
                $verbs = array_map(function ($v) {
                    return $v['verb'] . (in_array(strtolower($v['changes']), ['', 'no', '-'], true) ? '' : '*');
                }, $u['verbs']);
                $out .= "- **{$u['name']}** (`{$u['dir']}`): {$u['meta']['description']}" . ($verbs ? ' Verbs: ' . implode(', ', $verbs) . '.' : '') . "\n";
            }
        }
        return $out;
    }
}
