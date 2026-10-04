<?php
/**
 * memory.php save|search — lessons as flat markdown files.
 *
 *   php memory.php save "<title>" <bodyFile> [--tags a,b] [--sources "x; y"]
 *   php memory.php search "<query>" [--limit 5]
 *
 * Lessons: ../../memory/YYYYMMDD-slug.md (user data, gitignored) plus read-only
 * lessons shipped by packs (packs/<name>/memory/). Frontmatter: tags, sources.
 * Search: whole words, stopwords dropped, all terms required (a quarter may miss at 5+ terms),
 * score divided by sqrt(length), inline-link boost only for files that already matched.
 * The link graph is built at query time; there is no index file to go stale.
 */
const STOPWORDS = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'it', 'how', 'what', 'why', 'do', 'does', 'with', 'my', 'i'];

function lessonsDir()
{
    return dirname(__DIR__, 2) . '/memory';
}

function searchDirs()
{
    $dirs = [lessonsDir()];
    foreach ((array) glob(dirname(__DIR__, 3) . '/packs/*/memory') as $d) {
        $dirs[] = $d;
    }
    return $dirs;
}

function terms($text)
{
    preg_match_all('/[a-z0-9][a-z0-9._-]*/', strtolower($text), $m);
    return array_values(array_diff(array_unique($m[0]), STOPWORDS));
}

function slugify($title)
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    return $slug !== '' ? $slug : 'lesson';
}

function search($query, $limit)
{
    $want = terms($query);
    if (!$want) {
        return [];
    }
    $allowMiss = count($want) >= 5 ? (int) floor(count($want) / 4) : 0;
    $files = [];
    foreach (searchDirs() as $dir) {
        foreach ((array) glob($dir . '/*.md') as $f) {
            if (basename($f) === 'README.md') {
                continue;
            }
            $files[$f] = (string) file_get_contents($f);
        }
    }
    $scores = [];
    foreach ($files as $f => $text) {
        $have = array_flip(terms($text));
        $hits = 0;
        foreach ($want as $t) {
            if (isset($have[$t])) {
                $hits++;
            }
        }
        if ($hits >= count($want) - $allowMiss) {
            $scores[$f] = $hits / sqrt(max(1, str_word_count($text)));
        }
    }
    // Link boost: matched files linked from other matched files rank higher.
    foreach ($scores as $f => $s) {
        if (preg_match_all('/\]\(([^)]+\.md)\)/', $files[$f], $m)) {
            foreach ($m[1] as $target) {
                $abs = realpath(dirname($f) . '/' . $target);
                if ($abs !== false && isset($scores[$abs])) {
                    $scores[$abs] += 0.25 * $s;
                }
            }
        }
    }
    arsort($scores);
    return array_slice(array_keys($scores), 0, $limit);
}

function save($title, $bodyFile, $tags, $sources)
{
    $body = (string) @file_get_contents($bodyFile);
    if (trim($body) === '') {
        fwrite(STDERR, "body file is empty or unreadable\n");
        exit(1);
    }
    @mkdir(lessonsDir(), 0700, true);
    $path = lessonsDir() . '/' . gmdate('Ymd') . '-' . slugify($title) . '.md';
    if (file_exists($path)) {
        fwrite(STDERR, "lesson exists, not overwriting: $path\n");
        exit(1);
    }
    $front = "---\ntags: [" . $tags . "]\nsources: [" . $sources . "]\n---\n# " . $title . "\n\n";
    file_put_contents($path, $front . $body . "\n");
    echo "saved: $path\n";
    $related = array_diff(search($title, 4), [$path]);
    if ($related) {
        echo "related (link them in the body if relevant):\n  " . implode("\n  ", array_slice($related, 0, 3)) . "\n";
    }
}

$args = array_slice($argv, 1);
$opt = function ($name, $default) use (&$args) {
    $i = array_search('--' . $name, $args, true);
    if ($i === false) {
        return $default;
    }
    $v = isset($args[$i + 1]) ? $args[$i + 1] : $default;
    array_splice($args, $i, 2);
    return $v;
};
$tags = $opt('tags', '');
$sources = $opt('sources', '');
$limit = (int) $opt('limit', 5);
$cmd = isset($args[0]) ? $args[0] : '';

if ($cmd === 'save' && count($args) >= 3) {
    save($args[1], $args[2], $tags, $sources);
} elseif ($cmd === 'search' && count($args) >= 2) {
    foreach (search($args[1], $limit) as $f) {
        echo $f . "\n";
    }
} else {
    fwrite(STDERR, "usage: memory.php save \"<title>\" <bodyFile> [--tags a,b] [--sources \"x\"] | search \"<query>\" [--limit N]\n");
    exit(2);
}
