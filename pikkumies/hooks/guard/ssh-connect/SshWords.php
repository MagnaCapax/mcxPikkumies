<?php
/**
 * SshWords: shell text split into simple commands, the way the ssh-connect hook needs it (ADR-0068). Words keep their
 * literal text after quote removal and say whether an expansion ($VAR, $(...), backticks, $'...') or a glob or brace
 * (unquoted * ? [ { }) is in them, because a host built that way cannot be read. The text of every command substitution
 * and process substitution is returned too, to be read again at command position. Comments are kept with their offsets
 * (the `# intent:` line). An unclosed quote or substitution makes the whole text unreadable: split() returns null.
 * Not a shell: here-documents are read as ordinary lines (each a command), which reads more, never less. A here-string
 * (`bash <<< "ssh x"`) is returned with the substitutions, since a shell fed it runs it. The target word of each output
 * redirection (>, >>, &>, >|) is returned under writes with the index of its command, for the litter-check hook (ADR-0093).
 */
require_once __DIR__ . '/SshExpansion.php';

class SshWords
{
    /** ['commands' => [[word, ...], ...], 'nested' => [text, ...], 'comments' => [[start, end, text], ...], 'writes' => [[command index, word], ...]], or null when unreadable. */
    public static function split($text)
    {
        $text = (string) $text;
        $n = strlen($text);
        $out = ['commands' => [], 'nested' => [], 'comments' => [], 'writes' => []];
        $words = [];
        $w = null; // the word being read: ['text', 'dyn', 'glob', 'q'] (q: something in it was quoted)
        $target = ''; // what the next word is: '' an argument, 'skip' a redirection target, 'nested' a here-string's text
        $end = function () use (&$out, &$words, &$w, &$target) {
            self::push($words, $w, $target, $out);
            if ($words) {
                $out['commands'][] = $words;
            }
            list($words, $target) = [[], ''];
        };
        for ($i = 0; $i < $n; $i++) {
            $c = $text[$i];
            if ($c === ' ' || $c === "\t") {
                self::push($words, $w, $target, $out);
                continue;
            }
            if ($c === "\n" || $c === ';' || $c === '(' || $c === ')') {
                $end();
                continue;
            }
            if ($c === '|' || ($c === '&' && ($i + 1 >= $n || $text[$i + 1] !== '>'))) {
                $i += $i + 1 < $n && ($text[$i + 1] === $c || ($c === '|' && $text[$i + 1] === '&')) ? 1 : 0;
                $end();
                continue;
            }
            if ($c === '<' || $c === '>' || $c === '&') {
                if ($w !== null && !$w['q'] && ctype_digit($w['text'])) {
                    $w = null; // 2>&1: the digits are a file descriptor
                }
                self::push($words, $w, $target, $out);
                if (($c === '<' || $c === '>') && $i + 1 < $n && $text[$i + 1] === '(') {
                    $close = SshExpansion::closing($text, $i + 2);
                    if ($close < 0) {
                        return null;
                    }
                    $out['nested'][] = substr($text, $i + 2, $close - $i - 2);
                    $i = $close;
                    continue;
                }
                $op = $i;
                while ($i + 1 < $n && strpos('<>&|-', $text[$i + 1]) !== false) {
                    $i++;
                }
                if ($text[$i] === '&' && $i + 1 < $n && ctype_digit($text[$i + 1])) {
                    $i += strspn($text, '0123456789', $i + 1); // >&2 names a descriptor
                    continue;
                }
                // bash <<< "ssh x": the shell runs that text, so it is read as a command; a here-document's lines are read anyway.
                $target = substr($text, $op, 3) === '<<<' ? 'nested' : (strpos(substr($text, $op, $i - $op + 1), '>') !== false ? 'write' : 'skip');
                continue;
            }
            if ($c === '#' && $w === null) {
                $stop = strpos($text, "\n", $i);
                $stop = $stop === false ? $n : $stop;
                $out['comments'][] = [$i, $stop, substr($text, $i, $stop - $i)];
                $i = $stop - 1;
                continue;
            }
            $w = $w === null ? ['text' => '', 'dyn' => false, 'glob' => false, 'q' => false] : $w;
            if ($c === '\\') {
                if ($i + 1 < $n && $text[$i + 1] !== "\n") {
                    $w['text'] .= $text[$i + 1];
                    $w['q'] = true;
                }
                $i++;
            } elseif ($c === "'") {
                $close = strpos($text, "'", $i + 1);
                if ($close === false) {
                    return null;
                }
                $w['text'] .= substr($text, $i + 1, $close - $i - 1);
                $w['q'] = true;
                $i = $close;
            } elseif ($c === '"') {
                $i = SshExpansion::doubleQuoted($text, $i + 1, $w, $out['nested']);
                if ($i < 0) {
                    return null;
                }
            } elseif ($c === '$' || $c === '`') {
                $i = SshExpansion::expansion($text, $i, $w, $out['nested']);
                if ($i < 0) {
                    return null;
                }
            } else {
                $w['glob'] = $w['glob'] || strpos('*?[{}', $c) !== false;
                $w['text'] .= $c;
            }
        }
        $end();
        return $out;
    }

    /** Add the word read so far to the command (or where $target says); a lone unquoted { or } is a group, not a word. */
    private static function push(array &$words, &$w, &$target, array &$out)
    {
        if ($w === null) {
            return;
        }
        if ($target === 'nested') {
            $out['nested'][] = $w['text'];
        } elseif ($target === 'write') {
            $out['writes'][] = [count($out['commands']), $w];
        } elseif ($target === '' && ($w['q'] || ($w['text'] !== '{' && $w['text'] !== '}'))) {
            $words[] = $w;
        }
        list($w, $target) = [null, ''];
    }
}
