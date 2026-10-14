<?php
/**
 * Units: the tool units under tools/<category>/<tool>/ (ADR-0015) and what generate() makes of
 * them: local/generated/skills/<category>/<tool>/SKILL.md for Hermes, and tools/INDEX.md for the
 * agent. Both are generated and gitignored. A unit is skill.md (frontmatter name + description),
 * USAGE.md (its verbs table is the one list of verbs), README.md, and <tool>.php if it has a tool.
 */
class Units
{
    const VERBS_HEADER = '| verb | arguments | changes | what it does |';

    public static function toolsDir()
    {
        return Config::root() . '/tools';
    }

    public static function generatedDir()
    {
        return Config::localDir() . '/generated/skills';
    }

    /** Every unit with its parsed skill, verbs and problems (an empty 'errors' list means usable). */
    public static function all($toolsDir = null)
    {
        $units = [];
        foreach ((array) glob(($toolsDir ?: self::toolsDir()) . '/*/*/skill.md') as $file) {
            $dir = dirname($file);
            $name = basename($dir);
            list($meta, $body) = self::frontmatter((string) file_get_contents($file));
            $usage = is_file("$dir/USAGE.md") ? (string) file_get_contents("$dir/USAGE.md") : null;
            $u = ['category' => basename(dirname($dir)), 'name' => $name, 'dir' => $dir, 'meta' => $meta, 'body' => $body,
                'verbs' => $usage === null ? [] : self::verbs($usage), 'script' => is_file("$dir/$name.php") ? "$dir/$name.php" : '', 'errors' => []];
            if (!isset($meta['name']) || $meta['name'] !== $name || array_diff(array_keys($meta), ['name', 'description'])) {
                $u['errors'][] = 'skill.md frontmatter must be exactly name (= directory) and description';
            }
            if (empty($meta['description'])) {
                $u['errors'][] = 'skill.md needs a description';
            }
            if ($usage === null || !is_file("$dir/README.md")) {
                $u['errors'][] = 'USAGE.md and README.md are required';
            } elseif ($u['script'] !== '' && !$u['verbs']) {
                $u['errors'][] = 'USAGE.md has no verbs table (' . self::VERBS_HEADER . ')';
            }
            $units[] = $u;
        }
        return $units;
    }

    /** "---\nkey: value\n---\nbody" -> [[key => value], body]. Values may be "quoted". */
    public static function frontmatter($text)
    {
        if (!preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', str_replace("\r\n", "\n", $text), $m)) {
            return [[], $text];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z][a-z0-9_-]*):\s*(.*)$/', $line, $kv)) {
                $v = trim($kv[2]);
                $meta[$kv[1]] = preg_match('/^"(.*)"$/s', $v, $q) ? stripcslashes($q[1]) : $v;
            }
        }
        return [$meta, ltrim($m[2], "\n")];
    }

    /** Rows of the first "| verb | ... |" table; a literal pipe inside a cell is written \|. */
    public static function verbs($usage)
    {
        $verbs = [];
        $in = false;
        foreach (explode("\n", $usage) as $line) {
            $line = trim($line);
            if (!$in) {
                $in = (bool) preg_match('/^\|\s*verb\s*\|/i', $line);
                continue;
            }
            if (preg_match('/^\|[\s|:-]+\|$/', $line)) {
                continue; // separator row
            }
            if ($line === '' || $line[0] !== '|') {
                break;
            }
            $cells = array_map(function ($c) {
                return trim(str_replace('\|', '|', $c));
            }, preg_split('/(?<!\\\\)\|/', substr($line, 1, -1)));
            if (count($cells) >= 4) {
                $verbs[] = ['verb' => trim($cells[0], '` '), 'args' => $cells[1], 'changes' => $cells[2], 'does' => $cells[3]];
            }
        }
        return $verbs;
    }

    public static function renderSkill(array $u)
    {
        $out = "---\nname: " . $u['name'] . "\ndescription: " . json_encode($u['meta']['description'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n---\n"
            . "<!-- Generated from tools/{$u['category']}/{$u['name']}/skill.md. To change it, copy it under a new name into your own skills (ADR-0007). -->\n\n"
            . rtrim($u['body']) . "\n";
        if ($u['verbs']) {
            $out .= "\n## Verbs\n\n";
            if ($u['script'] !== '') {
                $out .= "Run: `php {$u['script']} <verb> [arguments]`. Output is JSON Lines; the last line says `ok` or gives `error` and `hint`. Add `--human` to show it to the user.\n";
            }
            $out .= "Full usage with examples: `{$u['dir']}/USAGE.md`\n\n" . self::VERBS_HEADER . "\n|---|---|---|---|\n";
            foreach ($u['verbs'] as $v) {
                $out .= '| ' . implode(' | ', array_map(function ($c) {
                    return str_replace('|', '\|', $c);
                }, [$v['verb'], $v['args'], $v['changes'], $v['does']])) . " |\n";
            }
        } else {
            $out .= "\nFull usage: `{$u['dir']}/USAGE.md`\n";
        }
        return $out;
    }

    public static function renderIndex(array $units)
    {
        $out = "# Pikkumies tools\n\nGenerated by pikkumies; do not edit. One line per tool: what it is for, and its verbs (* = changes something). Run a tool with `--help`, or read its USAGE.md.\n";
        $category = null;
        foreach ($units as $u) {
            if ($u['category'] !== $category) {
                $category = $u['category'];
                $out .= "\n## $category\n";
            }
            $verbs = array_map(function ($v) {
                return $v['verb'] . (in_array(strtolower($v['changes']), ['', 'no', '-'], true) ? '' : '*');
            }, $u['verbs']);
            $out .= "- **{$u['name']}** (`{$u['dir']}`): {$u['meta']['description']}" . ($verbs ? ' Verbs: ' . implode(', ', $verbs) . '.' : '') . "\n";
        }
        return $out;
    }
}
