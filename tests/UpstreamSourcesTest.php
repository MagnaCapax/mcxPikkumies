<?php
/**
 * Upstream projects in config/documentationSources.md (ADR-0027 addendum 2026-10-20): the repo, issues and
 * tracker lines, the upstream entries without a type, their checks, the memory unit's `upstreams` and
 * `sources` verbs, and the low-priority "known upstream issue" step in the research order.
 */

function upstShipped()
{
    return (string) file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md');
}

/** A throwaway pikkumies tree with the shipped lib and memory unit, and the given config and overlay text. */
function upstTree($config, $overlay = null)
{
    $tmp = sys_get_temp_dir() . '/pk-upstream-' . getmypid() . '-' . mt_rand();
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
    file_put_contents("$tmp/pikkumies/config/documentationSources.md", $config);
    if ($overlay !== null) {
        file_put_contents("$tmp/pikkumies/local/documentationSources.md", $overlay);
    }
    return $tmp;
}

/** DocSources::upstreams() of a tree, with the root seam reset afterwards. */
function upstList($tree)
{
    DocSources::$root = "$tree/pikkumies";
    try {
        return DocSources::upstreams();
    } finally {
        DocSources::$root = null;
    }
}

$upstConfig = "# test\n\n## How to change this list\n\nNot a source and not a project.\n\n"
    . "## app-wiki\n\n- type: site\n- url: https://app.example.org/sitemap.xml\n- trust: upstream\n- scope: all\n- refresh: 30d\n"
    . "- repo: https://github.com/example/app\n- issues: https://github.com/example/app/issues\n- status: on\n\nThe app's wiki.\n\n"
    . "## lib-a\n\n- repo: https://github.com/example/lib-a\n- issues: https://github.com/example/lib-a/issues\n- docs: app-wiki\n- verified: 2026-10-20\n\nA library.\n\n"
    . "## forum-app\n\n- issues: https://forum.example.org/\n- tracker: forum\n- verified: 2026-10-20\n\nA closed app with a forum.\n";

return [
    'upstreams: every repo and issues line of the shipped list parses into exactly one valid project' => function () {
        $text = upstShipped();
        preg_match_all('/^- repo: (.*)$/m', $text, $repos);
        preg_match_all('/^- issues: (.*)$/m', $text, $issues);
        check(count($repos[1]) >= 40, 'too few repo lines: ' . count($repos[1]));
        $tree = upstTree($text);
        try {
            $all = upstList($tree);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
        check(array_values(array_filter(array_column($all, 'repo'))) === array_map('trim', $repos[1]), 'repo lines and parsed projects differ');
        check(array_values(array_filter(array_column($all, 'issues'))) === array_map('trim', $issues[1]), 'issues lines and parsed projects differ');
        foreach ($all as $name => $u) {
            check($u['problems'] === [], "$name: " . implode('; ', $u['problems']));
            check($u['repo'] === '' || preg_match('#^https://[^/\s]+/\S+$#', $u['repo']), "$name repo " . $u['repo']);
            check(in_array($u['tracker'], DocSources::TRACKERS, true), "$name tracker " . $u['tracker']);
            check($u['description'] !== '', "$name has no description");
        }
    },
    'upstreams: the shipped list names PMSS, Hermes, the *arr apps (docs: servarr-wiki) and the torrent clients, and no model or vendor' => function () {
        $text = upstShipped();
        $tree = upstTree($text);
        try {
            $all = upstList($tree);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
        check($all['pmss-readme']['repo'] === 'https://github.com/MagnaCapax/PMSS' && $all['pmss-readme']['issues'] === 'https://github.com/MagnaCapax/PMSS/issues', 'PMSS');
        check($all['hermes-readme']['repo'] === preg_replace('/\.git$/', '', HermesInstall::REPO), 'Hermes repo is not HermesInstall::REPO: ' . $all['hermes-readme']['repo']);
        foreach (['sonarr', 'radarr', 'lidarr', 'prowlarr', 'readarr', 'whisparr'] as $n) {
            check(isset($all[$n]) && $all[$n]['docs'] === 'servarr-wiki', "$n: " . json_encode(isset($all[$n]) ? $all[$n] : null));
        }
        foreach (['rtorrent-wiki', 'rutorrent-wiki', 'qbittorrent-wiki', 'deluge-docs', 'pikkumies'] as $n) {
            check(isset($all[$n]) && $all[$n]['issues'] !== '', "$n has no issues line");
        }
        check($all['deluge-docs']['tracker'] === 'other' && $all['plex-support']['tracker'] === 'forum' && $all['cmake']['tracker'] === 'gitlab', 'non-GitHub trackers');
        check(!preg_match('/\b(openai|anthropic|claude|chatgpt|gemini|mistral|cohere|llama|grok|deepseek|ollama|qwen)\b/i', $text), 'a model or vendor name in the shipped list');
    },
    'upstreams: a downloaded GitHub source never uses /tree/ (robots.txt disallows it); repository docs come from raw.githubusercontent.com' => function () {
        $sources = DocSources::parse(upstShipped());
        $raw = 0;
        foreach ($sources as $src) {
            check(!preg_match('#^https://github\.com/[^/]+/[^/]+/tree/#', $src['url']), $src['name'] . ' starts at a /tree/ page');
            $raw += strpos($src['url'], 'https://raw.githubusercontent.com/') === 0 ? 1 : 0;
        }
        check($raw >= 4 && $sources['pmss-readme']['scope'] === 'pmss' && $sources['pmss-readme']['trust'] === 'upstream', "raw sources: $raw");
        check(DocSite::isMarkdown($sources['rootlesskit-docs']['url']) && $sources['rootlesskit-docs']['prefix'] === '/rootless-containers/rootlesskit/master/docs/', 'rootlesskit-docs');
    },
    'upstreams: problems() catches a typo in repo, issues, tracker or docs, on a source and on an entry' => function () {
        $base = ['name' => 'x', 'type' => 'site', 'url' => 'https://x.example.org/sitemap.xml', 'trust' => 'upstream'];
        $ok = $base + ['repo' => 'https://github.com/a/b', 'issues' => 'https://github.com/a/b/issues'];
        check(DocSources::problems($ok) === [], 'valid lines refused: ' . implode('; ', DocSources::problems($ok)));
        check(DocSources::problems(['repo' => 'http://github.com/a/b'] + $ok) !== [], 'http repo accepted');
        check(DocSources::problems(['repo' => 'github.com/a/b'] + $ok) !== [], 'repo without https:// accepted');
        check(DocSources::problems(['issues' => 'git://example.org/x'] + $ok) !== [], 'git:// issues accepted');
        check(DocSources::problems(['tracker' => 'githb'] + $ok) !== [], 'unknown tracker accepted');
        check(DocSources::problems(['tracker' => 'forum'] + $ok) !== [], 'a forum tracker on a github.com issues page accepted');
        check(DocSources::problems(['issues' => 'https://bugs.example.org/'] + $ok) !== [], 'a non-GitHub issues page without a tracker line accepted');
        check(DocSources::problems(['issues' => 'https://bugs.example.org/', 'tracker' => 'other'] + $ok) === [], 'a non-GitHub tracker refused');
        check(DocSources::problems(['tracker' => 'gitlab', 'repo' => 'https://gitlab.example.org/a'] + $base) !== [], 'tracker without issues accepted');
        check(DocSources::problems(['docs' => 'Servarr Wiki'] + $ok) !== [], 'bad docs list accepted');
        check(DocSources::upstreamProblems(['repo' => 'https://example.org/a.git', 'docs' => 'a-wiki, b-docs']) === [], 'entry lines refused');
    },
    'upstreams: repo and issues must be public https host names: no IP literal, port, user or password, or local name' => function () {
        foreach (['https://github.com/a/b', 'https://gitlab.kitware.com/cmake/cmake/-/issues', 'https://forums.plex.tv/', 'https://code.ffmpeg.org/FFmpeg/FFmpeg/issues',
            'https://github.com/search?q=org%3Alinuxserver&type=issues', 'https://Codeberg.org/x/y'] as $u) {
            check(DocSources::isPublicAddress($u) && DocSources::upstreamProblems(['repo' => $u]) === [], "refused $u");
        }
        foreach (['https://127.0.0.1:8080/', 'https://127.0.0.1/', 'https://169.254.169.254/latest/meta-data/', 'https://[::1]/', 'https://[fd00::1]/x',
            'https://user:pw@internal.example.org/', 'https://user@github.com/a', 'https://github.com:8443/a/b', 'https://localhost/', 'https://gitlab/',
            'https://git.corp.internal/a', 'https://nas.lan/x', 'https://printer.local/', 'https://0x7f000001/', 'https://2130706433/', 'http://github.com/a',
            'https://github.com', 'https://github.com?x/', 'https://-bad-.example.org/'] as $u) {
            check(!DocSources::isPublicAddress($u), "accepted $u");
            check(DocSources::upstreamProblems(['repo' => $u]) !== [] && DocSources::upstreamProblems(['issues' => $u, 'tracker' => 'other']) !== [], "no problem for $u");
        }
    },
    'upstreams: entries without a type are listed with the sources, never become sources, and a docs line must name a source' => function () use ($upstConfig) {
        $tree = upstTree($upstConfig . "\n## lib-b\n\n- repo: https://github.com/example/lib-b\n- docs: no-such-wiki\n\nBroken docs pointer.\n");
        try {
            DocSources::$root = "$tree/pikkumies";
            DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
            $sources = DocSources::load();
            $active = DocSources::active();
            $all = DocSources::upstreams();
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            exec('rm -rf ' . escapeshellarg($tree));
        }
        check(array_keys($sources) === ['app-wiki'] && array_keys($active) === ['app-wiki'], 'an entry became a source: ' . implode(',', array_keys($sources)));
        check(array_keys($all) === ['app-wiki', 'lib-a', 'forum-app', 'lib-b'], 'projects: ' . implode(',', array_keys($all)));
        check($all['app-wiki']['docs'] === 'app-wiki' && $all['lib-a']['docs'] === 'app-wiki' && $all['lib-a']['problems'] === [], 'docs pointers');
        check($all['forum-app']['repo'] === '' && $all['forum-app']['tracker'] === 'forum', 'forum entry: ' . json_encode($all['forum-app']));
        check($all['lib-b']['problems'] === ['docs names no source: no-such-wiki'], 'lib-b: ' . json_encode($all['lib-b']['problems']));
    },
    'upstreams: the overlay replaces a shipped project by name and can switch one off; a bad entry name is a problem' => function () use ($upstConfig) {
        $overlay = "## lib-a\n\n- repo: https://codeberg.org/example/lib-a\n- issues: https://codeberg.org/example/lib-a/issues\n- tracker: other\n\nMoved.\n\n"
            . "## forum-app\n\n- issues: https://forum.example.org/\n- tracker: forum\n- status: off\n\nOff here.\n\n"
            . "## Bad_Name\n\n- repo: https://github.com/example/bad\n\nNot a name.\n";
        $tree = upstTree($upstConfig, $overlay);
        try {
            $all = upstList($tree);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
        check($all['lib-a']['origin'] === 'local' && $all['lib-a']['repo'] === 'https://codeberg.org/example/lib-a' && $all['lib-a']['docs'] === '', 'overlay did not replace: ' . json_encode($all['lib-a']));
        check($all['forum-app']['status'] === 'off', 'status off lost');
        check($all['Bad_Name']['problems'] !== [], 'bad name accepted');
    },
    'upstreams: the memory unit lists projects (filtered by word) and sources shows repo and issues' => function () use ($upstConfig) {
        $tree = upstTree($upstConfig);
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            list($code, $out) = runPhp($tool, ['upstreams']);
            $names = array_column(array_filter(cliLines($out), function ($l) {
                return isset($l['name']);
            }), 'name');
            check($code === 0 && $names === ['app-wiki', 'lib-a', 'forum-app'], 'upstreams: ' . $out);
            list($code, $out) = runPhp($tool, ['upstreams', 'LIB-A']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['name'] === 'lib-a' && $l[0]['issues'] === 'https://github.com/example/lib-a/issues' && $l[0]['tracker'] === 'github' && count(array_filter($l, function ($r) {
                return isset($r['name']);
            })) === 1, 'filter: ' . $out);
            list($code, $out) = runPhp($tool, ['upstreams', 'nothing-like-this']);
            check($code === 0 && strpos($out, 'no upstream project matched') !== false, 'empty filter: ' . $out);
            list($code, $out) = runPhp($tool, ['sources']);
            $src = cliLines($out)[0];
            check($src['name'] === 'app-wiki' && $src['repo'] === 'https://github.com/example/app' && $src['issues'] === 'https://github.com/example/app/issues', 'sources: ' . $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    },
    'upstreams: the known-issue check is in the research order at low priority, after memory and docs, never first' => function () {
        $root = dirname(__DIR__);
        $skill = (string) file_get_contents("$root/pikkumies/tools/research/deep-research/skill.md");
        $p1 = strpos($skill, '## Part 1:');
        $plan = strpos($skill, '1. **Plan.**');
        $up = strpos($skill, '2. **Known upstream issue (low priority).**');
        $web = strpos($skill, '3. **Web.**');
        check($p1 !== false && $plan > $p1 && $up > $plan && $web > $up, 'step order in part 2');
        $step = substr($skill, $up, $web - $up);
        foreach (['part 1 did not explain', 'tools/development/upstream', 'search-issues', 'commits', 'web search on', 'upstreams <name>', 'only when the user asked'] as $w) {
            check(strpos($step, $w) !== false, "step lost: $w");
        }
        $doctrine = (string) file_get_contents("$root/pikkumies/doctrine/doctrine.md");
        check(preg_match('/No answer: deep research \(for a problem in an upstream app, its known issues and recent commits come after memory and docs, never first\)/', $doctrine) === 1, 'doctrine ladder');
        $sop = (string) file_get_contents("$root/pikkumies/doctrine/SOP/diagnose-a-failing-service.md");
        check(strpos($sop, 'known upstream issues and recent commits (low priority, after memory and docs)') !== false, 'SOP');
        $mem = (string) file_get_contents("$root/pikkumies/tools/memory/pikkumies/pikkumies.php");
        preg_match("/const NEXT_STEP = '((?:[^'\\\\]|\\\\.)*)'/", $mem, $next);
        preg_match("/const PEER_NEXT = '((?:[^'\\\\]|\\\\.)*)'/", $mem, $peer);
        check(isset($next[1]) && strpos($next[1], 'known issues and recent commits') > strpos($next[1], 'tier 4'), 'NEXT_STEP');
        check(isset($peer[1]) && strpos($peer[1], 'known issues and recent commits') > strpos($peer[1], 'tier 4'), 'PEER_NEXT');
        $sources = upstShipped();
        check(strpos($sources, '## Upstream projects') !== false && strpos($sources, 'Checking them is low priority') !== false, 'the source list explains the order');
    },
    'upstreams: AGENTS.md points to the development README for the sibling repositories, which tells a developer to check upstream issues first' => function () {
        $root = dirname(__DIR__);
        $agents = (string) file_get_contents("$root/AGENTS.md");
        check(strpos($agents, 'principles.md) explain every rule below with its reason and the mistake that taught it, and list the sibling') === false, 'AGENTS.md still says the principles list the sibling repositories');
        check(strpos($agents, '[development README](development/README.md#other-magnacapax-repositories) lists the sibling repositories') !== false, 'AGENTS.md pointer');
        $dev = (string) file_get_contents("$root/development/README.md");
        check(strpos($dev, '## Other MagnaCapax repositories') !== false && strpos($dev, 'When a bug is in an upstream project, check its issues and recent commits first') !== false, 'development README');
    },
];
