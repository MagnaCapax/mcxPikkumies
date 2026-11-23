<?php
/**
 * End-use journeys of the run bound (ADR-0126, GH #30), through the real entry points in a private account tree: the
 * `pikkumies generate` every start runs (it writes the managed Hermes config), `pikkumies doctor` (the start gate's report),
 * the configure tool the agent uses, and the one-shot loop. Each case prints one "UCnn observed:" line, so the run's output
 * is the evidence of a testing-machine acceptance record. No model call is made: the provider key is a fixture and the
 * model is named in the config, so nothing is looked up or sent.
 */
require_once __DIR__ . '/ModelStartTest.php';
if (!function_exists('runBoundDrive')) {
    require __DIR__ . '/RunBoundTest.php';
}

/** A private account tree with a named model, so generate needs no endpoint: [dir]. */
function runBoundAcctWorld(array $run = null)
{
    $dir = modelStartWorld();
    file_put_contents("$dir/.pikkumiesKey", "endpoint=http://127.0.0.1:9/v1\nkey=fixture-key\n");
    chmod("$dir/.pikkumiesKey", 0600);
    runBoundAcctConfig($dir, $run);
    return $dir;
}

function runBoundAcctConfig($dir, array $run = null)
{
    $config = ['autoUpdate' => ['disabled' => true], 'cron' => ['disabled' => true],
        'provider' => ['keyFile' => "$dir/.pikkumiesKey", 'virsilipasKeyFile' => '', 'model' => 'fixture-model']];
    if ($run !== null) {
        $config['run'] = $run;
    }
    file_put_contents("$dir/local/config.php", '<?php return ' . var_export($config, true) . ';');
}

/** Run a real `pikkumies <args>` in the tree as that account: [exit code, output]. */
function runBoundAcctCli($dir, array $args)
{
    return testWithEnv(['HOME' => $dir, 'PIKKUMIES_CONFIG' => null, 'PIKKUMIES_DEPTH' => null, 'PIKKUMIES_ENDPOINT' => null,
        'PIKKUMIES_KEY' => null, 'OPENCLAW_STATE_DIR' => "$dir/openclaw"], function () use ($dir, $args) {
        return runPhp("$dir/bin/pikkumies", $args);
    });
}

function runBoundAcctManaged($dir)
{
    return (string) @file_get_contents("$dir/local/hermes-managed/config.yaml");
}

function runBoundAcctSay($id, $text)
{
    echo "$id observed: $text\n";
}

/** Generate in a fresh tree with this run section; the managed config text. */
function runBoundAcctGenerate(array $run = null)
{
    $dir = runBoundAcctWorld($run);
    try {
        list($code, $out) = runBoundAcctCli($dir, ['generate']);
        check($code === 0, "generate exited $code: " . $out);
        return [runBoundAcctManaged($dir), (string) @file_get_contents("$dir/local/hermes/config.yaml"), $out];
    } finally {
        testRmTree($dir);
    }
}

return [
    'UC01 an account started with the shipped defaults: `pikkumies generate` pins 100 model requests per turn in the managed config' => function () {
        list($managed) = runBoundAcctGenerate();
        check(strpos($managed, "\nagent:\n  max_turns: 100\n") !== false, 'no pin: ' . $managed);
        runBoundAcctSay('UC01', 'managed config.yaml holds "agent: max_turns: 100"');
    },
    'UC02 a deployer who sets run.maxTurns to 0 gets no pin at all' => function () {
        list($managed) = runBoundAcctGenerate(['maxTurns' => 0]);
        check(strpos($managed, 'max_turns') === false, 'a pin remained: ' . $managed);
        runBoundAcctSay('UC02', 'managed config.yaml has no max_turns line');
    },
    'UC03 a deployer who writes a non-integer run.maxTurns ("many") still gets the default 100, never an unbounded run' => function () {
        list($managed) = runBoundAcctGenerate(['maxTurns' => 'many']);
        check(strpos($managed, "\nagent:\n  max_turns: 100\n") !== false, 'fell to no bound: ' . $managed);
        runBoundAcctSay('UC03', 'managed config.yaml holds max_turns: 100');
    },
    'UC04 a deployer with longer tasks sets run.maxTurns to 250 and gets exactly that' => function () {
        list($managed) = runBoundAcctGenerate(['maxTurns' => 250]);
        check(strpos($managed, "\nagent:\n  max_turns: 250\n") !== false, $managed);
        check(substr_count($managed, 'max_turns') === 1, 'one pin only: ' . $managed);
        runBoundAcctSay('UC04', 'managed config.yaml holds max_turns: 250, once');
    },
    'UC05 an existing install whose managed config predates the bound gets the pin at its next start, and the rest of the file stays' => function () {
        $dir = runBoundAcctWorld();
        try {
            list($code, $out) = runBoundAcctCli($dir, ['generate']);
            check($code === 0, $out);
            $new = runBoundAcctManaged($dir);
            $old = str_replace("agent:\n  max_turns: 100\n", '', $new); // what an older Pikkumies wrote: no agent section
            check($old !== $new && strpos($old, 'max_turns') === false, 'could not build the old file');
            file_put_contents("$dir/local/hermes-managed/config.yaml", $old);
            list($code, $out) = runBoundAcctCli($dir, ['generate']);
            check($code === 0, $out);
            check(runBoundAcctManaged($dir) === $new, 'the next start did not restore the exact current managed file');
            runBoundAcctSay('UC05', 'an old managed config without the pin was rewritten at the next generate, byte for byte equal to a fresh one with max_turns: 100');
        } finally {
            testRmTree($dir);
        }
    },
    'UC06 a deployer\'s run.budgetSeconds never reaches Hermes: no run_budget_seconds in the managed or the user\'s config, so interactive turns and long windows get no time notice' => function () {
        list($managed, $user) = runBoundAcctGenerate(['maxTurns' => 100, 'budgetSeconds' => 5]);
        check(strpos($managed . $user, 'run_budget_seconds') === false, 'a wall-clock key reached Hermes: ' . $managed . $user);
        check(strpos($managed, 'max_turns: 100') !== false, 'the config is not the generated one: ' . $managed);
        runBoundAcctSay('UC06', 'with run.budgetSeconds=5 neither hermes-managed/config.yaml nor hermes/config.yaml names run_budget_seconds');
    },
    'UC07 the user\'s own Hermes config is seeded without the pin, so the pin is managed and Hermes refuses a change to it' => function () {
        list($managed, $user) = runBoundAcctGenerate();
        check($user !== '' && strpos($user, 'max_turns') === false, 'the user file carries a max_turns: ' . $user);
        check(strpos($managed, 'max_turns: 100') !== false, 'the pin is not managed');
        runBoundAcctSay('UC07', 'pin only in hermes-managed/config.yaml (' . strlen($user) . ' byte user config has no max_turns)');
    },
    'UC08 the agent asking the configure tool to set run.maxTurns is refused and the config file is unchanged' => function () {
        $dir = runBoundAcctWorld(['maxTurns' => 100]);
        try {
            $before = file_get_contents("$dir/local/config.php");
            $code = $out = null;
            testWithEnv(['HOME' => $dir, 'PIKKUMIES_CONFIG' => null, 'PIKKUMIES_DEPTH' => null], function () use ($dir, &$code, &$out) {
                list($code, $out) = runPhp("$dir/tools/system/pikkumies-configure/pikkumies-configure.php", ['set', 'run.maxTurns', '100000']);
            });
            check($code !== 0 && strpos($out, '"ok":false') !== false && strpos($out, 'run.maxTurns: this one is yours to change') !== false, "set was not refused: $code $out");
            check(file_get_contents("$dir/local/config.php") === $before, 'the config file changed');
            list($code, $out) = (function () use ($dir) {
                return runPhp("$dir/tools/system/pikkumies-configure/pikkumies-configure.php", ['set', 'run.budgetSeconds', '0']);
            })();
            check($code !== 0 && file_get_contents("$dir/local/config.php") === $before, 'run.budgetSeconds was settable');
            runBoundAcctSay('UC08', 'configure set run.maxTurns and run.budgetSeconds exit non-zero (a key only the person sets, ADR-0059: "this one is yours to change"), local/config.php byte for byte unchanged');
        } finally {
            testRmTree($dir);
        }
    },
    'UC09 a Hermes checkout without agent.max_turns is named by `pikkumies doctor` as one Pikkumies will not start; with the key present it reads ok' => function () {
        $dir = runBoundAcctWorld();
        try {
            // Every fact a start of this account checks: the Eternals one is on or off by the host's marker, so it is given too.
            $facts = array_merge(HermesInstall::configFacts(), HermesInstall::LAYER_FACTS, ['session.terminal_continue' => ['hermes_cli/config_defaults.py', '"terminal_continue":']]);
            foreach ($facts as $name => $fact) {
                if ($name === 'agent.max_turns') {
                    continue;
                }
                @mkdir(dirname("$dir/hermes/{$fact[0]}"), 0700, true);
                file_put_contents("$dir/hermes/{$fact[0]}", (is_file("$dir/hermes/{$fact[0]}") ? file_get_contents("$dir/hermes/{$fact[0]}") : '') . $fact[1] . "\n");
            }
            list($code, $out) = runBoundAcctCli($dir, ['doctor']);
            check(preg_match('/^hermes config keys: missing: .*agent\.max_turns.*\(Pikkumies will not start this Hermes\)/m', $out) === 1, "doctor did not name the key: $code " . $out);
            $line = preg_match('/^hermes config keys: .*$/m', $out, $m) ? $m[0] : '(no line)';
            $fact = HermesInstall::CONFIG_FACTS['agent.max_turns'];
            file_put_contents("$dir/hermes/{$fact[0]}", file_get_contents("$dir/hermes/{$fact[0]}") . $fact[1] . "\n");
            list($code, $out) = runBoundAcctCli($dir, ['doctor']);
            check(preg_match('/^hermes config keys: ok$/m', $out) === 1, "doctor still refused with the key present: $code " . $out);
            runBoundAcctSay('UC09', "doctor said '$line'; after the key was added: 'hermes config keys: ok'");
        } finally {
            testRmTree($dir);
        }
    },
    'UC10 a one-shot question whose run is not finished stops being resumed once run.budgetSeconds are used, says so, and keeps what it got to' => function () {
        list($code, $out, $err) = runBoundDrive(['maxTurns' => 100, 'budgetSeconds' => 1], $calls);
        check($calls === 1 && strpos($err, 'Stopped: this run used its time limit') !== false && strpos($err, 'is not finished') !== false, "$calls runs: $err");
        check($out === "first part\n", 'the answer so far was lost: ' . json_encode($out));
        runBoundAcctSay('UC10', "one Hermes run, stderr says it used its time limit and is not finished, stdout keeps 'first part'");
    },
    'UC11 the same unfinished one-shot with run.budgetSeconds at 0 is resumed as before: 1 run and 3 bounded continues' => function () {
        list($code, $out, $err) = runBoundDrive(['maxTurns' => 100, 'budgetSeconds' => 0], $calls);
        check($calls === 4 && strpos($err, 'Stopped:') === false, "$calls runs: $err");
        runBoundAcctSay('UC11', '4 Hermes runs, no stop line');
    },
    'UC12 a person with a generous budget (run.budgetSeconds 3600) is not stopped: the one-shot is resumed as before' => function () {
        list($code, $out, $err) = runBoundDrive(['maxTurns' => 100, 'budgetSeconds' => 3600], $calls);
        check($calls === 4 && strpos($err, 'Stopped:') === false, "$calls runs: $err");
        runBoundAcctSay('UC12', '4 Hermes runs inside the budget, no stop line');
    },
];
