<?php
/**
 * Questions: read, select and lint the question files (ADR-0039). A question file is front matter between two
 * --- lines, one `key: value` per line (a key may repeat; repeats collect into a list), then the question itself.
 * Checks grades a run against them.
 */
class Questions
{
    /** Every key a question file may use. source and note are for people only and are never checked. */
    const KEYS = ['tags', 'family', 'setup', 'calls', 'first_call', 'not_calls', 'contains', 'contains_any', 'not_contains', 'judge', 'source', 'note'];
    const SINGLE = ['family', 'setup', 'first_call'];
    const CHECKS = ['calls', 'first_call', 'not_calls', 'contains', 'contains_any', 'not_contains', 'judge'];
    /** The search-first gate, whose list of read-only tools first_call uses as it is. */
    const GATE = '/pikkumies/hooks/search/search-first-gate/search-first-gate.php';
    private static $readOnly = null;

    /**
     * Tools that only look around: first_call skips them and checks the first tool that does something. Read from the
     * gate's SEARCH_FIRST_READ_ONLY (the gate file cannot be included: it reads a hook payload), so the two never differ.
     */
    public static function readOnly()
    {
        if (self::$readOnly === null) {
            $src = (string) @file_get_contents(dirname(__DIR__, 4) . self::GATE);
            self::$readOnly = preg_match('/const SEARCH_FIRST_READ_ONLY = \[([^\]]*)\]/', $src, $m) && preg_match_all("/'([a-z_]+)'/", $m[1], $names)
                ? $names[1] : [];
        }
        return self::$readOnly;
    }

    /** Is $fact in $text, any case, ignoring spacing ("200 KB/s" matches "200KB/s")? */
    public static function has($text, $fact)
    {
        $strip = function ($s) { return (string) preg_replace('/\s+/', '', (string) $s); };
        return stripos((string) $text, (string) $fact) !== false || ($strip($fact) !== '' && stripos($strip($text), $strip($fact)) !== false);
    }

    /**
     * Stale-fact guard: for a question tagged source:<name>, each contains value must still be in the downloaded copy
     * of that source (local/docs-cache/<name>/ of the install, any case). Returns the missing facts, [] when all are there.
     */
    public static function stale(array $q, $docsCache)
    {
        $facts = self::values($q, 'contains');
        $missing = [];
        foreach ($facts ? $q['tags'] : [] as $tag) {
            $name = substr($tag, 0, 7) === 'source:' ? substr($tag, 7) : '';
            if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name) || strpos($name, '..') !== false) {
                continue;
            }
            $left = $facts;
            $dir = rtrim($docsCache, '/') . '/' . $name;
            $files = is_dir($dir) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) : [];
            foreach ($files as $file) {
                $text = $file->isFile() && !$file->isLink() ? (string) file_get_contents($file->getPathname()) : '';
                $left = array_values(array_filter($left, function ($fact) use ($text) {
                    return !self::has($text, $fact);
                }));
                if (!$left) {
                    break;
                }
            }
            foreach ($left as $fact) {
                $missing[] = "\"$fact\" is no longer in source $name" . (is_dir($dir) ? '' : ' (no downloaded copy)');
            }
        }
        return $missing;
    }

    /** One question file -> ['id', 'path', 'meta' => [key => [values]], 'tags' => [...], 'body', 'problems' => [...]]. */
    public static function parse($path)
    {
        $q = ['id' => basename($path, '.md'), 'path' => $path, 'meta' => [], 'tags' => [], 'body' => '', 'problems' => []];
        $text = str_replace("\r\n", "\n", (string) @file_get_contents($path));
        if (!preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $text, $m)) {
            $q['problems'][] = 'no front matter between --- lines';
            return $q;
        }
        foreach (explode("\n", $m[1]) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (!preg_match('/^([a-z][a-z0-9_]*):\s*(.*)$/', $line, $kv)) {
                $q['problems'][] = 'front matter line ' . ($n + 2) . ' is not "key: value"';
            } elseif (!in_array($kv[1], self::KEYS, true)) {
                $q['problems'][] = 'unknown key "' . $kv[1] . '" (known: ' . implode(', ', self::KEYS) . ')';
            } else {
                $q['meta'][$kv[1]][] = trim($kv[2]);
            }
        }
        foreach (isset($q['meta']['tags']) ? $q['meta']['tags'] : [] as $list) {
            $q['tags'] = array_merge($q['tags'], array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen')));
        }
        $q['body'] = trim($m[2]);
        return $q;
    }

    /** Every question file in $dir, parsed, sorted by id. */
    public static function load($dir)
    {
        $files = glob(rtrim($dir, '/') . '/*.md') ?: [];
        sort($files);
        return array_map([__CLASS__, 'parse'], $files);
    }

    /** The questions --only and --tag pick; both empty = all. */
    public static function select(array $questions, $only, $tag)
    {
        return array_values(array_filter($questions, function ($q) use ($only, $tag) {
            return ($only === '' || $q['id'] === $only) && ($tag === '' || in_array($tag, $q['tags'], true));
        }));
    }

    /** What is wrong with a question: parse problems, empty values, repeated single keys, no check, bad regex, missing setup. */
    public static function lint(array $q, $fixturesDir)
    {
        $problems = $q['problems'];
        if ($q['body'] === '') {
            $problems[] = 'no question text after the front matter';
        }
        $checks = 0;
        foreach ($q['meta'] as $key => $values) {
            if (in_array($key, self::SINGLE, true) && count($values) > 1) {
                $problems[] = "$key may appear once";
            }
            foreach ($values as $v) {
                if ($v === '') {
                    $problems[] = "$key has no value";
                } elseif (in_array($key, ['calls', 'first_call', 'not_calls'], true) && @preg_match(self::regex($v), '') === false) {
                    $problems[] = "$key is not a valid regular expression: $v";
                }
            }
            $checks += in_array($key, self::CHECKS, true) ? count($values) : 0;
        }
        if ($checks === 0) {
            $problems[] = 'no check (calls, first_call, not_calls, contains, contains_any, not_contains or judge)';
        }
        if (isset($q['meta']['setup'][0]) && !is_dir(rtrim($fixturesDir, '/') . '/' . $q['meta']['setup'][0])) {
            $problems[] = 'setup directory not found under fixtures/: ' . $q['meta']['setup'][0];
        }
        if (isset($q['meta']['setup'][0]) && strpos($q['meta']['setup'][0], '..') !== false) {
            $problems[] = 'setup may not contain ..';
        }
        return $problems;
    }

    /** A pattern from a question file as a PCRE: no delimiters to escape (chr(1) is the delimiter), case-insensitive. */
    public static function regex($pattern)
    {
        return "\x01" . $pattern . "\x01i";
    }

    public static function values(array $q, $key)
    {
        return isset($q['meta'][$key]) ? $q['meta'][$key] : [];
    }
}
