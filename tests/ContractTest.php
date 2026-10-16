<?php
/**
 * Authoring contract for shipped skills and packs, checked the way Hermes reads them (found in the
 * 0.19.0 source; re-check on a pin bump, ADR-0009).
 * A frontmatter Hermes cannot parse does not fail loudly: it logs a warning and lists the skill
 * with an empty description (agent/prompt_builder.py). Descriptions over 1024 characters are
 * cut silently (tools/skills_tool.py). A skill name that exists twice cannot be loaded
 * (tools/skills_tool.py, "Ambiguous skill name").
 */

// Names Hermes v0.21.5 (tag v2026.9.24) already uses: registered tools, toolsets and built-in slash
// commands. A skill with one of these names competes with the built-in. Re-check on a pin bump (ADR-0009).
const HERMES_RESERVED_NAMES = 'a2a agents annotate_preview apply_layout approvals approve battery bg blueprint branch browser browser_cdp browser_dialog browser_exec browser_vault_enter_code browser_vault_fill browser_vault_list browser_vault_save_login browser_vault_unlock btw bundles busy clarify clear close_terminal code_execution codex-runtime commands compress computer_use config connections context context_engine copy cron cronjob cronjob_manage curator debug delegate_task delegation deny desktop_preview desktop_project diff drive_preview egress execute_code export fast feishu_doc_read file focus focus_pane footer goal gui_tour handoff hatch heartbeat help history homeassistant image image_gen image_generate import indicator init insights journey kanban learn login loop manage_catalog manage_connections meet memory moa model new palette paste patch pause personality pet plan platform platforms plugins process_manage profile prompt queue quit react_to_message read_file read_terminal read_window_below reasoning redraw refine relay reload reload-mcp reload-skills restart resume retry review rollback save search_files sessions session_search sethome show_tip skill_manage skills skills_list skill_view skin snapshot spotify start status statusbar steer stop stt subgoal subscription suggestions terminal text_to_speech timestamps title todo todo_list tools toolsets topic topup tts undo update usage verbose version video video_analyze video_gen video_generate vision vision_analyze voice wake web web_extract web_search whoami worktree write_file x_search yolo yuanbao';

/** Every shipped skill source: the skill.md of each tool unit. */
function shippedSkillFiles()
{
    $root = dirname(__DIR__);
    return (array) glob($root . '/pikkumies/tools/*/*/skill.md');
}

/** [name, description] from a SKILL.md, or a string describing what is wrong. */
function skillFrontmatter($file)
{
    $text = (string) file_get_contents($file);
    if (!preg_match('/\A---\n(.*?)\n---\n/s', $text, $m)) {
        return 'no frontmatter block (--- ... ---) at the top';
    }
    $fields = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv)) {
            return "line is not a single-line key: value: $line";
        }
        $fields[$kv[1]] = $kv[2];
    }
    foreach (['name', 'description'] as $k) {
        if (!isset($fields[$k]) || $fields[$k] === '') {
            return "missing $k";
        }
    }
    $desc = $fields['description'];
    if ($desc[0] === '"') {
        if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $desc, $q)) {
            return 'description has an unterminated quote';
        }
        $desc = stripcslashes($q[1]);
    } elseif (strpos($desc, ': ') !== false || strpos($desc, ' #') !== false) {
        return 'unquoted description contains ": " or " #", which YAML misreads; quote it';
    }
    return [$fields['name'], $desc];
}

return [
    'skills: frontmatter parses, name matches its directory, description fits Hermes limits' => function () {
        check(count(shippedSkillFiles()) > 0, 'no skills found');
        foreach (shippedSkillFiles() as $file) {
            $dir = basename(dirname($file));
            $fm = skillFrontmatter($file);
            check(is_array($fm), is_array($fm) ? '' : "$dir: $fm");
            list($name, $desc) = $fm;
            check($name === $dir, "$dir: name '$name' must equal the directory name");
            check(strlen($desc) <= 1024, "$dir: description over 1024 characters is cut by Hermes");
        }
    },
    'skills: names are unique and do not shadow a Hermes built-in' => function () {
        $tools = explode(' ', HERMES_RESERVED_NAMES);
        $seen = [];
        foreach (shippedSkillFiles() as $file) {
            $name = basename(dirname($file));
            check(!isset($seen[$name]), isset($seen[$name]) ? "skill '$name' exists twice ($seen[$name] and $file); Hermes cannot load an ambiguous name" : '');
            $seen[$name] = $file;
            check(!in_array($name, $tools, true), "skill '$name' has a name Hermes already uses (tool, toolset or command)");
        }
    },
    'units: every tool unit has skill.md, USAGE.md, README.md and, with a script, a verbs table' => function () {
        $units = Units::all();
        check(count($units) >= 4, 'tool units missing');
        foreach ($units as $u) {
            check(!$u['errors'], "{$u['category']}/{$u['name']}: " . implode('; ', $u['errors']));
        }
    },
    'units: the verbs in USAGE.md are exactly the verbs the tool answers to' => function () {
        foreach (Units::all() as $u) {
            if ($u['script'] === '') {
                continue;
            }
            list(, $out) = runPhp($u['script'], ['--verbs']);
            $code = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
            $doc = array_column($u['verbs'], 'verb');
            sort($code);
            sort($doc);
            check($code === $doc, "{$u['name']}: USAGE.md lists " . implode(',', $doc) . ' but the tool answers to ' . implode(',', $code));
            foreach ($doc as $verb) {
                list($c) = runPhp($u['script'], [$verb, '--help']);
                check($c === 0, "{$u['name']} $verb --help exited $c");
            }
        }
    },
    'units: names do not collide with a skill Hermes ships' => function () {
        $src = glob(dirname(__DIR__) . '/pikkumies/include/hermes-*/src');
        if (!$src) {
            return; // Hermes not built here (CI); the reserved-name check above still runs
        }
        $bundled = [];
        foreach (['skills', 'optional-skills'] as $d) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src[0] . "/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getFilename() === 'SKILL.md' && preg_match('/^name:\s*"?([^"\n]+)/m', (string) file_get_contents((string) $f), $m)) {
                    $bundled[trim($m[1])] = true;
                }
            }
        }
        foreach (Units::all() as $u) {
            check(!isset($bundled[$u['name']]), "{$u['name']} is also the name of a skill Hermes ships; skill_view would refuse both");
        }
    },
    'units: every tool of a category has the verbs its category README lists (ADR-0015)' => function () {
        foreach ((array) glob(dirname(__DIR__) . '/pikkumies/tools/*/README.md') as $readme) {
            $shared = array_column(Units::verbs((string) file_get_contents($readme)), 'verb');
            if (!$shared) {
                continue;
            }
            foreach (Units::all() as $u) {
                if ($u['category'] === basename(dirname($readme))) {
                    $missing = array_diff($shared, array_column($u['verbs'], 'verb'));
                    check(!$missing, $u['name'] . ' lacks the category verbs: ' . implode(', ', $missing));
                }
            }
        }
    },
    'units: removed commands are not mentioned in units, doctrine or docs' => function () {
        $root = dirname(__DIR__);
        $files = array_merge((array) glob("$root/pikkumies/tools/*/*/*.md"), (array) glob("$root/pikkumies/doctrine/*.md"), (array) glob("$root/pikkumies/doctrine/SOP/*.md"), (array) glob("$root/pikkumies/lib/*.php"), (array) glob("$root/pikkumies/skills/*/SKILL.md"), (array) glob("$root/pikkumies/hooks/*/*/*.php"), ["$root/README.md", "$root/AGENTS.md"]);
        foreach ($files as $f) {
            $text = (string) @file_get_contents($f);
            check(!preg_match('#\bpacks/|pikkumies packs\b|tools/system/status\.php#', $text), basename(dirname($f)) . '/' . basename($f) . ' still mentions packs or a moved tool path');
        }
    },
    'hermes contract: the pinned source still behaves the way the design relies on' => function () {
        $src = glob(dirname(__DIR__) . '/pikkumies/include/hermes-' . Config::defaults()['hermes']['version'] . '/src');
        if (!$src) {
            return; // Hermes not built here (CI); checked wherever the pinned release is installed
        }
        $facts = [
            'agent/skill_utils.py' => ['"SKILL.md" in files', 'SKILL_PROMPT_DESC_LIMIT = 60'],            // ADR-0015: exact file name; 60-char index
            'agent/shell_hooks.py' => ['data.get("action") == "approve"', 'raw.get("fail_closed"'],          // ADR-0020
            'hermes_cli/plugins.py' => ['rule_key=details.rule_key or tool_name'],                         // ADR-0020: per-command rule_key
            'hermes_cli/config_defaults.py' => ['"keyless_fallback": True', '"keyless_rescue": True'],     // ADR-0021: we turn both off
            'tools/tool_output_limits.py' => ['DEFAULT_MAX_BYTES = 50_000'],                                 // ADR-0016: our budget stays below
            'tools/approval_context.py' => ['_VALID_MODES = ("manual", "smart", "off")'],                    // ADR-0020: we set manual
            'hermes_cli/config.py' => ['def _deep_merge('],                                                   // ADR-0020: approvals.mode alone keeps the other defaults
        ];
        foreach ($facts as $file => $needles) {
            $text = (string) @file_get_contents($src[0] . '/' . $file);
            foreach ($needles as $n) {
                check(strpos($text, $n) !== false, "Hermes $file no longer contains '$n': re-check the ADR that relies on it");
            }
        }
    },
];
