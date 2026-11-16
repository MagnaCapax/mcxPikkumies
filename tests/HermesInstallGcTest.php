<?php
/**
 * lib/HermesInstall.php: the daily update has upstream's own `hermes pm gc` reclaim superseded dependency generations
 * (ADR-0114). Hermetic: a temp pikkumies/ with a complete-looking tree whose .hermes/bin/hermes is a fake that logs how it
 * was called; fake nice and ionice on PATH log that they wrapped the call. Never the network, never a real Hermes.
 */

/** A temp world: root (pikkumies/), bin (fakes, first on PATH), log (what the fakes saw). $gcExit: the fake gc's exit code. */
function hermesGcWorld($gcExit = 0)
{
    $t = sys_get_temp_dir() . '/pk-gc-' . bin2hex(random_bytes(6));
    $w = ['t' => $t, 'root' => "$t/pikkumies", 'bin' => "$t/bin", 'log' => "$t/fake.log"];
    mkdir($w['bin'], 0700, true);
    mkdir($w['root'] . '/hermes/.git', 0700, true);
    mkdir($w['root'] . '/hermes/.hermes/bin', 0700, true);
    mkdir($w['root'] . '/include', 0700, true);
    touch($w['root'] . '/include/.hermes-ready');
    $log = 'file_put_contents(getenv("PK_FAKE_LOG"), %s . "\n", FILE_APPEND);';
    $fake = function ($path, $body) {
        file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n" . $body);
        chmod($path, 0755);
    };
    $fake($w['root'] . '/hermes/.hermes/bin/hermes', sprintf($log, '"hermes " . implode(" ", array_slice($argv, 1)) . " | HOME=" . getenv("HERMES_HOME") . " | CWD=" . getcwd() . " | NOCONFIG=" . getenv("UV_NO_CONFIG")')
        . 'echo "gc: removed 2, kept 9; removed 1 dependency generations, 0 PM runtime generations, 0 install state dir(s) of deleted checkouts\n"; exit(' . (int) $gcExit . ');');
    // nice -n 19 CMD... and ionice -c 3 CMD...: log, then run CMD.
    foreach (['nice', 'ionice'] as $name) {
        $fake($w['bin'] . "/$name", sprintf($log, '"' . $name . ' " . $argv[1] . " " . $argv[2]') . '$c = 0; passthru(implode(" ", array_map("escapeshellarg", array_slice($argv, 3))), $c); exit($c);');
    }
    return $w;
}

function hermesGcIn(array $w, callable $fn, array $env = [])
{
    HermesInstall::$root = $w['root'];
    try {
        return testWithEnv(array_merge(['PATH' => $w['bin'] . ':' . getenv('PATH'), 'PK_FAKE_LOG' => $w['log'], 'PIKKUMIES_SKIP_HERMES' => null, 'HERMES_RUNTIME_DIR' => null], $env), $fn);
    } finally {
        HermesInstall::$root = null;
        exec('rm -rf ' . escapeshellarg($w['t']));
    }
}

function hermesGcSeen(array $w)
{
    return is_file($w['log']) ? (string) file_get_contents($w['log']) : '';
}

const HERMES_GC_INSTALLED = 'installed (pikkumies/hermes; updates: `pikkumies run update`)';

return [
    'the daily update runs upstream pm gc on a complete install, at idle priority, with our HERMES_HOME, from the tree' => function () {
        $w = hermesGcWorld();
        hermesGcIn($w, function () use ($w) {
            $msg = HermesInstall::ensure(true);
            check($msg === HERMES_GC_INSTALLED, 'the status line must not change: ' . $msg);
            $seen = hermesGcSeen($w);
            $home = $w['root'] . '/local/hermes';
            check(substr_count($seen, 'hermes pm gc | HOME=' . $home . ' | CWD=' . $w['root'] . '/hermes | NOCONFIG=1') === 1, 'one upstream gc with the bootstrap environment, from the tree: ' . $seen);
            check(strpos($seen, 'nice -n 19') !== false && strpos($seen, 'ionice -c 3') !== false, 'nobody waits on it, so it runs at idle priority: ' . $seen);
            check(strpos((string) @file_get_contents($w['root'] . '/local/install.log'), 'removed 1 dependency generations') !== false, 'gc output goes to the install log');
        });
    },
    'a person-started ensure runs no gc' => function () {
        $w = hermesGcWorld();
        hermesGcIn($w, function () use ($w) {
            check(HermesInstall::ensure(false) === HERMES_GC_INSTALLED, 'status line');
            check(strpos(hermesGcSeen($w), 'pm gc') === false, 'a person waiting must not wait on gc: ' . hermesGcSeen($w));
        });
    },
    'no gc while an update holds the Hermes lock' => function () {
        $w = hermesGcWorld();
        hermesGcIn($w, function () use ($w) {
            $update = HermesInstall::lock(true);
            check($update !== null, 'test setup: the exclusive lock');
            try {
                check(HermesInstall::ensure(true) === HERMES_GC_INSTALLED, 'status line');
            } finally {
                fclose($update);
            }
            check(strpos(hermesGcSeen($w), 'pm gc') === false, 'gc must skip the day an update holds the tree: ' . hermesGcSeen($w));
        });
    },
    'a failing gc changes nothing the update reports' => function () {
        $w = hermesGcWorld(3);
        hermesGcIn($w, function () use ($w) {
            check(HermesInstall::ensure(true) === HERMES_GC_INSTALLED, 'status line');
            check(strpos(hermesGcSeen($w), 'pm gc') !== false, 'gc was tried');
        });
    },
    'no gc without a complete install' => function () {
        $w = hermesGcWorld();
        hermesGcIn($w, function () use ($w) {
            unlink($w['root'] . '/include/.hermes-ready');
            HermesInstall::ensure(true);
            check(strpos(hermesGcSeen($w), 'pm gc') === false, 'nothing to collect without a complete install: ' . hermesGcSeen($w));
        }, ['PIKKUMIES_SKIP_HERMES' => '1']);
    },
];
