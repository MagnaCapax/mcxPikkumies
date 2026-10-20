<?php
/** A `site` source that starts at a markdown file (a repository README on raw.githubusercontent.com, ADR-0027 addendum 2026-10-20). */

function mdSiteTree()
{
    $tmp = sys_get_temp_dir() . '/pk-mdsite-' . getmypid() . '-' . mt_rand();
    foreach (['config', 'memory', 'local'] as $d) {
        @mkdir("$tmp/pikkumies/$d", 0700, true);
    }
    file_put_contents("$tmp/pikkumies/config/documentationSources.md", "# none\n");
    return $tmp;
}

$mdReadme = "<p align=\"center\"><img src=\"assets/banner.png\" alt=\"x\"></p>\n\n```sh\n# not the title: a shell comment\nmake\n```\n\n"
    . "# Kit: user namespaces\n\nRun it as `kit --port <host>:<ctr>` when a < b and the <user> placeholder stays.\n\n"
    . "See [port forwarding](./docs/port.md), [the network](docs/network.md#drivers \"title\"), ![logo](docs/logo.png), "
    . "[license](LICENSE), [contributing](CONTRIBUTING.md) and [elsewhere](https://other.example.org/x.md).\n";

return [
    'docsite markdown: a markdown page keeps its text as written and takes its title from the first heading outside code' => function () use ($mdReadme) {
        $d = DocSite::markdownToText($mdReadme);
        check($d['title'] === 'Kit: user namespaces', 'title: ' . $d['title']);
        check(strpos($d['text'], 'when a < b and the <user> placeholder stays') !== false, 'text cut at "<": ' . $d['text']);
        check(strpos($d['text'], "make\n```") !== false, 'line breaks of code blocks lost');
        check(DocSite::markdownToText("<h1 align=\"center\">Only <b>HTML</b></h1>\ntext")['title'] === 'Only HTML', 'h1 fallback');
    },
    'docsite markdown: a markdown page links only to other markdown files on the same host under the prefix' => function () use ($mdReadme) {
        $base = 'https://raw.example.org/o/kit/master/README.md';
        check(DocSite::isMarkdown($base) && !DocSite::isMarkdown('https://raw.example.org/o/kit/master/') && !DocSite::isMarkdown('https://x.example.org/md'), 'isMarkdown');
        $all = DocSite::links($mdReadme, $base, 'raw.example.org', '/o/kit/master/');
        sort($all);
        check($all === ['https://raw.example.org/o/kit/master/CONTRIBUTING.md', 'https://raw.example.org/o/kit/master/docs/network.md', 'https://raw.example.org/o/kit/master/docs/port.md'], 'links: ' . implode(' ', $all));
        check(DocSite::links($mdReadme, $base, 'raw.example.org', '/o/kit/master/docs/') === ['https://raw.example.org/o/kit/master/docs/port.md', 'https://raw.example.org/o/kit/master/docs/network.md'], 'prefix ignored');
        check(DocSite::links($mdReadme, 'https://raw.example.org/o/kit/master/index.html', 'raw.example.org', '/') === [], 'markdown links read from an HTML page');
    },
    'docsite markdown: links are read from the first MAX_PAGE bytes only (the part the cache keeps), so a huge page costs no regex time' => function () {
        $base = 'https://raw.example.org/o/kit/master/README.md';
        $early = "[early](docs/early.md)\n";
        $late = str_repeat('x', DocSources::MAX_PAGE) . "\n[late](docs/late.md)\n";
        check(DocSite::links($early . $late, $base, 'raw.example.org', '/o/kit/master/') === ['https://raw.example.org/o/kit/master/docs/early.md'], 'a link past MAX_PAGE was read');
        $t = microtime(true);
        DocSite::links(str_repeat('](', 2500000), $base, 'raw.example.org', '/');
        check(microtime(true) - $t < 2.0, 'a 5 MB page of "](" took ' . round(microtime(true) - $t, 2) . ' s');
    },
    'docsite markdown: a source that starts at a README fetches it and its docs/ pages, titled and whole' => function () use ($mdReadme) {
        $tree = mdSiteTree();
        $log = [];
        $base = 'https://raw.example.org/o/kit/master/';
        $pages = [$base . 'README.md' => $mdReadme, $base . 'docs/port.md' => "# Port forwarding\n\nUse `--port-driver=builtin` if <x> fails.\n", $base . 'docs/network.md' => "# Network\n\nslirp4netns.\n"];
        DocSources::$root = "$tree/pikkumies";
        DocSources::$fetcher = function ($url) use ($pages, &$log) {
            $log[] = $url;
            return isset($pages[$url]) ? $pages[$url] : ['code' => 404];
        };
        try {
            $src = ['name' => 'kit-docs', 'type' => 'site', 'url' => $base . 'README.md', 'trust' => 'upstream', 'refresh' => '30d', 'prefix' => '/o/kit/master/docs/'];
            check(DocSources::problems($src) === [], implode('; ', DocSources::problems($src)));
            list($ok, $msg) = DocSources::refresh($src);
            $files = glob("$tree/pikkumies/local/docs-cache/kit-docs/*.md");
            $titles = [];
            $text = '';
            foreach ($files as $f) {
                $titles[] = DocSources::pageInfo($f)[0];
                $text .= file_get_contents($f);
            }
            sort($titles);
        } finally {
            DocSources::$root = null;
            DocSources::$fetcher = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
        check($ok && strpos($msg, '3 pages fetched') === 0, "refresh: $msg");
        check($titles === ['Kit: user namespaces', 'Network', 'Port forwarding'], 'titles: ' . implode(' | ', $titles));
        check(strpos($text, 'if <x> fails') !== false && strpos($text, 'the <user> placeholder') !== false, 'text cut');
        check(!in_array($base . 'CONTRIBUTING.md', $log, true) && !in_array($base . 'docs/logo.png', $log, true), 'fetched outside the prefix: ' . implode(' ', $log));
    },
];
