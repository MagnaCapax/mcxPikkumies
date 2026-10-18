<?php
/**
 * Memory metrics at their edges (ADR-0041): the period boundary inside a month, the 2-hour window
 * (exactly 7200 s, a save in the search's own second, each search counted once), and pruning by
 * sessions.retentionDays (older months deleted, the cutoff month rewritten, at most once a day).
 * A fixed $now keeps every boundary in a known month.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/MemoryStats.php';

if (!function_exists('memEdgeWrite')) {
    /** Write records as JSON Lines to $file. */
    function memEdgeWrite($file, array $records)
    {
        $text = '';
        foreach ($records as $r) {
            $text .= json_encode($r) . "\n";
        }
        file_put_contents($file, $text);
    }
}

return [
    'memory stats: a line one second before the period, inside the boundary month, is left out; saves pair with searches by time' => function () {
        $tmp = testTempDir('pk-mm-edge');
        DocSources::$root = $tmp;
        try {
            $now = gmmktime(12, 0, 0, 10, 15, 2026);
            $since = $now - 30 * 86400; // 2026-09-15 12:00 UTC: the boundary month is 202609
            $t = $since + 50000;
            $dir = DocSources::metricsDir();
            mkdir($dir, 0700, true);
            $none = [1 => 0, 2 => 0, 3 => 0];
            memEdgeWrite("$dir/searches-202609.jsonl", [
                ['ts' => $since - 1, 'hits' => $none, 'words' => 'too old'],
                ['ts' => $since, 'hits' => $none, 'words' => 'just in'],
                ['ts' => $t, 'hits' => [1 => 0, 2 => 1, 3 => 0]],          // a save in its own second follows it
                ['ts' => $t + 100000, 'hits' => [1 => 0, 2 => 1, 3 => 0]], // the next save is exactly 7200 s later: too late
                ['ts' => $t + 200000, 'hits' => [1 => 0, 2 => 1, 3 => 0]], // the next save is 7199 s later
            ]);
            memEdgeWrite("$dir/saves-202609.jsonl", [['ts' => $since - 1], ['ts' => $t], ['ts' => $t + 107200], ['ts' => $t + 207199]]);
            check(count(MemoryMetrics::read('searches', $since)) === 4, 'read must skip the line at since - 1');
            file_put_contents("$tmp/keep.php", "<?php return ['sessions' => ['retentionDays' => 0]];\n"); // the machine's own config must not prune here
            $s = testWithEnv(['PIKKUMIES_CONFIG' => "$tmp/keep.php"], function () use ($now) {
                return MemoryStats::compute(30, $now);
            });
            check($s['searches'] === 4 && $s['found_nothing'] === 1 && $s['last_found_nothing'] === ['just in'], 'period boundary: ' . json_encode($s));
            check($s['saved'] === 3 && $s['found_no_lesson'] === 4 && $s['found_no_lesson_then_saved'] === 2, 'window: ' . json_encode($s));
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'save reminder: a search exactly 7200 s old is no longer a recent miss; 7199 s old still is' => function () {
        $tmp = testTempDir('pk-mm-window');
        DocSources::$root = $tmp;
        try {
            $now = time();
            mkdir(DocSources::searchStateDir(), 0700, true);
            $record = function ($time) {
                file_put_contents(DocSources::searchStateDir() . '/last-search.json', json_encode(['time' => $time, 'tiers' => [1, 2, 3], 'hits' => [1 => 0, 2 => 0, 3 => 0]]));
            };
            $record($now - 7200);
            check(MemoryMetrics::recentMiss($now) === null, 'a search exactly SAVE_WINDOW old must not count');
            $record($now - 7199);
            check(MemoryMetrics::recentMiss($now) !== null, 'a search 7199 s old must count');
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'metrics pruning: retentionDays deletes older months, rewrites the cutoff month (0600 kept), runs at most once a day, 0 keeps all' => function () {
        $tmp = testTempDir('pk-mm-prune');
        $umask = umask(0022);
        try {
            $dir = "$tmp/m";
            mkdir($dir, 0700);
            $now = gmmktime(12, 0, 0, 10, 15, 2026);
            $cut = $now - 7 * 86400; // 2026-10-08 12:00: the cutoff month is 202610
            memEdgeWrite("$dir/searches-202609.jsonl", [['ts' => $cut - 86400 * 10]]);
            memEdgeWrite("$dir/searches-202610.jsonl", [['ts' => $cut - 1, 'words' => 'old'], ['ts' => $cut, 'words' => 'kept']]);
            chmod("$dir/searches-202610.jsonl", 0600);
            MetricsLog::prune($dir, 0, $now);
            check(count(MetricsLog::files($dir, '*')) === 2, '0 must keep everything');
            MetricsLog::prune($dir, 7, $now);
            $lines = file("$dir/searches-202610.jsonl", FILE_IGNORE_NEW_LINES);
            check(!is_file("$dir/searches-202609.jsonl") && $lines === [json_encode(['ts' => $cut, 'words' => 'kept'])], 'pruned: ' . json_encode($lines));
            check((fileperms("$dir/searches-202610.jsonl") & 0777) === 0600 && !glob("$dir/*.prune"), 'rewritten file mode or a stray temp file');
            file_put_contents("$dir/searches-202610.jsonl", json_encode(['ts' => $cut - 1]) . "\n", FILE_APPEND);
            MetricsLog::prune($dir, 7, $now + 3600);
            check(count(file("$dir/searches-202610.jsonl")) === 2, 'pruned twice in one day');
            MetricsLog::prune($dir, 8, $now + 86400); // a day later, 8 days back is the same cutoff
            check(count(file("$dir/searches-202610.jsonl")) === 1, 'not pruned after a day');
            memEdgeWrite("$dir/saves-200001.jsonl", [['ts' => 946684800]]);
            @unlink("$dir/.pruned");
            check(MetricsLog::append($dir, 'saves', ['ts' => time()], 7) === true && !is_file("$dir/saves-200001.jsonl"), 'an append must prune when due');
        } finally {
            umask($umask);
            testRmTree($tmp);
        }
    },
];
