<?php
/**
 * Live check of every shipped documentation source (ADR-0038). Not part of tests/run.php or CI: it needs
 * network access and downloads real pages. Run by hand:
 *
 *   php tests/testing/sources/check-sources.php [--out DIR] [source ...]
 *
 * It copies this checkout into a temporary directory (nothing here is touched), forces each host family
 * through a PIKKUMIES_CONFIG overlay so that family's sources are in scope, runs the memory unit's
 * `refresh` for them, and prints one line per source. Each source is refreshed once; app manuals (scope
 * all) under the generic family. Results: DIR/results.jsonl (default: a new directory under the system temp dir).
 */
$repo = dirname(__DIR__, 3);
$args = array_slice($argv, 1);
$out = sys_get_temp_dir() . '/pikkumies-check-sources-' . gmdate('Ymd-His');
if (($i = array_search('--out', $args, true)) !== false) {
    $out = (string) $args[$i + 1];
    array_splice($args, $i, 2);
}
$only = $args;
$work = sys_get_temp_dir() . '/pikkumies-check-sources-work-' . getmypid();
@mkdir($work, 0700, true);
exec('cp -a ' . escapeshellarg($repo) . ' ' . escapeshellarg("$work/checkout") . ' 2>&1');
@unlink("$work/checkout/pikkumies/local/host.json"); // a cached detection must not decide the family
if (!is_dir("$work/checkout/pikkumies")) {
    fwrite(STDERR, "could not copy the checkout to $work\n");
    exit(1);
}
@mkdir("$work/home", 0700, true);
@mkdir($out, 0700, true);
$memory = "$work/checkout/pikkumies/tools/memory/pikkumies/pikkumies.php";

/** Run the memory unit with HOME and the overlay set; the parsed JSON lines. */
function checkSourcesRun($memory, $work, $family, array $argv)
{
    file_put_contents("$work/overlay.php", "<?php\nreturn ['host' => ['family' => " . var_export($family, true) . ", 'stack' => 'none']];\n");
    $cmd = 'HOME=' . escapeshellarg("$work/home") . ' PIKKUMIES_CONFIG=' . escapeshellarg("$work/overlay.php")
        . ' php ' . escapeshellarg($memory) . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    exec($cmd, $lines);
    return array_values(array_filter(array_map(function ($l) {
        return json_decode($l, true);
    }, $lines), 'is_array'));
}

// Which family puts each fetchable, switched-on source in scope.
$plan = [];
foreach (checkSourcesRun($memory, $work, 'generic', ['sources', '--limit', '500']) as $src) {
    if (!isset($src['name']) || $src['type'] === 'link' || $src['status'] === 'off' || ($only && !in_array($src['name'], $only, true))) {
        continue;
    }
    $scopes = array_map('trim', explode(',', (string) $src['scope']));
    $family = in_array('all', $scopes, true) || in_array('generic', $scopes, true) ? 'generic' : $scopes[0];
    $plan[$family][] = $src['name'];
}
$failed = 0;
$fh = fopen("$out/results.jsonl", 'w');
foreach ($plan as $family => $names) {
    foreach (checkSourcesRun($memory, $work, $family, array_merge(['refresh'], $names)) as $r) {
        if (!isset($r['source'])) {
            continue;
        }
        $r['family'] = $family;
        $r['ts'] = gmdate('c');
        fwrite($fh, json_encode($r) . "\n");
        printf("%-4s %-22s %-16s %s\n", $r['ok'] ? 'OK' : 'FAIL', $r['source'], $family, $r['message']);
        $failed += $r['ok'] ? 0 : 1;
    }
}
fclose($fh);
exec('rm -rf ' . escapeshellarg($work));
echo "results: $out/results.jsonl, $failed failed\n";
exit($failed ? 1 : 0);
