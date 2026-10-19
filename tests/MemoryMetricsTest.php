<?php
/**
 * Memory metrics and the save reminder (ADR-0041): metrics are written only with privacy.perfLogging
 * on, `stats` counts them right, the search's `next` carries the save command, and the reminder
 * fires once per missed search, never after a save, never after 2 hours, never when disabled.
 */
if (!function_exists('memMetricsTree')) {
    /** A throwaway pikkumies tree with the memory unit and the save-reminder hook; $perf sets privacy.perfLogging. */
    function memMetricsTree($perf = true, array $extraConfig = [])
    {
        $tmp = testTempDir('pk-mm');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/memory/pikkumies', 'hooks/search/save-reminder', 'config'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/memory/lessons/disk", 0700, true);
        @mkdir("$tmp/pikkumies/local/state/search-first", 0700, true);
        file_put_contents("$tmp/pikkumies/memory/lessons/disk/20261001-rtorrent-full-disk.md", "# rtorrent stops on a full disk\n\nFree space first.\n");
        touch("$tmp/pikkumies/memory/lessons/disk/20261001-rtorrent-full-disk.md", time() - 86400);
        $config = ['privacy' => ['perfLogging' => $perf]] + $extraConfig;
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        return $tmp;
    }

    function memMetricsLines($file)
    {
        return is_file($file) ? cliLines((string) file_get_contents($file)) : [];
    }

    /** Run the save-reminder hook in $tree; returns its context or ''. */
    function memMetricsRemind($tree)
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/search/save-reminder/save-reminder.php", [], json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => 's']));
        $d = json_decode($out, true);
        return is_array($d) && isset($d['context']) ? $d['context'] : (trim($out) === '' ? '' : 'UNPARSED: ' . $out);
    }

    function memMetricsRecord($tree, $time, array $hits)
    {
        file_put_contents("$tree/pikkumies/local/state/search-first/last-search.json", json_encode(['time' => $time, 'tiers' => [1, 2, 3], 'hits' => $hits]) . "\n");
    }
}

require_once dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/MemoryStats.php';

return [
    'memory metrics: with perfLogging on, a search and a save each append one 0600 line (time, tiers, hits; words only without a lesson; a save only its time)' => function () {
        $tree = memMetricsTree(true);
        $umask = umask(0022); // a permissive umask: only the explicit chmod makes the file 0600
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            list($code, $out) = runPhp($tool, ['search', 'zebra', 'quantum', '--offline']);
            check($code === 0, 'search failed: ' . $out);
            $dir = "$tree/pikkumies/local/state/memory-metrics";
            $file = "$dir/searches-" . gmdate('Ym') . '.jsonl';
            $l = memMetricsLines($file);
            check(count($l) === 1 && array_keys($l[0]) === ['ts', 'tiers', 'hits', 'words'] && $l[0]['words'] === 'zebra quantum' && $l[0]['tiers'] === [1, 2, 3]
                && $l[0]['hits'] === ['1' => 0, '2' => 0, '3' => 0] && $l[0]['ts'] >= time() - 60, 'search line: ' . json_encode($l));
            check((fileperms($dir) & 0777) === 0700 && (fileperms($file) & 0777) === 0600, 'modes: ' . decoct(fileperms($dir) & 0777) . ' ' . decoct(fileperms($file) & 0777));
            file_put_contents("$tree/body.md", "Zebra quantum is not a setting here.\n");
            list($code, $out) = runPhp($tool, ['save', '--topic', 'misc', '--title', 'zebra quantum is not a thing', '--sources', 'search', '--no-related', 'first', '--body-file', "$tree/body.md"]);
            check($code === 0, 'save failed: ' . $out);
            $s = memMetricsLines("$dir/saves-" . gmdate('Ym') . '.jsonl');
            check(count($s) === 1 && array_keys($s[0]) === ['ts'] && $s[0]['ts'] >= time() - 60, 'save line: ' . json_encode($s));
            list(, $out) = runPhp($tool, ['search', 'rtorrent', 'disk', '--offline']);
            $l = memMetricsLines($file);
            check(count($l) === 2 && $l[1]['hits'][1] === 1 && !isset($l[1]['words']), 'a search that found a lesson keeps no words: ' . json_encode($l));
        } finally {
            umask($umask);
            testRmTree($tree);
        }
    },
    'memory metrics: with perfLogging off, a search and a save write no metrics at all (the gate record still is)' => function () {
        $tree = memMetricsTree(false);
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            runPhp($tool, ['search', 'zebra', '--offline']);
            file_put_contents("$tree/body.md", "x\n");
            list($code, $out) = runPhp($tool, ['save', '--topic', 'misc', '--title', 'zebra', '--sources', 's', '--no-related', 'x', '--body-file', "$tree/body.md"]);
            check($code === 0, 'save failed: ' . $out);
            check(!file_exists("$tree/pikkumies/local/state/memory-metrics"), 'metrics written with perfLogging off: ' . json_encode(glob("$tree/pikkumies/local/state/memory-metrics/*")));
            check(is_file("$tree/pikkumies/local/state/search-first/last-search.json"), 'the gate record must not depend on perfLogging');
        } finally {
            testRmTree($tree);
        }
    },
    'memory metrics: appendMetric() follows the PIKKUMIES_CONFIG overlay in-process: off writes nothing, on writes one line' => function () {
        $tmp = testTempDir('pk-mm-ov');
        DocSources::$root = $tmp;
        try {
            file_put_contents("$tmp/off.php", "<?php return ['privacy' => ['perfLogging' => false]];\n");
            file_put_contents("$tmp/on.php", "<?php return ['privacy' => ['perfLogging' => true]];\n");
            testWithEnv(['PIKKUMIES_CONFIG' => "$tmp/off.php"], function () {
                check(DocSources::appendMetric('searches', ['ts' => time()]) === false, 'off must report not written');
            });
            check(!is_dir(DocSources::metricsDir()), 'off created the metrics directory');
            testWithEnv(['PIKKUMIES_CONFIG' => "$tmp/on.php"], function () {
                check(DocSources::appendMetric('searches', ['ts' => time(), 'words' => 'a']) === true, 'on must write');
            });
            check(count(MemoryMetrics::read('searches', time() - 60)) === 1, 'one line expected');
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'memory stats: 0 searches, all misses and a mixed period count right; older lines are outside the period' => function () {
        $tmp = testTempDir('pk-mm-stats');
        DocSources::$root = $tmp;
        try {
            @mkdir("$tmp/memory/lessons/disk", 0700, true);
            @mkdir("$tmp/memory/reference", 0700, true);
            file_put_contents("$tmp/memory/lessons/disk/a.md", "# a\n");
            file_put_contents("$tmp/memory/reference/b.md", "# b\n");
            file_put_contents("$tmp/memory/README.md", "# not a lesson\n");
            $now = time();
            $s = MemoryStats::compute(30, $now);
            check($s['searches'] === 0 && $s['with_hit_pct'] === null && $s['found_nothing'] === 0 && $s['last_found_nothing'] === [] && $s['found_no_lesson'] === 0, 'empty: ' . json_encode($s));
            check($s['lessons_on_disk'] === 2 && $s['by_kind'] === ['lesson' => 1, 'reference' => 1, 'research' => 0] && $s['by_topic'] === ['disk' => 1], 'disk: ' . json_encode($s));
            $dir = DocSources::metricsDir();
            mkdir($dir, 0700, true);
            $lines = '';
            for ($i = 0; $i < 12; $i++) {
                $lines .= json_encode(['ts' => $now - 100 + $i, 'words' => "miss $i", 'hits' => [1 => 0, 2 => 0, 3 => 0]]) . "\n";
            }
            file_put_contents("$dir/searches-" . gmdate('Ym', $now) . '.jsonl', $lines);
            file_put_contents("$dir/searches-200001.jsonl", json_encode(['ts' => 946684800, 'words' => 'ancient', 'hits' => [1 => 1]]) . "\n");
            $s = MemoryStats::compute(30, $now);
            check($s['searches'] === 12 && $s['with_hit'] === 0 && $s['with_hit_pct'] === 0 && $s['found_nothing'] === 12 && $s['found_no_lesson'] === 12, 'all misses: ' . json_encode($s));
            check(count($s['last_found_nothing']) === 10 && $s['last_found_nothing'][0] === 'miss 11' && $s['last_found_nothing'][9] === 'miss 2', 'newest 10 misses first: ' . json_encode($s['last_found_nothing']));
            // Mixed: 12 found nothing + 3 with a lesson + 1 with only a tier-2 hit = 16 searches, 4 found something (25%), 3 lessons (19%), 13 found no lesson.
            $mixed = '';
            foreach ([[1 => 2, 2 => 0, 3 => 0], [1 => 1, 2 => 4, 3 => 0], [1 => 1, 2 => 0, 3 => 0], [1 => 0, 2 => 3, 3 => 0]] as $i => $h) {
                $mixed .= json_encode(['ts' => $now - 10 + $i, 'words' => "hit $i", 'hits' => $h]) . "\n";
            }
            file_put_contents("$dir/searches-" . gmdate('Ym', $now) . '.jsonl', $mixed, FILE_APPEND);
            // Two saves in one second, at now-95: the 6 no-lesson searches at now-100..now-95 were followed by a save (each once), the 7 later ones were not.
            file_put_contents("$dir/saves-" . gmdate('Ym', $now) . '.jsonl', json_encode(['ts' => $now - 95]) . "\n" . json_encode(['ts' => $now - 95]) . "\n");
            $s = MemoryStats::compute(30, $now);
            check($s['searches'] === 16 && $s['with_hit'] === 4 && $s['with_hit_pct'] === 25 && $s['with_lesson_hit'] === 3 && $s['with_lesson_hit_pct'] === 19 && $s['found_nothing'] === 12 && $s['found_no_lesson'] === 13, 'mixed: ' . json_encode($s));
            check($s['saved'] === 2 && $s['found_no_lesson_then_saved'] === 6, 'saves: ' . json_encode($s));
            $human = MemoryStats::human($s);
            check(strpos($human, '▰▰▰▱▱▱▱▱▱▱ 25%') !== false && preg_match('/^found no lesson +13  ▰▰▰▰▰▰▰▰▱▱ 81%$/mu', $human) === 1
                && preg_match('/^  then saved in 2 h +6  ▰▰▰▰▰▱▱▱▱▱ 46%$/mu', $human) === 1, 'human: ' . $human);
            file_put_contents("$tmp/on.php", "<?php return ['privacy' => ['perfLogging' => true], 'sessions' => ['retentionDays' => 7]];\n");
            $line = testWithEnv(['PIKKUMIES_CONFIG' => "$tmp/on.php"], function () {
                return MemoryStats::doctorLine();
            });
            check($line === 'memory:   2 lessons · 16 searches in 30 days · 25% found something · 12 found nothing · 13 found no lesson, 6 then saved', 'doctor: ' . $line);
            check(!is_file("$dir/searches-200001.jsonl") && count(memMetricsLines("$dir/searches-" . gmdate('Ym', $now) . '.jsonl')) === 16, 'doctor with retentionDays 7 must prune only the old month');
        } finally {
            DocSources::$root = null;
            testRmTree($tmp);
        }
    },
    'memory search: next carries the exact save command when no lesson matched, and not when one did' => function () {
        $tree = memMetricsTree(false);
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            list(, $out) = runPhp($tool, ['search', 'zebra', '--offline']);
            $l = cliLines($out);
            $next = $l[count($l) - 1]['next'];
            check(strpos($next, "php $tree/pikkumies/tools/memory/pikkumies/pikkumies.php save --topic <topic> --title") !== false && strpos($next, '--sources') !== false, 'next: ' . $next);
            list(, $out) = runPhp($tool, ['search', 'rtorrent', 'disk', '--offline']);
            $l = cliLines($out);
            check(!isset($l[count($l) - 1]['next']), 'a lesson hit must not ask for a save: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
    'save reminder: fires once per missed search, not after a save, not for a lesson hit, not after 2 hours, not when disabled' => function () {
        $tree = memMetricsTree(false);
        try {
            memMetricsRecord($tree, time() - 30, [1 => 0, 2 => 2, 3 => 0]);
            $c = memMetricsRemind($tree);
            check(strpos($c, 'The last search found no lesson') === 0 && strpos($c, 'pikkumies.php save --topic <topic>') !== false, 'first: ' . $c);
            check(memMetricsRemind($tree) === '', 'it repeated for the same search');
            memMetricsRecord($tree, time() - 20, [1 => 0, 2 => 0, 3 => 0]);
            check(memMetricsRemind($tree) !== '', 'a new missed search got no reminder');
            memMetricsRecord($tree, time() - 7201, [1 => 0, 2 => 0, 3 => 0]);
            check(memMetricsRemind($tree) === '', 'reminded about a search over 2 hours old');
            $t = time() - 15;
            memMetricsRecord($tree, $t, [1 => 0, 2 => 0, 3 => 0]);
            touch("$tree/pikkumies/memory/lessons/disk/20261001-rtorrent-full-disk.md", $t); // appended to in the search's own second
            check(memMetricsRemind($tree) === '', 'reminded although a memory file changed in the same second as the search');
            memMetricsRecord($tree, time() - 10, [1 => 0, 2 => 0, 3 => 0]);
            file_put_contents("$tree/pikkumies/memory/lessons/disk/20261018-saved.md", "# saved\n");
            check(memMetricsRemind($tree) === '', 'reminded although a lesson was saved after the search');
            memMetricsRecord($tree, time() + 5, [1 => 1, 2 => 0, 3 => 0]);
            check(memMetricsRemind($tree) === '', 'reminded after a search that found a lesson');
        } finally {
            testRmTree($tree);
        }
        $tree = memMetricsTree(false, ['searchFirst' => ['disabled' => true]]);
        try {
            memMetricsRecord($tree, time() - 30, [1 => 0, 2 => 0, 3 => 0]);
            check(memMetricsRemind($tree) === '', 'reminded with searchFirst.disabled');
        } finally {
            testRmTree($tree);
        }
    },
    // D106: the "stats" CLI verb's --days default (30) and override live only in pikkumies.php's
    // own option parsing; every existing MemoryStats::compute() test calls it directly with an
    // explicit $days, so the CLI default/override was never exercised end to end.
    'memory stats CLI: "stats" with no --days defaults to 30; "stats --days 5" honours the given window' => function () {
        $tree = memMetricsTree(true);
        try {
            $tool = "$tree/pikkumies/tools/memory/pikkumies/pikkumies.php";
            $dir = "$tree/pikkumies/local/state/memory-metrics";
            @mkdir($dir, 0700, true);
            $now = time();
            file_put_contents("$dir/searches-" . gmdate('Ym', $now) . '.jsonl',
                json_encode(['ts' => $now - 6 * 86400, 'words' => 'old miss', 'hits' => [1 => 0, 2 => 0, 3 => 0]]) . "\n");
            list($code, $out) = runPhp($tool, ['stats']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['days'] === 30 && $l[0]['searches'] === 1, 'default --days must be 30 and include a 6-day-old search: ' . $out);
            list($code2, $out2) = runPhp($tool, ['stats', '--days', '5']);
            $l2 = cliLines($out2);
            check($code2 === 0 && $l2[0]['days'] === 5 && $l2[0]['searches'] === 0, '--days 5 must exclude the 6-day-old search: ' . $out2);
        } finally {
            testRmTree($tree);
        }
    },
    'deep-research skill: part 1 searches wide and checks twice (4 children, 3 rounds, always a lesson) before the web' => function () {
        $text = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/research/deep-research/skill.md');
        $p1 = strpos($text, '## Part 1: search wide, check twice');
        $p2 = strpos($text, '## Part 2: the web (tier 4), only after part 1');
        check($p1 !== false && $p2 !== false && $p1 < $p2, 'parts missing or out of order');
        foreach (['whenever a lesson (tier 1) does not settle', 'at most 4', 'at most 3 rounds', 'confirmed, contradicted or unsupported', 'Always save a lesson', 'not settled', 'credits'] as $phrase) {
            $at = strpos($text, $phrase);
            check($at !== false && $at > $p1 && $at < $p2, "part 1 lost: $phrase");
        }
    },
];
