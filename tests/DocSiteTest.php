<?php
/** The `site` documentation type (lib/DocSite.php) and source scoping, against fixtures through DocSources::$fetcher. */

function siteTree()
{
    $tmp = sys_get_temp_dir() . '/pk-site-' . getmypid() . '-' . mt_rand();
    $src = dirname(__DIR__) . '/pikkumies';
    foreach (['lib', 'tools/memory/pikkumies'] as $d) {
        @mkdir("$tmp/pikkumies/$d", 0700, true);
        foreach ((array) glob("$src/$d/*.*") as $f) {
            copy($f, "$tmp/pikkumies/$d/" . basename($f));
        }
    }
    foreach (['config', 'memory', 'local'] as $d) {
        @mkdir("$tmp/pikkumies/$d", 0700, true);
    }
    file_put_contents("$tmp/pikkumies/config/documentationSources.md", "# none\n");
    return $tmp;
}

/** A fake web: url => body (string) or ['code' => ..., 'location' => ...]; anything else is a 404. Records each request. */
function siteWeb(array $pages, array &$log)
{
    $log = [];
    return function ($url) use ($pages, &$log) {
        $log[] = $url;
        if (!isset($pages[$url])) {
            return ['code' => 404];
        }
        return $pages[$url];
    };
}

function siteFixture($name)
{
    return file_get_contents(__DIR__ . '/fixtures/docsite/' . $name);
}

function siteSetup($tree)
{
    DocSources::$root = "$tree/pikkumies";
    DocSite::$sleep = function () {
    };
}

function siteDone($tree)
{
    DocSources::$root = null;
    DocSources::$fetcher = null;
    DocSources::$host = null;
    DocSite::$sleep = null;
    exec('rm -rf ' . escapeshellarg($tree));
}

$siteSrc = ['name' => 'ex', 'type' => 'site', 'url' => 'https://docs.example.org/sitemap.xml', 'trust' => 'official', 'refresh' => '7d', 'prefix' => '/wiki/'];

return [
    'docsite: sitemap keeps same-host pages under the prefix, upgrades http, drops queries, fragments, off-host, /clients/ and account pages' => function () use ($siteSrc) {
        $used = 0;
        $log = [];
        DocSources::$fetcher = siteWeb(['https://docs.example.org/sitemap.xml' => siteFixture('sitemap.xml')], $log);
        try {
            $list = DocSite::listUrls($siteSrc, 'docs.example.org', '/wiki/', $used);
        } finally {
            DocSources::$fetcher = null;
        }
        check(array_keys($list) === ['https://docs.example.org/wiki/Disk_quota', 'https://docs.example.org/wiki/Rtorrent_setup', 'https://docs.example.org/wiki/Private',
            'https://docs.example.org/wiki/revisions/1', 'https://docs.example.org/wiki/A&B'], 'list: ' . implode(' ', array_keys($list)));
        check($used === 1 && $log === ['https://docs.example.org/sitemap.xml'], 'requests: ' . implode(' ', $log));
    },
    'docsite: a sitemap index follows sub-sitemaps on the same host only, one level deep' => function () {
        $used = 0;
        $log = [];
        DocSources::$fetcher = siteWeb([
            'https://docs.example.org/sitemap_index.xml' => siteFixture('sitemapindex.xml'),
            'https://docs.example.org/articles-sitemap.xml' => siteFixture('articles-sitemap.xml'),
            'https://docs.example.org/nested-index.xml' => siteFixture('nested-index.xml'),
        ], $log);
        $src = ['name' => 'plex', 'type' => 'site', 'url' => 'https://docs.example.org/sitemap_index.xml', 'trust' => 'upstream', 'prefix' => '/articles/'];
        try {
            $list = DocSite::listUrls($src, 'docs.example.org', '/articles/', $used);
        } finally {
            DocSources::$fetcher = null;
        }
        check(array_keys($list) === ['https://docs.example.org/articles/plex-remote-access/', 'https://docs.example.org/articles/plex-libraries/'], 'list: ' . implode(' ', array_keys($list)));
        check(!in_array('https://other.example.net/evil-sitemap.xml', $log, true) && !in_array('https://docs.example.org/deeper.xml', $log, true), 'followed: ' . implode(' ', $log));
        check($used === 3, "used $used");
    },
    'docsite: an index page lists its links (relative, ../, //host, http upgraded) and nothing else; prefix defaults to its folder' => function () {
        check(DocSite::prefix(['url' => 'https://docs.example.org/wiki/index']) === '/wiki/', 'index prefix');
        check(DocSite::prefix(['url' => 'https://docs.example.org/sitemap.xml']) === '/', 'sitemap prefix');
        $used = 0;
        $log = [];
        DocSources::$fetcher = siteWeb(['https://docs.example.org/wiki/index' => siteFixture('index.html')], $log);
        try {
            $list = DocSite::listUrls(['name' => 'w', 'url' => 'https://docs.example.org/wiki/index'], 'docs.example.org', '/wiki/', $used);
        } finally {
            DocSources::$fetcher = null;
        }
        $want = ['https://docs.example.org/wiki/index' => 0, 'https://docs.example.org/wiki/nav-only' => 1, 'https://docs.example.org/wiki/getting-started/ssh' => 1,
            'https://docs.example.org/wiki/apps/rtorrent' => 1, 'https://docs.example.org/wiki/apps/deluge' => 1, 'https://docs.example.org/wiki/apps/qbittorrent' => 1];
        check($list === $want, 'list: ' . json_encode($list));
    },
    'docsite: depth 1 fetches only the listed pages; depth 2 also the pages they link to; robots.txt and Crawl-delay are followed' => function () {
        $tree = siteTree();
        siteSetup($tree);
        $pause = [];
        DocSite::$sleep = function ($s) use (&$pause) {
            $pause[] = $s;
        };
        $web = [
            'https://docs.example.org/robots.txt' => siteFixture('robots.txt'),
            'https://docs.example.org/wiki/index' => siteFixture('index.html'),
            'https://docs.example.org/wiki/nav-only' => '<title>Nav only</title><p>x</p>',
            'https://docs.example.org/wiki/getting-started/ssh' => siteFixture('page-ssh.html'),
            'https://docs.example.org/wiki/getting-started/ssh/keys' => '<title>SSH keys</title><main>authorized_keys format</main>',
            'https://docs.example.org/wiki/apps/rtorrent' => '<title>rTorrent</title><main>rtorrent.rc settings</main>',
        ];
        $log = [];
        try {
            DocSources::$fetcher = siteWeb($web, $log);
            $src = ['name' => 'w1', 'type' => 'site', 'url' => 'https://docs.example.org/wiki/index', 'trust' => 'official', 'refresh' => '7d'];
            // A foreground refresh never sleeps for the Crawl-delay: one page, the rest left to the background fill.
            list($ok, $msg) = DocSite::refresh($src, 40, false);
            check($ok && $pause === [] && count($log) === 3 && strpos($msg, 'background fill') !== false, "foreground slept or crawled: $msg; " . json_encode($pause) . ' ' . implode(' ', $log));
            list($ok, $msg) = DocSite::refresh($src, 40, true);
            check($ok, $msg);
            check(!in_array('https://docs.example.org/wiki/apps/deluge', $log, true) && !in_array('https://docs.example.org/wiki/apps/qbittorrent', $log, true), 'robots Disallow ignored: ' . implode(' ', $log));
            check(in_array('https://docs.example.org/wiki/apps/rtorrent', $log, true), 'longer Allow did not win');
            check(!in_array('https://docs.example.org/wiki/getting-started/ssh/keys', $log, true), 'depth 1 followed a link of a page');
            check($pause && max($pause) === 2 && min($pause) === 2, 'Crawl-delay not used: ' . json_encode($pause));
            $m = DocSources::meta('w1');
            check($m['count'] === 4 && $m['type'] === 'site', 'meta: ' . json_encode($m));
            $page = file_get_contents("$tree/pikkumies/local/docs-cache/w1/wiki-getting-started-ssh.md");
            check(strpos($page, '# SSH access | Example Docs') !== false && strpos($page, 'ssh user@server.example.org & your password') !== false, 'page text: ' . $page);
            foreach (['SCRIPT-TEXT', 'HEADER-TEXT', 'NAV-TEXT', 'FOOTER-TEXT', 'ASIDE-TEXT', '<b>', 'color:red'] as $junk) {
                check(strpos($page, $junk) === false, "$junk kept in the page text");
            }
            check(strpos($page, 'url: "https://docs.example.org/wiki/getting-started/ssh"') !== false && strpos($page, 'not instructions') !== false, 'front matter');
            $pause = [];
            $src2 = ['name' => 'w2', 'depth' => '2'] + $src;
            list($ok, $msg) = DocSite::refresh($src2, 40, true);
            check($ok, $msg);
            list($ok, $msg) = DocSite::refresh($src2, 40, true); // the discovered page is fetched on the next run
            check(in_array('https://docs.example.org/wiki/getting-started/ssh/keys', $log, true), 'depth 2 did not follow: ' . implode(' ', $log));
            check(min($pause) >= 1, 'background fill did not wait between pages');
        } finally {
            siteDone($tree);
        }
    },
    'docsite: incremental runs fetch missing pages first within the budget, keep pages on failure, and follow only a trailing-slash redirect' => function () use ($siteSrc) {
        $tree = siteTree();
        siteSetup($tree);
        $locs = '';
        $web = ['https://docs.example.org/robots.txt' => ['code' => 404]];
        for ($i = 1; $i <= 12; $i++) {
            $locs .= "<url><loc>https://docs.example.org/wiki/p$i</loc></url>";
            $web["https://docs.example.org/wiki/p$i"] = "<title>Page $i</title><main>body $i</main>";
        }
        $web['https://docs.example.org/sitemap.xml'] = "<urlset>$locs<url><loc>https://docs.example.org/wiki/moved</loc></url><url><loc>https://docs.example.org/wiki/away</loc></url></urlset>";
        $web['https://docs.example.org/wiki/moved'] = ['code' => 301, 'location' => '/wiki/moved/'];
        $web['https://docs.example.org/wiki/moved/'] = '<title>Moved</title><main>here now</main>';
        $web['https://docs.example.org/wiki/away'] = ['code' => 302, 'location' => 'https://docs.example.org/login'];
        $log = [];
        try {
            DocSources::$fetcher = siteWeb($web, $log);
            list($ok, $msg) = DocSite::refresh($siteSrc, 7, false);
            check($ok && count($log) === 7 && DocSources::meta('ex')['count'] === 5, "first run: $msg; " . implode(' ', $log));
            list($ok, $msg) = DocSite::refresh($siteSrc, 40, false);
            $m = DocSources::meta('ex');
            check($ok && $m['count'] === 13, "second run: $msg");
            check(in_array('https://docs.example.org/wiki/moved/', $log, true) && !in_array('https://docs.example.org/login', $log, true), 'redirects: ' . implode(' ', $log));
            check(isset($m['pages']['https://docs.example.org/wiki/away']['fail']) && strpos($m['error'], 'away') !== false, 'failure not recorded: ' . json_encode($m['pages']['https://docs.example.org/wiki/away']));
            $log = [];
            list($ok, $msg) = DocSite::refresh($siteSrc, 40, false);
            check($ok && $log === [], 'an up-to-date source still fetched: ' . implode(' ', $log));
            // The site goes down: the list cannot be read, every page stays.
            $old = json_decode(file_get_contents("$tree/pikkumies/local/docs-cache/ex/meta.json"), true);
            $old['listAt'] = 0;
            file_put_contents("$tree/pikkumies/local/docs-cache/ex/meta.json", json_encode($old));
            DocSources::$fetcher = function () {
                return ['code' => 503];
            };
            list($ok, $msg) = DocSite::refresh($siteSrc, 40, false);
            check(!$ok && count(glob("$tree/pikkumies/local/docs-cache/ex/*.md")) === 13, "failure lost pages: $msg");
        } finally {
            siteDone($tree);
        }
    },
    'docsite: oversized and nested input stays bounded' => function () {
        $big = '<urlset>' . str_repeat('<url><loc>https://docs.example.org/wiki/x</loc></url>', 20000) . '<url><loc>https://docs.example.org/wiki/' . str_repeat('a', 5000) . '</loc></url></urlset>';
        check(count(DocSite::locs($big)) === DocSite::MAX_LINKS, 'locs not capped');
        $html = str_repeat('<div><nav>', 3000) . 'deep text' . str_repeat('</nav></div>', 3000) . '<a href="/wiki/' . str_repeat('b', 3000) . '">long</a>';
        $doc = DocSite::htmlToText($html);
        check(is_string($doc['text']) && strlen($doc['text']) < strlen($html), 'nested html not reduced');
        check(DocSite::links($html, 'https://docs.example.org/wiki/', 'docs.example.org', '/wiki/') === [], 'an over-long link was kept');
        $t = DocSite::htmlToText('<title>Docs - Site</title><header><h1>Logo</h1></header><main><h1><a href="#n">¶</a> Newbie info</h1>x</main>');
        check($t['title'] === 'Docs - Site' && $t['h1'] === 'Newbie info', 'title or h1: ' . json_encode($t));
        check(DocSite::title($t, 'https://x.org/a', ['https://x.org/b' => ['slug' => 'b', 't' => 1, 'rt' => 'Docs - Site']]) === 'Newbie info - Docs - Site', 'a site-wide <title> did not take the h1');
        check(DocSite::title($t, 'https://x.org/a', ['https://x.org/b' => ['slug' => 'b', 't' => 1, 'rt' => 'Other']]) === 'Docs - Site', 'a page-own <title> was changed');
        $many = '<urlset>';
        for ($i = 0; $i < 900; $i++) {
            $many .= "<url><loc>https://docs.example.org/wiki/p$i</loc></url>";
        }
        $used = 0;
        DocSources::$fetcher = function () use ($many) {
            return $many . '</urlset>';
        };
        try {
            $list = DocSite::listUrls(['name' => 'm', 'url' => 'https://docs.example.org/sitemap.xml'], 'docs.example.org', '/', $used);
        } finally {
            DocSources::$fetcher = null;
        }
        check(count($list) === DocSite::MAX_SITE_PAGES, 'list not capped: ' . count($list));
        check(DocSite::slugFor('https://docs.example.org/' . str_repeat('c', 300), []) !== '' && strlen(DocSite::slugFor('https://docs.example.org/' . str_repeat('c', 300), [])) <= 100, 'slug not capped');
    },
    'docsite: a held lock skips the run; past the page cap the oldest pages go first' => function () use ($siteSrc) {
        $tree = siteTree();
        siteSetup($tree);
        $dir = "$tree/pikkumies/local/docs-cache";
        @mkdir("$dir/ex", 0700, true);
        $h = fopen("$dir/ex.lock", 'c');
        flock($h, LOCK_EX);
        $log = [];
        try {
            DocSources::$fetcher = siteWeb([], $log);
            list($ok, $msg) = DocSite::refresh($siteSrc, 40, false);
            check(!$ok && strpos($msg, 'skipped') === 0 && $log === [], "lock ignored: $msg");
            flock($h, LOCK_UN);
            fclose($h);
            // 505 pages on disk, the oldest first; a run with a fresh list evicts the 5 oldest.
            $pages = [];
            $list = [];
            for ($i = 0; $i < 505; $i++) {
                $pages["https://docs.example.org/wiki/old$i"] = ['slug' => "old$i", 't' => time() - 1000 + $i];
                file_put_contents("$dir/ex/old$i.md", "# old $i\n");
                $list["https://docs.example.org/wiki/old$i"] = 1;
            }
            file_put_contents("$dir/ex/meta.json", json_encode(['fetched' => time(), 'count' => 505, 'pages' => $pages, 'list' => $list, 'listAt' => time(), 'robots' => ['rules' => [], 'delay' => 0]]));
            list($ok, $msg) = DocSite::refresh($siteSrc, 40, false);
            check($ok && DocSources::meta('ex')['count'] === DocSite::MAX_SITE_PAGES, "cap: $msg");
            check(!is_file("$dir/ex/old0.md") && !is_file("$dir/ex/old4.md") && is_file("$dir/ex/old5.md") && is_file("$dir/ex/old504.md"), 'the wrong pages were evicted');
        } finally {
            siteDone($tree);
        }
    },
    'docsite: sources are filtered by scope against the host; link sources are never downloaded and are offered when nothing matches' => function () {
        $tree = siteTree();
        siteSetup($tree);
        file_put_contents("$tree/pikkumies/local/documentationSources.md", implode("\n", [
            "## p-docs\n- type: site\n- url: https://docs.example.org/sitemap.xml\n- trust: official\n- scope: pmss\n",
            "## f-docs\n- type: site\n- url: https://f.example.org/sitemap.xml\n- trust: official\n- scope: feral, whatbox\n",
            "## s-docs\n- type: site\n- url: https://s.example.org/sitemap.xml\n- trust: official\n- scope: swizzin\n",
            "## g-docs\n- type: site\n- url: https://g.example.org/sitemap.xml\n- trust: upstream\n- scope: generic\n",
            "## a-docs\n- type: site\n- url: https://a.example.org/sitemap.xml\n- trust: upstream\n- scope: all\n",
            "## old-docs\n- type: kb-json\n- url: https://o.example.org/kb.json\n- trust: community\n",
            "## f-help\n- type: link\n- url: https://f.example.org/tickets\n- trust: official\n- covers: support tickets\n- scope: feral\n",
            "## bad-scope\n- type: site\n- url: https://b.example.org/sitemap.xml\n- trust: official\n- scope: Feral Hosting\n",
        ]));
        try {
            DocSources::$host = ['family' => 'feral', 'stack' => 'swizzin'];
            check(array_keys(DocSources::active()) === ['f-docs', 's-docs', 'a-docs', 'old-docs'], 'feral+swizzin: ' . implode(',', array_keys(DocSources::active())));
            check(array_keys(DocSources::links()) === ['f-help'], 'links: ' . implode(',', array_keys(DocSources::links())));
            DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
            check(array_keys(DocSources::active()) === ['g-docs', 'a-docs', 'old-docs'], 'generic: ' . implode(',', array_keys(DocSources::active())));
            DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
            check(array_keys(DocSources::active()) === ['p-docs', 'a-docs', 'old-docs'], 'pmss: ' . implode(',', array_keys(DocSources::active())));
            check(DocSources::problems(DocSources::load()['bad-scope']) !== [], 'bad scope accepted');
            list(, $msg) = DocSources::refresh(DocSources::load()['f-help']);
            check(strpos($msg, 'not downloaded') !== false, 'a link source was downloaded');
            DocSources::$host = ['family' => 'feral', 'stack' => 'none'];
            list($hits, $notes) = DocSources::search('nothing matches this', 5, true);
            check($hits === [] && $notes && strpos(end($notes), 'f-help: https://f.example.org/tickets (support tickets)') !== false, 'no link note: ' . json_encode($notes));
            mkdir("$tree/pikkumies/local/docs-cache/f-docs", 0700, true);
            file_put_contents("$tree/pikkumies/local/docs-cache/f-docs/x.md", "# Disk quota\n\nquota explained\n");
            list($hits, $notes) = DocSources::search('disk quota', 5, true);
            check(count($hits) === 1 && $notes === [], 'link note with hits: ' . json_encode($notes));
            // The sources verb: scope match, type, pages, age, last error.
            list($code, $out) = runPhp("$tree/pikkumies/tools/memory/pikkumies/pikkumies.php", ['sources']);
            $rec = null;
            foreach (explode("\n", trim($out)) as $line) {
                $d = json_decode($line, true);
                if (isset($d['name']) && $d['name'] === 'p-docs') {
                    $rec = $d;
                }
            }
            check($code === 0 && $rec !== null && array_key_exists('in_scope', $rec) && isset($rec['type'], $rec['scope']) && array_key_exists('pages', $rec) && array_key_exists('age_s', $rec) && array_key_exists('last_error', $rec), 'sources: ' . $out);
        } finally {
            siteDone($tree);
        }
    },
    'docsite: robots.txt parsing: the pikkumies-docs group wins over *, wildcards and $ are understood' => function () {
        $r = DocSite::parseRobots("User-agent: *\nDisallow: /\n\nUser-agent: pikkumies-docs\nDisallow: /private/*.html\$\nCrawl-delay: 1.5\n");
        check($r['delay'] === 2, 'delay');
        check(DocSite::allowed('https://x.org/docs/a', $r['rules']) && !DocSite::allowed('https://x.org/private/a/b.html', $r['rules']) && DocSite::allowed('https://x.org/private/a.htmlx', $r['rules']), 'rules: ' . json_encode($r));
        $none = DocSite::parseRobots("<!doctype html><title>Page Not Found</title>");
        check($none === ['rules' => [], 'delay' => 0], 'an HTML 404 page made rules');
    },
    'docsite: a search never crawls a site source, even a stale one; a stale kb-json source is still refreshed' => function () {
        $tree = siteTree();
        siteSetup($tree);
        $slept = 0;
        DocSite::$sleep = function ($s) use (&$slept) {
            $slept += $s;
        };
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## up-docs\n- type: site\n- url: https://up.example.org/sitemap.xml\n- trust: upstream\n- scope: all\n- refresh: 1h\n\n"
            . "## p-kb\n- type: kb-json\n- url: https://kb.example.org/kb.json\n- trust: official\n- scope: pmss\n- refresh: 1h\n");
        $log = [];
        try {
            DocSources::$host = ['family' => 'pmss', 'stack' => 'pmss'];
            DocSources::$fetcher = function ($u) use (&$log) {
                $log[] = $u;
                if ($u === 'https://up.example.org/robots.txt') {
                    return "User-agent: *\nCrawl-delay: 5\n";
                }
                if ($u === 'https://kb.example.org/kb.json') {
                    return json_encode(['categories' => [['name' => 'c', 'articles' => [['title' => 'Disk quota', 'body' => 'quota']]]]]);
                }
                return '<urlset><url><loc>https://up.example.org/a</loc></url><url><loc>https://up.example.org/b</loc></url></urlset>';
            };
            list(, $notes) = DocSources::search('disk quota', 5, false);
            $site = array_filter($log, function ($u) {
                return strpos($u, 'https://up.example.org/') === 0;
            });
            check($site === [] && $slept === 0, 'a search crawled a site source: ' . implode(' ', $log) . "; slept $slept");
            check($log === ['https://kb.example.org/kb.json'] && strpos(implode(' ', $notes), 'p-kb: downloaded') !== false, 'kb-json not refreshed: ' . implode(' ', $log) . ' ' . json_encode($notes));
        } finally {
            siteDone($tree);
        }
    },
    'docsite: a page that failed on its first fetch keeps a URL slug and is written as <slug>.md once it succeeds; never ".md"' => function () {
        $tree = siteTree();
        siteSetup($tree);
        $src = ['name' => 'ex', 'type' => 'site', 'url' => 'https://d.example.org/sitemap.xml', 'trust' => 'official', 'refresh' => '1h'];
        $fail = true;
        DocSources::$fetcher = function ($u) use (&$fail) {
            if ($u === 'https://d.example.org/sitemap.xml') {
                return '<urlset><url><loc>https://d.example.org/a</loc></url><url><loc>https://d.example.org/b</loc></url></urlset>';
            }
            if ($u === 'https://d.example.org/robots.txt') {
                return ['code' => 404];
            }
            return $fail ? ['code' => 500] : '<html><title>Page ' . basename($u) . '</title><main>zebra body of ' . $u . '</main></html>';
        };
        $dir = "$tree/pikkumies/local/docs-cache/ex";
        try {
            list($ok, $msg) = DocSite::refresh($src, 40, false);
            check($ok && strpos($msg, '2 failed') !== false, "first run: $msg");
            foreach (DocSources::meta('ex')['pages'] as $u => $p) {
                check($p['slug'] !== '', "$u failed with an empty slug");
            }
            $m = json_decode(file_get_contents("$dir/meta.json"), true);
            foreach ($m['pages'] as $u => $p) {
                $m['pages'][$u]['t'] -= 7200; // past the refresh period: retried
            }
            file_put_contents("$dir/meta.json", json_encode($m));
            $fail = false;
            list($ok, $msg) = DocSite::refresh($src, 40, false);
            check($ok && DocSources::meta('ex')['count'] === 2, "second run: $msg");
            check(!is_file("$dir/.md") && is_file("$dir/a.md") && is_file("$dir/b.md"), 'files: ' . implode(',', array_map('basename', glob("$dir/*.md", GLOB_NOSORT) ?: [])) . (is_file("$dir/.md") ? ',.md' : ''));
            file_put_contents("$tree/pikkumies/local/documentationSources.md", "## ex\n- type: site\n- url: https://d.example.org/sitemap.xml\n- trust: official\n");
            list($hits) = DocSources::search('zebra', 5, true);
            check(count($hits) === 2, 'search did not find the pages: ' . json_encode($hits));
        } finally {
            siteDone($tree);
        }
        check(DocSite::slugFor('https://d.example.org/', []) !== '' && DocSite::slugFor('https://d.example.org/%E2%80%A6', []) !== '', 'an empty slug');
    },
    'docsite: robots.txt that gives a 5xx or no response skips the run; a 404 allows everything' => function () use ($siteSrc) {
        foreach ([503, 0] as $code) {
            $tree = siteTree();
            siteSetup($tree);
            $log = [];
            DocSources::$fetcher = siteWeb(['https://docs.example.org/robots.txt' => ['code' => $code],
                'https://docs.example.org/sitemap.xml' => '<urlset><url><loc>https://docs.example.org/wiki/a</loc></url></urlset>',
                'https://docs.example.org/wiki/a' => '<title>A</title><main>a</main>'], $log);
            try {
                list($ok, $msg) = DocSite::refresh($siteSrc, 40, true);
                check(!$ok && strpos($msg, 'skipped') === 0 && $log === ['https://docs.example.org/robots.txt'], "robots $code: $msg; " . implode(' ', $log));
                check(strpos(DocSources::meta('ex')['error'], 'robots.txt') !== false && !isset(DocSources::meta('ex')['listAt']), "robots $code: not recorded");
            } finally {
                siteDone($tree);
            }
        }
        $used = 0;
        DocSources::$fetcher = function () {
            return ['code' => 404];
        };
        try {
            check(DocSite::robots('docs.example.org', $used) === ['rules' => [], 'delay' => 0], 'a 404 robots.txt did not allow all');
        } finally {
            DocSources::$fetcher = null;
        }
    },
    'docsite: one failing sub-sitemap is skipped, the others are still read' => function () {
        $used = 0;
        $log = [];
        DocSources::$fetcher = siteWeb([
            'https://docs.example.org/sitemap_index.xml' => '<sitemapindex><sitemap><loc>https://docs.example.org/broken.xml</loc></sitemap><sitemap><loc>https://docs.example.org/good.xml</loc></sitemap></sitemapindex>',
            'https://docs.example.org/broken.xml' => ['code' => 500],
            'https://docs.example.org/good.xml' => '<urlset><url><loc>https://docs.example.org/articles/ok</loc></url></urlset>',
        ], $log);
        try {
            $list = DocSite::listUrls(['name' => 'x', 'url' => 'https://docs.example.org/sitemap_index.xml'], 'docs.example.org', '/', $used);
        } finally {
            DocSources::$fetcher = null;
        }
        check(array_keys($list) === ['https://docs.example.org/articles/ok'] && $used === 3, 'list: ' . json_encode($list) . " used $used");
    },
    'docsite: index-page links drop wiki action paths (_new, _history, _compare, _edit, any _segment) but keep _pages' => function () {
        $html = '<a href="/o/r/wiki/Setup">s</a><a href="/o/r/wiki/_new">n</a><a href="/o/r/wiki/Setup/_history">h</a><a href="/o/r/wiki/_compare/abc">c</a>'
            . '<a href="/o/r/wiki/Setup/_edit">e</a><a href="/o/r/wiki/_pages">p</a><a href="/o/r/wiki/_access">a</a><a href="/o/r/wiki/Under_score">u</a>';
        $links = DocSite::links($html, 'https://github.com/o/r/wiki/_pages', 'github.com', '/o/r/wiki/');
        check($links === ['https://github.com/o/r/wiki/Setup', 'https://github.com/o/r/wiki/_pages', 'https://github.com/o/r/wiki/Under_score'], 'links: ' . json_encode($links));
    },
    'docsite: stored titles are one clean line of at most 120 characters' => function () {
        $doc = DocSite::htmlToText("<title>Bad\x01title\xE2\x80\xA8next\xC2\x85line \t  end " . str_repeat('x', 300) . '</title><h1>H&#8233;one</h1>');
        check(strpos($doc['title'], 'Bad title next line end x') === 0, 'title: ' . json_encode($doc['title']));
        check(!preg_match('/[\x00-\x1F\x7F]|\xE2\x80[\xA8\xA9]|\xC2[\x80-\x9F]/', $doc['title'] . $doc['h1']), 'control or line-break characters kept: ' . json_encode($doc));
        check(strlen($doc['title']) <= 120 && $doc['h1'] === 'H one', 'length or h1: ' . json_encode($doc));
        check(DocSite::title(['title' => '', 'h1' => ''], 'https://x.org/' . str_repeat('y', 300), []) === 'https://x.org/' . str_repeat('y', 106), 'url fallback not cut');
        check(DocSite::cleanTitle("caf\xE9 ok") === 'caf? ok', 'non-UTF-8 not made safe');
    },
];
