<?php
/**
 * Links: the one reader of markdown links (ADR-0060). Search's link boost, the memory unit's save, append and
 * `links` verb, and the link test for tracked markdown all parse and resolve links here, so they cannot disagree
 * again (two resolvers that disagreed let absolute links pass save and miss the search boost).
 * A link is `[text](target)` or `[text](target "title")`; links inside YAML front matter, fenced code blocks
 * and code spans are examples, not links, and are skipped. Web addresses and anchor-only links are skipped.
 */
class Links
{
    const LINK = '/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/';

    /** Link targets in $text in order, each without its #fragment. */
    public static function targets($text)
    {
        return array_column(self::matches($text), 1);
    }

    /**
     * $text with each link target $map gives a new value for replaced; the #fragment and title stay.
     * $map(target without fragment) returns the new target, or null to leave the link as it is.
     */
    public static function rewrite($text, callable $map)
    {
        foreach (array_reverse(self::matches($text)) as $m) {
            list($offset, $target) = $m;
            $new = $map($target);
            if ($new !== null) {
                $text = substr_replace($text, $new, $offset, strlen($target));
            }
        }
        return $text;
    }

    /**
     * The real path $target names from $fromFile, or false: an absolute target as it is, then the target
     * relative to $fromFile's directory, then relative to each of $bases in order. Each corpus passes its own
     * bases (ADR-0060): none for tracked markdown, the memory root for memory, and save also '' (the target as
     * written, which for a relative one is the current directory). $accept($real), when given, must say yes.
     */
    public static function resolve($target, $fromFile, array $bases = [], $accept = null)
    {
        $candidates = $target !== '' && $target[0] === '/' ? [$target] : [];
        $candidates[] = dirname($fromFile) . '/' . $target;
        foreach ($bases as $base) {
            $candidates[] = $base === '' ? $target : "$base/$target";
        }
        foreach ($candidates as $c) {
            $real = realpath($c);
            if ($real !== false && ($accept === null || $accept($real))) {
                return $real;
            }
        }
        return false;
    }

    /** The link from $fromFile to $toFile, relative to $fromFile's directory; both absolute paths. */
    public static function relative($fromFile, $toFile)
    {
        $from = array_values(array_filter(explode('/', dirname($fromFile)), 'strlen'));
        $to = array_values(array_filter(explode('/', $toFile), 'strlen'));
        $same = 0;
        while ($same < count($from) && $same < count($to) && $from[$same] === $to[$same]) {
            $same++;
        }
        return str_repeat('../', count($from) - $same) . implode('/', array_slice($to, $same));
    }

    /**
     * The link graph of $files (path => its bases), markdown targets only: [out, in, broken]. out: path => real
     * paths it links to; in: real path => the paths linking to it; broken: path => targets that resolve to nothing.
     */
    public static function scan(array $files)
    {
        $out = $in = $broken = [];
        foreach ($files as $path => $bases) {
            foreach (self::targets((string) @file_get_contents($path)) as $target) {
                if (substr($target, -3) !== '.md') {
                    continue;
                }
                $real = self::resolve($target, $path, $bases);
                if ($real === false) {
                    $broken[$path][] = $target;
                } else {
                    $out[$path][] = $real;
                    $in[$real][] = $path;
                }
            }
        }
        return [$out, $in, $broken];
    }

    /**
     * $text with YAML front matter, fenced blocks (any indent, so also inside list items) and code spans blanked
     * to spaces, same length, so offsets still point into $text. A code span ends at the next run of as many
     * backticks, never past a blank line. A pattern that fails leaves the text as it was.
     */
    public static function prose($text)
    {
        $blank = function ($m) {
            return preg_replace('/[^\n]/', ' ', $m[0]);
        };
        $prose = (string) $text;
        foreach (['/\A---\n.*?\n---\n/s', '/^[ \t]*(`{3,}|~{3,})[^\n]*\n.*?(?:^[ \t]*\1[^\n]*$|\z)/ms', '/(`+)(?:(?!\n[ \t]*\n).)+?(?<!`)\1(?!`)/s'] as $p) {
            $next = preg_replace_callback($p, $blank, $prose);
            $prose = $next === null ? $prose : $next;
        }
        return $prose;
    }

    /** [offset, target] of each link target outside front matter and code, the #fragment left out. */
    private static function matches($text)
    {
        if (strpos((string) $text, '](') === false) {
            return []; // no link at all: search calls this for every matched page
        }
        preg_match_all(self::LINK, self::prose($text), $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[1] as $hit) {
            $target = explode('#', $hit[0], 2)[0];
            if ($target !== '' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
                $out[] = [$hit[1], $target];
            }
        }
        return $out;
    }
}
