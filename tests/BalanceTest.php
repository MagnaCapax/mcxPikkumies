<?php
/**
 * balance.php end to end, as a real subprocess (Cli::run calls exit(), so it can never be require()'d
 * in-process -- same reason every other unit's CLI contract is tested via runPhp(), tests/run.php).
 * The deep algorithm coverage lives in BalancePlanTest.php and BalanceGatherTest.php, which call the
 * pure classes directly; this file only proves the wiring: argument validation, --human, and that a
 * real run with no services configured and no reachable local client degrades to an empty, valid
 * plan instead of crashing.
 */

function balTool()
{
    return dirname(__DIR__) . '/pikkumies/tools/seedbox/balance/balance.php';
}

return [
    'balance.php: a bad verb exits 2 with a valid ok:false line (same contract every unit answers to, CliContractTest)' => function () {
        list($code, $out) = runPhp(balTool(), ['frobnicate']);
        $l = cliLines($out);
        check($code === 2 && end($l)['ok'] === false, 'bad verb did not fail the standard way: ' . $out);
    },

    'balance.php: --goal rejects anything but free or upload' => function () {
        list($code, $out) = runPhp(balTool(), ['plan', '--goal', 'moon']);
        $l = cliLines($out);
        check($code === 1 && end($l)['ok'] === false && strpos(end($l)['error'], '--goal') !== false, '--goal moon should be refused: ' . $out);
    },

    'balance.php: --max-moves rejects zero, negative and non-numeric values' => function () {
        foreach (['0', '-1', 'many'] as $bad) {
            list($code, $out) = runPhp(balTool(), ['plan', '--max-moves', $bad]);
            $l = cliLines($out);
            check($code === 1 && end($l)['ok'] === false, "--max-moves $bad should be refused: $out");
        }
    },

    'balance.php: plan with no services configured and no reachable local client answers a valid, empty plan, never a crash' => function () {
        $home = testTempDir('pk-balance-home');
        try {
            list($code, $out) = testWithEnv(['HOME' => $home], function () {
                return runPhp(balTool(), ['plan']);
            });
            $l = cliLines($out);
            $status = end($l);
            check($code === 0, "a reachable-nothing environment must still exit 0: $out");
            check(is_array($status) && $status['ok'] === true, "expected a clean ok:true status line: $out");
            check($status['moves_proposed'] === 0, "no services means no moves could ever be proposed: $out");
            check($status['goal'] === 'free', "default goal must be 'free': $out");
        } finally {
            testRmTree($home);
        }
    },

    'balance.php: --human never prints raw JSON' => function () {
        $home = testTempDir('pk-balance-home');
        try {
            list($code, $out) = testWithEnv(['HOME' => $home], function () {
                return runPhp(balTool(), ['plan', '--human']);
            });
            check($code === 0 && strpos($out, '{') === false, "--human output must never contain raw JSON: $out");
        } finally {
            testRmTree($home);
        }
    },
];
