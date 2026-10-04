<?php
/**
 * Authoring contract for shipped skills and packs, checked the way Hermes 0.19.0 reads them.
 * A frontmatter Hermes cannot parse does not fail loudly: it logs a warning and lists the skill
 * with an empty description (agent/prompt_builder.py). Descriptions over 1024 characters are
 * cut silently (tools/skills_tool.py). A skill name that exists twice cannot be loaded
 * (tools/skills_tool.py, "Ambiguous skill name").
 */

// Names Hermes v0.21.5 (tag v2026.9.24) already uses: registered tools, toolsets and built-in slash
// commands. A skill with one of these names competes with the built-in. Re-check on a pin bump (ADR-0009).
const HERMES_RESERVED_NAMES = 'a2a agents annotate_preview apply_layout approvals approve battery bg blueprint branch browser browser_cdp browser_dialog browser_exec browser_vault_enter_code browser_vault_fill browser_vault_list browser_vault_save_login browser_vault_unlock btw bundles busy clarify clear close_terminal code_execution codex-runtime commands compress computer_use config connections context context_engine copy cron cronjob cronjob_manage curator debug delegate_task delegation deny desktop_preview desktop_project diff drive_preview egress execute_code export fast feishu_doc_read file focus focus_pane footer goal gui_tour handoff hatch heartbeat help history homeassistant image image_gen image_generate import indicator init insights journey kanban learn login loop manage_catalog manage_connections meet memory moa model new palette paste patch pause personality pet plan platform platforms plugins process_manage profile prompt queue quit react_to_message read_file read_terminal read_window_below reasoning redraw refine relay reload reload-mcp reload-skills restart resume retry review rollback save search_files sessions session_search sethome show_tip skill_manage skills skills_list skill_view skin snapshot spotify start status statusbar steer stop stt subgoal subscription suggestions terminal text_to_speech timestamps title todo todo_list tools toolsets topic topup tts undo update usage verbose version video video_analyze video_gen video_generate vision vision_analyze voice wake web web_extract web_search whoami worktree write_file x_search yolo yuanbao';

function shippedSkillFiles()
{
    $root = dirname(__DIR__);
    return array_merge((array) glob($root . '/pikkumies/skills/*/SKILL.md'), (array) glob($root . '/packs/*/skills/*/SKILL.md'));
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
    'packs: pack.json name matches its directory and has a description' => function () {
        foreach (Packs::all() as $name => $meta) {
            check(basename($meta['path']) === $name, "pack '$name' lives in directory " . basename($meta['path']));
            check(!empty($meta['description']), "pack '$name' has no description");
        }
    },
];
