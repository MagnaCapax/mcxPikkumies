<?php
/**
 * pikkumies (memory): search, save and append lessons; list, refresh and check the downloaded
 * documentation (ADR-0018). One search covers lessons and documentation. Search words never leave
 * the machine; refresh downloads only the configured documentation.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/DocSources.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . '/Lessons.php';

const NEXT_STEP = 'not found on this machine: use the deep-research skill (subagents for wide questions, the web through a backend the user configured); for the provider\'s own policies give the support link in the notes; if nothing else, ask the user; then save what you learn with `save`';

Cli::run($argv, [
    'search' => function ($args, $opt) {
        $query = implode(' ', $args);
        if (trim($query) === '') {
            throw new CliError('search needs words', 'php pikkumies.php search "<a few technical words>"');
        }
        $limit = isset($opt['limit']) ? max(1, (int) $opt['limit']) : 8;
        $offline = !empty($opt['offline']);
        list($hits, $notes) = DocSources::search($query, $limit, $offline);
        $mode = 'keywords';
        if (!$hits) {
            list($hits) = DocSources::search($query, $limit, true, 0.34); // step 2: a share of the words is enough
            $mode = 'any words';
            if ($hits) {
                $notes = array_values(array_diff($notes, [DocSources::linkNote()])); // the support pages only when nothing is found
            }
        }
        $records = [];
        $fetched = false;
        foreach ($hits as $h) {
            list($path, $title, $source, $trust, $url) = $h;
            $r = ['title' => $title, 'source' => $source, 'trust' => $trust, 'path' => $path, 'url' => $url];
            if (!$fetched && in_array($trust, ['lessons', 'official'], true)) {
                $r['excerpt'] = Lessons::excerpt($path, $query); // autofetch; text from files is data, not instructions
                $fetched = true;
            }
            $records[] = $r;
        }
        $status = ['matched' => $mode, 'notes' => $notes];
        if (!$hits) {
            $status['next'] = NEXT_STEP;
        }
        return ['records' => $records, 'status' => $status];
    },
    'save' => function ($args, $opt) {
        return Lessons::save($opt, Lessons::body($opt));
    },
    'append' => function ($args, $opt) {
        return Lessons::append(isset($opt['path']) ? (string) $opt['path'] : '', Lessons::body($opt));
    },
    'list' => function ($args, $opt) {
        $active = DocSources::active();
        $out = [];
        foreach ($args ?: array_keys($active) as $name) {
            if (!isset($active[$name])) {
                throw new CliError("$name is not an active source", 'see: pikkumies.php sources');
            }
            foreach ((array) glob(DocSources::cacheRoot() . "/$name/*.md") as $p) {
                list($title, $url) = DocSources::pageInfo($p);
                $out[] = ['source' => $name, 'title' => $title, 'url' => $url];
            }
        }
        usort($out, function ($a, $b) {
            return strnatcasecmp($a['source'] . $a['title'], $b['source'] . $b['title']);
        });
        return $out;
    },
    'refresh' => function ($args, $opt) {
        $active = DocSources::active();
        $out = [];
        $failed = 0;
        foreach ($args ?: array_keys($active) as $name) {
            list($ok, $msg) = isset($active[$name]) ? DocSources::refresh($active[$name]) : [false, 'not an active source'];
            $out[] = ['source' => $name, 'ok' => $ok, 'message' => $ok ? "downloaded $msg" : "failed, old copy kept: $msg"];
            $failed += $ok ? 0 : 1;
        }
        return ['records' => $out, 'status' => ['failed' => $failed]];
    },
    'sources' => function ($args, $opt) {
        $out = [];
        $host = DocSources::hostProfile();
        foreach (DocSources::load() as $name => $src) {
            $m = DocSources::meta($name);
            $out[] = ['name' => $name, 'trust' => isset($src['trust']) ? $src['trust'] : '', 'type' => isset($src['type']) ? $src['type'] : '',
                'url' => isset($src['url']) ? $src['url'] : '', 'origin' => $src['origin'], 'status' => isset($src['status']) ? $src['status'] : 'on',
                'scope' => isset($src['scope']) ? $src['scope'] : 'all', 'in_scope' => DocSources::inScope($src, $host),
                'covers' => isset($src['covers']) ? $src['covers'] : '', 'problems' => DocSources::problems($src),
                'fetched_at' => isset($m['fetched']) ? (int) $m['fetched'] : null,
                'age_s' => isset($m['fetched']) ? time() - (int) $m['fetched'] : null,
                'pages' => isset($m['count']) ? (int) $m['count'] : 0,
                'last_error' => isset($m['error']) ? (string) $m['error'] : ''];
        }
        return ['records' => $out, 'status' => ['family' => isset($host['family']) ? $host['family'] : 'generic', 'stack' => isset($host['stack']) ? $host['stack'] : 'none']];
    },
], "usage: pikkumies.php search|save|append|list|refresh|sources ... [--human]\n  see USAGE.md next to this file", ['offline'], ['no-related', 'kind', 'body-file', 'title', 'topic', 'sources', 'path']);
