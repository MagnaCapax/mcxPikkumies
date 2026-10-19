<?php
/**
 * FileopsClassify: which shell commands the fileops check (hooks/guard/fileops-check/, ADR-0048) stops for a second
 * look, read from the command text without running it. words() splits a command into shell words the way the shell
 * quotes them; classify() reads each simple command and returns the ones that matter:
 * - 'block' (the agent re-checks once): recursive chmod/chown/chgrp, chmod/chown/chgrp fed by xargs, find -exec or
 *   -execdir of chmod/chown/chgrp/mv, chattr, rename and setfacl;
 * - 'mv': a move the caller checks for an existing target (mv -n never replaces one, so it is left out).
 * A plain chmod/chown/chgrp of a file, and everything else, is not returned. 'complex' is true when the shell builds
 * part of the command at run time (; & | ( ) a newline, $ or a backtick, xargs, or code): the paths in the text are
 * then not reliable (a cd before them, a variable in them), so the caller lists none.
 */
class FileopsClassify
{
    const VERBS = ['chmod', 'chown', 'chgrp', 'setfacl', 'chattr', 'rename', 'mv', 'find'];
    // Words a command can follow in one segment: wrappers (their options are skipped) and shell keywords.
    const WRAPPERS = ['sudo', 'doas', 'env', 'command', 'exec', 'nice', 'ionice', 'nohup', 'time', 'timeout', 'stdbuf', 'xargs',
        'if', 'then', 'else', 'elif', 'do', 'while', 'until', '!', '{'];
    const FIND_EXEC = ['chmod', 'chown', 'chgrp', 'mv'];

    /**
     * ['words' => list of words ['w' => text, 'p' => fnmatch pattern, 'g' => has a glob, 't' => starts with ~] and
     * operators ['op' => char], 'complex' => bool]. Single quotes are literal, a backslash escapes one character,
     * a quoted glob character stays literal in 'p'. A redirect and its file are dropped (2>/dev/null is not a path);
     * 'redirects' lists each one as [operator, target word] (target '' when none followed), read with the same quoting
     * (lib/ChangeClassify.php, ADR-0067). 'words' and 'complex' do not depend on it.
     */
    public static function words($command)
    {
        $s = (string) $command;
        $out = [];
        $w = null;
        $quote = '';
        $complex = false;
        $redirect = false;
        $redirects = [];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote === "'") {
                $quote = $c === "'" ? '' : $quote;
                self::add($w, $c === "'" ? '' : $c, true);
                continue;
            }
            if ($c === '\\') {
                $next = $i + 1 < $n ? $s[$i + 1] : '';
                if ($next === '') {
                    continue; // a trailing backslash
                } elseif ($quote === '"' && strpos("\$`\"\\\n", $next) === false) {
                    self::add($w, '\\', true); // inside double quotes only $ ` " \ and a newline are escaped
                    continue;
                } elseif ($next !== "\n") {
                    self::add($w, $next, true);
                }
                $i++;
                continue;
            }
            $complex = $complex || $c === '$' || $c === '`';
            if ($quote === '"' || $c === "'" || $c === '"') {
                $quote = $quote === '"' ? ($c === '"' ? '' : '"') : $c;
                $quote === '"' && $c !== '"' ? self::add($w, $c, true) : self::add($w, '', true); // "" is an empty word
                continue;
            }
            if ($c === '#' && $w === null) {
                $i = strpos($s, "\n", $i) === false ? $n : strpos($s, "\n", $i) - 1; // a comment runs to the end of the line
                continue;
            }
            if ($c === ' ' || $c === "\t" || strpos(";&|()\n<>", $c) !== false) {
                if (($c === '<' || $c === '>') && $w !== null && ctype_digit($w['w'])) {
                    $w = null; // the 2 of 2>
                }
                if ($w !== null && !$redirect) {
                    $out[] = $w;
                } elseif ($w !== null) {
                    $redirects[count($redirects) - 1][1] = $w['w'];
                }
                if ($w !== null) {
                    list($redirect, $w) = [false, null];
                }
                if ($c === '<' || $c === '>') {
                    $op = $c;
                    while ($i + 1 < $n && strpos('<>&|', $s[$i + 1]) !== false) {
                        $op .= $s[++$i];
                    }
                    $redirects[] = [$op, ''];
                    $redirect = true; // the next word is the redirect's file
                } elseif ($c !== ' ' && $c !== "\t") {
                    $complex = true;
                    $out[] = ['op' => $c];
                }
                continue;
            }
            self::add($w, $c, false);
        }
        if ($w !== null && !$redirect) {
            $out[] = $w;
        } elseif ($w !== null) {
            $redirects[count($redirects) - 1][1] = $w['w'];
        }
        return ['words' => $out, 'complex' => $complex || $quote !== '', 'redirects' => $redirects];
    }

    /** Append one character to the current word; an unquoted * ? [ is a glob, an unquoted leading ~ is the home. */
    private static function add(&$w, $c, $quoted)
    {
        if ($w === null) {
            $w = ['w' => '', 'p' => '', 'g' => false, 't' => !$quoted && $c === '~'];
        }
        $meta = $c !== '' && strpos('*?[\\', $c) !== false;
        $w['w'] .= $c;
        $w['p'] .= $meta && $quoted ? '\\' . $c : $c;
        $w['g'] = $w['g'] || ($meta && !$quoted && $c !== '\\');
    }

    /** ['matches' => list of ['verb', 'tier' => block|mv, 'recursive', 'paths', 'dest', 'intoDir', 'noTargetDir'], 'complex']. */
    public static function classify($command, $isCode = false)
    {
        // Code (execute_code) carries shell commands in strings and lists: read it with quotes and brackets as spaces.
        $t = self::words($isCode ? preg_replace('/["\'\[\](),]/', ' ', (string) $command) : $command);
        $segments = [[]];
        foreach ($t['words'] as $w) {
            if (isset($w['op'])) {
                $segments[] = [];
            } else {
                $segments[count($segments) - 1][] = $w;
            }
        }
        $matches = [];
        $fed = false; // a command run by xargs takes its paths from stdin
        foreach ($segments as $seg) {
            for ($i = 0, $n = count($seg), $xargs = false, $wrapped = false; $i < $n; $i++) {
                $word = $seg[$i]['w'];
                $base = basename($word);
                if ($isCode && !in_array($base, self::VERBS, true)) {
                    continue; // in code a command can start anywhere
                }
                if (!$isCode && (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) || ($wrapped && preg_match('/^(-|\d)/', $word)))) {
                    continue; // X=1, or a wrapper's option
                }
                if (!$isCode && in_array($base, self::WRAPPERS, true)) {
                    $xargs = $xargs || $base === 'xargs';
                    $fed = $fed || $xargs;
                    $wrapped = true;
                    continue;
                }
                $m = self::command($base, array_slice($seg, $i + 1), $xargs);
                if ($m !== null) {
                    $matches[] = $m;
                }
                if (!$isCode) {
                    break; // one command per segment
                }
            }
        }
        return ['matches' => $matches, 'complex' => $t['complex'] || $isCode || $fed];
    }

    /** One simple command (verb, its words), or null when it is not one this check stops for. */
    private static function command($verb, array $args, $xargs)
    {
        $m = ['verb' => $verb, 'tier' => 'block', 'recursive' => false, 'paths' => [], 'dest' => null, 'intoDir' => false, 'noTargetDir' => false];
        if ($verb === 'find') {
            list($m['paths'], $exec) = self::findParts($args);
            if ($exec === '') {
                return null;
            }
            $m['verb'] = "find -exec $exec";
            $m['recursive'] = true;
            return $m;
        }
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }
        // Short options that take the next word, and words that look like options but are the first argument (a mode).
        $valued = ['mv' => '/^-[a-zA-Z]*[tS]$/', 'setfacl' => '/^-[a-zA-Z]*[mMxX]$/', 'chattr' => '/^-[vp]$/', 'rename' => '/^-e$/'];
        $modeLike = ['chmod' => '/^-[rwxXst]+$/', 'chattr' => '/^-(?![RVf]+$)[a-zA-Z]+$/'];
        list($opts, $pos, $values) = self::split($args, isset($valued[$verb]) ? $valued[$verb] : '', isset($modeLike[$verb]) ? $modeLike[$verb] : '');
        $optText = ' ' . implode(' ', $opts) . ' ';
        $m['recursive'] = (bool) preg_match('/ (-[a-zA-Z]*R[a-zA-Z]*|--recursive) /', $optText);
        if ($verb === 'mv') {
            if (preg_match('/ (-[a-zA-Z]*n[a-zA-Z]*|--no-clobber|--update=none) /', $optText)) {
                return null; // never replaces an existing file
            }
            $m['tier'] = 'mv';
            $m['noTargetDir'] = (bool) preg_match('/ (-[a-zA-Z]*T[a-zA-Z]*|--no-target-directory) /', $optText);
            foreach ($opts as $o) {
                if (preg_match('/^(?:-[a-zA-Z]*t|--target-directory=?)(.*)$/', $o, $tm)) { // -t DIR, -tDIR, --target-directory[=]DIR
                    $m['intoDir'] = true;
                    $m['dest'] = $tm[1] !== '' ? ['w' => $tm[1], 'p' => addcslashes($tm[1], '*?[\\'), 'g' => false, 't' => $tm[1][0] === '~']
                        : (isset($values[$o]) ? $values[$o] : null);
                }
            }
            if (!$m['intoDir'] && count($pos) < 2) {
                return null;
            }
            $m['dest'] = $m['intoDir'] ? $m['dest'] : array_pop($pos);
            $m['paths'] = $pos;
            return $m;
        }
        if (in_array($verb, ['chmod', 'chown', 'chgrp'], true) && !$m['recursive'] && !$xargs) {
            return null; // a plain permission or owner change of named files is silent
        }
        // The first argument is the mode, owner, expression or ACL, not a path (setfacl's ACL comes with an option).
        $skipFirst = in_array($verb, ['chmod', 'chown', 'chgrp', 'chattr', 'rename'], true) && strpos($optText, ' --reference') === false;
        $m['paths'] = $skipFirst ? array_slice($pos, 1) : $pos;
        return $m;
    }

    /** [option words, positional words, [option word => its value word]]; after -- everything is positional. */
    private static function split(array $args, $valued, $modeLike)
    {
        $opts = [];
        $pos = [];
        $values = [];
        for ($i = 0, $n = count($args), $end = false; $i < $n; $i++) {
            $w = $args[$i]['w'];
            if ($end || $w === '-' || $w === '' || $w[0] !== '-' || ($modeLike !== '' && !$pos && preg_match($modeLike, $w))) {
                $pos[] = $args[$i];
            } elseif ($w === '--') {
                $end = true;
            } else {
                $opts[] = $w;
                if (($valued !== '' && preg_match($valued, $w)) || preg_match('/^--(target-directory|suffix|set|set-file|modify|modify-file|remove|remove-file|reference)$/', $w)) {
                    if ($i + 1 < $n) {
                        $values[$w] = $args[++$i];
                    }
                }
            }
        }
        return [$opts, $pos, $values];
    }

    /** find's starting points (. when none) and the program -exec/-execdir/-ok runs when it is one of FIND_EXEC, else ''. */
    private static function findParts(array $args)
    {
        $paths = [];
        $i = 0;
        $n = count($args);
        while ($i < $n && preg_match('/^-([HLP]|O\d*|D)$/', $args[$i]['w'])) {
            $i += $args[$i]['w'] === '-D' ? 2 : 1;
        }
        for (; $i < $n && $args[$i]['w'] !== '' && strpos('-(!,', $args[$i]['w'][0]) === false; $i++) {
            $paths[] = $args[$i];
        }
        for ($exec = ''; $i < $n - 1 && $exec === ''; $i++) {
            if (in_array($args[$i]['w'], ['-exec', '-execdir', '-ok', '-okdir'], true) && in_array(basename($args[$i + 1]['w']), self::FIND_EXEC, true)) {
                $exec = basename($args[$i + 1]['w']);
            }
        }
        return [$paths ?: [['w' => '.', 'p' => '.', 'g' => false, 't' => false]], $exec];
    }
}
