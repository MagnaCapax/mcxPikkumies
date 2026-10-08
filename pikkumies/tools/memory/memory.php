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
 * The link graph is built at query time; there is no index file to go stale (lib/TextSearch.php).
 */
require_once dirname(__DIR__, 2) . '/lib/TextSearch.php';

function lessonsDir()
{
    return dirname(__DIR__, 2) . '/memory';
}

function searchDirs()
{
    return TextSearch::lessonDirs(dirname(__DIR__, 2));
}

function slugify($title)
{
    return TextSearch::slug($title);
}

function search($query, $limit)
{
    return TextSearch::rank(TextSearch::readDirs(searchDirs()), $query, $limit);
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
