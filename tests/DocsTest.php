<?php
/** A throwaway pikkumies tree (lib, hooks, tools, config) so nothing touches this checkout's local/. */
function docsTree()
{
    $tmp = sys_get_temp_dir() . '/pk-docs-' . getmypid() . '-' . mt_rand();
    $src = dirname(__DIR__) . '/pikkumies';
    foreach (['lib', 'hooks/pre_llm_call', 'hooks/pre_tool_call', 'tools/docs', 'config'] as $d) {
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

$kbFixture = json_encode(['_meta' => ['built' => '2026-10-08T00:00:00+00:00'], 'categories' => [
    ['name' => 'Billing', 'articles' => [
        ['id' => 1, 'title' => 'Refund policy', 'url' => 'https://example.org/clients/kb.php?id=1', 'body' => 'Refunds are given within 14 days.'],
        ['id' => 2, 'title' => '../../etc/passwd', 'url' => '', 'body' => 'path test'],
    ]],
]]);

return [
    'docs: shipped config parses; header sections are not sources; shipped sources are valid' => function () {
        $all = DocSources::parse(file_get_contents(dirname(__DIR__) . '/pikkumies/config/documentationSources.md'));
        check(array_keys($all) === ['pm-wiki', 'pm-kb'], 'sources: ' . implode(',', array_keys($all)));
        foreach ($all as $src) {
            check(DocSources::problems($src) === [], $src['name'] . ': ' . implode('; ', DocSources::problems($src)));
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
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## pm-wiki\n- type: mediawiki\n- url: https://wiki.example.org/api.php\n- trust: upstream\n- status: off\n\n## my-kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: community\n- covers: x\n- refresh: 12h\n");
        $all = DocSources::load();
        $active = DocSources::active();
        DocSources::$root = null;
        check($all['pm-wiki']['origin'] === 'local' && $all['pm-wiki']['trust'] === 'upstream', 'overlay did not replace');
        check(!isset($active['pm-wiki']) && isset($active['pm-kb']) && isset($active['my-kb']), 'active: ' . implode(',', array_keys($active)));
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'docs: bad sources are refused, and nothing is fetched from http or /clients/' => function () {
        $base = ['name' => 'x', 'type' => 'kb-json', 'url' => 'https://example.org/kb.json', 'trust' => 'official'];
        check(DocSources::problems($base) === [], 'valid source refused');
        check(DocSources::problems(['url' => 'http://example.org/kb.json'] + $base) !== [], 'http accepted');
        check(DocSources::problems(['url' => 'https://example.org/clients/kb.json'] + $base) !== [], '/clients/ accepted');
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
        check(DocSources::readable("$dir/refund-policy.md") && !DocSources::readable("$dir/../../../../../../etc/passwd") && !DocSources::readable("$tree/pikkumies/lib/Config.php"), 'readable() wrong');
        DocSources::$root = null;
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'docs list: every downloaded page of a source by title, with its address' => function () use ($kbFixture) {
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
        list($code, $out) = runPhp("$tree/pikkumies/tools/docs/docs.php", ['list']);
        check($code === 0 && strpos($out, '== kb: 2 pages') !== false, 'list header: ' . $out);
        check(strpos($out, "Refund policy  https://example.org/clients/kb.php?id=1\n") !== false, 'title and address missing: ' . $out);
        list(, $out) = runPhp("$tree/pikkumies/tools/docs/docs.php", ['list', 'nope']);
        check(strpos($out, 'nope: not an active source') !== false, 'unknown source not reported');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'search-first hook: first turn only, bounded, names the search command, no page text' => function () {
        $tree = docsTree();
        @mkdir("$tree/pikkumies/local/docs-cache/kb", 0700, true);
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n");
        file_put_contents("$tree/pikkumies/local/docs-cache/kb/disk-full.md", "---\ntitle: \"Disk full\"\nurl: \"https://example.org/x\"\n---\n# Disk full\n\nSECRET-BODY-TEXT rtorrent stops when the disk is full.\n");
        $out = docsHook($tree, 'pre_llm_call/search-first.php', ['hook_event_name' => 'pre_llm_call', 'session_id' => 's', 'extra' => ['is_first_turn' => true, 'user_message' => 'rtorrent stopped, disk full?']]);
        $d = json_decode($out, true);
        check(is_array($d) && strpos($d['context'], 'docs.php search') !== false, 'no instruction: ' . $out);
        check(strpos($d['context'], '[official] kb: Disk full') !== false && strpos($d['context'], 'SECRET-BODY-TEXT') === false, 'hits wrong or page text leaked: ' . $d['context']);
        check(strlen($d['context']) <= 1500, 'context too long');
        $multi = json_decode(docsHook($tree, 'pre_llm_call/search-first.php', ['extra' => ['is_first_turn' => true, 'user_message' => [['type' => 'text', 'text' => 'disk full']]]]), true);
        check(strpos($multi['context'], 'Disk full') !== false, 'multimodal message not searched');
        check(trim(docsHook($tree, 'pre_llm_call/search-first.php', ['extra' => ['is_first_turn' => false, 'user_message' => 'x']])) === '', 'fired after the first turn');
        exec('rm -rf ' . escapeshellarg($tree));
    },
    'search-first gate: blocks acting tools until docs.php search runs, per session; switch turns it off' => function () {
        $tree = docsTree();
        $gate = 'pre_tool_call/search-first-gate.php';
        $call = function ($session, $tool, $cmd = null) use ($tree, $gate) {
            $p = ['hook_event_name' => 'pre_tool_call', 'session_id' => $session, 'tool_name' => $tool, 'tool_input' => $cmd === null ? ['path' => 'x'] : ['command' => $cmd]];
            $d = json_decode(docsHook($tree, $gate, $p), true);
            return is_array($d) && isset($d['action']) && $d['action'] === 'block';
        };
        check($call('s1', 'write_file'), 'write_file not blocked before search');
        check($call('s1', 'terminal', 'systemctl restart nginx'), 'terminal not blocked before search');
        check(!$call('s1', 'read_file') && !$call('s1', 'clarify'), 'read-only tool blocked');
        check(!$call('s1', 'terminal', 'php ~/pikkumies/tools/docs/docs.php sources'), 'docs sources blocked');
        check($call('s1', 'patch'), 'docs sources must not open the gate');
        check(!$call('s1', 'terminal', 'php /home/u/pikkumies/tools/docs/docs.php search "disk full"'), 'docs search blocked');
        check(!$call('s1', 'write_file') && !$call('s1', 'terminal', 'df -h'), 'gate stayed closed after search');
        check($call('s2', 'write_file'), 'another session inherited the opened gate');
        file_put_contents("$tree/pikkumies/local/config.php", "<?php return ['searchFirst' => ['disabled' => true]];\n");
        check(!$call('s3', 'write_file'), 'searchFirst.disabled ignored');
        exec('rm -rf ' . escapeshellarg($tree));
    },
];
