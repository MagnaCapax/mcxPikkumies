<?php
/**
 * LitterTargets: the paths a terminal command creates or writes, for the litter-check hook (ADR-0093). Words come from
 * ../ssh-connect/SshWords.php, so quotes, substitutions and redirections read as in the other guards. Read: output
 * redirections; mkdir, touch, tee, truncate, dd of=; cp, mv, install, ln and rsync (their target, or target/name when it
 * is an existing folder); git clone and init; curl -o/-O, wget -O/-P and their default names; unzip and tar (extracting
 * makes unknown names, so the folder counts); zip; mktemp with a path; the text after sh -c and bash -c, read again. Wrappers
 * (sudo, env, nice, timeout, busybox ...) are skipped with their option values. Here-document bodies are data, not commands.
 * A relative path is read from the folder a cd earlier in the same text moved to, else $dir (the call's workdir); $HOME,
 * ${HOME} and ~ are the home folder. A word built at run time counts by the folder before the expansion; one that starts
 * with the expansion ("$LOG", "$(mktemp -d)/x") is not placed at all.
 * Out of its sight: a path in any other variable or built in code, a cd made in an earlier call when the call has no
 * workdir, and programs that write by themselves (the scratch-dir hook's tree check sees what they leave).
 */
require_once dirname(__DIR__) . '/ssh-connect/SshWords.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class LitterTargets
{
    /** Options that take the next word as their value, per program. */
    const VALUES = ['mkdir' => ['-m', '--mode', '-Z', '--context'], 'touch' => ['-d', '-r', '-t', '--date', '--reference'],
        'truncate' => ['-s', '-r', '-o', '--size', '--reference'], 'cp' => ['-S', '--suffix'], 'mv' => ['-S', '--suffix'],
        'install' => ['-m', '-o', '-g', '-S', '--mode', '--owner', '--group', '--suffix'], 'ln' => ['-S', '--suffix'],
        'rsync' => ['-e', '--rsh', '-f', '--filter', '--exclude', '--include', '-T', '--temp-dir', '--chmod', '--chown'],
        'clone' => ['-b', '--branch', '-o', '--origin', '--depth', '--reference', '-c', '--config', '--template', '--separate-git-dir', '-j', '--jobs', '--filter', '-u', '--upload-pack'],
        'zip' => ['-b', '-n', '-t', '-tt', '-x', '-i'], 'mktemp' => ['-p', '--tmpdir', '--suffix'], 'unzip' => ['-d', '-x'],
        'tar' => ['-C', '--directory', '-f', '--file', '-T', '-X', '--exclude'],
        'curl' => ['-o', '--output', '--output-dir', '-H', '--header', '-d', '--data', '-X', '-u', '-A', '-e', '-b', '-c', '-F', '-T', '-w', '-m', '-x', '-r', '-K', '--retry', '--connect-timeout'],
        'wget' => ['-O', '--output-document', '-P', '--directory-prefix', '-o', '-a', '-t', '-T', '-U', '-e', '--header'], 'tee' => []];
    const WRAPPERS = ['sudo', 'env', 'command', 'nohup', 'time', 'nice', 'ionice', 'timeout', 'stdbuf', 'exec', 'busybox'];
    /** Wrapper options whose value is the next word. */
    const WRAPPER_VALUES = ['sudo' => ['-u', '-g', '-C', '-h', '-p', '-U', '-D', '-r', '-t', '-T', '-R'], 'env' => ['-u', '-C', '-S'],
        'timeout' => ['-s', '-k', '--signal', '--kill-after'], 'nice' => ['-n'], 'ionice' => ['-c', '-n', '-p'], 'stdbuf' => ['-i', '-o', '-e']];
    const SHELLS = ['sh', 'bash', 'dash', 'zsh', 'ksh', 'ash'];

    /** [[absolute path, glob], ...] what $text creates or writes (glob: the names are unknown, the folder is what counts); null when unreadable. */
    public static function read($text, $dir, $home, $depth = 0)
    {
        $split = $depth > 4 ? null : SshWords::split(self::dropHeredocs($text));
        if ($split === null) {
            return null;
        }
        $out = [];
        $cwd = self::path($dir, '', $home);
        foreach ($split['commands'] as $i => $words) {
            foreach ($split['writes'] as $write) {
                if ($write[0] === $i) {
                    self::add($out, $write[1], $cwd, $home);
                }
            }
            $here = $cwd; // env -C and sudo -D move only the program they start
            $words = self::strip($words, $here, $home);
            $verb = $words ? PathName::base($words[0]['text']) : '';
            if ($verb === 'cd' || $verb === 'pushd') {
                $to = isset($words[1]) && !$words[1]['glob'] && $words[1]['text'] !== '-' ? self::path(self::text($words[1], $home), $cwd, $home) : '';
                $cwd = isset($words[1]) ? (string) $to : self::path($home, '', $home);
                continue;
            }
            if (in_array($verb, self::SHELLS, true)) {
                // sh -c 'text', bash -lc "text": the text is a command line of its own.
                for ($k = 1; isset($words[$k + 1]) && strlen($words[$k]['text']) > 1 && $words[$k]['text'][0] === '-'; $k++) {
                    if ($words[$k]['text'][1] !== '-' && strpos($words[$k]['text'], 'c') !== false) {
                        $more = self::read($words[$k + 1]['text'], (string) $here, $home, $depth + 1);
                        $out = $more === null ? $out : array_merge($out, $more);
                        break;
                    }
                }
                continue;
            }
            foreach (self::targets($verb, array_slice($words, 1), $here, $home) as $t) {
                $out[] = $t;
            }
        }
        foreach ($split['writes'] as $write) {
            if ($write[0] >= count($split['commands'])) {
                self::add($out, $write[1], $cwd, $home); // a redirection with no command after it
            }
        }
        foreach ($split['nested'] as $nested) {
            $more = self::read($nested, $dir, $home, $depth + 1);
            $out = $more === null ? $out : array_merge($out, $more);
        }
        return $out;
    }

    /** The targets of one command, its program name $verb and its arguments $args. */
    private static function targets($verb, array $args, $cwd, $home)
    {
        $out = [];
        if ($verb === 'git') {
            while ($args && $args[0]['text'] !== 'clone' && $args[0]['text'] !== 'init') {
                $w = array_shift($args);
                if (($w['text'] === '-C' || $w['text'] === '-c') && $args) {
                    $next = array_shift($args);
                    $cwd = $w['text'] === '-C' ? (string) self::path(self::text($next, $home), $cwd, $home) : $cwd;
                } elseif ($w['text'] === '' || $w['text'][0] !== '-') {
                    return []; // another git command
                }
            }
            $sub = $args ? array_shift($args)['text'] : '';
            list($plain) = self::split($args, self::VALUES['clone']);
            if ($sub === 'clone' && $plain) {
                $to = isset($plain[1]) ? $plain[1] : ['text' => preg_replace('/\.git$/', '', PathName::base(rtrim($plain[0]['text'], '/'))), 'glob' => false, 'dyn' => false];
                self::add($out, $to, $cwd, $home);
            } elseif ($sub === 'init') {
                self::add($out, ['text' => (isset($plain[0]) ? $plain[0]['text'] : '.') . '/.git', 'glob' => isset($plain[0]) && $plain[0]['glob'], 'dyn' => isset($plain[0]) && $plain[0]['dyn']], $cwd, $home);
            }
            return $out;
        }
        list($plain, $opts) = self::split($args, isset(self::VALUES[$verb]) ? self::VALUES[$verb] : []);
        if (in_array($verb, ['mkdir', 'touch', 'tee', 'truncate'], true)) {
            foreach ($plain as $w) {
                self::add($out, $w, $cwd, $home);
            }
        } elseif (in_array($verb, ['cp', 'mv', 'install', 'ln', 'rsync'], true)) {
            $into = self::opt($opts, ['-t', '--target-directory']);
            if ($verb === 'install' && self::opt($opts, ['-d', '--directory'], true) !== null) {
                foreach ($plain as $w) {
                    self::add($out, $w, $cwd, $home);
                }
                return $out;
            }
            if ($into === null && count($plain) < 2 && $verb !== 'ln') {
                return [];
            }
            $dest = $into !== null ? $into : (count($plain) > 1 ? array_pop($plain) : ['text' => '.', 'glob' => false, 'dyn' => false]);
            if ($verb === 'rsync' && preg_match('#^[^/]*:#', $dest['text'])) {
                return []; // host:path, another machine
            }
            $real = self::path(self::text($dest, $home), $cwd, $home);
            $resolved = $real === null ? null : TreeLitter::resolve($real, '');
            if ($resolved !== null && $resolved[1] && is_dir($resolved[0]) && !$dest['glob']) {
                foreach ($plain as $src) {
                    self::add($out, ['text' => $real . '/' . PathName::base(rtrim($src['text'], '/')), 'glob' => $src['glob'], 'dyn' => $src['dyn']], $cwd, $home);
                }
            } else {
                self::add($out, $dest, $cwd, $home);
            }
        } elseif ($verb === 'dd') {
            foreach ($plain as $w) {
                if (strpos($w['text'], 'of=') === 0) {
                    self::add($out, ['text' => substr($w['text'], 3)] + $w, $cwd, $home);
                }
            }
        } elseif ($verb === 'curl' || $verb === 'wget') {
            $file = self::opt($opts, $verb === 'curl' ? ['-o', '--output'] : ['-O', '--output-document']);
            $prefix = self::opt($opts, $verb === 'curl' ? ['--output-dir'] : ['-P', '--directory-prefix']);
            $named = $verb === 'wget' ? $file === null : self::opt($opts, ['-O', '--remote-name'], true) !== null;
            if ($file !== null && $file['text'] !== '-') {
                self::add($out, $prefix !== null && $file['text'][0] !== '/' ? ['text' => $prefix['text'] . '/' . $file['text']] + $file : $file, $cwd, $home);
            }
            foreach ($named ? $plain : [] as $url) {
                $name = PathName::base((string) parse_url($url['text'], PHP_URL_PATH)) ?: 'index.html';
                self::add($out, ['text' => ($prefix !== null ? $prefix['text'] : '.') . '/' . $name, 'glob' => false, 'dyn' => $url['dyn'] || ($prefix !== null && $prefix['dyn'])], $cwd, $home);
            }
        } elseif ($verb === 'unzip' || $verb === 'tar') {
            $mode = $verb === 'unzip' ? (self::opt($opts, ['-l', '-t', '-p', '-Z', '-v'], true) === null ? 'x' : '')
                : (preg_match('/^-?-?[a-zA-Z]*x|^--extract|^--get/', isset($args[0]) ? $args[0]['text'] : '') || self::opt($opts, ['-x', '--extract', '--get'], true) !== null ? 'x'
                : (preg_match('/^-?[a-zA-Z]*c/', isset($args[0]) ? $args[0]['text'] : '') || self::opt($opts, ['-c', '--create'], true) !== null ? 'c' : ''));
            $into = self::opt($opts, $verb === 'unzip' ? ['-d'] : ['-C', '--directory']);
            if ($mode === 'x') {
                self::add($out, ['text' => ($into !== null ? $into['text'] : '.') . '/*', 'glob' => true, 'dyn' => $into !== null && $into['dyn']], $cwd, $home);
            } elseif ($mode === 'c') {
                $file = self::opt($opts, ['-f', '--file']);
                if ($file === null && isset($args[0]) && preg_match('/^-?[a-zA-Z]*f$/', $args[0]['text']) && isset($args[1])) {
                    $file = $args[1]; // tar czf out.tgz ... and tar -czf out.tgz ...
                }
                if ($file !== null && $file['text'] !== '-') {
                    self::add($out, $file, $cwd, $home);
                }
            }
        } elseif ($verb === 'zip' && $plain) {
            self::add($out, $plain[0], $cwd, $home);
        } elseif ($verb === 'mktemp' && $plain && self::opt($opts, ['-t'], true) === null) {
            $in = self::opt($opts, ['-p', '--tmpdir']);
            self::add($out, $in !== null ? ['text' => $in['text'] . '/' . $plain[0]['text'], 'glob' => false, 'dyn' => $in['dyn'] || $plain[0]['dyn']] : $plain[0], $cwd, $home);
        }
        return $out;
    }

    /** Leading variable assignments and wrapper programs (sudo, env, nice ...) with their options, removed. */
    private static function strip(array $words, &$cwd, $home)
    {
        $wrapper = '';
        while ($words) {
            $t = $words[0]['text'];
            if (isset(self::WRAPPER_VALUES[$wrapper]) && in_array($t, self::WRAPPER_VALUES[$wrapper], true) && isset($words[1])) {
                if (($wrapper === 'env' && $t === '-C') || ($wrapper === 'sudo' && $t === '-D')) {
                    $cwd = (string) self::path((string) self::text($words[1], $home), $cwd, $home); // runs the program in that folder
                }
                array_splice($words, 0, 2); // sudo -u nobody: the user is the option's value, not the program
                continue;
            }
            if (in_array(PathName::base($t), self::WRAPPERS, true)) {
                $wrapper = PathName::base($t);
            } elseif (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $t) && ($wrapper === '' || $t === '' || ($t[0] !== '-' && !ctype_digit(rtrim($t, 'smhd.'))))) {
                return $words;
            }
            array_shift($words);
        }
        return [];
    }

    /** $text without the bodies of its here-documents, which are data for a program, not commands (a <p> in HTML is not a redirection). */
    private static function dropHeredocs($text)
    {
        list($out, $ends) = [[], []];
        foreach (explode("\n", (string) $text) as $line) {
            if ($ends) {
                if (trim($line) === $ends[0]) {
                    array_shift($ends);
                }
                continue;
            }
            $out[] = $line;
            if (preg_match_all('/(?<!<)<<(?!<)-?[ \t]*([\'"]?)([A-Za-z0-9_.-]+)\1/', $line, $m)) {
                $ends = $m[2];
            }
        }
        return implode("\n", $out);
    }

    /** [plain words, options as [[name, word or null], ...]]; $values: options whose value is the next word (or after =). */
    private static function split(array $args, array $values)
    {
        list($plain, $opts, $rest) = [[], [], false];
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $t = $args[$i]['text'];
            if ($rest || $t === '' || $t[0] !== '-' || $t === '-') {
                $plain[] = $args[$i];
            } elseif ($t === '--') {
                $rest = true;
            } elseif (strpos($t, '--') === 0 && strpos($t, '=') !== false) {
                list($name, $value) = explode('=', $t, 2);
                $opts[] = [$name, ['text' => $value] + $args[$i]];
            } elseif (in_array($t, $values, true) || preg_match('/^-[a-zA-Z]*[' . self::shortValues($values) . ']$/', $t)) {
                $opts[] = [strlen($t) > 2 && $t[1] !== '-' ? '-' . substr($t, -1) : $t, isset($args[$i + 1]) ? $args[++$i] : null];
                if (strlen($t) > 2 && $t[1] !== '-') {
                    foreach (str_split(substr($t, 1, -1)) as $c) {
                        $opts[] = ["-$c", null];
                    }
                }
            } else {
                foreach (strpos($t, '--') === 0 ? [$t] : array_map(function ($c) {
                    return "-$c";
                }, str_split(substr($t, 1))) as $name) {
                    $opts[] = [$name, null];
                }
            }
        }
        return [$plain, $opts];
    }

    /** The one-letter options of $values, for a character class; '' matches nothing extra. */
    private static function shortValues(array $values)
    {
        $s = '';
        foreach ($values as $v) {
            $s .= strlen($v) === 2 && $v[0] === '-' && ctype_alpha($v[1]) ? $v[1] : '';
        }
        return $s === '' ? '\x00' : $s;
    }

    /** The value word of the first option named in $names, or null; $flag: an option without a value, a marker word. */
    private static function opt(array $opts, array $names, $flag = false)
    {
        foreach ($opts as $o) {
            if (in_array($o[0], $names, true) && ($flag || $o[1] !== null)) {
                return $flag ? ['text' => $o[0]] : $o[1];
            }
        }
        return null;
    }

    /** Add the word as an absolute path; with a glob or an expansion in it, its fixed folder part. */
    private static function add(array &$out, array $w, $cwd, $home)
    {
        $text = self::text($w, $home);
        $dyn = $text === null; // built at run time: only the part before the expansion is known, so it counts like a glob
        $text = $dyn ? self::text(['dyn' => false] + $w, $home) : $text;
        if ($text === '' || $text === '-' || ($dyn && strpos('$`', $text[0]) !== false)) {
            return; // "$LOG", "$(mktemp -d)/x": the place is decided at run time, so nothing here says it is the tree
        }
        $glob = !empty($w['glob']) || $dyn;
        if ($glob) {
            $cut = strcspn($text, $dyn ? '*?[{$`' : '*?[{');
            $text = substr($text, 0, $cut);
            $text = substr($text, -1) === '/' ? $text : dirname($text === '' ? '.' : $text);
        }
        $path = self::path($text, $cwd, $home);
        if ($path !== null) {
            $out[] = [$path, $glob];
        }
    }

    /** The word's text with $HOME, ${HOME} and a leading ~ as the home folder; null when another expansion is in it. */
    private static function text(array $w, $home)
    {
        $t = (string) preg_replace('/\$\{HOME\}|\$HOME(?![A-Za-z0-9_])/', $home, $w['text']);
        $t = $t === '~' || strpos($t, '~/') === 0 ? $home . substr($t, 1) : $t;
        return !empty($w['dyn']) && strpbrk($t, '$`') !== false ? null : $t;
    }

    /** $p as an absolute path from $cwd (~ as home); null when relative and $cwd is unknown. */
    private static function path($p, $cwd, $home)
    {
        $p = (string) $p;
        $p = $p === '~' || strpos($p, '~/') === 0 ? $home . substr($p, 1) : $p;
        if ($p === '' || $p[0] === '/') {
            return $p === '' ? null : $p;
        }
        return is_string($cwd) && $cwd !== '' && $cwd[0] === '/' ? rtrim($cwd, '/') . '/' . $p : null;
    }
}
