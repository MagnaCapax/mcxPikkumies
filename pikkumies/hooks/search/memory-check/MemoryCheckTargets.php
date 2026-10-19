<?php
/**
 * MemoryCheckTargets: what a tool call uses, for the memory-check hook (ADR-0081). Each target is a label the hold is keyed
 * on:
 * - a file a memory links to (frontmatter `tools`, written by `save --tool`) whose name the call names: its path. The name
 *   is matched as a word in the texts HookPayload::read gives, so `cd x && php rtorrent.php` counts too; over-matching is
 *   the chosen direction, as in HookPayload::selects;
 * - a Pikkumies tool unit verb whose USAGE.md `changes` cell does not start with `no` (yes, deletes, sends, loads):
 *   "<unit> <verb>". `X=<path>.php` in the same text is read into `$X`, `${X}` and `"$X"`;
 * - a Hermes tool outside QUIET, unless its `action` only reads: "<tool>[ <action>][ <path, job, name or process>]".
 */
class MemoryCheckTargets
{
    /**
     * Hermes tools never held as such. They read, plan or show; terminal and execute_code name no target of their own (their
     * Pikkumies verbs and linked files are targets); web research is held by search-first-gate until tiers 1-3 were searched
     * (ADR-0031) and is the work itself (ADR-0073). Linked files count for every tool, these included.
     */
    const QUIET = ['read_file', 'search_files', 'skill_view', 'skills_list', 'session_search', 'read_terminal', 'read_window_below',
        'todo', 'todo_list', 'clarify', 'vision_analyze', 'video_analyze', 'show_tip', 'terminal', 'execute_code', 'web_search', 'web_extract', 'x_search'];
    /** Values of a manage tool's `action` that only read (process_manage list/poll/log/wait, cronjob_manage list). */
    const READ_ACTIONS = ['list', 'poll', 'log', 'wait', 'view', 'read', 'get', 'status', 'show', 'search'];
    /** The fields that name what a Hermes tool call changes, first found wins. */
    const NAMED_BY = ['path', 'job_id', 'name', 'session_id'];
    const HEAD = 8192; // bytes of a memory file read for its frontmatter and title

    /** [real path of a linked file => [[memory file, title], ...]], read from the memory files each call (no index, ADR-0060). */
    public static function links($root)
    {
        $out = [];
        foreach (TextSearch::lessonDirs($root) as $dir) {
            foreach ((array) glob("$dir/*.md") as $file) {
                if (basename($file) === 'README.md') {
                    continue;
                }
                list($meta, $body) = Units::frontmatter((string) @file_get_contents($file, false, null, 0, self::HEAD));
                $tools = isset($meta['tools']) ? json_decode($meta['tools'], true) : null;
                if (!is_array($tools)) {
                    continue;
                }
                $title = preg_match('/^# (.+)$/m', $body, $m) ? $m[1] : basename($file, '.md');
                foreach ($tools as $t) {
                    if (is_string($t) && $t !== '') {
                        $out[$t][] = [$file, $title];
                    }
                }
            }
        }
        return $out;
    }

    /** The linked files whose name one of $texts names: [real path => memories]. */
    public static function linked(array $texts, array $links)
    {
        $hits = [];
        foreach ($links as $file => $memories) {
            $word = '#(?<![A-Za-z0-9_.-])' . preg_quote(basename($file), '#') . '(?![A-Za-z0-9_.-])#';
            foreach ($texts as $text) {
                if (preg_match($word, $text)) {
                    $hits[$file] = $memories;
                    break;
                }
            }
        }
        return $hits;
    }

    /** ["<unit> <verb>" => the unit's script] for each Pikkumies verb the texts run whose `changes` is not `no`. */
    public static function verbs(array $texts, $toolsDir)
    {
        if (!preg_grep('/\.php\b/', $texts)) {
            return []; // no script named: skip reading the units
        }
        $units = [];
        foreach (Units::all($toolsDir) as $u) {
            if (!$u['errors'] && $u['script'] !== '') {
                $units[$u['name']] = $u;
            }
        }
        $out = [];
        foreach ($texts as $text) {
            preg_match_all('/(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)=(\S+\.php)(?![A-Za-z0-9_.-])/', $text, $vars, PREG_SET_ORDER);
            foreach ($vars as $v) {
                $text = preg_replace('/\$\{?' . $v[1] . '\}?(?![A-Za-z0-9_])/', str_replace(['\\', '$'], ['\\\\', '\\$'], $v[2]), $text);
            }
            preg_match_all('/(?<![A-Za-z0-9_.-])([A-Za-z0-9][A-Za-z0-9_-]*)\.php(?:\s+--?[A-Za-z][A-Za-z0-9-]*(?:=\S*)?)*\s+([a-z][a-z0-9-]*)/', $text, $calls, PREG_SET_ORDER);
            foreach ($calls as $c) {
                if (!isset($units[$c[1]])) {
                    continue;
                }
                foreach ($units[$c[1]]['verbs'] as $v) {
                    $first = strtolower((string) preg_replace('/[\s(].*/', '', trim($v['changes'], '` ')));
                    if ($v['verb'] === $c[2] && !in_array($first, ['no', '', '-'], true)) {
                        $out["{$c[1]} {$c[2]}"] = $units[$c[1]]['script'];
                    }
                }
            }
        }
        return $out;
    }

    /** The labels a Hermes tool call changes, or [] for a QUIET tool or a reading action. */
    public static function hermes(array $payload)
    {
        $tool = isset($payload['tool_name']) && is_string($payload['tool_name']) ? $payload['tool_name'] : '';
        $in = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        $action = isset($in['action']) && is_string($in['action']) ? $in['action'] : '';
        if ($tool === '' || in_array($tool, self::QUIET, true) || in_array(strtolower($action), self::READ_ACTIONS, true)) {
            return [];
        }
        $named = [];
        foreach (self::NAMED_BY as $k) {
            if (isset($in[$k]) && is_scalar($in[$k]) && (string) $in[$k] !== '') {
                $named = [(string) $in[$k]];
                break;
            }
        }
        if (!$named && $tool === 'patch' && isset($in['patch']) && is_scalar($in['patch'])) {
            $named = (array) PatchPaths::v4a((string) $in['patch']); // the V4A form names its files in headers
        }
        $base = $tool . ($action !== '' ? " $action" : '');
        return $named ? array_map(function ($n) use ($base) {
            return "$base $n";
        }, $named) : [$base];
    }
}
