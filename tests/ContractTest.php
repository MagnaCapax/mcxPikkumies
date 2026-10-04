<?php
/**
 * Authoring contract for shipped skills and packs, checked the way Hermes 0.19.0 reads them.
 * A frontmatter Hermes cannot parse does not fail loudly: it logs a warning and lists the skill
 * with an empty description (agent/prompt_builder.py). Descriptions over 1024 characters are
 * cut silently (tools/skills_tool.py). A skill name that exists twice cannot be loaded
 * (tools/skills_tool.py, "Ambiguous skill name").
 */

// Tool names registered by Hermes 0.19.0 (registry.register(name=...) under tools/). A skill
// named like a tool competes with it for the same requests. Re-check on a Hermes bump (ADR-0008).
const HERMES_0_19_0_TOOLS = 'browser_back browser_cdp browser_click browser_console browser_dialog browser_get_images browser_navigate browser_press browser_scroll browser_snapshot browser_type browser_vision clarify close_terminal computer_use cronjob delegate_task discord discord_admin execute_code feishu_doc_read feishu_drive_add_comment feishu_drive_list_comment_replies feishu_drive_list_comments feishu_drive_reply_comment ha_call_service ha_get_state ha_list_entities ha_list_services image_generate kanban_attach kanban_attachments kanban_attach_url kanban_block kanban_comment kanban_complete kanban_create kanban_heartbeat kanban_link kanban_list kanban_show kanban_unblock memory patch process project_create project_list project_switch read_file read_terminal search_files session_search skill_manage skills_list skill_view terminal text_to_speech todo video_analyze video_generate vision_analyze web_extract web_search write_file xai_video_edit xai_video_extend x_search yb_query_group_info yb_query_group_members yb_search_sticker yb_send_dm yb_send_sticker';

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
    'skills: names are unique and do not shadow a Hermes tool' => function () {
        $tools = explode(' ', HERMES_0_19_0_TOOLS);
        $seen = [];
        foreach (shippedSkillFiles() as $file) {
            $name = basename(dirname($file));
            check(!isset($seen[$name]), isset($seen[$name]) ? "skill '$name' exists twice ($seen[$name] and $file); Hermes cannot load an ambiguous name" : '');
            $seen[$name] = $file;
            check(!in_array($name, $tools, true), "skill '$name' has the name of a Hermes tool");
        }
    },
    'packs: pack.json name matches its directory and has a description' => function () {
        foreach (Packs::all() as $name => $meta) {
            check(basename($meta['path']) === $name, "pack '$name' lives in directory " . basename($meta['path']));
            check(!empty($meta['description']), "pack '$name' has no description");
        }
    },
];
