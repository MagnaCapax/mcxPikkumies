<?php
/**
 * The question suite's documentation coverage (ADR-0039 amended 2026-10-19), without asking anything: the `found` check
 * reads the memory search's own metrics line (a real search writes it here), --regrade reads the saved search-log, the
 * report columns and scorecard line, and the corpus is every family's documentation before the first question.
 */
$qaLib = dirname(__DIR__) . '/tests/testing/questions/lib';
require_once dirname(__DIR__) . '/pikkumies/lib/Provider.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Host.php';
foreach (['Questions', 'Checks', 'Asker', 'Report', 'Sandbox', 'Targets', 'CrontabGuard', 'Coverage', 'Corpus'] as $lib) {
    require_once "$qaLib/$lib.php";
}

return [
    'questions coverage: a real memory search writes the metrics line that found reads; only this question\'s lines, saves ignored' => function () {
        $tmp = testTempDir('pk-qa-cov');
        try {
            $root = "$tmp/pikkumies";
            foreach (['lib', 'tools/memory/pikkumies'] as $d) {
                @mkdir("$root/$d", 0700, true);
                foreach ((array) glob(dirname(__DIR__) . "/pikkumies/$d/*.*") as $f) {
                    copy($f, "$root/$d/" . basename($f));
                }
            }
            @mkdir("$root/config", 0700, true);
            @mkdir("$root/memory", 0700, true);
            file_put_contents("$root/config/documentationSources.md", "## site-kb\n- type: kb-json\n- url: https://kb.example.org/kb.json\n- trust: official\n- scope: all\n");
            @mkdir("$root/local/docs-cache/site-kb", 0700, true);
            file_put_contents("$root/local/docs-cache/site-kb/traffic.md", "---\ntitle: \"Traffic limit\"\nurl: \"https://kb.example.org/t\"\n---\n# Traffic limit\n\nThe monthly traffic limit is 10 TB.\n");
            $dir = "$root/local/state/memory-metrics";
            runPhp("$root/tools/memory/pikkumies/pikkumies.php", ['search', 'zebra', '--offline']); // an earlier question's search
            $counts = CommandLogCheck::counts($dir);
            check($counts !== [], 'perfLogging is on by default, so the metrics line must exist: ' . json_encode($counts));
            file_put_contents("$dir/saves-" . gmdate('Ym') . '.jsonl', "{\"ts\":1}\n", FILE_APPEND);
            runPhp("$root/tools/memory/pikkumies/pikkumies.php", ['search', 'traffic', 'limit', '--offline']);
            $searches = Coverage::newSearches($dir, $counts);
            check(count($searches) === 1 && Coverage::found($searches, 'site-kb') && Coverage::found($searches, 'tier:2'), 'this question\'s search: ' . json_encode($searches));
            check(!Coverage::found($searches, 'other-wiki') && !Coverage::found($searches, 'tier:3') && Coverage::sourcesHit($searches) === ['site-kb'], 'a source or tier that was not hit');
        } finally {
            testRmTree($tmp);
        }
    },
    'questions coverage: the found key lints, --regrade grades it from search-log, and the report counts docs questions that opened no page' => function () {
        $tmp = testTempDir('pk-qa-regrade');
        try {
            $qdir = "$tmp/questions";
            mkdir($qdir, 0700);
            file_put_contents("$qdir/probe.md", "---\ntags: docs, source:site-kb\nfound: site-kb\nfound: tier:3\n---\nWhat is my traffic limit?\n");
            file_put_contents("$qdir/bad.md", "---\nfound: Site_KB\nfound: tier:4\n---\nx\n");
            $qs = Questions::load($qdir);
            $lint = Questions::lint($qs[0], $tmp);
            check(count($lint) === 2 && strpos($lint[0], 'found is not') === 0, 'bad found values: ' . json_encode($lint));
            check(Questions::lint($qs[1], $tmp) === [], 'a good found value was refused: ' . json_encode(Questions::lint($qs[1], $tmp)));
            $run = "$tmp/run";
            @mkdir("$run/transcripts/t1", 0700, true);
            @mkdir("$run/search-log/t1", 0700, true);
            file_put_contents("$run/transcripts/t1/probe.jsonl", "{\"type\":\"system\",\"subtype\":\"init\"}\n{\"type\":\"tool_use\",\"name\":\"terminal\",\"input\":{\"command\":\"php p.php search traffic\"}}\n{\"type\":\"result\",\"text\":\"10 TB\",\"exit_code\":0}\n");
            file_put_contents("$run/results.jsonl", json_encode(['question' => 'probe', 'target' => 't1', 'tags' => ['docs'], 'status' => 'fail', 'checks' => [], 'judge' => [], 'judge_error' => '', 'tokens' => 5, 'seconds' => 1, 'error' => '']) . "\n");
            file_put_contents("$run/search-log/t1/probe.jsonl", json_encode(['ts' => 1, 'tiers' => [1, 2, 3], 'hits' => ['1' => 0, '2' => 1, '3' => 1], 'sources' => ['site-kb' => ['hits' => 1, 'tier' => 2], 'peer-wiki' => ['hits' => 1, 'tier' => 3]]]) . "\n");
            $rows = Report::regrade($run, [$qs[1]]);
            check(count($rows) === 1 && $rows[0]['status'] === 'pass' && $rows[0]['code_total'] === 2, 'regrade from search-log: ' . json_encode($rows));
            check($rows[0]['searched'] === 1 && $rows[0]['sources_hit'] === ['peer-wiki', 'site-kb'] && $rows[0]['pages_opened'] === 0, 'columns: ' . json_encode($rows[0]));
            check(strpos(Report::scorecard($rows), 'docs questions that opened no documentation page: 1 of 1 (probe)') !== false, 'scorecard: ' . Report::scorecard($rows));
            unlink("$run/search-log/t1/probe.jsonl");
            check(Report::regrade($run, [$qs[1]])[0]['status'] === 'fail', 'found passed with no search recorded');
            $opened = Coverage::pagesOpened([['name' => 'read_file', 'input' => ['path' => '/x/local/docs-cache/site-kb/traffic.md']],
                ['name' => 'terminal', 'input' => ['command' => 'php ~/pikkumies/tools/documentation/kb/kb.php search x']], ['name' => 'terminal', 'input' => ['command' => 'df -h']]]);
            check($opened === 2, 'pages opened: ' . $opened);
        } finally {
            testRmTree($tmp);
        }
    },
    'questions corpus: every host family and generic are downloaded once per install, each with its family forced; EMPTY sources named' => function () {
        $root = testTempDir('pk-qa-corpus');
        try {
            $tool = "$root/checkout/pikkumies/tools/memory/pikkumies";
            @mkdir($tool, 0700, true);
            @mkdir("$root/home", 0700, true);
            // A stand-in memory tool: records the family the overlay forces for each refresh.
            file_put_contents("$tool/pikkumies.php", "<?php \$o = require getenv('PIKKUMIES_CONFIG'); file_put_contents('$root/families', \$o['host']['family'] . \"\\n\", FILE_APPEND);\n");
            $box = new Sandbox($root);
            $guard = new CrontabGuard($root, new Report("$root/out", []), $root, 'nobody', function () {
                return 'same';
            });
            ob_start();
            Corpus::refresh($box, ['endpoint' => 'http://127.0.0.1:9/v1', 'keyFile' => "$root/key", 'model' => 'm'], $guard, "$root/out");
            Corpus::refresh($box, ['endpoint' => 'http://127.0.0.1:9/v1', 'keyFile' => "$root/key", 'model' => 'm'], $guard, "$root/out");
            ob_end_clean();
            $want = array_merge(array_keys(Host::TABLE), ['generic']);
            $got = file("$root/families", FILE_IGNORE_NEW_LINES);
            check(count($got) === count($want) && !array_diff($want, $got) && end($got) === 'generic', 'families refreshed (twice would repeat them): ' . json_encode($got));
            check(Corpus::emptySources([['name' => 'a', 'type' => 'site', 'status' => 'on', 'pages' => 0], ['name' => 'b', 'type' => 'link', 'pages' => 0],
                ['name' => 'c', 'type' => 'site', 'status' => 'off', 'pages' => 0], ['name' => 'd', 'type' => 'mediawiki', 'pages' => 12]]) === ['a'], 'empty sources');
        } finally {
            testRmTree($root);
        }
    },
];
