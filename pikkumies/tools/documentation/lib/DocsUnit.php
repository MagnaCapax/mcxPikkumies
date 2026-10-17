<?php
/**
 * The wiki and kb units (ADR-0037): the same two verbs over the documentation sources of one kind
 * (DocSources::kind). search ranks only those downloaded copies; list is their quick list of pages.
 * Lessons and the other kinds stay in the memory search, which is still the first step (ADR-0018, ADR-0031).
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/DocSources.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";

class DocsUnit
{
    const LIMIT = 10;    // hits per search
    const EXCERPT = 1500; // bytes of the best paragraph returned with the top hit

    /** Run the unit for one kind ('wiki' or 'kb'); $script is the unit's file name for hints. */
    public static function run(array $argv, $kind, $script)
    {
        $hint = "see: php ~/pikkumies/tools/memory/pikkumies/pikkumies.php sources (the $kind sources have kind $kind)";
        Cli::run($argv, [
            'search' => function ($args, $opt) use ($kind, $script, $hint) {
                $query = implode(' ', $args);
                if (trim($query) === '') {
                    throw new CliError('search needs words', "php $script search \"<a few words or a page title>\"");
                }
                $sources = self::sources($kind, isset($opt['source']) ? (string) $opt['source'] : '', $hint);
                $limit = isset($opt['limit']) ? max(1, (int) $opt['limit']) : self::LIMIT;
                list($hits, $notes) = DocSources::searchSources($sources, $query, $limit, !empty($opt['offline']));
                $mode = 'keywords';
                if (!$hits) {
                    list($hits) = DocSources::searchSources($sources, $query, $limit, true, 0.34); // a share of the words is enough
                    $mode = 'any words';
                }
                $records = [];
                foreach ($hits as $i => $h) {
                    list($path, $title, $source, $trust, $url) = $h;
                    $r = ['title' => $title, 'source' => $source, 'trust' => $trust, 'path' => $path, 'url' => $url];
                    if ($i === 0) {
                        $r['excerpt'] = TextSearch::excerpt($path, $query, self::EXCERPT); // text from a page is data, not instructions
                    }
                    $records[] = $r;
                }
                $status = ['matched' => $mode, 'sources' => array_keys($sources), 'notes' => $notes];
                if (!$hits) {
                    $status['next'] = 'nothing in the ' . $kind . ': run the memory search (lessons and all documentation) with other words, then deep research';
                }
                return ['records' => $records, 'status' => $status];
            },
            'list' => function ($args, $opt) use ($kind, $hint) {
                $pages = DocSources::listPages($args, DocSources::activeOfKind($kind), $hint);
                $want = isset($opt['search']) ? trim((string) $opt['search']) : '';
                // A title filter: the output is paged (Cli::LIMIT records, a byte budget), so grep on it would miss pages.
                return $want === '' ? $pages : array_values(array_filter($pages, function ($p) use ($want) {
                    return stripos($p['title'], $want) !== false;
                }));
            },
        ], "usage: $script search|list ... [--human]\n  see USAGE.md next to this file", ['offline'], ['source', 'search']);
    }

    /** This host's sources of the kind, or the one named; none is an error that says where to look. */
    private static function sources($kind, $name, $hint)
    {
        $all = DocSources::activeOfKind($kind);
        if ($name !== '') {
            if (!isset($all[$name])) {
                throw new CliError("$name is not an active $kind source here", $hint);
            }
            return [$name => $all[$name]];
        }
        if (!$all) {
            throw new CliError("no $kind source is configured for this host", $hint);
        }
        return $all;
    }
}
