<?php
/**
 * HookPayload: what a tool call says, for the hooks that read it (ADR-0054). One extractor shared by the delete
 * guard and the orchestrator's tool-hook selection, so both read a call the same way.
 *
 * read() is the text the guard has always checked (lifted unchanged from guard-destructive.php): the `terminal`
 * command, the code of `execute_code`, the text `process_manage` writes or submits to a background process, the
 * path and search pattern of the file tools, and the arguments of a `cronjob_manage` call. Each text is also read
 * without quotes and backslashes (the shell joins r"m" into rm) and with repeated slashes and /./ folded.
 *
 * selects() decides whether a tool hook (hooks/tools/<tool>/<hook>/) runs for a call. Over-matching is the safe
 * direction: a tool hook can only add a block or a question. What stays out of its sight: a script path built in
 * code or by command substitution, an alias, and a cd made in an earlier call (the payload's cwd is the directory
 * Hermes runs in, not the terminal's).
 */
class HookPayload
{
    const FILE_TOOLS = ['read_file', 'write_file', 'patch', 'search_files'];

    /**
     * [kind, command, texts]. kind: 'command' (terminal, execute_code, process_manage write/submit), 'file' (the file
     * tools: path and pattern), 'schedule' (cronjob_manage: its arguments as JSON) or '' (nothing to read).
     */
    public static function read(array $payload)
    {
        $tool = isset($payload['tool_name']) ? $payload['tool_name'] : '';
        $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        $field = $tool === 'terminal' ? 'command' : ($tool === 'execute_code' ? 'code' : '');
        if ($tool === 'process_manage' && isset($input['action']) && in_array($input['action'], ['write', 'submit'], true)) {
            $field = 'data';
        }
        if (in_array($tool, self::FILE_TOOLS, true)) {
            $kind = 'file';
            $command = (isset($input['path']) ? (string) $input['path'] : '') . ' ' . (isset($input['pattern']) ? (string) $input['pattern'] : '');
        } elseif ($tool === 'cronjob_manage') {
            $kind = 'schedule';
            $command = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } elseif ($field !== '') {
            $kind = 'command';
            $command = isset($input[$field]) ? (string) $input[$field] : '';
        } else {
            return ['', '', []];
        }
        $bare = str_replace(['"', "'", '\\'], '', $command);
        return [$kind, $command, array_values(array_unique([$command, $bare, preg_replace('#/(\./)*/*#', '/', $bare)]))];
    }

    /**
     * Inside an agent `pikkumies <tool> ...` runs `php <tool script> ...` (Launch::toolScript, ADR-0103). The guards know
     * the php form, so before any unit reads the call, the command (terminal), code (execute_code) or data (process_manage)
     * gets every `[path/]pikkumies <tool>` at the start of a word replaced by `php <canonical script>`, with the same
     * resolver the launcher runs. A quoted or backslashed form is matched on the text without quotes and backslashes
     * (what SendGuard checks too); that view then becomes the text the units see. Rewriting where nothing runs (an echo)
     * only makes a guard stricter. Returns the payload unchanged when nothing matches, or null when a regex fails: the
     * caller then blocks the call, so an unreadable command never reaches the guards in the form they cannot see.
     * $scripts: name => script, for tests (default Launch::toolScripts()).
     */
    public static function normalize(array $payload, $scripts = null)
    {
        $tool = isset($payload['tool_name']) ? $payload['tool_name'] : '';
        $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        $field = $tool === 'terminal' ? 'command' : ($tool === 'execute_code' ? 'code' : '');
        if ($tool === 'process_manage' && isset($input['action']) && in_array($input['action'], ['write', 'submit'], true)) {
            $field = 'data';
        }
        if ($field === '' || !isset($input[$field]) || !is_string($input[$field])
            || stripos(str_replace(['"', "'", '\\'], '', $input[$field]), 'pikkumies') === false) {
            return $payload;
        }
        $scripts = $scripts === null ? Launch::toolScripts() : $scripts;
        if (!$scripts) {
            return $payload;
        }
        $names = array_keys($scripts);
        usort($names, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $sep = '\s;&|()<>\'"`';
        // Spaces, tabs, line breaks and backslashes between the words: a backslash-newline continues the line in the shell.
        $re = '~(?<![^' . $sep . '])(?:[^' . $sep . ']*/)?pikkumies(?:[ \t\r\n]|\\\\)+(' . implode('|', array_map(function ($n) {
            return preg_quote($n, '~');
        }, $names)) . ')(?=[' . $sep . ']|$)~';
        $rewrite = function ($text) use ($re, $scripts) {
            return preg_replace_callback($re, function ($m) use ($scripts) {
                return 'php ' . $scripts[$m[1]];
            }, $text);
        };
        $text = $input[$field];
        $out = $rewrite($text);
        if ($out === $text) {
            $bare = str_replace(['"', "'", '\\'], '', $text);
            $out = $bare === $text ? $text : $rewrite($bare);
            $out = $out === $bare ? $text : $out;
        }
        if ($out === null || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }
        $payload['tool_input'][$field] = $out;
        return $payload;
    }

    /**
     * True when the tool hook $unit runs for this call. A hook for a Hermes tool ('toolCategory' '') runs on that
     * tool_name. A hook for a Pikkumies unit runs when the call names `<tool>.php` or `tools/<category>/<tool>`, when
     * the payload's cwd is inside that unit, or when the call runs php on a variable (`php $X`, `php "$X"`, `php ${X}`),
     * which could be any unit. A unit that is not a tool hook always runs.
     */
    public static function selects(array $unit, array $payload, array $texts)
    {
        if (empty($unit['tool'])) {
            return true;
        }
        $tool = $unit['tool'];
        if ($unit['toolCategory'] === '') {
            return isset($payload['tool_name']) && $payload['tool_name'] === $tool;
        }
        $q = preg_quote($tool, '#');
        $path = '#(^|[^A-Za-z0-9_.-])' . $q . '\.php\b|tools/' . preg_quote($unit['toolCategory'], '#') . '/' . $q . '(?![A-Za-z0-9_.-])#';
        $cwd = isset($payload['cwd']) && is_string($payload['cwd']) ? $payload['cwd'] : '';
        foreach (array_merge($texts, [$cwd]) as $text) {
            if (preg_match($path, $text)) {
                return true;
            }
        }
        foreach ($texts as $text) {
            if (preg_match('/\bphp[0-9.]*\s+(-\S+\s+)*["\']?\$\{?[A-Za-z_]/', $text)) {
                return true;
            }
        }
        return false;
    }
}
