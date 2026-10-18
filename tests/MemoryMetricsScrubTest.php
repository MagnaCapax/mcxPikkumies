<?php
/**
 * Memory metrics written before ADR-0041 was amended (paths, mode, ms, topic, the words of every search) lose those
 * fields once, on the first append or prune of the current code; `stats` says its save count is an upper bound.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/MemoryStats.php';

return [
    'memory metrics: the first prune or append drops the old fields from every file once, keeping words only for searches without a lesson' => function () {
        $tmp = testTempDir('pk-mm-scrub');
        try {
            foreach (['prune', 'append'] as $how) {
                $dir = "$tmp/$how";
                mkdir($dir, 0700);
                $ts = gmmktime(12, 0, 0, 9, 20, 2026);
                file_put_contents("$dir/searches-202609.jsonl", json_encode(['ts' => $ts, 'tiers' => [1, 2], 'hits' => [1 => 2, 2 => 0], 'words' => 'found a lesson', 'mode' => 'all', 'paths' => ['/x/a.md'], 'ms' => 12]) . "\n"
                    . json_encode(['ts' => $ts + 1, 'tiers' => [1, 2], 'hits' => [1 => 0, 2 => 1], 'words' => 'no lesson', 'paths' => ['/x/b.md']]) . "\n");
                file_put_contents("$dir/saves-202609.jsonl", json_encode(['ts' => $ts + 2, 'kind' => 'lesson', 'topic' => 'disk', 'path' => '/x/c.md', 'after_miss' => true]) . "\n");
                chmod("$dir/searches-202609.jsonl", 0600);
                if ($how === 'prune') {
                    MetricsLog::prune($dir, 0, time());
                } else {
                    check(MetricsLog::append($dir, 'saves', ['ts' => time()], 0) === true, 'append failed');
                }
                $search = array_map(function ($l) {
                    return json_decode($l, true);
                }, file("$dir/searches-202609.jsonl", FILE_IGNORE_NEW_LINES));
                check(array_keys($search[0]) === ['ts', 'tiers', 'hits'] && array_keys($search[1]) === ['ts', 'tiers', 'hits', 'words'] && $search[1]['words'] === 'no lesson', "$how: searches " . json_encode($search));
                check(file("$dir/saves-202609.jsonl", FILE_IGNORE_NEW_LINES) === [json_encode(['ts' => $ts + 2])], "$how: saves " . file_get_contents("$dir/saves-202609.jsonl"));
                check(is_file("$dir/.scrubbed-1") && (fileperms("$dir/searches-202609.jsonl") & 0777) === 0600 && !glob("$dir/*.prune"), "$how: marker, mode or a stray temp file");
            }
            // Once only: a line added by hand afterwards is not touched again.
            file_put_contents("$tmp/prune/saves-202609.jsonl", json_encode(['ts' => 5, 'extra' => 1]) . "\n", FILE_APPEND);
            MetricsLog::prune("$tmp/prune", 0, time());
            check(strpos(file_get_contents("$tmp/prune/saves-202609.jsonl"), '"extra"') !== false, 'scrubbed a second time');
        } finally {
            testRmTree($tmp);
        }
    },
    'memory stats: the save count after a search with no lesson is called an upper bound, in the record and the --human view' => function () {
        $tmp = testTempDir('pk-mm-note');
        DocSources::$root = $tmp;
        try {
            $dir = DocSources::metricsDir();
            mkdir($dir, 0700, true);
            file_put_contents("$dir/searches-" . gmdate('Ym') . '.jsonl', json_encode(['ts' => time() - 10, 'hits' => [1 => 0, 2 => 0, 3 => 0], 'words' => 'x']) . "\n");
            file_put_contents("$tmp/keep.php", "<?php return ['sessions' => ['retentionDays' => 0]];\n");
            $s = testWithEnv(['PIKKUMIES_CONFIG' => "$tmp/keep.php"], function () {
                return MemoryStats::compute(30);
            });
            check(strpos($s['note'], 'upper bound') !== false && strpos(MemoryStats::human($s), 'upper bound') !== false, 'no upper-bound note: ' . json_encode($s));
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
];
