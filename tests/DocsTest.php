<?php
/** A throwaway pikkumies tree (lib, hooks, tools, config) so nothing touches this checkout's local/. */
function docsTree()
{
    $tmp = sys_get_temp_dir() . '/pk-docs-' . getmypid() . '-' . mt_rand();
    $src = dirname(__DIR__) . '/pikkumies';
    foreach (['lib', 'hooks/search/search-first', 'hooks/search/search-first-gate', 'tools/memory/pikkumies', 'config'] as $d) {
        @mkdir("$tmp/pikkumies/$d", 0700, true);
        foreach ((array) glob("$src/$d/*.*") as $f) {
            copy($f, "$tmp/pikkumies/$d/" . basename($f));
        }
    }
    @mkdir("$tmp/pikkumies/memory", 0700, true);
    @mkdir("$tmp/pikkumies/local", 0700, true);
    return $tmp;
}

function docsHook($tree, $hook, array $payload)
{
    list(, $out) = runPhp("$tree/pikkumies/hooks/$hook", [], json_encode($payload));
    return $out;
}

/** Search tiers (ADR-0031): own kb + an app manual (tier 2), a peer site and a peer kb (tier 3), a peer link. */
function docsTierTree()
{
    $tree = docsTree();
    file_put_contents("$tree/pikkumies/config/documentationSources.md", "# tiers\n\n"
        . "## own-kb\n- type: kb-json\n- url: https://own.example.org/kb.json\n- trust: official\n- scope: pmss\n- refresh: 1d\n\n"
        . "## app-handbook\n- type: site\n- url: https://handbook.example.org/sitemap.xml\n- trust: community\n- scope: all\n- refresh: 30d\n\n"
        . "## peer-wiki\n- type: site\n- url: https://peer.example.org/sitemap.xml\n- trust: official\n- scope: feral\n- refresh: 7d\n\n"
        . "## peer-kb\n- type: kb-json\n- url: https://peerkb.example.org/kb.json\n- trust: official\n- scope: whatbox\n- refresh: 1d\n\n"
        . "## peer-link\n- type: link\n- url: https://peer.example.org/support\n- trust: official\n- scope: feral\n");
    return $tree;
}

/** A cached documentation page as the downloads write it. */
function docsTierPage($tree, $source, $trust, $slug, $title, $text)
{
    @mkdir("$tree/pikkumies/local/docs-cache/$source", 0700, true);
    $page = DocSources::renderPage(['name' => $source, 'trust' => $trust], ['title' => $title, 'url' => "https://$source.example.org/$slug", 'text' => $text, 'updated' => '']);
    file_put_contents("$tree/pikkumies/local/docs-cache/$source/$slug.md", $page);
    return "$tree/pikkumies/local/docs-cache/$source/$slug.md";
}

$kbFixture = json_encode(['_meta' => ['built' => '2026-10-08T00:00:00+00:00'], 'categories' => [
    ['name' => 'Billing', 'articles' => [
        ['id' => 1, 'title' => 'Refund policy', 'url' => 'https://example.org/clients/kb.php?id=1', 'body' => 'Refunds are given within 14 days.'],
        ['id' => 2, 'title' => '../../etc/passwd', 'url' => '', 'body' => 'path test'],
    ]],
]]);

return [
    'docs: shipped config parses; header sections are not sources; shipped sources are valid' => function () {
        $all = DocSources::parse(file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md'));
        check(isset($all['pm-wiki'], $all['pm-kb']), 'sources: ' . implode(',', array_keys($all)));
        foreach ($all as $src) {
            check(DocSources::problems($src) === [], $src['name'] . ': ' . implode('; ', DocSources::problems($src)));
            check(isset($src['scope'], $src['verified']), $src['name'] . ' has no scope or verified date');
            check($src['description'] !== '', $src['name'] . ' has no description');
        }
    },
    'docs: trust levels are the same in the code, the config file and the doctrine' => function () {
        $cfg = file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md');
        preg_match('/## Trust levels\n(.*?)\n## /s', $cfg, $sec);
        preg_match_all('/^\| ([a-z]+) \|/m', $sec[1], $m);
        check($m[1] === DocSources::TRUST, 'config table: ' . implode(',', $m[1]));
        $doc = file_get_contents(dirname(__DIR__) . '/pikkumies/doctrine/doctrine.md');
        preg_match('/\*\*Trust levels\*\*, highest first: ([^\n]+)/', $doc, $line);
        preg_match_all('/(?:^|, )([a-z]+)(?= \(|,|$)/', $line[1], $d);
        check($d[1] === DocSources::TRUST, 'doctrine: ' . implode(',', $d[1]));
    },
    'docs: local overlay replaces, adds and switches off' => function () {
        $tree = docsTree();
        DocSources::$root = "$tree/pikkumies";
        DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss']; // the shipped pm-* sources are scoped to pmss
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## pm-wiki\n- type: mediawiki\n- url: https://wiki.example.org/api.php\n- trust: upstream\n- status: off\n\n## my-kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: community\n- covers: x\n- refresh: 12h\n");
        try {
            $all = DocSources::load();
            $active = DocSources::active();
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
        }
        check($all['pm-wiki']['origin'] === 'local' && $all['pm-wiki']['trust'] === 'upstream', 'overlay did not replace');
        check(!isset($active['pm-wiki']) && isset($active['pm-kb']) && isset($active['my-kb']), 'active: ' . implode(',', array_keys($active)));
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'docs: bad sources are refused, and nothing is fetched from http or /clients/' => function () {
        $base = ['name' => 'x', 'type' => 'kb-json', 'url' => 'https://example.org/kb.json', 'trust' => 'official'];
        check(DocSources::problems($base) === [], 'valid source refused');
        check(DocSources::problems(['url' => 'http://example.org/kb.json'] + $base) !== [], 'http accepted');
        check(DocSources::problems(['url' => 'https://example.org/clients/kb.json'] + $base) !== [], '/clients/ accepted');
        check(DocSources::problems(['type' => 'site', 'url' => 'https://example.org/clients/sitemap.xml'] + $base) !== [], '/clients/ accepted for a downloaded site');
        // A link is never downloaded, only given to the user, so a client-area ticket page is a valid link.
        check(DocSources::problems(['type' => 'link', 'url' => 'https://example.org/clients/submitticket.php'] + $base) === [], '/clients/ refused for a link');
        check(DocSources::problems(['type' => 'web'] + $base) !== [], 'unknown type accepted');
        check(DocSources::problems(['trust' => 'high'] + $base) !== [], 'unknown trust accepted');
        check(DocSources::problems(['name' => '../x'] + $base) !== [], 'bad name accepted');
        check(DocSources::problems(['refresh' => 'weekly'] + $base) !== [], 'bad refresh accepted');
        $called = 0;
        DocSources::$fetcher = function () use (&$called) {
            $called++;
            return '';
        };
        foreach (['http://example.org/a', 'https://example.org/clients/clientarea.php'] as $u) {
            try {
                DocSources::get($u);
                check(false, "fetched $u");
            } catch (Exception $e) {
            }
        }
        DocSources::$fetcher = null;
        check($called === 0, 'refused URL reached the fetcher');
    },
    'docs: a scope name that is not a known host is a problem, also on an off source; the shipped RapidSeedbox sources are off and valid' => function () {
        $base = ['name' => 'x', 'type' => 'site', 'url' => 'https://example.org/sitemap.xml', 'trust' => 'official'];
        foreach (['pmss', 'feral, whatbox', 'generic', 'all', 'swizzin,all'] as $scope) {
            check(DocSources::problems(['scope' => $scope] + $base) === [], "valid scope $scope refused");
        }
        foreach (['ferral', 'feral, whatbx', 'rapidseedbox'] as $scope) {
            check(DocSources::problems(['scope' => $scope] + $base) !== [], "unknown scope $scope accepted");
            check(DocSources::problems(['scope' => $scope, 'status' => 'off'] + $base) !== [], "unknown scope $scope accepted on an off source");
        }
        $all = DocSources::parse(file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md'));
        foreach (['rapidseedbox-help', 'rapidseedbox-support'] as $n) {
            check(isset($all[$n]) && $all[$n]['status'] === 'off' && DocSources::problems($all[$n]) === [] && strpos($all[$n]['description'], 'Host.php') !== false, "$n: " . json_encode(isset($all[$n]) ? $all[$n] : null));
        }
    },
    'docs: scope-all sources are fetched gently: 30 days between refreshes, 100 pages per fill; provider sources 300; the fill start is jittered' => function () {
        foreach (DocSources::parse(file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md')) as $src) {
            if (DocSources::isShared($src) && (isset($src['status']) ? $src['status'] : 'on') === 'on' && $src['type'] !== 'link') {
                check(DocSources::refreshSeconds($src['refresh']) >= 30 * 86400, $src['name'] . ' refreshes every ' . $src['refresh']);
            }
        }
        check(DocSources::fillBudget(['scope' => 'all']) === 100 && DocSources::fillBudget([]) === 100 && DocSources::fillBudget(['scope' => 'feral']) === 300, 'fill budgets');
        $old = getenv('PIKKUMIES_DOCS_FILL_NOW');
        putenv('PIKKUMIES_DOCS_FILL_NOW=1');
        $now = DocSources::fillJitter();
        putenv('PIKKUMIES_DOCS_FILL_NOW');
        $j = DocSources::fillJitter();
        if ($old !== false) {
            putenv("PIKKUMIES_DOCS_FILL_NOW=$old");
        }
        check($now === 0 && $j >= 0 && $j <= 3600, "jitter: $now / $j");
    },
    'docs: a mediawiki or kb-json refresh takes the per-source lock and skips while it is held' => function () use ($kbFixture) {
        $tree = docsTree();
        DocSources::$root = "$tree/pikkumies";
        $called = 0;
        DocSources::$fetcher = function () use ($kbFixture, &$called) {
            $called++;
            return $kbFixture;
        };
        @mkdir("$tree/pikkumies/local/docs-cache", 0700, true);
        $h = fopen("$tree/pikkumies/local/docs-cache/kb.lock", 'c');
        flock($h, LOCK_EX);
        try {
            $src = ['name' => 'kb', 'type' => 'kb-json', 'url' => 'https://example.org/kb.json', 'trust' => 'official'];
            list($ok, $msg) = DocSources::refresh($src);
            check(!$ok && strpos($msg, 'skipped') === 0 && $called === 0, "lock ignored: $msg, $called requests");
            flock($h, LOCK_UN);
            list($ok, $msg) = DocSources::refresh($src);
            check($ok && $called === 1, "refresh after the lock: $msg");
        } finally {
            fclose($h);
            DocSources::$fetcher = null;
            DocSources::$root = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'docs: mediawiki follows continue and builds page addresses' => function () {
        $urls = [];
        DocSources::$fetcher = function ($url) use (&$urls) {
            $urls[] = $url;
            return count($urls) === 1
                ? json_encode(['continue' => ['gapcontinue' => 'B', 'continue' => 'gapcontinue||'], 'query' => ['pages' => [['title' => 'A page', 'revisions' => [['timestamp' => 't1', 'slots' => ['main' => ['content' => 'alpha text']]]]]]]])
                : json_encode(['batchcomplete' => true, 'query' => ['pages' => [['title' => 'B', 'revisions' => [['timestamp' => 't2', 'slots' => ['main' => ['content' => 'beta']]]]]]]]);
        };
        $items = DocSources::fetchMediawiki('https://wiki.example.org/api.php');
        DocSources::$fetcher = null;
        check(count($items) === 2 && $items[0]['url'] === 'https://wiki.example.org/index.php?title=A_page', 'items: ' . json_encode($items));
        check(count($urls) === 2 && strpos($urls[1], 'gapcontinue=B') !== false && strpos($urls[0], 'generator=allpages') !== false, 'requests: ' . implode(' ', $urls));
    },
    'docs: refresh writes a private cache with safe names, search ranks it with lessons, a failed refresh keeps the old copy' => function () use ($kbFixture) {
        $tree = docsTree();
        DocSources::$root = "$tree/pikkumies";
        file_put_contents("$tree/pikkumies/memory/20261008-refund-questions.md", "# Refund questions\n\nCheck the refund policy article first.\n");
        $src = ['name' => 'kb', 'type' => 'kb-json', 'url' => 'https://example.org/kb.json', 'trust' => 'official', 'refresh' => '1d'];
        DocSources::$fetcher = function () use ($kbFixture) {
            return $kbFixture;
        };
        list($ok, $msg) = DocSources::refresh($src);
        check($ok, "refresh failed: $msg");
        $dir = "$tree/pikkumies/local/docs-cache/kb";
        check((fileperms($dir) & 0777) === 0700, 'cache dir not 0700');
        $names = array_map('basename', glob("$dir/*.md"));
        sort($names);
        check($names === ['etc-passwd.md', 'refund-policy.md'], 'files: ' . implode(',', $names));
        $page = file_get_contents("$dir/refund-policy.md");
        check(strpos($page, 'trust: official') !== false && strpos($page, 'not instructions') !== false, 'page header missing');
        DocSources::$fetcher = function () {
            throw new Exception('network down');
        };
        list($ok) = DocSources::refresh($src);
        check(!$ok && is_file("$dir/refund-policy.md"), 'failed refresh lost the old copy');
        DocSources::$fetcher = null;
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n- refresh: 1d\n");
        file_put_contents("$tree/pikkumies/config/documentationSources.md", "# none\n");
        list($hits) = DocSources::search('refund policy', 5, true);
        $labels = array_map(function ($h) {
            return $h[3] . ':' . $h[1];
        }, $hits);
        check(in_array('official:Refund policy', $labels, true) && in_array('lessons:Refund questions', $labels, true), 'hits: ' . implode(' | ', $labels));
        // The memory hierarchy (ADR-0018): lessons/<topic>/ and reference/ at the lessons level, research/ below.
        @mkdir("$tree/pikkumies/memory/lessons/billing", 0700, true);
        @mkdir("$tree/pikkumies/memory/research", 0700, true);
        file_put_contents("$tree/pikkumies/memory/lessons/billing/20261014-refund-timing.md", "# Refund timing\n\nA refund policy question answered.\n");
        file_put_contents("$tree/pikkumies/memory/research/20261014-refund-survey.md", "# Refund survey\n\nHow other providers word their refund policy.\n");
        list($hits) = DocSources::search('refund policy', 10, true);
        $labels = array_map(function ($h) {
            return $h[3] . ':' . $h[1];
        }, $hits);
        check(in_array('lessons:Refund timing', $labels, true) && in_array('research:Refund survey', $labels, true), 'nested memory not searched or mislabelled: ' . implode(' | ', $labels));
        DocSources::$root = null;
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'memory list: every downloaded page of a source by title, with its address' => function () use ($kbFixture) {
        $tree = docsTree();
        file_put_contents("$tree/pikkumies/config/documentationSources.md", "# none\n");
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n");
        DocSources::$root = "$tree/pikkumies";
        DocSources::$fetcher = function () use ($kbFixture) {
            return $kbFixture;
        };
        list($ok) = DocSources::refresh(DocSources::active()['kb']);
        DocSources::$fetcher = null;
        DocSources::$root = null;
        check($ok, 'refresh failed');
        $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
        list($code, $out) = runPhp($tool, ['list']);
        $l = cliLines($out);
        check($code === 0 && $l[count($l) - 1]['count'] === 2, 'list: ' . $out);
        check(strpos($out, '"title":"Refund policy","url":"https://example.org/clients/kb.php?id=1"') !== false, 'title and address missing: ' . $out);
        list($code, $out) = runPhp($tool, ['list', 'nope']);
        check($code === 1 && strpos($out, 'nope is not an active source') !== false, 'unknown source not reported: ' . $out);
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'search-first hook: first turn only, bounded, names the search command, no page text' => function () {
        $tree = docsTree();
        @mkdir("$tree/pikkumies/local/docs-cache/kb", 0700, true);
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n");
        file_put_contents("$tree/pikkumies/local/docs-cache/kb/disk-full.md", "---\ntitle: \"Disk full\"\nurl: \"https://example.org/x\"\n---\n# Disk full\n\nSECRET-BODY-TEXT rtorrent stops when the disk is full.\n");
        $out = docsHook($tree, 'search/search-first/search-first.php', ['hook_event_name' => 'pre_llm_call', 'session_id' => 's', 'extra' => ['is_first_turn' => true, 'user_message' => 'rtorrent stopped, disk full?']]);
        $d = json_decode($out, true);
        check(is_array($d) && strpos($d['context'], 'tools/memory/pikkumies/pikkumies.php search') !== false, 'no instruction: ' . $out);
        check(strpos($d['context'], '[official] kb: Disk full') !== false && strpos($d['context'], 'SECRET-BODY-TEXT') === false, 'hits wrong or page text leaked: ' . $d['context']);
        check(strlen($d['context']) <= 1500, 'context too long');
        $multi = json_decode(docsHook($tree, 'search/search-first/search-first.php', ['extra' => ['is_first_turn' => true, 'user_message' => [['type' => 'text', 'text' => 'disk full']]]]), true);
        check(strpos($multi['context'], 'Disk full') !== false, 'multimodal message not searched');
        check(trim(docsHook($tree, 'search/search-first/search-first.php', ['extra' => ['is_first_turn' => false, 'user_message' => 'x']])) === '', 'fired after the first turn');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'search-first gate: blocks acting tools until the memory search runs, per session; switch turns it off' => function () {
        $tree = docsTree();
        $gate = 'search/search-first-gate/search-first-gate.php';
        $call = function ($session, $tool, $cmd = null) use ($tree, $gate) {
            $p = ['hook_event_name' => 'pre_tool_call', 'session_id' => $session, 'tool_name' => $tool, 'tool_input' => $cmd === null ? ['path' => 'x'] : ['command' => $cmd]];
            $d = json_decode(docsHook($tree, $gate, $p), true);
            return is_array($d) && isset($d['action']) && $d['action'] === 'block';
        };
        check($call('s1', 'write_file'), 'write_file not blocked before search');
        check($call('s1', 'terminal', 'systemctl restart nginx'), 'terminal not blocked before search');
        check(!$call('s1', 'read_file') && !$call('s1', 'clarify'), 'read-only tool blocked');
        check(!$call('s1', 'terminal', 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php sources') && !$call('s1', 'terminal', 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php list pm-wiki --human'), 'sources or list blocked');
        check($call('s1', 'patch'), 'sources or list must not open the gate');
        check($call('s1', 'terminal', 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php save --topic x --title y'), 'save is an action, it must not pass before a search');
        check(!$call('s1', 'terminal', 'php /home/u/pikkumies/tools/memory/pikkumies/pikkumies.php --offline search "disk full"'), 'memory search blocked');
        check(!$call('s1', 'write_file') && !$call('s1', 'terminal', 'df -h'), 'gate stayed closed after search');
        check($call('s2', 'write_file'), 'another session inherited the opened gate');
        file_put_contents("$tree/pikkumies/local/config.php", "<?php return ['searchFirst' => ['disabled' => true]];\n");
        check(!$call('s3', 'write_file'), 'searchFirst.disabled ignored');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'search tiers: peers are other providers\' downloadable sources; app manuals stay tier 2; links and generic-only scopes are not peers' => function () {
        $tree = docsTree(); // the shipped sources
        DocSources::$root = "$tree/pikkumies";
        try {
            DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
            $peers = DocSources::peers();
            check(isset($peers['feral-wiki'], $peers['whatbox-wiki']) && !isset($peers['feral-support']) && !isset($peers['rtorrent-wiki']) && !isset($peers['pm-wiki']), 'pmss peers: ' . implode(',', array_keys($peers)));
            check(!isset($peers['rapidseedbox-help']), 'a source that is off became a peer');
            DocSources::$host = ['family' => 'feral', 'stack' => 'none'];
            $peers = DocSources::peers();
            check(isset($peers['pm-wiki'], $peers['pm-kb']) && !isset($peers['feral-wiki']) && !isset($peers['pm-support']), 'feral peers: ' . implode(',', array_keys($peers)));
            check(!DocSources::isPeer(['scope' => 'generic'], ['family' => 'pmss', 'stack' => 'pmss']), 'a generic-only source counted as another provider');
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'search tiers: a lesson outranks a higher-scoring community page by tier; peer pages come after own-provider hits and carry their tier' => function () {
        $tree = docsTierTree();
        DocSources::$root = "$tree/pikkumies";
        DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
        try {
            @mkdir("$tree/pikkumies/memory/lessons/rtorrent", 0700, true);
            $lesson = "$tree/pikkumies/memory/lessons/rtorrent/20261016-long-notes.md";
            file_put_contents($lesson, "# Notes from the week\n\n" . str_repeat('Assorted notes about the seedbox and its many services. ', 60) . "Once rtorrent stopped because the disk was full.\n");
            $community = docsTierPage($tree, 'app-handbook', 'community', 'disk-full', 'rtorrent disk full', 'rtorrent stops when the disk is full.');
            $own = docsTierPage($tree, 'own-kb', 'official', 'space', 'Storage space', 'When the disk is full rtorrent pauses. ' . str_repeat('Quota and storage details. ', 20));
            $peer = docsTierPage($tree, 'peer-wiki', 'official', 'disk-full', 'rtorrent disk full', 'rtorrent disk full: free space first.');
            // Without tiers the community and peer pages would win on score: the test is only meaningful if they do.
            $plain = TextSearch::rank(TextSearch::readDirs([dirname($lesson), dirname($community), dirname($own), dirname($peer)]), 'rtorrent disk full', 10);
            check(array_search($community, $plain, true) < array_search($lesson, $plain, true) && array_search($peer, $plain, true) < array_search($lesson, $plain, true), 'fixture: the lesson already scores highest: ' . implode(' ', array_map('basename', $plain)));
            list($hits, $notes, $tiers) = DocSources::search('rtorrent disk full', 5, true);
            $got = array_map(function ($h) {
                return $h[5] . ':' . $h[2];
            }, $hits);
            check($got === ['1:lessons', '2:app-handbook', '2:own-kb', '3:peer-wiki'] || $got === ['1:lessons', '2:own-kb', '2:app-handbook', '3:peer-wiki'], 'tier order: ' . implode(' ', $got));
            check($hits[0][0] === $lesson && $hits[3][3] === 'official' && $tiers === [1, 2, 3], 'lesson first, peer keeps its trust label, tiers recorded: ' . json_encode([$hits[0][0], $hits[3][3], $tiers]));
            check(strpos(implode(' ', $notes), 'peer.example.org/support') === false, 'a peer provider\'s support link was offered');
            list($one) = DocSources::search('rtorrent disk full', 1, true);
            check(array_map(function ($h) {
                return $h[5];
            }, $one) === [1, 2, 3], 'the limit is per tier: ' . json_encode($one));
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'search tiers: peer sources are never downloaded by a search; the fill takes them only once enabled, 50 requests a source at most' => function () use ($kbFixture) {
        $tree = docsTierTree();
        DocSources::$root = "$tree/pikkumies";
        DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
        DocSite::$sleep = function () {
        };
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        for ($i = 1; $i <= 60; $i++) {
            $sitemap .= "<url><loc>https://peer.example.org/p$i</loc></url>";
        }
        $sitemap .= '</urlset>';
        $log = [];
        DocSources::$fetcher = function ($url) use (&$log, $kbFixture, $sitemap) {
            $log[] = $url;
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === 'own.example.org' || $host === 'peerkb.example.org') {
                return $kbFixture;
            }
            if ($url === 'https://peer.example.org/robots.txt') {
                return "User-agent: *\nDisallow:\n";
            }
            if ($url === 'https://peer.example.org/sitemap.xml') {
                return $sitemap;
            }
            if ($host === 'peer.example.org') {
                return '<title>Peer page</title><main>peer text</main>';
            }
            return ['code' => 404];
        };
        $peerRequests = function () use (&$log) {
            return count(array_filter($log, function ($u) {
                return in_array(parse_url($u, PHP_URL_HOST), ['peer.example.org', 'peerkb.example.org'], true);
            }));
        };
        try {
            DocSources::enablePeerFill(); // even when enabled, a search never downloads a peer source
            list(, $notes) = DocSources::search('refund policy', 5, false);
            check(in_array('https://own.example.org/kb.json', $log, true), 'the stale own source was not refreshed: ' . implode(' ', $log) . ' ' . implode(' | ', $notes));
            check($peerRequests() === 0, 'a search downloaded a peer source: ' . implode(' ', $log));
            unlink(DocSources::peerFlagFile());
            $log = [];
            $rows = DocSources::fill();
            check($peerRequests() === 0 && !array_filter($rows, function ($r) {
                return strpos($r[0], 'peer-') === 0;
            }), 'the fill took peer sources before peer fill was enabled: ' . implode(' ', $log));
            check(DocSources::enablePeerFill() && !DocSources::enablePeerFill(), 'enable must report only the first time');
            $log = [];
            $rows = DocSources::fill();
            $peerSite = count(array_filter($log, function ($u) {
                return parse_url($u, PHP_URL_HOST) === 'peer.example.org';
            }));
            check($peerSite === DocSources::PEER_FILL_REQUESTS && in_array('https://peerkb.example.org/kb.json', $log, true), "peer fill: $peerSite site requests; " . json_encode($rows));
            check(count(glob("$tree/pikkumies/local/docs-cache/peer-wiki/*.md")) === DocSources::PEER_FILL_REQUESTS - 2, 'peer pages on disk: ' . count(glob("$tree/pikkumies/local/docs-cache/peer-wiki/*.md")));
            list($hits) = DocSources::search('refund policy', 5, true);
            check($hits && end($hits)[2] === 'peer-kb' && end($hits)[5] === 3 && $hits[0][5] === 2, 'peer copies not searched as tier 3: ' . json_encode($hits));
            $log = [];
            DocSources::fill();
            check(!in_array('https://peerkb.example.org/kb.json', $log, true), 'a fresh peer copy was fetched again within 30 days: ' . implode(' ', $log));
        } finally {
            DocSources::$fetcher = null;
            DocSources::$root = null;
            DocSources::$host = null;
            DocSite::$sleep = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'search tiers: the memory search groups by tier, enables peer fill only after a tier-1/2 miss, and records the tiers for the gate' => function () {
        $tree = docsTierTree();
        file_put_contents("$tree/pikkumies/local/config.php", "<?php return ['host' => ['family' => 'pmss', 'stack' => 'pmss']];\n");
        @mkdir("$tree/pikkumies/memory/lessons/rtorrent", 0700, true);
        file_put_contents("$tree/pikkumies/memory/lessons/rtorrent/20261016-disk-full.md", "# rtorrent stops on a full disk\n\nFree space, then start rtorrent.\n");
        DocSources::$root = "$tree/pikkumies";
        docsTierPage($tree, 'own-kb', 'official', 'disk', 'Disk full', 'rtorrent and a full disk.');
        docsTierPage($tree, 'peer-wiki', 'official', 'zebra', 'Zebra quantum tuning', 'zebra quantum settings for another provider.');
        DocSources::$root = null;
        $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
        $flag = "$tree/pikkumies/local/docs-peer-enabled";
        $record = "$tree/pikkumies/local/state/search-first/last-search.json";
        try {
            list($code, $out) = runPhp($tool, ['search', 'rtorrent', 'disk', 'full', '--offline']);
            $l = cliLines($out);
            $status = $l[count($l) - 1];
            check($code === 0 && $l[0]['tier'] === 1 && strpos($l[0]['heading'], 'tier 1') === 0 && $l[1]['tier'] === 2 && strpos($l[1]['heading'], 'tier 2') === 0, 'groups: ' . $out);
            check($status['tiers_searched'] === [1, 2, 3] && !isset($status['next']), 'status: ' . $out);
            check(!is_file($flag), 'peer fill enabled although tiers 1-2 answered');
            $rec = json_decode((string) @file_get_contents($record), true);
            check(is_array($rec) && $rec['tiers'] === [1, 2, 3] && $rec['time'] >= time() - 60, 'record: ' . json_encode($rec));
            list($code, $out) = runPhp($tool, ['search', 'zebra', 'quantum', '--offline']);
            $l = cliLines($out);
            $status = $l[count($l) - 1];
            check($code === 0 && $l[0]['tier'] === 3 && $l[0]['source'] === 'peer-wiki' && $l[0]['trust'] === 'official' && strpos($l[0]['note'], 'may not apply to this host') !== false && strpos($l[0]['heading'], 'tier 3') === 0, 'peer hit: ' . $out);
            check(is_file($flag) && strpos(implode(' ', $status['notes']), 'background fill') !== false && strpos($status['next'], 'tier 4') !== false, 'peer fill not enabled after a tier-1/2 miss, or no next step: ' . $out);
            list(, $out) = runPhp($tool, ['search', 'nginx', 'certificate', '--offline']);
            $l = cliLines($out);
            check(count($l) === 1 && strpos($l[0]['next'], 'deep research') !== false, 'no hit must name tier 4: ' . $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'search tiers: the gate function opens web tools only after a completed search of tiers 1-3 in this turn' => function () {
        $tree = docsTree();
        DocSources::$root = "$tree/pikkumies";
        try {
            $dir = DocSources::searchStateDir();
            @mkdir($dir, 0700, true);
            $marker = $dir . '/' . sha1('s1');
            check(DocSources::tiersSearchedThisTurn('s1') === [] && !DocSources::webResearchAllowed('s1'), 'open without any search');
            touch($marker, time() - 20); // the gate let a search through ...
            check(DocSources::tiersSearchedThisTurn('s1') === [], 'open before the search completed');
            DocSources::recordSearch([3, 1, 2], [1 => 0, 2 => 1, 3 => 0]); // ... and it completed
            check(DocSources::tiersSearchedThisTurn('s1') === [1, 2, 3] && DocSources::webResearchAllowed('s1'), 'closed after a search of tiers 1-3');
            check(DocSources::tiersSearchedThisTurn('s2') === [] && DocSources::tiersSearchedThisTurn('') === [], 'another session or no session inherited the search');
            touch($marker . '.armed', time() - 10); // a new question re-armed the turn
            check(!DocSources::webResearchAllowed('s1'), 'a re-armed turn kept the old search');
            touch($marker, time() + 30); // the gate let the next search through; its record is not written yet
            check(!DocSources::webResearchAllowed('s1'), 'the previous search\'s record counted for a search that has not completed');
            touch($marker, time() - 5);
            DocSources::recordSearch([1, 2], []);
            check(DocSources::tiersSearchedThisTurn('s1') === [1, 2] && !DocSources::webResearchAllowed('s1'), 'tiers 1-2 alone opened the web');
            DocSources::recordSearch([1, 2, 3], []);
            check(DocSources::webResearchAllowed('s1') && strpos(DocSources::webGateMessage(), 'pikkumies.php search') !== false, 'gate message or reopen');
        } finally {
            DocSources::$root = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    // D105: the "sources" verb's in_scope must be true ONLY for a source whose scope actually
    // matches the detected host, never merely present as a key. The existing scope-filter test
    // ("search tiers: sources are filtered by scope...") checked only that the `in_scope` key
    // exists on one record, not that its boolean value is correct for an in-scope vs. an
    // out-of-scope (peer) source, including a `link`-type source.
    'docs: the sources verb reports in_scope true only for a source whose scope matches this host, false for an out-of-scope peer source (downloadable or link)' => function () {
        $tree = docsTierTree();
        file_put_contents("$tree/pikkumies/local/config.php", "<?php return ['host' => ['family' => 'pmss', 'stack' => 'pmss']];\n");
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            list($code, $out) = runPhp($tool, ['sources']);
            $byName = [];
            foreach (cliLines($out) as $l) {
                if (isset($l['name'])) {
                    $byName[$l['name']] = $l;
                }
            }
            check($code === 0, 'sources failed: ' . $out);
            check($byName['own-kb']['in_scope'] === true, 'own-kb (scope: pmss) must be in_scope on a pmss host: ' . json_encode($byName['own-kb']));
            check($byName['app-handbook']['in_scope'] === true, 'app-handbook (scope: all) must always be in_scope: ' . json_encode($byName['app-handbook']));
            check($byName['peer-wiki']['in_scope'] === false, 'peer-wiki (scope: feral) must not be in_scope on a pmss host: ' . json_encode($byName['peer-wiki']));
            check($byName['peer-kb']['in_scope'] === false, 'peer-kb (scope: whatbox) must not be in_scope on a pmss host: ' . json_encode($byName['peer-kb']));
            check($byName['peer-link']['in_scope'] === false, 'peer-link (type: link, scope: feral) must not be in_scope on a pmss host: ' . json_encode($byName['peer-link']));
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    // D112: fetchMediawiki() must stop and throw once MAX_REQUESTS (40) requests have been made,
    // never loop forever on a wiki whose "continue" cursor never runs out. No existing test drives
    // this past a single page of continuation (only "docs: mediawiki follows continue..." which
    // stops after exactly 2 requests on purpose).
    'docs: fetchMediawiki() stops after exactly MAX_REQUESTS requests and throws, never looping forever when "continue" never ends' => function () {
        $requests = 0;
        DocSources::$fetcher = function ($url) use (&$requests) {
            $requests++;
            return json_encode(['continue' => ['gapcontinue' => "p$requests"], 'query' => ['pages' => []]]);
        };
        try {
            DocSources::fetchMediawiki('https://wiki.example.org/api.php');
            check(false, 'fetchMediawiki() must throw when continue never stops');
        } catch (Exception $e) {
            check($requests === DocSources::MAX_REQUESTS, "requests made: $requests, expected " . DocSources::MAX_REQUESTS);
            check(strpos($e->getMessage(), (string) DocSources::MAX_REQUESTS) !== false, 'message must name the request cap: ' . $e->getMessage());
        } finally {
            DocSources::$fetcher = null;
        }
    },
];
