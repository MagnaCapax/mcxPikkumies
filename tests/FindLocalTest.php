<?php
/**
 * FindLocal::discover() and ::run() (tools/torrent-clients/find/FindLocal.php) against throwaway fake units:
 * tiny scripts that print JSON Lines like a real torrent-client unit's `list`, without touching rtorrent,
 * qbittorrent or deluge at all.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/ToolProc.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/find/FindLocal.php';

/** A fixture root with one unit directory per key of $units (name => PHP source for its `list` answer). */
function findLocalFixture(array $units)
{
    $root = testTempDir('pk-find-local');
    mkdir("$root/lib", 0700); // never discovered: lib/lib.php does not exist
    foreach ($units as $name => $src) {
        mkdir("$root/$name", 0700);
        file_put_contents("$root/$name/$name.php", $src);
    }
    return $root;
}

return [
    'findlocal: discover() finds "<name>/<name>.php" directories only, skips lib and an excluded name' => function () {
        $root = findLocalFixture(['alpha' => '<?php', 'beta' => '<?php']);
        try {
            mkdir("$root/nounit"); // a directory with no matching script: not a unit
            mkdir("$root/notthis");
            file_put_contents("$root/notthis/notthis.php", '<?php');
            check(FindLocal::discover($root, ['lib', 'notthis']) === ['alpha' => "$root/alpha/alpha.php", 'beta' => "$root/beta/beta.php"],
                'discover() must list exactly alpha and beta, in order, excluding lib and notthis');
        } finally {
            testRmTree($root);
        }
    },
    'findlocal: run() runs every unit\'s `list` concurrently and decodes its JSON Lines' => function () {
        $root = findLocalFixture([
            'alpha' => '<?php echo json_encode(["id"=>str_repeat("a",40),"name"=>"Alpha"]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";',
            'beta' => '<?php echo json_encode(["id"=>str_repeat("b",40),"name"=>"Beta"]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";',
        ]);
        try {
            $r = FindLocal::run(FindLocal::discover($root), 5);
            check($r['busy'] === [], 'nothing should be busy: ' . json_encode($r));
            check(count($r['rows']) === 2, 'both units must answer: ' . json_encode($r));
            check($r['rows']['alpha'][0]['name'] === 'Alpha' && $r['rows']['alpha'][1]['ok'] === true, 'alpha: ' . json_encode($r['rows']['alpha']));
            check($r['rows']['beta'][0]['name'] === 'Beta', 'beta: ' . json_encode($r['rows']['beta']));
        } finally {
            testRmTree($root);
        }
    },
    'findlocal: a client past its deadline is terminated and reported busy, without holding up a fast one' => function () {
        $root = findLocalFixture([
            'fast' => '<?php echo json_encode(["ok"=>true,"count"=>0]),"\n";',
            'slow' => '<?php sleep(10); echo json_encode(["ok"=>true,"count"=>0]),"\n";',
        ]);
        try {
            $t0 = microtime(true);
            $r = FindLocal::run(FindLocal::discover($root), 1);
            $elapsed = microtime(true) - $t0;
            check($elapsed < 5, "a 1-second timeout must not make run() wait anywhere near the slow client's 10-second sleep: {$elapsed}s");
            check($r['busy'] === ['slow'], 'the slow client must be reported busy: ' . json_encode($r));
            check(isset($r['rows']['fast']) && !isset($r['rows']['slow']), 'the fast client must still answer: ' . json_encode($r));
        } finally {
            testRmTree($root);
        }
    },
    'findlocal: a client that ignores SIGTERM is still gone after the grace window (SIGKILL on its process group)' => function () {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            skip('pcntl is not loaded here; FindLocal::run() itself never calls a pcntl function (posix_kill only)');
        }
        $root = findLocalFixture(['stubborn' => '<?php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
$deadline = time() + 30; // longer than any timeout this test uses; proves SIGKILL, not a slow natural exit
while (time() < $deadline) { usleep(50000); }
']);
        try {
            $t0 = microtime(true);
            $r = FindLocal::run(FindLocal::discover($root), 1);
            $elapsed = microtime(true) - $t0;
            check($elapsed < FindLocal::GRACE_SEC + 5, "SIGTERM-ignoring client must still be gone well inside the grace window, not the full 30s sleep: {$elapsed}s");
            check($r['busy'] === ['stubborn'], 'a SIGTERM-ignoring client must still be reported busy: ' . json_encode($r));
            // No separate "is it still alive" check is needed: run() only returns once proc_close() has reaped
            // the child (proc_close() blocks until the process actually exits), so returning this quickly with
            // the 30-second sleep still in its future is itself the proof that SIGKILL, not a natural exit, ended it.
        } finally {
            testRmTree($root);
        }
    },
    'findlocal: an empty unit list runs nothing and returns empty rows and busy' => function () {
        check(FindLocal::run([], 5) === ['rows' => [], 'busy' => []], 'no units means nothing to run');
    },
    'findlocal: a unit that answers ok:false (e.g. "not installed") is still decoded like any other answer' => function () {
        $root = findLocalFixture(['gone' => '<?php echo json_encode(["ok"=>false,"error"=>"gone is not installed (test)","hint"=>"n/a"]),"\n";']);
        try {
            $r = FindLocal::run(FindLocal::discover($root), 5);
            check($r['rows']['gone'][0]['ok'] === false && strpos($r['rows']['gone'][0]['error'], 'not installed') !== false,
                'FindLocal itself must not interpret the answer, only decode it: ' . json_encode($r));
        } finally {
            testRmTree($root);
        }
    },
];
