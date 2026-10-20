<?php
/**
 * tools/system/telemetry (ADR-0086): TelemetryLog's own record/series/list/prune behaviour, the
 * privacy.perfLogging gate, and the CLI wiring (telemetry.php's test seams, never the real
 * pikkumies/local/). The four call sites (system-status, storage, Torrents) are exercised by their
 * own unit's tests; this file only proves TelemetryLog itself behaves, so a regression there is
 * caught once, in the one shared place, rather than four times.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/telemetry/TelemetryLog.php';

/** Runs the real telemetry.php with $telemetryLocalDir and (optionally) $telemetryConfig as seams. */
function telRun($dir, array $args, array $config = null)
{
    $code = "<?php\n\$telemetryLocalDir = " . var_export($dir, true) . ";\n"
        . ($config !== null ? "\$telemetryConfig = " . var_export($config, true) . ";\n" : '')
        . 'require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/system/telemetry/telemetry.php', true) . ";\n";
    $script = $dir . '/run.php';
    file_put_contents($script, $code);
    return runPhp($script, $args);
}

return [
    'TelemetryLog::record: a bad source name throws; privacy.perfLogging off writes nothing and is not a failure' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $config = ['privacy' => ['perfLogging' => true]];
            $threw = false;
            try {
                TelemetryLog::record('Bad Name!', ['a' => 1], $dir, $config);
            } catch (CliError $e) {
                $threw = true;
            }
            check($threw, 'a bad source name must throw CliError, not write anything');
            check(!is_dir(TelemetryLog::dir($dir) . '/Bad Name!'), 'a bad source must never create a directory');
            check(TelemetryLog::record('x', ['a' => 1], $dir, ['privacy' => ['perfLogging' => false]]) === false, 'off must report not written');
            check(!is_dir(TelemetryLog::dir($dir) . '/x'), 'off must create no directory at all');
            check(TelemetryLog::listSources($dir) === [], 'off: list must show nothing');
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::record: only numeric/bool fields survive, ts/bad names/too many fields are dropped, no field left is not a failure' => function () {
        $dir = testTempDir('pk-tel');
        $savedInterval = TelemetryLog::$minIntervalS;
        TelemetryLog::$minIntervalS = 0; // this test writes twice back-to-back on purpose; the rate limit has its own test below
        try {
            $config = ['privacy' => ['perfLogging' => true]];
            $base = TelemetryLog::dir($dir) . '/storage';
            $today = gmdate('Ymd');
            $many = array_fill_keys(array_map(function ($n) { return "f$n"; }, range(1, 40)), 1);
            check(TelemetryLog::record('storage', $many, $dir, $config) === true, 'a record with 40 fields must still write (capped, not refused)');
            $rec = json_decode(trim((string) file_get_contents("$base/$today.jsonl")), true);
            check(count($rec) === 1 + 32, 'ts plus at most 32 fields: got ' . count($rec));
            check(TelemetryLog::record('storage', ['ts' => 1, 'bad name' => 1, 'ok_one' => 2.5, 'yes' => true, 'no' => false, 'txt' => 'nope', 'arr' => [1]], $dir, $config) === true,
                'one valid field among junk must still write');
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents("$base/$today.jsonl")), 'strlen'));
            $rec2 = json_decode(end($lines), true);
            check($rec2['ts'] !== 1 && $rec2['ok_one'] === 2.5 && $rec2['yes'] === 1 && $rec2['no'] === 0
                && !isset($rec2['bad name']) && !isset($rec2['txt']) && !isset($rec2['arr']), 'cleaned record: ' . json_encode($rec2));
            check((fileperms($base) & 0777) === 0700 && (fileperms("$base/$today.jsonl") & 0777) === 0600, 'modes');
            check(TelemetryLog::record('storage', ['txt' => 'nope', 'arr' => [1]], $dir, $config) === false, 'a record with no numeric field left must write nothing and still not be a failure');
            check(TelemetryLog::listSources($dir)[0]['failures'] === 0, 'nothing-to-write must never count as a failure');
        } finally {
            TelemetryLog::$minIntervalS = $savedInterval;
            testRmTree($dir);
        }
    },
    'TelemetryLog::record: a source name is refused with a trailing newline (security review F9a: \\z, never $)' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $config = ['privacy' => ['perfLogging' => true]];
            $threw = false;
            try {
                TelemetryLog::record("storage\n", ['a' => 1], $dir, $config);
            } catch (CliError $e) {
                $threw = true;
            }
            check($threw, 'a source name with a trailing newline must be refused, not treated as "storage"');
            check(glob(TelemetryLog::dir($dir) . '/*') === [], 'nothing must be written for a name that only looks valid before its newline');
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::record: at most one write per source per minute, whoever calls it (security review F9c); a second write in the window is a counted drop, not a failure' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $config = ['privacy' => ['perfLogging' => true]];
            check(TelemetryLog::record('storage', ['a' => 1], $dir, $config) === true, 'the first write in the window must succeed');
            check(TelemetryLog::record('storage', ['a' => 2], $dir, $config) === false, 'a second write inside the same minute must be rate-limited, not written');
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents(TelemetryLog::dir($dir) . '/storage/' . gmdate('Ymd') . '.jsonl')), 'strlen'));
            check(count($lines) === 1, 'the rate-limited write must leave no second line: ' . json_encode($lines));
            $list = TelemetryLog::listSources($dir);
            check($list[0]['dropped'] === 1 && $list[0]['failures'] === 0, 'the rate-limited write must count as a drop, never a failure: ' . json_encode($list[0]));
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::record: a source-day file never grows past the size cap (security review F9c); the cap is a counted drop, not a failure' => function () {
        $dir = testTempDir('pk-tel');
        $savedInterval = TelemetryLog::$minIntervalS;
        $savedMax = TelemetryLog::$maxFileBytes;
        TelemetryLog::$minIntervalS = 0;
        TelemetryLog::$maxFileBytes = 40; // small enough that one record already fills it
        try {
            $config = ['privacy' => ['perfLogging' => true]];
            check(TelemetryLog::record('storage', ['a' => 1], $dir, $config) === true, 'the first write must still fit under a small cap');
            check(TelemetryLog::record('storage', ['a' => 2], $dir, $config) === false, 'a write that would exceed the cap must not be written');
            $list = TelemetryLog::listSources($dir);
            check($list[0]['dropped'] === 1 && $list[0]['failures'] === 0, 'a size-capped write must count as a drop, never a failure: ' . json_encode($list[0]));
        } finally {
            TelemetryLog::$minIntervalS = $savedInterval;
            TelemetryLog::$maxFileBytes = $savedMax;
            testRmTree($dir);
        }
    },
    'TelemetryLog::record: a lock already held elsewhere gives up quickly (security review F9b: LOCK_NB with a short retry, never a blocking flock)' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $telDir = TelemetryLog::dir($dir) . '/storage';
            mkdir($telDir, 0700, true);
            $holder = fopen("$telDir/.lock", 'c');
            flock($holder, LOCK_EX); // hold the lock for the whole test, as a concurrent writer would
            try {
                $config = ['privacy' => ['perfLogging' => true]];
                $start = microtime(true);
                $written = TelemetryLog::record('storage', ['a' => 1], $dir, $config);
                $elapsed = microtime(true) - $start;
                check($written === false, 'a write that cannot take the lock must report not written, never block until it can');
                check($elapsed < 1.0, "giving up must be fast, not a blocking wait: took {$elapsed}s");
                check(TelemetryLog::listSources($dir)[0]['failures'] === 1, 'a lock that could not be taken must count as a failure, not a silent drop');
            } finally {
                flock($holder, LOCK_UN);
                fclose($holder);
            }
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog: a write that genuinely fails is counted by noteFailure() and shown in list(), separately per source' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $jammedToday = TelemetryLog::dir($dir) . '/jammed/' . gmdate('Ymd') . '.jsonl';
            mkdir($jammedToday, 0700, true); // the day-file's own path is a directory: the write must fail
            $config = ['privacy' => ['perfLogging' => true]];
            check(TelemetryLog::record('jammed', ['a' => 1], $dir, $config) === false, 'a genuine write failure must report not written');
            check(TelemetryLog::record('jammed', ['a' => 1], $dir, $config) === false, 'and again');
            TelemetryLog::record('storage', ['a' => 1], $dir, $config); // an unrelated source must stay unaffected
            $byName = [];
            foreach (TelemetryLog::listSources($dir) as $r) {
                $byName[$r['source']] = $r;
            }
            check($byName['jammed']['failures'] === 2, 'two failed writes must be counted: ' . json_encode($byName));
            check($byName['storage']['failures'] === 0, 'an unrelated source must not share the failure count: ' . json_encode($byName));
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::series: buckets by hour and by day, a missing field or source is an empty list, not an error' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $base = TelemetryLog::dir($dir) . '/storage';
            mkdir($base, 0700, true);
            $day = 86400;
            $today = intdiv(time(), $day) * $day;
            $lines = [
                json_encode(['ts' => $today + 100, 'used_pct' => 10]),
                json_encode(['ts' => $today + 200, 'used_pct' => 20]), // same hour as above
                json_encode(['ts' => $today + 3700, 'used_pct' => 90]), // next hour
                json_encode(['ts' => $today + 3800, 'skip_me' => 1]),  // no used_pct: must be skipped, not treated as 0
            ];
            file_put_contents("$base/" . gmdate('Ymd', $today) . '.jsonl', implode("\n", $lines) . "\n");
            $hours = TelemetryLog::series('storage', 'used_pct', 2, 'hour', $dir);
            check(count($hours) === 2, 'two hour-buckets expected: ' . json_encode($hours));
            check($hours[0]['ts'] === $today && $hours[0]['value'] === 15.0 && $hours[0]['min'] === 10.0 && $hours[0]['max'] === 20.0 && $hours[0]['count'] === 2,
                'first bucket mean/min/max/count: ' . json_encode($hours[0]));
            check($hours[1]['ts'] === $today + 3600 && $hours[1]['value'] === 90.0 && $hours[1]['count'] === 1, 'second bucket: ' . json_encode($hours[1]));
            $days = TelemetryLog::series('storage', 'used_pct', 2, 'day', $dir);
            check(count($days) === 1 && $days[0]['ts'] === $today && $days[0]['count'] === 3, 'one day-bucket of 3 points: ' . json_encode($days));
            check(TelemetryLog::series('storage', 'no_such_field', 2, 'hour', $dir) === [], 'an unknown field must be an empty list');
            check(TelemetryLog::series('no_such_source', 'used_pct', 2, 'hour', $dir) === [], 'an unknown source must be an empty list, not an error');
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::counts: calls, ok tally and last_ts per field over the window; older lines (by file and by ts), malformed and fieldless lines are excluded, not an error' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $base = TelemetryLog::dir($dir) . '/usage-tool';
            mkdir($base, 0700, true);
            $now = time();
            $today = gmdate('Ymd', $now);
            $oldFile = gmdate('Ymd', $now - 40 * 86400);
            file_put_contents("$base/$oldFile.jsonl", json_encode(['ts' => $now - 40 * 86400, 'terminal' => 1]) . "\n"); // a whole file outside a 30-day window
            $lines = [
                json_encode(['ts' => $now - 100, 'terminal' => 1]),
                json_encode(['ts' => $now - 50, 'terminal' => 0]),
                json_encode(['ts' => $now, 'terminal' => 1]),
                json_encode(['ts' => $now - 10, 'skill_view' => 1]),
                json_encode(['ts' => $now - 40 * 86400, 'terminal' => 1]), // a ts older than the window, even though the FILE it lives in is today's
                'not json at all',
                json_encode(['ts' => $now]), // no field besides ts: nothing to tally
            ];
            file_put_contents("$base/$today.jsonl", implode("\n", $lines) . "\n");
            $c = TelemetryLog::counts('usage-tool', 30, $dir);
            check(count($c) === 2, 'only terminal and skill_view fields expected: ' . json_encode($c));
            check($c['terminal']['calls'] === 3 && $c['terminal']['ok'] === 2 && $c['terminal']['last_ts'] === $now, 'terminal tally: ' . json_encode($c['terminal']));
            check($c['skill_view']['calls'] === 1 && $c['skill_view']['ok'] === 1 && $c['skill_view']['last_ts'] === $now - 10, 'skill_view tally: ' . json_encode($c['skill_view']));
            $threw = false;
            try {
                TelemetryLog::counts('Bad Name!', 30, $dir);
            } catch (CliError $e) {
                $threw = true;
            }
            check($threw, 'a bad source name must throw, like record() and series()');
            check(TelemetryLog::counts('no-such-source', 30, $dir) === [], 'an unknown source is an empty result, not an error');
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::prune: nothing without --keep-days (kept forever by default); with it, only files older than the cutoff are removed, per source' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $base = TelemetryLog::dir($dir) . '/storage';
            mkdir($base, 0700, true);
            $old = gmdate('Ymd', time() - 400 * 86400);
            $recent = gmdate('Ymd', time() - 5 * 86400);
            $today = gmdate('Ymd');
            foreach ([$old, $recent, $today] as $d) {
                file_put_contents("$base/$d.jsonl", json_encode(['ts' => time(), 'a' => 1]) . "\n");
            }
            check(TelemetryLog::prune(0, $dir) === ['sources' => 0, 'files_removed' => 0], 'keepDays 0 must delete nothing');
            check(count(glob("$base/*.jsonl")) === 3, 'still 3 files after the no-op prune');
            check(TelemetryLog::prune(30, $dir) === ['sources' => 1, 'files_removed' => 1], 'only the 400-day-old file is past a 30-day cutoff');
            $left = array_map('basename', glob("$base/*.jsonl"));
            sort($left);
            check($left === ["$recent.jsonl", "$today.jsonl"], 'the recent and today files must survive: ' . json_encode($left));
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::listSources: bytes/days/oldest/newest are right, and a non-telemetry directory is never listed' => function () {
        $dir = testTempDir('pk-tel');
        try {
            $telDir = TelemetryLog::dir($dir);
            mkdir("$telDir/storage", 0700, true);
            mkdir("$telDir/..stray", 0700, true); // not SOURCE_RE: must be ignored
            file_put_contents("$telDir/storage/20260101.jsonl", "a\n");
            file_put_contents("$telDir/storage/20260103.jsonl", "bb\n");
            $list = TelemetryLog::listSources($dir);
            check(count($list) === 1 && $list[0]['source'] === 'storage', 'only the valid source must be listed: ' . json_encode($list));
            check($list[0]['days'] === 2 && $list[0]['bytes'] === 5 && $list[0]['oldest'] === '20260101' && $list[0]['newest'] === '20260103',
                'list fields: ' . json_encode($list[0]));
        } finally {
            testRmTree($dir);
        }
    },
    'TelemetryLog::series/listSources: security review F-TEL/F4 -- PIKKUMIES_SERVE=1 (set by serve.php for every tool it runs) hides every usage-* source; a diagnostic source stays visible; unset, nothing changes' => function () {
        $dir = testTempDir('pk-tel');
        $telDir = TelemetryLog::dir($dir);
        mkdir("$telDir/storage", 0700, true);
        mkdir("$telDir/usage-tool", 0700, true);
        $today = gmdate('Ymd');
        file_put_contents("$telDir/storage/$today.jsonl", json_encode(['ts' => time(), 'a' => 5]) . "\n");
        file_put_contents("$telDir/usage-tool/$today.jsonl", json_encode(['ts' => time(), 'terminal' => 1]) . "\n");
        try {
            check(TelemetryLog::series('usage-tool', 'terminal', 7, 'hour', $dir) !== [], 'without PIKKUMIES_SERVE, usage-tool must read normally');
            $sources = array_column(TelemetryLog::listSources($dir), 'source');
            check(in_array('usage-tool', $sources, true) && in_array('storage', $sources, true), 'without PIKKUMIES_SERVE, both sources must be listed: ' . json_encode($sources));
            putenv('PIKKUMIES_SERVE=1');
            check(TelemetryLog::series('usage-tool', 'terminal', 7, 'hour', $dir) === [], 'PIKKUMIES_SERVE=1 must make series(usage-tool) read back empty, like an unknown source');
            check(TelemetryLog::series('storage', 'a', 7, 'hour', $dir) !== [], 'PIKKUMIES_SERVE=1 must not touch a non-usage diagnostic source');
            $sources = array_column(TelemetryLog::listSources($dir), 'source');
            check(!in_array('usage-tool', $sources, true) && in_array('storage', $sources, true),
                'PIKKUMIES_SERVE=1 must drop every usage-* source from list but keep storage: ' . json_encode($sources));
        } finally {
            putenv('PIKKUMIES_SERVE'); // unset: must never leak into another test
            testRmTree($dir);
        }
    },
    'telemetry.php CLI: record/list/series/prune work through the real tool with its own test seams, never touching pikkumies/local' => function () {
        $on = ['privacy' => ['perfLogging' => true]];
        $off = ['privacy' => ['perfLogging' => false]];
        $dir = testTempDir('pk-tel-cli');
        try {
            list($code, $out) = telRun($dir, ['record', 'storage', '--json', '{"quota_used_percent":42}'], $on);
            check($code === 0 && strpos($out, '"written":true') !== false, 'record via CLI: ' . $out);
            list(, $out) = telRun($dir, ['list'], $on);
            check(strpos($out, '"source":"storage"') !== false, 'list via CLI: ' . $out);
            list(, $out) = telRun($dir, ['series', 'storage', 'quota_used_percent'], $on);
            check(strpos($out, '"value":42') !== false, 'series via CLI: ' . $out);
            list($code, $out) = telRun($dir, ['record', 'storage', '--json', '{"a":1}'], $off);
            check($code === 0 && strpos($out, '"written":false') !== false, 'record via CLI with perfLogging off: ' . $out);
            list($code, $out) = telRun($dir, ['prune', '--keep-days', '9999'], $on);
            check($code === 0 && strpos($out, '"files_removed":0') !== false, 'prune via CLI keeps a brand-new file: ' . $out);
            list($code) = telRun($dir, ['record'], $on); // no source name
            check($code === 1, 'record with no source must refuse, not crash');
        } finally {
            testRmTree($dir);
        }
    },
    'telemetry.php CLI: usage reads usage-tool/usage-skill/usage-u-<tool> back, sorted by calls, --kind filters, --human renders bars, perfLogging off gives an empty list with a note' => function () {
        $dir = testTempDir('pk-tel-usage-cli');
        try {
            $now = time();
            $today = gmdate('Ymd', $now);
            foreach (['usage-tool', 'usage-skill', 'usage-u-storage'] as $source) {
                mkdir(TelemetryLog::dir($dir) . "/$source", 0700, true);
            }
            file_put_contents(TelemetryLog::dir($dir) . "/usage-tool/$today.jsonl",
                json_encode(['ts' => $now, 'terminal' => 1]) . "\n" . json_encode(['ts' => $now, 'terminal' => 0]) . "\n" . json_encode(['ts' => $now, 'skill_view' => 1]) . "\n");
            file_put_contents(TelemetryLog::dir($dir) . "/usage-skill/$today.jsonl", json_encode(['ts' => $now, 'telemetry' => 1]) . "\n");
            file_put_contents(TelemetryLog::dir($dir) . "/usage-u-storage/$today.jsonl",
                json_encode(['ts' => $now, 'stats' => 1]) . "\n" . json_encode(['ts' => $now, 'stats' => 1]) . "\n" . json_encode(['ts' => $now, 'stats' => 0]) . "\n");
            $on = ['privacy' => ['perfLogging' => true]];
            list($code, $out) = telRun($dir, ['usage'], $on);
            $lines = array_slice(cliLines($out), 0, -1); // drop the trailing {"ok":...} status line
            check($code === 0 && count($lines) === 4, 'usage must list every name across all three kinds: ' . $out);
            check($lines[0]['kind'] === 'unit' && $lines[0]['name'] === 'storage' && $lines[0]['verb'] === 'stats' && $lines[0]['calls'] === 3 && $lines[0]['ok_pct'] === 66.7,
                'unit row (most calls, first): ' . json_encode($lines[0]));
            check($lines[1]['kind'] === 'tool' && $lines[1]['name'] === 'terminal' && $lines[1]['calls'] === 2, 'tool row: ' . json_encode($lines[1]));
            list(, $out) = telRun($dir, ['usage', '--kind', 'skill'], $on);
            $lines = array_slice(cliLines($out), 0, -1);
            check(count($lines) === 1 && $lines[0]['kind'] === 'skill' && $lines[0]['name'] === 'telemetry', '--kind skill must show only the skill row: ' . $out);
            list($code, $out) = telRun($dir, ['usage', '--kind', 'bogus'], $on);
            check($code === 1, '--kind bogus must be refused, not silently ignored');
            list(, $out) = telRun($dir, ['usage', '--human'], $on);
            check(strpos($out, '▰') !== false && strpos($out, 'storage stats') !== false, '--human must render a bar table: ' . $out);
            $off = ['privacy' => ['perfLogging' => false]];
            list($code, $out) = telRun($dir, ['usage'], $off);
            $lines = cliLines($out);
            check($code === 0 && end($lines)['note'] !== '' && count($lines) === 1, 'perfLogging off: empty list, a note, never an error: ' . $out);
        } finally {
            testRmTree($dir);
        }
    },
    'telemetry.php USAGE.md: the verbs table matches exactly what the tool answers to' => function () {
        $script = dirname(__DIR__) . '/pikkumies/tools/system/telemetry/telemetry.php';
        list(, $out) = runPhp($script, ['--verbs']);
        $code = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
        $usage = (string) file_get_contents(dirname($script) . '/USAGE.md');
        $table = substr($usage, 0, strpos($usage, '```sh')); // the verbs table only: a later table (Sources and fields) is not verbs
        preg_match_all('/^\| (\w[\w-]*) \|/m', $table, $m);
        $doc = array_values(array_diff($m[1], ['verb'])); // drop the header cell
        sort($code);
        sort($doc);
        check($code === $doc, 'USAGE.md lists ' . implode(',', $doc) . ' but the tool answers to ' . implode(',', $code));
    },
];
