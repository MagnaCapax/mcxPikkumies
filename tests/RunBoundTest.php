<?php
/**
 * The bound on every Hermes run (ADR-0126, GH #30): agent.max_turns pinned in the managed config from run.maxTurns (and
 * no Hermes wall-clock key), its fact that refuses a Hermes without it, no setting by the agent, and no resume of an
 * unfinished one-shot after run.budgetSeconds. Expected values come from the issue's acceptance (a run
 * that cannot finish ends inside a written-down bound), not from the code's own output.
 */
require_once __DIR__ . '/haltRunSupport.php';
if (!function_exists('userCfgWorld')) {
    require __DIR__ . '/HermesUserConfigTest.php';
}
if (!function_exists('haltRunRenderDrive')) {
    require_once __DIR__ . '/HaltRunRenderTest.php';
}

if (!function_exists('runBoundDrive')) { // guarded: tests/RunBoundAcceptanceTest.php loads this file too
/**
 * A run of the one-shot loop against the fake Hermes (each Hermes run takes a second) with local/config.php's run section.
 * The judge's call to the fake light model sometimes never leaves the sandbox host (0 requests, on untouched main too: the
 * HaltRun tests there fail the same way), and a run that was never judged says nothing about the bound, so it is run again,
 * up to 3 times; a run that reached the model is never repeated.
 */
function runBoundDrive(array $run, &$calls)
{
    for ($try = 1; $try <= 3; $try++) {
        $r = runBoundDriveOnce($run, $calls, $asked);
        if ($asked > 0) {
            break;
        }
    }
    return $r;
}

function runBoundDriveOnce(array $run, &$calls, &$asked)
{
    $fakeModel = testTempDir('pk-runbound-model');
    list($proc, $port) = haltRunFakeModel($fakeModel);
    $tree = haltRunTree($port);
    try {
        list($continueReply) = haltRunReplies();
        file_put_contents("$fakeModel/replies.json", json_encode([$continueReply]));
        file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['provider' =>
            ['endpoint' => "http://127.0.0.1:$port/v1", 'model' => 'm', 'keyFile' => "$tree/key"], 'run' => $run], true) . ";\n");
        putenv('PK_FAKE_HERMES_DIR=' . $tree);
        file_put_contents("$tree/plan.json", json_encode([['events' => haltRunRenderEvents('first part'), 'code' => 0, 'sleep' => 1]]));
        $r = haltRunRenderDrive($tree, haltRunFakeHermes($tree), 'fakesess', false, ['chat', '--query=please fix the thing', '--format=stream-json']);
        $calls = haltRunCalls($tree);
        $asked = count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen'));
        $r[2] .= " [light-model requests: $asked]";
        return $r;
    } finally {
        putenv('PK_FAKE_HERMES_DIR');
        proc_terminate($proc, 9);
        proc_close($proc);
        testRmTree($tree);
        testRmTree($fakeModel);
    }
}
}

return [
    'run bound: the managed Hermes config carries the step limit by default and never a wall-clock key, and the user\'s own file does not pin it' => function () {
        $w = userCfgWorld();
        try {
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                $managed = (string) file_get_contents($w['local'] . '/hermes-managed/config.yaml');
                check(strpos($managed, "agent:\n  max_turns: 100\n") !== false, 'the managed file must bound the run: ' . $managed);
                check(strpos($managed, 'run_budget_seconds') === false, 'a Hermes wall-clock key would reach every interactive turn and Eternal window: ' . $managed);
                check(substr_count($managed, "\nagent:\n") === 1, 'one agent: section in the managed file (a second would replace the first): ' . $managed);
                $user = (string) file_get_contents($w['home'] . '/config.yaml');
                check(strpos($user, 'max_turns') === false && strpos($user, 'run_budget_seconds') === false, 'the seed is the user\'s, the bound is pinned: ' . $user);
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'run bound: a deployer sets both numbers in local/config.php, 0 turns one off, and a value that is not a whole number is the default and never "no bound"' => function () {
        $y = function ($run) {
            return RunBound::yaml(['run' => $run]);
        };
        check($y(['maxTurns' => 300, 'budgetSeconds' => 3600]) === "agent:\n  max_turns: 300\n", $y(['maxTurns' => 300, 'budgetSeconds' => 3600]));
        check($y(['maxTurns' => 0, 'budgetSeconds' => 1200]) === '', 'steps off writes no section: ' . $y(['maxTurns' => 0, 'budgetSeconds' => 1200]));
        check(RunBound::budgetSeconds(['run' => ['budgetSeconds' => 3600]]) === 3600 && RunBound::budgetSeconds(['run' => ['budgetSeconds' => 0]]) === 0, 'the time limit is read as set, 0 = off');
        foreach (['100', -5, 1.5, null, true, [], 'unlimited'] as $bad) {
            check($y(['maxTurns' => $bad]) === "agent:\n  max_turns: 100\n" && RunBound::budgetSeconds(['run' => ['budgetSeconds' => $bad]]) === 1200, 'a typo must not remove the bound: ' . json_encode($bad));
        }
        check(RunBound::yaml([]) === "agent:\n  max_turns: 100\n", 'no run section: the default');
    },
    'run bound: the agent cannot change it (not a settable key), and a Hermes without the step key is refused like any other pin' => function () {
        check(Config::type('run.maxTurns') === null && Config::type('run.budgetSeconds') === null, 'run.* must not be settable by the configure tool');
        check(isset(HermesInstall::CONFIG_FACTS['agent.max_turns']), 'agent.max_turns must be a fact, so a Hermes without it is refused');
        check(!isset(HermesInstall::CONFIG_FACTS['agent.run_budget_seconds']), 'the wall-clock key is not pinned, so it is no fact');
    },
    'run bound: an unfinished one-shot is not resumed once run.budgetSeconds have passed; the same run with the bound off is resumed 3 times' => function () {
        list($code, $out, $err) = runBoundDrive(['maxTurns' => 100, 'budgetSeconds' => 1], $calls);
        check($calls === 1, 'after the time is used there is no resume: ' . $calls . ' Hermes runs. stderr: ' . $err);
        check(strpos($err, 'Stopped: this run used its time limit') !== false && strpos($err, 'is not finished') !== false, 'the stop must say it did not finish: ' . $err);
        check($out === "first part\n", 'the answer it got to is still printed: ' . json_encode($out));
        // Negative control: the same plan with the time bound off runs the guard's 1 + 3 bounded continues.
        list(, , $err) = runBoundDrive(['maxTurns' => 100, 'budgetSeconds' => 0], $calls);
        check($calls === 4 && strpos($err, 'Stopped:') === false, 'with the bound off the old behaviour stays: ' . $calls . ' runs. ' . $err);
    },
];
