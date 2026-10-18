<?php
/** The wiki and kb units (ADR-0037): kind, search and list per kind, the shared excerpt, the search-first gate. */

/** A throwaway tree with lib, the gate, the memory unit and the documentation units; one wiki, one kb, one manual source with a page each. */
function docuTree()
{
    $tmp = sys_get_temp_dir() . '/pk-docu-' . getmypid() . '-' . mt_rand();
    $src = dirname(__DIR__) . '/pikkumies';
    foreach (['lib', 'hooks/search/search-first-gate', 'tools/memory/pikkumies', 'tools/documentation/lib', 'tools/documentation/wiki', 'tools/documentation/kb', 'config'] as $d) {
        @mkdir("$tmp/pikkumies/$d", 0700, true);
        foreach ((array) glob("$src/$d/*.*") as $f) {
            copy($f, "$tmp/pikkumies/$d/" . basename($f));
        }
    }
    @mkdir("$tmp/pikkumies/memory", 0700, true);
    @mkdir("$tmp/pikkumies/local", 0700, true);
    file_put_contents("$tmp/pikkumies/config/documentationSources.md", "# test\n\n"
        . "## a-wiki\n- type: mediawiki\n- url: https://wiki.example.org/api.php\n- trust: official\n- refresh: 30d\n\n"
        . "## b-kb\n- type: kb-json\n- url: https://kb.example.org/kb.json\n- trust: official\n- refresh: 30d\n\n"
        . "## c-site-wiki\n- type: site\n- kind: wiki\n- url: https://docs.example.org/sitemap.xml\n- trust: official\n- refresh: 30d\n\n"
        . "## d-manual\n- type: site\n- url: https://app.example.org/sitemap.xml\n- trust: upstream\n- refresh: 30d\n");
    $pages = [
        'a-wiki' => ['jellyfin', 'Jellyfin', "Install Jellyfin from the app installer.\n\nJellyfin listens on its own port and streams media."],
        'b-kb' => ['refunds', 'Refund policy', 'Refunds are given within 14 days of the order.'],
        'c-site-wiki' => ['jellyfin-other', 'Jellyfin on our platform', 'Jellyfin is started from the control panel.'],
        'd-manual' => ['jellyfin-manual', 'Jellyfin manual', 'Jellyfin configuration reference.'],
    ];
    foreach ($pages as $source => $p) {
        @mkdir("$tmp/pikkumies/local/docs-cache/$source", 0700, true);
        file_put_contents("$tmp/pikkumies/local/docs-cache/$source/{$p[0]}.md", DocSources::renderPage(['name' => $source, 'trust' => 'official'], ['title' => $p[1], 'url' => "https://$source.example.org/{$p[0]}", 'text' => $p[2], 'updated' => '']));
        // fresh copies: no download is attempted in the tests
        file_put_contents("$tmp/pikkumies/local/docs-cache/$source/meta.json", json_encode(['fetched' => time(), 'count' => 1]));
    }
    return $tmp;
}

return [
    'documentation: kind defaults from the type, a kind line overrides it, and a bad kind is a problem' => function () {
        check(DocSources::kind(['type' => 'mediawiki']) === 'wiki' && DocSources::kind(['type' => 'kb-json']) === 'kb', 'type defaults');
        check(DocSources::kind(['type' => 'site']) === 'manual' && DocSources::kind(['type' => 'link']) === 'link', 'site and link defaults');
        check(DocSources::kind(['type' => 'site', 'kind' => 'kb']) === 'kb', 'kind line ignored');
        $base = ['name' => 'x', 'type' => 'site', 'url' => 'https://x.example.org/sitemap.xml', 'trust' => 'official'];
        check(DocSources::problems($base + ['kind' => 'wiki']) === [], 'valid kind reported: ' . implode(';', DocSources::problems($base + ['kind' => 'wiki'])));
        check(DocSources::problems($base + ['kind' => 'blog']) !== [], 'unknown kind accepted');
        check(DocSources::problems($base + ['kind' => 'link']) !== [], 'kind link on a site accepted');
    },
    'documentation: the shipped config gives this provider list a wiki and a kb, and every provider wiki or kb site is marked' => function () {
        $all = DocSources::parse(file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md'));
        check(DocSources::kind($all['pm-wiki']) === 'wiki' && DocSources::kind($all['pm-kb']) === 'kb', 'pm sources');
        foreach (['feral-wiki', 'whatbox-wiki'] as $n) {
            check(DocSources::kind($all[$n]) === 'wiki', "$n is not a wiki");
        }
        check(DocSources::kind($all['seedhost-kb']) === 'kb', 'seedhost-kb is not a kb');
        check(DocSources::kind($all['servarr-wiki']) === 'manual', 'an app manual became a provider wiki');
    },
    'documentation: wiki search ranks only wiki sources (mediawiki and a site marked wiki), with an excerpt on the top hit' => function () {
        $tree = docuTree();
        list($code, $out) = runPhp("$tree/pikkumies/tools/documentation/wiki/wiki.php", ['search', 'jellyfin']);
        $l = cliLines($out);
        $sources = array_column(array_filter($l, function ($r) {
            return isset($r['source']);
        }), 'source');
        sort($sources);
        check($code === 0 && $sources === ['a-wiki', 'c-site-wiki'], 'wrong sources: ' . $out);
        check(isset($l[0]['excerpt']) && strpos($l[0]['excerpt'], 'Jellyfin') !== false && !isset($l[1]['excerpt']), 'excerpt only on the top hit: ' . $out);
        list($code, $out) = runPhp("$tree/pikkumies/tools/documentation/wiki/wiki.php", ['search', 'jellyfin', '--source', 'c-site-wiki']);
        check($code === 0 && strpos($out, 'a-wiki') === false && strpos($out, 'c-site-wiki') !== false, '--source not honoured: ' . $out);
        list($code, $out) = runPhp("$tree/pikkumies/tools/documentation/wiki/wiki.php", ['search', 'jellyfin', '--source', 'b-kb']);
        check($code === 1 && strpos($out, 'b-kb is not an active wiki source') !== false, 'a kb source accepted by wiki: ' . $out);
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'documentation: kb search and list cover only kb sources' => function () {
        $tree = docuTree();
        $kb = "$tree/pikkumies/tools/documentation/kb/kb.php";
        list($code, $out) = runPhp($kb, ['search', 'refund', 'policy']);
        check($code === 0 && strpos($out, '"title":"Refund policy"') !== false && strpos($out, 'Jellyfin') === false, 'kb search: ' . $out);
        list($code, $out) = runPhp($kb, ['search', 'jellyfin']);
        $l = cliLines($out);
        check($code === 0 && isset($l[count($l) - 1]['next']), 'no hit without a next step: ' . $out);
        list($code, $out) = runPhp($kb, ['list']);
        check($code === 0 && strpos($out, 'Refund policy') !== false && strpos($out, 'Jellyfin') === false, 'kb list: ' . $out);
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'documentation: list --search keeps only the titles that contain the text, past the first output page' => function () {
        $tree = docuTree();
        for ($i = 1; $i <= 30; $i++) { // more pages than one output page (Cli::LIMIT)
            file_put_contents("$tree/pikkumies/local/docs-cache/a-wiki/filler-$i.md", DocSources::renderPage(['name' => 'a-wiki', 'trust' => 'official'], ['title' => sprintf('Aaa filler %02d', $i), 'url' => "https://a.example.org/f$i", 'text' => 'x', 'updated' => '']));
        }
        $wiki = "$tree/pikkumies/tools/documentation/wiki/wiki.php";
        list($code, $out) = runPhp($wiki, ['list']);
        check($code === 0 && strpos($out, '"truncated":true') !== false && strpos($out, 'Jellyfin') === false, 'the unfiltered first page should not reach Jellyfin: ' . $out);
        list($code, $out) = runPhp($wiki, ['list', '--search', 'JELLY']);
        $titles = array_column(array_filter(cliLines($out), function ($r) {
            return isset($r['title']);
        }), 'title');
        sort($titles);
        check($code === 0 && $titles === ['Jellyfin', 'Jellyfin on our platform'], 'filtered titles: ' . $out);
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'documentation: memory search still covers every kind, and memory sources shows each kind' => function () {
        $tree = docuTree();
        $mem = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
        list($code, $out) = runPhp($mem, ['search', 'jellyfin', '--offline']);
        foreach (['a-wiki', 'c-site-wiki', 'd-manual'] as $s) {
            check(strpos($out, "\"source\":\"$s\"") !== false, "memory search lost $s: $out");
        }
        list($code, $out) = runPhp($mem, ['sources']);
        check($code === 0 && strpos($out, '"name":"c-site-wiki","trust":"official","type":"site","kind":"wiki"') !== false, 'sources has no kind: ' . $out);
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'documentation: wiki and kb search and list wait for the memory search (ADR-0043), and do not open the gate' => function () {
        $tree = docuTree();
        $gate = "$tree/pikkumies/hooks/search/search-first-gate/search-first-gate.php";
        $call = function ($command) use ($gate) {
            list(, $out) = runPhp($gate, [], json_encode(['tool_name' => 'terminal', 'session_id' => 'docu', 'tool_input' => ['command' => $command]]));
            return trim($out);
        };
        check(strpos($call("php $tree/pikkumies/tools/documentation/wiki/wiki.php search jellyfin"), 'lessons before documentation') !== false, 'wiki search ran before the memory search');
        check(strpos($call("php $tree/pikkumies/tools/documentation/kb/kb.php --human list"), 'lessons before documentation') !== false, 'kb list ran before the memory search');
        check(strpos($call('rm -f /tmp/x'), 'block') !== false, 'wiki search opened the gate');
        check(strpos($call("php $tree/pikkumies/tools/documentation/wiki/kb.php search x"), 'block') !== false, 'a mismatched path passed');
        $call("php $tree/pikkumies/tools/memory/pikkumies/pikkumies.php search jellyfin");
        check($call("php $tree/pikkumies/tools/documentation/wiki/wiki.php search jellyfin") === '', 'wiki search blocked after the memory search');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'documentation: TextSearch::excerpt is the memory excerpt (same paragraph, same cut)' => function () {
        $p = sys_get_temp_dir() . '/pk-docu-ex-' . getmypid() . '.md';
        file_put_contents($p, "---\ntitle: x\n---\n# Disk full\n\nunrelated words here\n\nrtorrent stops when the disk is full\n");
        $a = TextSearch::excerpt($p, 'disk full rtorrent', 1500);
        check($a === 'rtorrent stops when the disk is full', 'excerpt: ' . $a);
        check(TextSearch::excerpt($p, 'disk full rtorrent', 8) === 'rtorrent…', 'cut: ' . TextSearch::excerpt($p, 'disk full rtorrent', 8));
        unlink($p);
    },
];
