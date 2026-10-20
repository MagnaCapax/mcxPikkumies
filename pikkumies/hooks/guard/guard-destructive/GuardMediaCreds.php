<?php
/**
 * The media-stack credentials rule of the guard (ADR-0078 amendment 2026-10-19), loaded by guard-destructive.php: the
 * agent's terminal, code and file tools never read ~/.media-stack-credentials.txt, the media apps' logins in plain
 * text. The login verbs read it in their own PHP and never name it, so they pass. A call is blocked when it
 * - names the file (`media-stack-cred`), outside the argument of an exclude option of a search;
 * - holds a glob the shell (or code) would expand to it: each token with * ? [ or { is resolved (~, $HOME, ${HOME},
 *   a relative one against the cd the command makes or the payload's cwd), its folder part expanded with glob()
 *   (bounded: GLOB_NOSORT|GLOB_ONLYDIR, at most MAX_DIRS folders, else blocked), and the last part globbed in each
 *   folder that is the home folder;
 * - searches content recursively from the home folder itself (grep -r/-R, rg, ag, ack, ugrep -r, find ... -exec or
 *   xargs with a reader, search_files on content) unless an exclude option names the file (grep --exclude, ag --ignore,
 *   rg -g '!...', find ! -name); a search rooted in a subfolder is allowed.
 * Out of its sight (a safety prompt, not security, AGENTS.md): a name or path built in a variable (${f}, $(...)), in
 * code from pieces, or by a program that reads files itself without one of the readers above.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class GuardMediaCreds
{
    const FILE = '.media-stack-credentials.txt';
    const MAX_DIRS = 256;
    const WHY = 'a command on the media apps\' passwords (~/.media-stack-credentials.txt); what the agent reads goes to the model. The user reads that file in their own terminal; the login-check and set-login verbs use it without printing it';
    const READERS = 'grep|egrep|fgrep|rg|ag|ack|cat|head|tail|less|more|strings|awk|sed|xxd|od|base64|cp|tar|zip|php|python3?|perl';

    /** The block message, or null. $cmds: the forms of the command (HookPayload texts); $cwd: the payload's cwd. */
    public static function check($tool, array $input, array $cmds, $cwd, $home)
    {
        $home = self::real($home);
        foreach ($cmds as $cmd) {
            list($text, $excluded) = self::excludes($cmd);
            if (preg_match('#media-stack-cred#', $text) || ($home !== '' && self::globHit($text, $cwd, $home))) {
                return self::WHY;
            }
            if ($home !== '' && !$excluded && self::homeSearch($tool, $input, $cmd, $cwd, $home)) {
                return 'a content search from the home folder itself, which would read ~/.media-stack-credentials.txt into the model. Search a subfolder, or exclude the file (grep --exclude, rg -g \'!' . self::FILE . '\'); the user reads that file in their own terminal';
            }
        }
        return null;
    }

    /** [the text with exclude-option arguments removed, whether one of them matches the file]. */
    protected static function excludes($cmd)
    {
        $re = '#(?<![\w-])(--exclude|--ignore|--glob|--iglob|-g|(?:!|-not)\s+-i?name)(=|\s+)(["\']?)(!?)([^\s"\']+)\3#';
        $hit = false;
        $text = (string) preg_replace_callback($re, function ($m) use (&$hit) {
            $neg = in_array($m[1], ['--exclude', '--ignore'], true) || $m[4] === '!' || preg_match('/^(!|-not)\s/', $m[1]);
            if (!$neg || !fnmatch($m[5], self::FILE, FNM_PERIOD) && !fnmatch($m[5], self::FILE)) {
                return $m[0]; // a positive filter, or one that does not cover the file: left for the other checks
            }
            $hit = true;
            return $m[1] . $m[2] . 'EXCLUDED';
        }, $cmd);
        return [$text, $hit];
    }

    /** True when a glob in $text expands to the file (folder parts with glob(), bounded). */
    protected static function globHit($text, $cwd, $home)
    {
        foreach (array_slice(self::words($text), 0, 64) as $w) {
            if (!$w['glob'] && !($w['qglob'] && preg_match('#^(~|\$\{?HOME\}?|/)#', $w['text']))) {
                continue; // the shell expands only an unquoted glob; quoted, only a path-like one counts (code)
            }
            $path = self::resolve($w['text'], self::base($text, $cwd, $home), $home);
            if ($path === null) {
                continue;
            }
            $cut = strrpos($path, '/');
            $dir = $cut === 0 ? '/' : substr($path, 0, $cut);
            $last = substr($path, $cut + 1);
            $dirs = strpbrk($dir, '*?[{') === false ? [$dir] : glob($dir, GLOB_ONLYDIR | GLOB_NOSORT | GLOB_BRACE);
            if (!is_array($dirs) || count($dirs) > self::MAX_DIRS) {
                return true; // too wide to check: blocked, the command can name a narrower folder
            }
            foreach ($dirs as $d) {
                if (self::real($d) === $home && in_array("$home/" . self::FILE, (array) glob("$home/$last", GLOB_NOSORT | GLOB_BRACE), true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** True for a recursive content search rooted at the home folder itself. */
    protected static function homeSearch($tool, array $input, $cmd, $cwd, $home)
    {
        if (in_array($tool, ['read_file', 'write_file', 'patch'], true)) {
            return false;
        }
        if ($tool === 'search_files') {
            $glob = isset($input['file_glob']) ? (string) $input['file_glob'] : '';
            $root = self::resolve(isset($input['path']) && $input['path'] !== '' ? (string) $input['path'] : '.', $cwd, $home);
            return (!isset($input['target']) || $input['target'] !== 'files') && $root !== null && self::real($root) === $home
                && ($glob === '' || fnmatch($glob, self::FILE));
        }
        $base = self::base($cmd, $cwd, $home);
        foreach (preg_split('/\|\||&&|[;|\n]/', $cmd) as $seg) {
            $w = array_column(self::words($seg), 'text');
            while ($w && preg_match('/^(\w+=\S*|sudo|nice|command|env|time)$/', $w[0])) {
                array_shift($w);
            }
            $prog = $w ? PathName::base((string) array_shift($w)) : '';
            $find = $prog === 'find' && preg_match('#(-exec(dir)?\s+|xargs\s+(-\S+\s+)*)(' . self::READERS . ')\b#', $cmd);
            $grep = in_array($prog, ['rg', 'ag', 'ack'], true) || (in_array($prog, ['grep', 'egrep', 'fgrep', 'ugrep'], true)
                && preg_grep('/^(-[a-zA-Z]*[rR][a-zA-Z]*|--recursive|--dereference-recursive|--directories=recurse)$/', $w));
            if (!$find && !$grep) {
                continue;
            }
            $pos = array_values(array_filter($w, function ($t) {
                return $t !== '' && $t[0] !== '-';
            }));
            $roots = $find ? array_slice($pos, 0, 1) : $pos;
            if (!$roots || (!$find && count($pos) < 2 && !preg_grep('/^(-e|-f|--regexp|--file)/', $w))) {
                $roots[] = '.'; // no folder named: the search starts where the command runs
            }
            foreach ($roots as $r) {
                $p = self::resolve($r, $base, $home);
                if ($p !== null && self::real($p) === $home) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Where a relative path in $cmd starts: the last cd it makes (~, $HOME, a path), else the payload's cwd. */
    protected static function base($cmd, $cwd, $home)
    {
        $base = $cwd;
        preg_match_all('/(?:^|[\s;&|(])cd(?=[\s;&|)]|$)(?:\s+([^\s;&|)]+))?/', $cmd, $m);
        foreach ($m[1] as $to) {
            $base = $to === '' ? $home : self::resolve($to, $base, $home);
        }
        return (string) $base;
    }

    /** ~, $HOME and ${HOME} as the home folder, a relative path against $base; null when $base is unknown. */
    protected static function resolve($p, $base, $home)
    {
        $p = (string) preg_replace('#^(~|\$\{HOME\}|\$HOME)(?=/|$)#', $home, trim($p, '"\''));
        if ($p === '' || $p[0] !== '/') {
            return $base === '' || $base === null ? null : rtrim($base, '/') . '/' . $p;
        }
        return $p;
    }

    protected static function real($p)
    {
        $r = @realpath((string) $p);
        return $r === false ? rtrim((string) $p, '/') : $r;
    }

    /** Shell-like words: the text without quotes, a glob character outside quotes (glob) or inside them (qglob). */
    protected static function words($s)
    {
        $out = [];
        $cur = ['text' => '', 'glob' => false, 'qglob' => false, 'open' => false];
        $q = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($q !== '') {
                if ($c === $q) {
                    $q = '';
                } else {
                    $cur['text'] .= $c;
                    $cur['qglob'] = $cur['qglob'] || strpbrk($c, '*?[{') !== false;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                list($q, $cur['open']) = [$c, true];
            } elseif (strpbrk($c, " \t\n;&|()<>") !== false) {
                if ($cur['open']) {
                    $out[] = $cur;
                }
                $cur = ['text' => '', 'glob' => false, 'qglob' => false, 'open' => false];
            } else {
                $cur['text'] .= $c;
                $cur['glob'] = $cur['glob'] || strpbrk($c, '*?[{') !== false;
                $cur['open'] = true;
            }
        }
        if ($cur['open']) {
            $out[] = $cur;
        }
        return $out;
    }
}
