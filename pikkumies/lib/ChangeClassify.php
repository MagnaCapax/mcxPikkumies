<?php
/**
 * ChangeClassify: whether a tool call changes data (ADR-0067), read from the call without running it, for the interval
 * hook units (the change check) and the delivery of their notices. isChange() answers true (it changes data: a write
 * tool, a write verb or flag, an output redirect into a file), false (it only reads, or Hermes blocked it, so it never
 * ran) or 'unknown' (an interpreter, a shell, code, a verb not in lib/ChangeVerbs.php). A command is split into simple
 * commands at ; & | ( ) and newlines with FileopsClassify::words, which also reads the redirects with the shell's
 * quoting, so `awk '$1>5'` is no redirect. One write anywhere makes the whole call a change.
 * A safety prompt's helper, not a security boundary: a command can hide what it does from any reading of its text.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/FileopsClassify.php';
require_once __DIR__ . '/ChangeVerbs.php';
require_once __DIR__ . '/ChangeUnits.php';

class ChangeClassify
{
    const READ_TOOLS = ['read_file', 'search_files', 'skill_view', 'skills_list', 'session_search', 'read_terminal', 'web_search',
        'web_extract', 'x_search', 'vision_analyze', 'video_analyze', 'todo', 'todo_list', 'clarify', 'show_tip', 'read_window_below', 'feishu_doc_read'];
    const WRAPPER_VALUED = ['sudo' => '-u -g -C -h -p -U -r -t', 'doas' => '-u -C', 'env' => '-u -C -S', 'timeout' => '-s -k',
        'nice' => '-n', 'ionice' => '-c -n -p', 'xargs' => '-I -n -P -d -L -s -E -a', 'stdbuf' => '-i -o -e'];
    /** Words that open a shell construct without being a command themselves. */
    const NO_COMMAND = ['for', 'in', 'case', 'esac', 'done', 'fi', '}', 'select', 'function'];

    /**
     * true, false or 'unknown' for one call: $tool and its arguments, $status as Hermes reported it after the call
     * ('blocked': it never ran). $toolsDir is where the Pikkumies tool units are (their USAGE.md says which verbs change).
     */
    public static function isChange($tool, $args, $status = '', $toolsDir = null)
    {
        $args = is_array($args) ? $args : [];
        if ($status === 'blocked') {
            return false;
        }
        if (in_array($tool, ['write_file', 'patch'], true)) {
            return true;
        }
        if (in_array($tool, self::READ_TOOLS, true)) {
            return false;
        }
        if ($tool === 'terminal') {
            return self::command(isset($args['command']) ? (string) $args['command'] : '', $toolsDir);
        }
        if ($tool === 'cronjob_manage') {
            return !isset($args['action']) || $args['action'] !== 'list';
        }
        if ($tool === 'process_manage') {
            $action = isset($args['action']) ? (string) $args['action'] : '';
            if (in_array($action, ['list', 'poll', 'log', 'wait'], true)) {
                return false;
            }
            if ($action === 'kill') {
                return true;
            }
            // Text written to a background process is often a shell line: read it as one.
            return in_array($action, ['write', 'submit'], true) ? self::command(isset($args['data']) ? (string) $args['data'] : '', $toolsDir) : 'unknown';
        }
        return 'unknown'; // execute_code, memory, delegation, the browser and anything else
    }

    /** true, false or 'unknown' for a shell command line. */
    public static function command($command, $toolsDir = null)
    {
        $t = FileopsClassify::words($command);
        foreach ($t['redirects'] as $r) {
            // > >> >| &> into a file; >&2, 2>&1 and /dev/null are not files.
            if (strpos($r[0], '>') !== false && $r[1] !== '/dev/null' && !preg_match('/^(\d+|-)$/', $r[1])) {
                return true;
            }
        }
        // Command substitution builds code at run time; a regex engine error must never read as "only reads".
        $result = preg_match('/\$\(|`/', $command) === 0 ? false : 'unknown';
        $segment = [];
        foreach (array_merge($t['words'], [['op' => 'end']]) as $w) {
            if (!isset($w['op'])) {
                $segment[] = $w['w'];
                continue;
            }
            $v = self::segment($segment, $toolsDir);
            $segment = [];
            if ($v === 'write') {
                return true;
            }
            $result = $v === 'unknown' ? 'unknown' : $result;
        }
        return $result;
    }

    /** 'read', 'write', 'unknown' or 'none' (no command) for one simple command's words. */
    public static function segment(array $words, $toolsDir = null)
    {
        $wrapper = '';
        for ($i = 0, $n = count($words); $i < $n; $i++) {
            $word = $words[$i];
            $base = basename($word);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) || in_array($word, self::NO_COMMAND, true) && $wrapper === '') {
                continue; // X=1, or a word like for / done
            }
            if ($wrapper !== '' && $word !== '' && ($word[0] === '-' || ctype_digit($word[0]))) {
                $valued = isset(self::WRAPPER_VALUED[$wrapper]) ? explode(' ', self::WRAPPER_VALUED[$wrapper]) : [];
                $i += in_array($word, $valued, true) ? 1 : 0; // that option's value
                continue;
            }
            if ($base === 'command' && isset($words[$i + 1]) && in_array($words[$i + 1], ['-v', '-V'], true)) {
                return 'read'; // command -v NAME names a program, it runs nothing
            }
            if (in_array($base, FileopsClassify::WRAPPERS, true)) {
                $wrapper = $base;
                continue;
            }
            if ($word === '' || $word[0] === '$') {
                return 'unknown'; // a verb from a variable
            }
            $rest = array_slice($words, $i + 1);
            if (in_array($base, ['bash', 'sh', 'zsh', 'dash', 'ksh', 'eval', 'source', '.'], true)) {
                return 'unknown';
            }
            $at = $base === 'php' ? ChangeUnits::plainAt($rest) : -1; // php [options] <script> <verb>, or the script run itself
            $unit = ChangeUnits::verdict($base === 'php' ? ($at < 0 ? '' : $rest[$at]) : $word, array_slice($rest, $at + 1), $toolsDir);
            if ($unit !== null) {
                return $unit;
            }
            if ($base === 'find') {
                return self::find($rest, $toolsDir);
            }
            if ($base === 'php') {
                return preg_grep('/^(-[lvmi]|--(version|ini|modules|info))$/', $rest) && ($at < 0 || in_array('-l', $rest, true)) ? 'read' : 'unknown';
            }
            return ChangeVerbs::verdict($base, $rest);
        }
        return $wrapper === '' || $wrapper === 'env' ? 'none' : 'unknown'; // `sudo -s` alone opens a shell
    }

    /** find reads, unless it deletes, writes a file list, or runs a command that changes data (-exec rm {} +). */
    private static function find(array $args, $toolsDir)
    {
        $verdict = 'read';
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            if (in_array($args[$i], ['-delete', '-fprint', '-fprint0', '-fprintf', '-fls'], true)) {
                return 'write';
            }
            if (in_array($args[$i], ['-exec', '-execdir', '-ok', '-okdir'], true)) {
                for ($run = []; ++$i < $n && !in_array($args[$i], [';', '+'], true);) {
                    $run[] = $args[$i];
                }
                $v = self::segment($run, $toolsDir);
                if ($v === 'write') {
                    return 'write';
                }
                $verdict = $v === 'unknown' ? 'unknown' : $verdict;
            }
        }
        return $verdict;
    }
}
