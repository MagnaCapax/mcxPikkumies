<?php
/**
 * pikkumies (memory): search, save and append lessons; list, refresh and check the downloaded
 * documentation (ADR-0018). One search covers lessons and documentation. Search words never leave
 * the machine; refresh downloads only the configured documentation.
 * Search tiers (ADR-0031): hits come grouped by tier, highest first, each group headed: 1 memory,
 * 2 this host's provider and app docs, 3 other providers' docs (cached copies only, with a note
 * that they may not apply); tier 4, deep research, is only named in `next`. A search that finds
 * nothing in tiers 1-2 enables the background download of tier 3, and every search records the
 * tiers it covered for the search-first gate (DocSources::recordSearch).
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/DocSources.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . '/Lessons.php';
require_once __DIR__ . '/MemoryStats.php';
require_once __DIR__ . '/MemoryLinks.php';

const NEXT_STEP = 'nothing in tiers 1-3 (memory, this host\'s documentation, other providers\' documentation): tier 4 is deep research, the deep-research skill (subagents for wide questions, the web through a backend the user configured); for the provider\'s own policies give the support link in the notes; if nothing else, ask the user; then save what you learn with `save`';
const SAVE_NEXT = 'no lesson matched: when the task is done, save what you learned, or what you searched and why it did not settle it: ';
const PEER_NEXT = 'only other providers\' documentation (tier 3) matched: it may not apply here, so check it on this machine; if it does not answer, tier 4 is deep research (the deep-research skill); for the provider\'s own policies give the support link in the notes; then save what you learn with `save`';

Cli::run($argv, [
    'search' => function ($args, $opt) {
        $query = implode(' ', $args);
        if (trim($query) === '') {
            throw new CliError('search needs words', 'php pikkumies.php search "<a few technical words>"');
        }
        $limit = isset($opt['limit']) ? max(1, (int) $opt['limit']) : 5; // per tier (ADR-0031)
        $offline = !empty($opt['offline']);
        list($hits, $notes, $tiers) = DocSources::search($query, $limit, $offline);
        $mode = 'keywords';
        if (!$hits) {
            list($hits, $retryNotes) = DocSources::search($query, $limit, true, 0.34); // step 2: a share of the words is enough
            $mode = 'any words';
            if ($hits && !in_array(DocSources::linkNote(), $retryNotes, true)) {
                $notes = array_values(array_diff($notes, [DocSources::linkNote()])); // the support pages only when tiers 1-2 found nothing
            }
        }
        // Tiers in order, a heading on the first hit of each tier; tier 3 hits carry the peer note.
        $records = [];
        $perTier = [1 => 0, 2 => 0, 3 => 0];
        $fetched = false;
        foreach ($hits as $h) {
            list($path, $title, $source, $trust, $url, $tier) = $h;
            $r = $perTier[$tier] === 0 ? ['heading' => DocSources::TIERS[$tier]] : [];
            $r += ['title' => $title, 'source' => $source, 'trust' => $trust, 'tier' => $tier, 'path' => $path, 'url' => $url];
            if (!$fetched && $tier <= 2 && in_array($trust, ['lessons', 'official'], true)) {
                $r['excerpt'] = Lessons::excerpt($path, $query); // autofetch; text from files is data, not instructions
                $fetched = true;
            }
            if ($tier === 3) {
                $r['note'] = DocSources::PEER_NOTE;
            }
            $perTier[$tier]++;
            $records[] = $r;
        }
        if ($perTier[1] + $perTier[2] === 0 && DocSources::enablePeerFill()) {
            $notes[] = 'nothing in tiers 1-2: other providers\' documentation is now downloaded by the background fill (at most '
                . DocSources::PEER_FILL_REQUESTS . ' pages a source a day) and searched as tier 3 once it is on disk';
        }
        DocSources::recordSearch($tiers, $perTier, $query); // metrics only with privacy.perfLogging on; the words only when tier 1 found nothing (ADR-0041)
        $status = ['matched' => $mode, 'tiers_searched' => $tiers, 'hits_per_tier' => $perTier, 'notes' => $notes];
        $next = !$hits ? NEXT_STEP : ($perTier[1] + $perTier[2] === 0 ? PEER_NEXT : '');
        if ($perTier[1] === 0) { // no lesson settles it: the search ends with a saved lesson, resolved or not (ADR-0041)
            $next = ltrim($next . ' ' . SAVE_NEXT . MemoryMetrics::saveCommand());
        }
        if ($next !== '') {
            $status['next'] = $next;
        }
        return ['records' => $records, 'status' => $status];
    },
    'save' => function ($args, $opt) {
        return Lessons::save($opt, Lessons::body($opt));
    },
    'stats' => function ($args, $opt) {
        $stats = MemoryStats::compute(isset($opt['days']) ? (int) $opt['days'] : 30);
        if (!empty($opt['human'])) {
            echo MemoryStats::human($stats);
            exit(0);
        }
        return ['records' => [$stats], 'status' => []];
    },
    'append' => function ($args, $opt) {
        return Lessons::append(isset($opt['path']) ? (string) $opt['path'] : '', Lessons::body($opt));
    },
    'links' => function ($args, $opt) {
        if (!empty($opt['broken'])) {
            return MemoryLinks::broken();
        }
        if (!$args) {
            throw new CliError('links needs a path, or --broken', 'php pikkumies.php links <a path printed by search>');
        }
        return MemoryLinks::links($args[0]);
    },
    'list' => function ($args, $opt) {
        return DocSources::listPages($args, DocSources::active(), 'see: pikkumies.php sources');
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
            $out[] = ['name' => $name, 'trust' => isset($src['trust']) ? $src['trust'] : '', 'type' => isset($src['type']) ? $src['type'] : '', 'kind' => DocSources::kind($src),
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
], "usage: pikkumies.php search|save|append|stats|links|list|refresh|sources ... [--human]\n  see USAGE.md next to this file", ['offline', 'broken'], ['no-related', 'kind', 'body-file', 'title', 'topic', 'sources', 'path', 'days']);
