<?php
/**
 * Live check of every shipped documentation source (ADR-0038). Not part of tests/run.php or CI: it needs
 * network access and downloads real pages. Run by hand:
 *
 *   php tests/testing/sources/check-sources.php [--out DIR] [source ...]
 *
 * It copies the tracked files of this checkout into a new temporary directory (nothing here is touched, and
 * local/ with its keys and caches is not copied), forces each host family through a PIKKUMIES_CONFIG overlay
 * so that family's sources are in scope, runs the memory unit's `refresh` for them and prints one line per
 * source. Each source is refreshed once; app manuals (scope all) under the generic family. A `link` source
 * is fetched once and passes on an HTTP status below 400. A source switched off in the shipped list is
 * printed as SKIP. Results: DIR/results.jsonl (default: a new directory under the system temp dir).
 * Exit 1 when any source fails or when the check could not see the whole list.
 */
$repo = dirname(__DIR__, 3);
require_once "$repo/pikkumies/lib/Http.php";
$args = array_slice($argv, 1);
$out = sys_get_temp_dir() . '/pikkumies-check-sources-' . gmdate('Ymd-His');
if (($i = array_search('--out', $args, true)) !== false) {
    if (!isset($args[$i + 1])) {
        fwrite(STDERR, "--out needs a directory\n");
        exit(2);
    }
    $out = (string) $args[$i + 1];
    array_splice($args, $i, 2);
}
$only = $args;
$work = sys_get_temp_dir() . '/pikkumies-check-sources-work-' . bin2hex(random_bytes(6));
if (!mkdir("$work/home", 0700, true) || (!is_dir($out) && !mkdir($out, 0700, true))) {
    fwrite(STDERR, "could not create $work or $out\n");
    exit(1);
}
exec('git -C ' . escapeshellarg($repo) . ' ls-files -z', $lsOut, $code);
foreach (array_filter(explode("\0", implode("\n", $lsOut)), 'strlen') as $file) {
    if (is_file("$repo/$file")) {
        @mkdir(dirname("$work/checkout/$file"), 0700, true);
        copy("$repo/$file", "$work/checkout/$file");
    }
}
$memory = "$work/checkout/pikkumies/tools/memory/pikkumies/pikkumies.php";
if ($code !== 0 || !is_file($memory)) {
    fwrite(STDERR, "could not copy the tracked files of $repo to $work\n");
    exit(1);
}

/** Run the memory unit with HOME and the overlay set: [records, status line or null]. */
function checkSourcesRun($memory, $work, $family, array $argv)
{
    file_put_contents("$work/overlay.php", "<?php\nreturn ['host' => ['family' => " . var_export($family, true) . ", 'stack' => 'none']];\n");
    chmod("$work/overlay.php", 0600);
    $cmd = 'HOME=' . escapeshellarg("$work/home") . ' PIKKUMIES_CONFIG=' . escapeshellarg("$work/overlay.php")
        . ' php ' . escapeshellarg($memory) . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    exec($cmd, $lines);
    $records = [];
    $status = null;
    foreach ($lines as $l) {
        $j = json_decode($l, true);
        if (is_array($j) && array_key_exists('ok', $j) && !isset($j['source'])) {
            $status = $j;
        } elseif (is_array($j)) {
            $records[] = $j;
        }
    }
    return [$records, $status];
}

$results = [];
$report = function ($ok, $source, $family, $message) use (&$results) {
    $results[] = ['ok' => $ok, 'source' => $source, 'family' => $family, 'message' => $message, 'ts' => gmdate('c')];
    printf("%-4s %-22s %-16s %s\n", $ok === null ? 'SKIP' : ($ok ? 'OK' : 'FAIL'), $source, $family, $message);
};
// Which family puts each fetchable, switched-on source in scope; links are fetched directly.
list($sources, $status) = checkSourcesRun($memory, $work, 'generic', ['sources', '--limit', '0', '--fields', 'name,type,status,scope,url']);
if (!$status || empty($status['ok']) || !empty($status['truncated']) || count($sources) !== (int) $status['count'] || !$sources) {
    fwrite(STDERR, 'could not read the whole source list: ' . json_encode($status) . "\n");
    exit(1);
}
$plan = [];
foreach ($sources as $src) {
    if ($only && !in_array($src['name'], $only, true)) {
        continue;
    }
    if ($src['status'] === 'off') {
        $report(null, $src['name'], '-', 'switched off in the shipped list');
    } elseif ($src['type'] === 'link') {
        $r = Http::request('GET', (string) $src['url'], ['timeout' => 20, 'max' => 262144]);
        $ok = $r['code'] > 0 && $r['code'] < 400;
        $report($ok, $src['name'], 'link', 'HTTP ' . $r['code'] . ($r['error'] !== '' && !$ok ? ' ' . $r['error'] : ''));
    } else {
        $scopes = array_map('trim', explode(',', (string) $src['scope']));
        $plan[in_array('all', $scopes, true) || in_array('generic', $scopes, true) ? 'generic' : $scopes[0]][] = $src['name'];
    }
}
foreach (array_diff($only, array_column($sources, 'name')) as $unknown) {
    $report(false, $unknown, '-', 'not in the shipped list');
}
foreach ($plan as $family => $names) {
    list($rows) = checkSourcesRun($memory, $work, $family, array_merge(['refresh', '--limit', '0'], $names));
    $seen = [];
    foreach ($rows as $r) {
        if (isset($r['source'])) {
            $seen[] = $r['source'];
            $report((bool) $r['ok'], $r['source'], $family, (string) $r['message']);
        }
    }
    foreach (array_diff($names, $seen) as $missing) {
        $report(false, $missing, $family, 'refresh printed no result for this source');
    }
}
file_put_contents("$out/results.jsonl", implode('', array_map(function ($r) {
    return json_encode($r) . "\n";
}, $results)));
exec('rm -rf ' . escapeshellarg($work));
$failed = count(array_filter($results, function ($r) {
    return $r['ok'] === false;
}));
echo "results: $out/results.jsonl, " . count($results) . " checked, $failed failed\n";
exit($failed ? 1 : 0);
