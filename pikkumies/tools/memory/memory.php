<?php
/**
 * memory.php save — write a lesson as a flat markdown file.
 *
 *   php memory.php save "<title>" <bodyFile> [--tags a,b] [--sources "x; y"]
 *
 * Lessons: ../../memory/YYYYMMDD-slug.md (user data, gitignored). Frontmatter: tags, sources.
 * Searching is `tools/docs/docs.php search`: one search over the lessons and the documentation
 * (ranking in lib/TextSearch.php).
 */
require_once dirname(__DIR__, 2) . '/lib/TextSearch.php';

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

if (!isset($args[0]) || $args[0] !== 'save' || count($args) < 3) {
    $search = isset($args[0]) && $args[0] === 'search' ? "search moved to: php " . dirname(__DIR__) . "/docs/docs.php search \"<words>\"\n" : '';
    fwrite(STDERR, $search . "usage: memory.php save \"<title>\" <bodyFile> [--tags a,b] [--sources \"x\"]\n");
    exit(2);
}
list(, $title, $bodyFile) = $args;
$body = (string) @file_get_contents($bodyFile);
if (trim($body) === '') {
    fwrite(STDERR, "body file is empty or unreadable\n");
    exit(1);
}
$root = dirname(__DIR__, 2);
@mkdir($root . '/memory', 0700, true);
@chmod($root . '/memory', 0700); // git creates this tracked directory with the clone's umask
$path = $root . '/memory/' . gmdate('Ymd') . '-' . TextSearch::slug($title) . '.md';
if (file_exists($path)) {
    fwrite(STDERR, "lesson exists, not overwriting: $path\n");
    exit(1);
}
file_put_contents($path, "---\ntags: [" . $tags . "]\nsources: [" . $sources . "]\n---\n# " . $title . "\n\n" . $body . "\n");
echo "saved: $path\n";
$related = array_diff(TextSearch::rank(TextSearch::readDirs(TextSearch::lessonDirs($root)), $title, 4), [$path]);
if ($related) {
    echo "related (link them in the body if relevant):\n  " . implode("\n  ", array_slice($related, 0, 3)) . "\n";
}
