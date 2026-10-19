<?php
/**
 * Lessons: the save/append side of the memory unit (ADR-0018). Lessons live in
 * pikkumies/memory/lessons/<topic>/YYYYMMDD-slug.md with frontmatter date, topic and sources.
 * A new lesson must link to at least one related memory file (or say why none is related), so the
 * lessons form a web that search can follow. Memory files are only ever appended to; the links in the new text are
 * written relative to the file, so they work in any markdown viewer (ADR-0060).
 * With privacy.perfLogging on, each save is also counted in the memory metrics (ADR-0041).
 */
class Lessons
{
    const EXCERPT = 1500; // bytes of the best paragraph returned with a search hit

    public static function root()
    {
        return DocSources::root() . '/memory';
    }

    /** The lesson text: --body-file, or stdin when it is not a terminal. */
    public static function body(array $opt)
    {
        if (isset($opt['body-file']) && is_string($opt['body-file'])) {
            $body = (string) @file_get_contents($opt['body-file']);
        } else {
            $body = (function_exists('posix_isatty') && posix_isatty(STDIN)) ? '' : (string) stream_get_contents(STDIN);
        }
        if (trim($body) === '') {
            throw new CliError('the lesson text is empty', 'pass --body-file <file>, or pipe the text on stdin');
        }
        return rtrim($body) . "\n";
    }

    public static function save(array $opt, $body)
    {
        $topic = isset($opt['topic']) ? (string) $opt['topic'] : '';
        $title = isset($opt['title']) ? trim((string) $opt['title']) : '';
        $sources = isset($opt['sources']) ? array_values(array_filter(array_map('trim', explode(';', (string) $opt['sources'])), 'strlen')) : [];
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $topic) || $title === '' || !$sources) {
            throw new CliError('save needs --topic, --title and --sources', 'e.g. --topic rtorrent --title "rtorrent stops on a full disk" --sources "df -h output; rtorrent log"');
        }
        // ADR-0018 hierarchy: lessons/<topic>/ for solved problems, reference/ for facts, research/ for longer findings.
        $kind = isset($opt['kind']) && is_string($opt['kind']) ? $opt['kind'] : 'lesson';
        if (!in_array($kind, ['lesson', 'reference', 'research'], true)) {
            throw new CliError("unknown --kind $kind", 'kinds: lesson (default), reference, research');
        }
        $dir = self::root() . ($kind === 'lesson' ? "/lessons/$topic" : "/$kind");
        $related = [];
        list($hits) = DocSources::search($title, 5, true, 0.5); // free text: half of the title's words
        foreach ($hits as $h) {
            if ($h[3] === 'lessons' || ($kind === 'research' && $h[3] === 'research')) { // research notes are labelled research (DocSources)
                $related[] = $h[0];
            }
        }
        // Links are written relative to the new file's directory under the real root; that directory may not exist yet.
        $real = realpath(self::root());
        list($body, $links) = self::relink($body, ($real !== false ? $real . substr($dir, strlen(self::root())) : $dir) . '/new.md');
        $why = isset($opt['no-related']) && is_string($opt['no-related']) ? trim($opt['no-related']) : '';
        if (!$links && $why === '') {
            throw new CliError('a lesson must link to at least one related memory file',
                'link them in the text, e.g. [what it is about](<path>)' . ($related ? ', from: ' . implode(', ', $related) : '') . '; or pass --no-related "<why none is related>"');
        }
        $slug = substr(TextSearch::slug($title), 0, 100);
        $path = "$dir/" . gmdate('Ymd') . "-$slug.md";
        if (file_exists($path)) {
            throw new CliError("a lesson with this title already exists today: $path", 'add to it with: append --path ' . $path);
        }
        $duplicate = null;
        foreach ($related as $p) {
            if (substr(basename($p, '.md'), 9) === $slug) {
                $duplicate = $p;
            }
        }
        $text = "---\ndate: " . gmdate('Y-m-d') . "\ntopic: $topic\nsources: " . json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n---\n# $title\n\n" . $body;
        if ((!@mkdir($dir, 0700, true) && !is_dir($dir)) || @file_put_contents($path, $text) !== strlen($text)) {
            throw new CliError("could not write $path", 'is the disk full?');
        }
        @chmod(self::root(), 0700); // git leaves the tracked memory/ directory 0755; lessons are private
        DocSources::appendMetric('saves', ['ts' => time()]); // the time only: stats pairs it with the searches before it
        $status = ['verified' => is_file($path) && filesize($path) === strlen($text)];
        if ($duplicate !== null) {
            $status['warning'] = "a lesson with the same title exists: $duplicate (append to it next time)";
        }
        if ($why !== '') {
            $status['no_related'] = $why;
        }
        return ['records' => [['path' => $path, 'links' => $links, 'related' => $related]], 'status' => $status];
    }

    public static function append($path, $body)
    {
        $real = realpath($path);
        $root = realpath(self::root());
        // README.md is tracked by git: changing it would stop every later `pikkumies update`.
        if ($real === false || $root === false || strpos($real, $root . '/') !== 0 || substr($real, -3) !== '.md' || !is_file($real) || basename($real) === 'README.md') {
            throw new CliError('append works only on an existing memory file', 'pass --path <a path printed by search or save>');
        }
        $before = filesize($real);
        list($body) = self::relink($body, $real);
        $add = "\n## Update " . gmdate('Y-m-d') . "\n\n" . $body;
        if (@file_put_contents($real, $add, FILE_APPEND | LOCK_EX) !== strlen($add)) {
            throw new CliError("could not append to $real", 'is the disk full?');
        }
        clearstatcache(true, $real);
        return ['records' => [['path' => $real]], 'status' => ['verified' => filesize($real) === $before + strlen($add)]];
    }

    /**
     * [$body with each link to a memory file written relative to $fromFile, the real paths it links to]. A link
     * counts when it names an existing .md file in memory other than README.md: absolute, relative to $fromFile,
     * relative to the memory root, or as written (relative to the current directory), the forms save has
     * always accepted (ADR-0060). Other links, and links in code or front matter, stay as they are.
     */
    public static function relink($body, $fromFile)
    {
        $root = realpath(self::root());
        $accept = function ($real) use ($root) {
            return $root !== false && strpos($real, $root . '/') === 0 && basename($real) !== 'README.md';
        };
        $links = [];
        // The body follows the frontmatter and title in the file, so a --- block at its start is text, not front
        // matter: a leading newline keeps Links from skipping it, and is taken off again below.
        $body = Links::rewrite("\n" . $body, function ($target) use ($fromFile, $accept, &$links) {
            $real = substr($target, -3) === '.md' ? Links::resolve($target, $fromFile, [self::root(), ''], $accept) : false;
            if ($real === false) {
                return null;
            }
            $links[] = $real;
            return Links::relative($fromFile, $real);
        });
        return [(string) substr($body, 1), array_values(array_unique(array_reverse($links)))]; // rewrite walks the text from the end
    }

    /** The paragraph of $path that contains the most search words, at most EXCERPT bytes (TextSearch::excerpt). */
    public static function excerpt($path, $query)
    {
        return TextSearch::excerpt($path, $query, self::EXCERPT);
    }
}
