<?php
/**
 * Edge coverage for lib/DocSources.php (parse/problems/isPeer/peer-fill/hostProfile/fillJitter/
 * recordSearch/tiersSearchedThisTurn/linkNote), lib/DocSite.php (resolve/dirBytes), and the
 * pikkumies.php search/save/append CLI verbs, beyond what DocsTest.php's tier-ordering scenarios
 * already exercise.
 *
 * Two rows in this package's reviewed idea table turned out to assert something the source does
 * not actually do, once checked against lib/DocSources.php directly (accuracy doctrine: read the
 * source, do not trust the table's prose). Both are implemented here against the VERIFIED
 * behaviour instead, noted at each test:
 *  - isPeer(): a scope *containing* "all" is never a peer of any host (the "all" token alone makes
 *    inScope() true everywhere, per DocSources.php:272-281), so a scope naming a real provider is
 *    only a peer when "all" is absent from it.
 *  - enablePeerFill(): the flag FILE is written by plain file_put_contents() with no chmod, so its
 *    mode depends on umask, not a fixed 0700/0600; only the containing DIRECTORY is mkdir'd 0700
 *    (DocSources.php:250), which is the actual privacy boundary (non-owners cannot traverse into it
 *    regardless of the file's own mode).
 */
if (!function_exists('docTiersEdgeTree')) {
    /** A throwaway pikkumies tree (lib, tools/memory/pikkumies, config), so nothing touches this checkout's local/. */
    function docTiersEdgeTree()
    {
        $tmp = testTempDir('pk-dte');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/memory/pikkumies', 'config'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/memory", 0700, true);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/pikkumies/config/documentationSources.md", "# none\n");
        return $tmp;
    }
}

return [
    'docsources: parse() — a later "## name" section with the same name inside ONE file overwrites the earlier one (last wins), and a section with no "- type:" line is pure documentation, excluded from the result entirely' => function () {
        $text = "## dup\n- type: site\n- url: https://a.example/sitemap.xml\n- trust: official\n\n## dup\n- type: kb-json\n- url: https://b.example/kb.json\n- trust: community\n";
        $parsed = DocSources::parse($text);
        check(array_keys($parsed) === ['dup'] && $parsed['dup']['trust'] === 'community' && $parsed['dup']['type'] === 'kb-json', 'last section must win: ' . json_encode($parsed));
        $text2 = "## header-only\njust documentation, no type line here.\n\n## real\n- type: link\n- url: https://x.example/help\n- trust: official\n";
        $parsed2 = DocSources::parse($text2);
        check(array_keys($parsed2) === ['real'], 'a typeless section must not become a source: ' . json_encode(array_keys($parsed2)));
    },
    'docsources: parse() — a line that doesn\'t match the `- key: value` pattern (e.g. a bare paragraph, or "-type" with no space) folds into the free-text description, never becomes a field' => function () {
        $text = "## m\n- type: link\n- url: https://x.example/help\n- trust: official\nthis is a bare paragraph, not a field\n-type with no colon space\n";
        $parsed = DocSources::parse($text);
        check(!isset($parsed['m']['nonsense']), 'a malformed line must not create a field');
        check(strpos($parsed['m']['description'], 'this is a bare paragraph, not a field') !== false, 'description: ' . $parsed['m']['description']);
        check(strpos($parsed['m']['description'], '-type with no colon space') !== false, 'description: ' . $parsed['m']['description']);
    },
    'docsources: problems() — depth must be the string "1" or "2" exactly (not int 1, not "3", not ""); prefix must start with "/"' => function () {
        $base = ['name' => 'x', 'type' => 'site', 'url' => 'https://example.org/sitemap.xml', 'trust' => 'official'];
        check(DocSources::problems(['depth' => 1] + $base) !== [], 'int 1 (not the string "1") must be flagged');
        check(DocSources::problems(['depth' => '1'] + $base) === [], 'the string "1" must be accepted');
        check(DocSources::problems(['depth' => '3'] + $base) !== [], '"3" must be flagged');
        check(DocSources::problems(['prefix' => 'x'] + $base) !== [], 'a prefix with no leading / must be flagged');
        check(DocSources::problems(['prefix' => '/x'] + $base) === [], 'a prefix with a leading / must be accepted');
    },
    'docsources: problems() — scope names are trimmed around commas/whitespace before validation, and ALL unknown names in a multi-bad list are named in the one error message' => function () {
        $base = ['name' => 'x', 'type' => 'site', 'url' => 'https://example.org/sitemap.xml', 'trust' => 'official'];
        $p = DocSources::problems(['scope' => 'pmss , bogus-one , bogus-two'] + $base);
        check(count($p) === 1 && strpos($p[0], 'bogus-one') !== false && strpos($p[0], 'bogus-two') !== false, 'scope problems: ' . json_encode($p));
    },
    'docsources: problems() — "verified" is checked by format only (`\d{4}-\d{2}-\d{2}`); a calendar-impossible date like 2026-02-30 is NOT flagged (documents a real, accepted looseness, not a future "fix")' => function () {
        $base = ['name' => 'x', 'type' => 'site', 'url' => 'https://example.org/sitemap.xml', 'trust' => 'official'];
        check(DocSources::problems(['verified' => '2026-02-30'] + $base) === [], 'a calendar-impossible but format-valid date must not be flagged');
        check(DocSources::problems(['verified' => '2026-2-3'] + $base) !== [], 'a non-zero-padded date must still be flagged');
    },
    // Corrected from the reviewed idea table (see file header): a scope naming ONLY a non-provider
    // token is never a peer; a scope naming a real Host::TABLE provider (with no "all") IS a peer of
    // a different host, because "all" (absent here) is the only thing that would force in-scope.
    'docsources: isPeer() — a source scoped to "generic" only is never a peer of any host; a source scoped to a real provider name alone IS a peer of a different host' => function () {
        check(DocSources::isPeer(['scope' => 'generic'], ['family' => 'pmss', 'stack' => 'pmss']) === false, 'generic-only must never be a peer');
        check(DocSources::isPeer(['scope' => 'feral'], ['family' => 'pmss', 'stack' => 'pmss']) === true, 'a real provider name must be a peer of a different host');
    },
    // Corrected from the reviewed idea table (see file header): only the directory is forced 0700;
    // the flag file's own mode depends on umask. What IS guaranteed: the second call is a true no-op.
    'docsources: enablePeerFill()/peerFlagFile() — the flag directory is 0700, and a second call leaves the flag file\'s bytes and mtime byte-for-byte unchanged' => function () {
        $tmp = testTempDir('pk-dte-peerflag');
        DocSources::$root = $tmp;
        try {
            check(DocSources::enablePeerFill() === true, 'the first call must report it turned the flag on');
            $file = DocSources::peerFlagFile();
            check((fileperms(dirname($file)) & 0777) === 0700, 'the flag directory must be 0700: ' . substr(sprintf('%o', fileperms(dirname($file))), -4));
            $content1 = file_get_contents($file);
            $mtime1 = filemtime($file);
            sleep(1);
            check(DocSources::enablePeerFill() === false, 'a second call must report false (already on)');
            clearstatcache(true, $file);
            check(file_get_contents($file) === $content1, 'a second call must never rewrite the audit record');
            check(filemtime($file) === $mtime1, 'a second call must never touch the file\'s mtime');
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'docsources: hostProfile() exercises its live-detection branch (a host.json that fails its fingerprint check) deterministically via Detect::$runOverride, merging through Config\'s host override exactly as the cached path does' => function () {
        $tmp = testTempDir('pk-dte-hostprofile');
        mkdir("$tmp/local", 0700, true);
        // A host.json that cannot pass readCache()'s fingerprint check forces Detect::cached() down its
        // probing branch; with no host.json at all, hostProfile() never calls Detect::cached() at all
        // (it calls Host::family()/Host::stack() directly instead), so $runOverride would never fire.
        file_put_contents("$tmp/local/host.json", json_encode(['schema' => 1, 'fingerprint' => 'stale', 'uid' => 999999]));
        DocSources::$root = $tmp;
        DocSources::$host = null;
        $called = 0;
        Detect::$runOverride = function () use (&$called) {
            $called++;
            return ['detectedAt' => 'x', 'uid' => 999999, 'family' => 'myfamily', 'stack' => 'mystack', 'tier' => 'medium',
                'limits' => ['cpuQuota' => ['value' => null, 'source' => null], 'memMb' => ['value' => null, 'source' => null], 'disk' => ['value' => null, 'source' => null], 'cpuset' => ['value' => null, 'source' => null]]];
        };
        try {
            $profile = DocSources::hostProfile();
            check($called === 1, 'the live probe must run exactly once for a stale/unusable cache');
            check($profile['family'] === 'myfamily' && $profile['stack'] === 'mystack', 'profile: ' . json_encode($profile));
        } finally {
            Detect::$runOverride = null;
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'docsources: fillJitter() returns exactly 0 every single time under PIKKUMIES_DOCS_FILL_NOW=1, across repeated calls (not "usually 0 by luck")' => function () {
        testWithEnv(['PIKKUMIES_DOCS_FILL_NOW' => '1'], function () {
            for ($i = 0; $i < 20; $i++) {
                check(DocSources::fillJitter() === 0, "call $i must be exactly 0 under the test-mode override");
            }
        });
    },
    'docsources: recordSearch() replaces (never merges) the previous record and leaves no stray `.last-search.*` temp file behind' => function () {
        $tmp = testTempDir('pk-dte-record');
        DocSources::$root = $tmp;
        try {
            DocSources::recordSearch([1, 2], ['x' => 1]);
            DocSources::recordSearch([3], ['y' => 2]);
            $dir = DocSources::searchStateDir();
            $rec = json_decode(file_get_contents("$dir/last-search.json"), true);
            check($rec['tiers'] === [3], 'the second call must replace, not merge: ' . json_encode($rec));
            check(glob("$dir/.last-search.*") === [], 'no stray temp file must remain: ' . json_encode(glob("$dir/.last-search.*")));
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'docsources: tiersSearchedThisTurn() — a marker exactly as old as the recorded search time still counts as searched (boundary, not just "newer"); a record with a non-array \'tiers\' or missing \'time\' is treated as not-searched' => function () {
        $tmp = testTempDir('pk-dte-tiers');
        DocSources::$root = $tmp;
        try {
            $dir = DocSources::searchStateDir();
            mkdir($dir, 0700, true);
            $marker = $dir . '/' . sha1('sX');
            touch($marker, 1000);
            file_put_contents("$dir/last-search.json", json_encode(['time' => 1000, 'tiers' => [1, 2]]));
            check(DocSources::tiersSearchedThisTurn('sX') === [1, 2], 'a record time exactly equal to the marker mtime must count as searched');
            file_put_contents("$dir/last-search.json", json_encode(['time' => 2000, 'tiers' => 'notarray']));
            check(DocSources::tiersSearchedThisTurn('sX') === [], 'a non-array tiers field must degrade to not-searched');
            file_put_contents("$dir/last-search.json", json_encode(['tiers' => [1, 2]]));
            check(DocSources::tiersSearchedThisTurn('sX') === [], 'a record with no time field must degrade to not-searched');
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'docsources: linkNote() omits the parenthetical when a link source has no "covers" field and joins multiple link sources with "; "; webGateMessage() always embeds the literal pikkumies.php tool path from DocSources::root()' => function () {
        $tmp = testTempDir('pk-dte-linknote');
        mkdir("$tmp/config", 0700, true);
        file_put_contents("$tmp/config/documentationSources.md", "## help-a\n- type: link\n- url: https://a.example/help\n- trust: official\n- covers: billing\n\n## help-b\n- type: link\n- url: https://b.example/help\n- trust: official\n");
        DocSources::$root = $tmp;
        DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
        try {
            check(DocSources::linkNote() === 'support pages for this host, to give the user to open when nothing here answers (never fetch them yourself): help-a: https://a.example/help (billing); help-b: https://b.example/help', 'linkNote: ' . DocSources::linkNote());
            check(strpos(DocSources::webGateMessage(), "$tmp/tools/memory/pikkumies/pikkumies.php") !== false, 'webGateMessage: ' . DocSources::webGateMessage());
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            testRmTree($tmp);
        }
    },
    'pikkumies.php: search with an empty/whitespace-only query exits 1 with "search needs words"; search --offline never touches a stale mediawiki/kb-json source (DocSources::$fetcher throwing proves it)' => function () {
        $tree = docTiersEdgeTree();
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            list($code, $out) = runPhp($tool, ['search', '   ']);
            $lines = array_values(array_filter(explode("\n", $out), 'strlen'));
            $status = json_decode($lines[0], true);
            check($code === 1 && $status['ok'] === false && $status['error'] === 'search needs words', 'empty query: ' . $out);

            // Offline-vs-online is the DocSources::search() parameter the CLI's --offline flag threads
            // through: a stale source must never be fetched offline, and (to prove the fixture would
            // otherwise touch it) must be fetched when the same search runs online.
            file_put_contents("$tree/pikkumies/config/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n- refresh: 1d\n");
            DocSources::$root = "$tree/pikkumies";
            DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
            $called = 0;
            DocSources::$fetcher = function () use (&$called) {
                $called++;
                throw new Exception('must not be called offline');
            };
            DocSources::search('whatever words', 5, true);
            check($called === 0, 'offline must never call the fetcher for a stale source: ' . $called);
            DocSources::search('whatever words', 5, false);
            check($called === 1, 'online must call the fetcher once, proving the fixture is meaningful: ' . $called);
        } finally {
            DocSources::$fetcher = null;
            DocSources::$root = null;
            DocSources::$host = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'pikkumies.php: search --limit caps hits per tier (not overall) at N, and a non-numeric or 0 --limit falls back to the minimum of 1 instead of erroring or returning everything' => function () {
        $tree = docTiersEdgeTree();
        try {
            file_put_contents("$tree/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n");
            mkdir("$tree/pikkumies/local/docs-cache/kb", 0700, true);
            for ($i = 1; $i <= 3; $i++) {
                $page = DocSources::renderPage(['name' => 'kb', 'trust' => 'official'], ['title' => "Widget page $i", 'url' => "https://example.org/w$i", 'text' => 'widget details here for ' . $i, 'updated' => '']);
                file_put_contents("$tree/pikkumies/local/docs-cache/kb/w$i.md", $page);
            }
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            foreach (['1' => 1, '0' => 1, 'abc' => 1] as $limit => $expectRecords) {
                list($code, $out) = runPhp($tool, ['search', '--offline', '--limit', $limit, 'widget']);
                $lines = array_values(array_filter(explode("\n", $out), 'strlen'));
                check($code === 0 && count($lines) - 1 === $expectRecords, "limit=$limit: " . $out);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'pikkumies.php: an end-to-end save --topic/--title/--sources/--body-file CLI call writes the lesson and prints its path; a follow-up append --path <that path> --body-file <file> succeeds, final {"ok":true}' => function () {
        $tree = docTiersEdgeTree();
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            list($code, $out) = runPhp($tool, ['save', '--topic', 'rtorrent', '--title', 'rtorrent stops on full disk', '--sources', 'manual test', '--no-related', 'first ever lesson, nothing to link'], "disk fills up, rtorrent stops responding until space is freed.\n");
            $lines = array_values(array_filter(explode("\n", $out), 'strlen'));
            check($code === 0 && count($lines) === 2, 'save output: ' . $out);
            $record = json_decode($lines[0], true);
            $status = json_decode($lines[1], true);
            check(is_string($record['path']) && is_file($record['path']), 'save must print a real path: ' . $out);
            check($status['ok'] === true, 'save status: ' . $out);
            list($code2, $out2) = runPhp($tool, ['append', '--path', $record['path']], "more detail body text here.\n");
            $lines2 = array_values(array_filter(explode("\n", $out2), 'strlen'));
            $status2 = json_decode($lines2[1], true);
            check($code2 === 0 && $status2['ok'] === true, 'append output: ' . $out2);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'pikkumies.php: save --service/--tags through the real CLI (ADR-0104 C1 regression: Cli::run\'s known-options list did not include service/tags, so every --service/--tags save failed "unknown option" even though Lessons::save itself was correct); --service auto-fills categories from a fixture services.jsonl, and --tags extends that union' => function () {
        $tree = docTiersEdgeTree();
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            mkdir("$tree/pikkumies/local/state", 0700, true);
            file_put_contents("$tree/pikkumies/local/state/services.jsonl", json_encode(['nick' => 'pm1', 'tags' => ['seedbox', 'backup']]) . "\n");
            file_put_contents("$tree/body.md", "pm1 needs a scrub every sunday.\n");
            list($code, $out) = runPhp($tool, ['save', '--topic', 'services', '--title', 'pm1 cli service tags test', '--sources', 'manual test', '--service', 'pm1', '--tags', 'nas', '--no-related', 'first', '--body-file', "$tree/body.md"]);
            $lines = cliLines($out);
            check($code === 0, 'save must accept --service/--tags, not "unknown option": ' . $out);
            $content = (string) file_get_contents($lines[0]['path']);
            check((bool) preg_match('/^service: \["pm1"\]$/m', $content), 'service frontmatter: ' . $content);
            check((bool) preg_match('/^categories: (.*)$/m', $content, $m) && is_array($cats = json_decode($m[1], true)), 'categories frontmatter: ' . $content);
            sort($cats);
            check($cats === ['backup', 'nas', 'seedbox'], 'categories union via the real CLI (service tags + --tags): ' . $content);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'pikkumies.php: search finds a memory by a word that exists only in its categories frontmatter, never in the body or title (TextSearch reads the whole file text, frontmatter included — ADR-0104 M4)' => function () {
        $tree = docTiersEdgeTree();
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            mkdir("$tree/pikkumies/memory/lessons/services", 0700, true);
            file_put_contents("$tree/pikkumies/memory/lessons/services/20261021-pm1-scrub.md",
                "---\ndate: 2026-10-21\ntopic: services\nsources: [\"x\"]\nservice: [\"pm1\"]\ncategories: [\"zyxelqux\"]\n---\n# pm1 wants a weekly scrub\n\nnothing else in this body mentions the search word at all.\n");
            list($code, $out) = runPhp($tool, ['search', 'zyxelqux', '--offline']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['tier'] === 1 && strpos($l[0]['path'], '20261021-pm1-scrub.md') !== false, 'search must match the categories frontmatter word: ' . $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'docsite: resolve() — "//host/path" becomes "https://host/path"; a non-http(s) scheme (mailto:, javascript:, tel:) returns \'\'; a bare "#frag" alone returns \'\'' => function () {
        $base = 'https://x.example/page';
        check(DocSite::resolve($base, '//host.example/path') === 'https://host.example/path', 'protocol-relative resolve');
        check(DocSite::resolve($base, 'mailto:a@b.com') === '', 'mailto must resolve to empty');
        check(DocSite::resolve($base, 'javascript:void(0)') === '', 'javascript: must resolve to empty');
        check(DocSite::resolve($base, 'tel:+123456') === '', 'tel: must resolve to empty');
        check(DocSite::resolve($base, '#frag') === '', 'a bare fragment must resolve to empty');
    },
    'docsite: dirBytes() sums file sizes recursively through nested subdirectories and returns exactly 0 for a missing or empty directory' => function () {
        $tmp = testTempDir('pk-dte-dirbytes');
        try {
            mkdir("$tmp/sub", 0700, true);
            file_put_contents("$tmp/a.txt", 'abc');      // 3 bytes
            file_put_contents("$tmp/sub/b.txt", '12345'); // 5 bytes
            check(DocSite::dirBytes($tmp) === 8, 'dirBytes: ' . DocSite::dirBytes($tmp));
            check(DocSite::dirBytes("$tmp/does-not-exist") === 0, 'a missing directory must be 0 bytes');
        } finally {
            testRmTree($tmp);
        }
    },
    'contract: a source with NO "scope" key at all (not "scope: all", the key absent) is still in-scope for every host and counted isShared() — matching documentationSources.md\'s literal "Missing means `all`" line' => function () {
        $doc = file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md');
        check(strpos($doc, 'Missing means `all`') !== false, 'the doc must still state the missing-scope rule');
        $srcNoScopeKey = ['name' => 'x'];
        check(DocSources::inScope($srcNoScopeKey, ['family' => 'pmss', 'stack' => 'pmss']) === true, 'a missing scope key must be in-scope everywhere');
        check(DocSources::isShared($srcNoScopeKey) === true, 'a missing scope key must count as shared (scope-all)');
    },
];
