<?php
/**
 * SshCommand: where a shell text connects over SSH (ADR-0068). A program counts only at command position: the start of a
 * command (after ; & | && || ( { a newline, $( or a backtick), after keywords (then, do, !), assignments and the
 * wrappers below, and inside sh -c / su -c / eval / watch text. When a shell reads its commands from elsewhere (`| sh`,
 * `bash <<< ...`, `sh -s`), a program name counts anywhere in the text. SshPrograms reads each program's own arguments.
 * Anything this cannot read for certain throws SshUnreadable, which the hook turns into a block.
 */
require_once __DIR__ . '/SshUnreadable.php';
require_once __DIR__ . '/SshWords.php';
require_once __DIR__ . '/SshWrappers.php';
require_once __DIR__ . '/SshPrograms.php';

class SshCommand
{
    const SHELLS = ['sh', 'bash', 'zsh', 'dash', 'ksh', 'mksh', 'ash'];
    const KEYWORDS = ['if', 'then', 'else', 'elif', 'fi', 'do', 'done', 'while', 'until', '!'];
    /** Environment variables that pick the program git or rsync connects with: a route this check cannot follow. */
    const ROUTE_VARS = ['GIT_SSH_COMMAND', 'GIT_SSH', 'RSYNC_RSH'];
    const MAX_DEPTH = 4;

    /** ['conns' => [['program', 'names' => [host], 'routes' => [text]]], 'routes' => [text]] for $text; throws SshUnreadable. */
    public static function read($text, $depth = 0, $anywhere = false)
    {
        if ($depth > self::MAX_DEPTH) {
            if (SshUnreadable::matches(SshPrograms::MENTION_RE, (string) $text)) {
                throw new SshUnreadable('it nests commands more than ' . self::MAX_DEPTH . ' deep');
            }
            return ['conns' => [], 'routes' => []];
        }
        $split = SshWords::split($text);
        if ($split === null) {
            throw new SshUnreadable('a quote or a substitution in it is not closed');
        }
        $found = ['conns' => [], 'routes' => []];
        $stdinShell = false;
        foreach ($split['commands'] as $words) {
            $stdinShell = self::command($words, $depth, $found) || $stdinShell;
            if ($anywhere) {
                self::anywhere($words, $depth, $found);
            }
        }
        foreach ($split['nested'] as $inner) {
            self::merge($found, self::read($inner, $depth + 1, $anywhere));
        }
        // A shell that reads its commands from stdin runs text this check sees only as data: read every word of it.
        return $stdinShell && !$anywhere ? self::read($text, $depth, true) : $found;
    }

    public static function merge(array &$found, array $more)
    {
        $found['conns'] = array_merge($found['conns'], $more['conns']);
        $found['routes'] = array_merge($found['routes'], $more['routes']);
    }

    /** The program word's name: /usr/bin/ssh is ssh. */
    public static function program(array $word)
    {
        return basename($word['text']);
    }

    /** One simple command. Returns true when it is a shell reading commands from stdin. */
    private static function command(array $words, $depth, array &$found)
    {
        for ($i = 0, $n = count($words); $i < $n; $i++) {
            $w = $words[$i];
            $name = self::program($w);
            if ($w['glob'] && self::globProgram($name)) {
                throw new SshUnreadable("its program name $name is a pattern that can match an SSH program");
            }
            if (in_array($w['text'], self::KEYWORDS, true) || self::assignment($w['text'], $found)) {
                continue;
            }
            if (in_array($name, ['alias', 'export', 'declare', 'typeset', 'local', 'readonly'], true)) {
                foreach (array_slice($words, $i + 1) as $arg) {
                    self::assignment($arg['text'], $found);
                }
                return false;
            }
            if (in_array($name, self::SHELLS, true)) {
                return SshWrappers::shell($words, $i, $depth, $found);
            }
            if (in_array($name, ['eval', 'watch', 'su'], true)) {
                SshWrappers::scriptOf($name, array_slice($words, $i + 1), $depth, $found);
                return false;
            }
            if ($name === 'command' && isset($words[$i + 1]) && SshUnreadable::matches('/^-[pvV]*[vV]/', $words[$i + 1]['text'])) {
                return false; // command -v ssh only prints where ssh is
            }
            if (isset(SshWrappers::WRAPPERS[$name])) {
                $i = SshWrappers::wrapper($name, $words, $i, $depth, $found) - 1;
                continue;
            }
            if (in_array($name, SshPrograms::NAMES, true)) {
                self::merge($found, SshPrograms::read($name, array_slice($words, $i + 1), $depth));
            }
            return false;
        }
        return false;
    }

    /** In text a shell reads from stdin, every word naming a program starts a command, and text in a word is read too. */
    private static function anywhere(array $words, $depth, array &$found)
    {
        foreach ($words as $j => $w) {
            if (in_array(self::program($w), SshPrograms::NAMES, true)) {
                self::command(array_slice($words, $j), $depth, $found);
            } elseif (SshUnreadable::matches('/[\s;&|`$(]/', $w['text'])) {
                self::merge($found, self::read($w['text'], $depth + 1, true));
            }
        }
    }

    /** True when $text is an assignment (it is then skipped); an SSH route variable is noted, a program stored in a variable refused. */
    public static function assignment($text, array &$found)
    {
        if (!SshUnreadable::matches('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)\z/s', $text, $m)) {
            return false;
        }
        if (in_array($m[1], self::ROUTE_VARS, true)) {
            $found['routes'][] = "$m[1]=$m[2]";
        } else {
            foreach (SshUnreadable::check(preg_split('/[\s;&|`()]+/', $m[2], -1, PREG_SPLIT_NO_EMPTY)) as $word) {
                if (in_array(basename($word), array_merge(SshPrograms::NAMES, ['sshpass']), true)) {
                    throw new SshUnreadable("it stores an SSH program in $m[1], so the command that runs it cannot be read");
                }
            }
        }
        return true;
    }

    /** A glob in a program word (/usr/bin/ss?) that could match one of the SSH programs. */
    private static function globProgram($pattern)
    {
        foreach (array_merge(SshPrograms::NAMES, self::SHELLS) as $p) {
            if (fnmatch($pattern, $p)) {
                return true;
            }
        }
        return false;
    }
}
