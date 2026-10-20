<?php
/**
 * SshExpansion: the quoted and expanded parts of a shell word, for SshWords (ADR-0068): "..." with its escapes, and
 * $VAR, ${...}, $(...), $'...' and backticks, which mark the word as built at run time and keep their raw text. The
 * text of a command substitution is collected, to be read again as commands. -1 means unclosed: the text is unreadable.
 */
require_once __DIR__ . '/SshUnreadable.php';

class SshExpansion
{

    /** "...": returns the index of the closing quote, -1 when there is none. */
    public static function doubleQuoted($text, $i, array &$w, array &$nested)
    {
        $w['q'] = true;
        for ($n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($c === '"') {
                return $i;
            }
            if ($c === '\\' && $i + 1 < $n && strpos("\$`\"\\\n", $text[$i + 1]) !== false) {
                $w['text'] .= $text[$i + 1] === "\n" ? '' : $text[$i + 1];
                $i++;
            } elseif ($c === '$' || $c === '`') {
                $i = self::expansion($text, $i, $w, $nested);
                if ($i < 0) {
                    return -1;
                }
            } else {
                $w['text'] .= $c;
            }
        }
        return -1;
    }

    /** $..., $(...), ${...}, $'...' or `...` at $i: marks the word, keeps the raw text, returns the last index read or -1. */
    public static function expansion($text, $i, array &$w, array &$nested)
    {
        $n = strlen($text);
        $next = $i + 1 < $n ? $text[$i + 1] : '';
        if ($text[$i] === '`') {
            $close = $i + 1;
            while ($close < $n && $text[$close] !== '`') {
                $close += $text[$close] === '\\' ? 2 : 1;
            }
            if ($close >= $n) {
                return -1;
            }
            $nested[] = SshUnreadable::check(preg_replace('/\\\\([\\\\`$])/', '$1', substr($text, $i + 1, $close - $i - 1)));
        } elseif ($next === '(' || $next === '{') {
            $close = $next === '(' ? self::closing($text, $i + 2) : strpos($text, '}', $i + 2);
            if ($close === false || $close < 0) {
                return -1;
            }
            if ($next === '(') {
                $nested[] = substr($text, $i + 2, $close - $i - 2);
            }
        } elseif ($next === "'") {
            $close = $i + 2;
            while ($close < $n && $text[$close] !== "'") {
                $close += $text[$close] === '\\' ? 2 : 1;
            }
            if ($close >= $n) {
                return -1;
            }
        } elseif ($next !== '' && SshUnreadable::matches('/[A-Za-z0-9_@*#?$!-]/', $next)) {
            SshUnreadable::matches('/\G[A-Za-z_][A-Za-z0-9_]*|\G./', $text, $m, 0, $i + 1);
            $close = $i + strlen($m[0]);
        } else {
            $w['text'] .= '$';
            return $i;
        }
        $w['text'] .= substr($text, $i, $close - $i + 1);
        $w['dyn'] = true;
        return $close;
    }

    /** The index of the ) that closes a ( opened just before $i, skipping quotes and escapes; -1 when there is none. */
    public static function closing($text, $i)
    {
        $depth = 1;
        for ($n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $n && $text[$j] !== $c) {
                    $j += $c === '"' && $text[$j] === '\\' ? 2 : 1;
                }
                if ($j >= $n) {
                    return -1;
                }
                $i = $j;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $i;
            }
        }
        return -1;
    }
}
