<?php
/**
 * tools/memory/pikkumies/MemoryLinks.php (ADR-0060): the `links` verb (outbound, inbound, broken, read from the
 * files on every call) and the broken-link count on the doctor line.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/MemoryStats.php';

return [
    'memory links: links <path> lists what a memory file links to (a memory-root-relative link included), what links to it, and its broken links; --broken lists each broken link once; a tree that is not a git checkout scans the memory alone' => function () {
        $tmp = testTempDir('pk-memlinks');
        try {
            $mem = realpath($tmp) . '/memory';
            mkdir("$mem/lessons/a", 0700, true);
            mkdir("$mem/reference", 0700, true);
            file_put_contents("$mem/README.md", "# memory\n");
            file_put_contents("$mem/lessons/a/A.md", "# A\n\n[r](reference/R.md) [gone](gone.md) `[code](nope.md)`\n");
            file_put_contents("$mem/reference/R.md", "# R\n\n[a](../lessons/a/A.md)\n");
            DocSources::$root = realpath($tmp);
            check(array_keys(MemoryLinks::corpus()) === ["$mem/reference/R.md", "$mem/lessons/a/A.md"], 'corpus: ' . json_encode(array_keys(MemoryLinks::corpus())));
            $r = MemoryLinks::links("$mem/lessons/a/A.md")['records'][0];
            check($r['links_to'] === ["$mem/reference/R.md"] && $r['linked_from'] === ["$mem/reference/R.md"] && $r['broken'] === ['gone.md'], 'links: ' . json_encode($r));
            $b = MemoryLinks::broken();
            check($b['records'] === [['path' => "$mem/lessons/a/A.md", 'target' => 'gone.md']] && $b['status']['broken'] === 1 && $b['status']['files_scanned'] === 2, 'broken: ' . json_encode($b));
            check(MemoryLinks::brokenCount() === 1, 'brokenCount: ' . MemoryLinks::brokenCount());
            check(strpos(MemoryStats::doctorLine(), 'memory:   2 lessons · 1 broken links (links --broken)') === 0, 'doctor: ' . MemoryStats::doctorLine());
            foreach (["$mem/README.md", "$tmp/elsewhere.md", "$mem/lessons/a"] as $bad) {
                try {
                    MemoryLinks::links($bad);
                    check(false, "$bad must be refused");
                } catch (CliError $e) {
                    check(strpos($e->getMessage(), 'links works on a markdown file') === 0, "$bad: " . $e->getMessage());
                }
            }
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'memory links: the links verb on Pikkumies\' own checkout covers its tracked markdown (git ls-files): docs/install.md is linked from the README; links with no path and no --broken is refused' => function () {
        $script = dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/pikkumies.php';
        exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' ls-files README.md 2>/dev/null', $listed, $code);
        if ($code !== 0 || !$listed) {
            skip('git cannot list this checkout here, so the verb scans the memory alone (the test above covers that)');
        }
        list($code, $out) = runPhp($script, ['links', dirname(__DIR__) . '/docs/install.md']);
        $rec = json_decode(strtok($out, "\n"), true);
        check($code === 0 && is_array($rec) && in_array(realpath(dirname(__DIR__) . '/README.md'), $rec['linked_from'], true), "links exited $code: $out");
        list($code) = runPhp($script, ['links']);
        check($code !== 0, 'links with no path must fail');
    },
];
