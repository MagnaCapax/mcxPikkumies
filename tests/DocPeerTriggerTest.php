<?php
/**
 * ADR-0031 amended 2026-10-19: the memory search says how many pages each tier had on disk, peer fill starts when no
 * lesson and no official page of this host answered (not only when tiers 1-2 were both empty), and a site that refuses
 * us (403, 429) is paused. Run through the real CLI where the behaviour lives there, with the stub fetcher otherwise.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/DocSite.php';

if (!function_exists('peerTriggerTree')) {
    /** A throwaway tree: an official kb of this host (pmss), an upstream app manual (scope all) and a peer site (feral). */
    function peerTriggerTree()
    {
        $tmp = testTempDir('pk-peer');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/memory/pikkumies', 'config'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/memory/lessons", 0700, true);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/pikkumies/config/documentationSources.md", "# test\n\n"
            . "## own-kb\n- type: kb-json\n- url: https://own.example.org/kb.json\n- trust: official\n- scope: pmss\n- refresh: 365d\n\n"
            . "## app-manual\n- type: site\n- url: https://app.example.org/sitemap.xml\n- trust: upstream\n- scope: all\n- refresh: 30d\n\n"
            . "## peer-wiki\n- type: site\n- url: https://peer.example.org/sitemap.xml\n- trust: official\n- scope: feral\n- refresh: 7d\n");
        file_put_contents("$tmp/pikkumies/local/config.php", "<?php return ['host' => ['family' => 'pmss', 'stack' => 'pmss']];\n");
        return $tmp;
    }

    /** One cached page, as the downloads write it. */
    function peerTriggerPage($tree, $source, $trust, $slug, $text)
    {
        @mkdir("$tree/pikkumies/local/docs-cache/$source", 0700, true);
        file_put_contents("$tree/pikkumies/local/docs-cache/$source/$slug.md", "---\ntitle: \"$slug\"\nsource: $source\ntrust: $trust\nurl: \"https://x.example.org/$slug\"\n---\n# $slug\n\n$text\n");
    }

    /** The memory search's status line for $words, offline. */
    function peerTriggerStatus($tree, $words)
    {
        list($code, $out) = runPhp("$tree/pikkumies/tools/memory/pikkumies/pikkumies.php", array_merge(['search'], explode(' ', $words), ['--offline']));
        $l = cliLines($out);
        check($code === 0 && $l, 'search failed: ' . $out);
        return $l[count($l) - 1];
    }
}

return [
    'peer trigger: an upstream-only tier-2 hit enables peer fill; an official hit of this host does not; no hit at all still does' => function () {
        $tree = peerTriggerTree();
        $flag = "$tree/pikkumies/local/docs-peer-enabled";
        try {
            peerTriggerPage($tree, 'own-kb', 'official', 'disk-full', 'rtorrent stops when the disk is full.');
            peerTriggerPage($tree, 'app-manual', 'upstream', 'prune', 'borg prune keeps the newest daily archives.');
            $s = peerTriggerStatus($tree, 'rtorrent disk full');
            check($s['hits_per_tier'][2] >= 1 && !is_file($flag), 'an official page of this host answered, yet peer fill was enabled: ' . json_encode($s));
            $s = peerTriggerStatus($tree, 'borg prune daily');
            check($s['hits_per_tier'][1] === 0 && $s['hits_per_tier'][2] === 1, 'fixture: only the app manual should match: ' . json_encode($s));
            check(is_file($flag) && strpos(implode(' ', $s['notes']), 'no official page of this host') !== false, 'an upstream-only hit did not enable peer fill: ' . json_encode($s));
            unlink($flag);
            peerTriggerStatus($tree, 'zebra quantum');
            check(is_file($flag), 'a search with no hit at all no longer enables peer fill');
        } finally {
            testRmTree($tree);
        }
    },
    'search status: pages_per_tier counts the files each tier had on disk, and an empty tier 3 says so' => function () {
        $tree = peerTriggerTree();
        try {
            foreach (['a', 'b', 'c'] as $n) {
                @mkdir("$tree/pikkumies/memory/lessons/misc", 0700, true);
                file_put_contents("$tree/pikkumies/memory/lessons/misc/$n.md", "# lesson $n\n\nnotes about $n.\n");
            }
            peerTriggerPage($tree, 'own-kb', 'official', 'one', 'first page.');
            peerTriggerPage($tree, 'app-manual', 'upstream', 'two', 'second page.');
            $s = peerTriggerStatus($tree, 'lesson notes');
            check($s['pages_per_tier'] === ['1' => 3, '2' => 2, '3' => 0], 'pages_per_tier: ' . json_encode($s));
            check(strpos(implode(' ', $s['notes']), 'not on disk yet') !== false, 'no note for an empty tier 3: ' . json_encode($s['notes']));
            peerTriggerPage($tree, 'peer-wiki', 'official', 'three', 'a peer page.');
            $s = peerTriggerStatus($tree, 'lesson notes');
            check($s['pages_per_tier']['3'] === 1 && strpos(implode(' ', $s['notes']), 'not on disk yet') === false, 'tier 3 has a page: ' . json_encode($s));
        } finally {
            testRmTree($tree);
        }
    },
    'site refusal: a 429 pauses the source (Retry-After), the next fill sends nothing to it, and sources shows the pause' => function () {
        $tree = peerTriggerTree();
        DocSources::$root = "$tree/pikkumies";
        DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
        DocSite::$sleep = function () {
        };
        $calls = [];
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><loc>https://app.example.org/a</loc><loc>https://app.example.org/b</loc></urlset>';
        DocSources::$fetcher = function ($url) use (&$calls, $sitemap) {
            $calls[] = $url;
            if (substr($url, -11) === '/robots.txt') {
                return ['code' => 404];
            }
            return substr($url, -4) === '.xml' ? $sitemap : ['code' => 429, 'retry_after' => '7200'];
        };
        try {
            $src = DocSources::active()['app-manual'];
            list($ok, $msg) = DocSite::refresh($src, 10, true);
            $meta = DocSources::meta('app-manual');
            check(!$ok || strpos($msg, '1 failed') !== false, 'refusal reported as success: ' . $msg);
            check(isset($meta['pausedUntil']) && abs($meta['pausedUntil'] - (time() + 7200)) < 60 && strpos($meta['error'], 'HTTP 429') !== false, 'no pause from Retry-After: ' . json_encode($meta));
            check(count(array_filter($calls, function ($u) {
                return strpos($u, 'app.example.org/') !== false && substr($u, -4) !== '.xml' && substr($u, -11) !== '/robots.txt';
            })) === 1, 'pages were still fetched after the refusal: ' . json_encode($calls));
            $calls = [];
            list($ok, $msg) = DocSite::refresh($src, 10, true);
            check(!$ok && strpos($msg, 'skipped') === 0 && $calls === [], 'a paused source was fetched again: ' . json_encode([$msg, $calls]));
            list(, $out) = runPhp("$tree/pikkumies/tools/memory/pikkumies/pikkumies.php", ['sources']);
            $row = array_values(array_filter(cliLines($out), function ($r) {
                return isset($r['name']) && $r['name'] === 'app-manual';
            }));
            check($row && $row[0]['paused_until'] === $meta['pausedUntil'], 'sources does not show the pause: ' . $out);
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            DocSources::$fetcher = null;
            DocSite::$sleep = null;
            testRmTree($tree);
        }
    },
    'site refusal: a 403 on robots.txt pauses for 7 days instead of counting as "no robots.txt, everything allowed"' => function () {
        $tree = peerTriggerTree();
        DocSources::$root = "$tree/pikkumies";
        DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
        $calls = [];
        DocSources::$fetcher = function ($url) use (&$calls) {
            $calls[] = $url;
            return ['code' => 403];
        };
        try {
            list($ok) = DocSite::refresh(DocSources::active()['app-manual'], 10, false);
            $meta = DocSources::meta('app-manual');
            check(!$ok && count($calls) === 1 && isset($meta['pausedUntil']) && abs($meta['pausedUntil'] - (time() + DocRefusal::PAUSE)) < 60, 'robots.txt 403 did not pause: ' . json_encode([$calls, $meta]));
            check(DocRefusal::seconds('999999999') === DocRefusal::MAX_PAUSE && DocRefusal::seconds('Wed, 21 Oct 2026 07:28:00 GMT') === DocRefusal::PAUSE, 'Retry-After bounds');
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            DocSources::$fetcher = null;
            testRmTree($tree);
        }
    },
];
