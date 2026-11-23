<?php
if (!function_exists('modelStartWorld')) {
    require __DIR__ . '/ModelStartTest.php';
}

/**
 * The runtime fixture of ModelStartTest with a real git checkout, the files the key and layer checks read, and a launcher that
 * answers `hooks test` and `config set` like Hermes does ($guards true) or says nothing ($guards false).
 */
function doctorGateWorld($guards)
{
    if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
        skip('git is not installed here');
    }
    $dir = modelStartWorld();
    @unlink("$dir/hermes/.git");
    // The Eternals fact too: a test host with the Eternals marker adds it to the start check's keys (HermesInstall::configFacts()).
    foreach (array_merge(HermesInstall::CONFIG_FACTS, HermesInstall::LAYER_FACTS, ['session.terminal_continue' => ['hermes_cli/config_defaults.py', '"terminal_continue":']]) as $fact) {
        @mkdir(dirname("$dir/hermes/{$fact[0]}"), 0700, true);
        file_put_contents("$dir/hermes/{$fact[0]}", (is_file("$dir/hermes/{$fact[0]}") ? file_get_contents("$dir/hermes/{$fact[0]}") : '') . $fact[1] . "\n");
    }
    $g = 'git -C ' . escapeshellarg("$dir/hermes") . ' -c user.name=t -c user.email=t@t -c commit.gpgsign=false ';
    exec('git init -q ' . escapeshellarg("$dir/hermes") . ' && ' . $g . 'add -A && ' . $g . 'commit -q -m one', $out, $code);
    check($code === 0, 'could not make the Hermes checkout fixture');
    $answers = $guards
        ? 'if ($argv[1] === "hooks") { echo "{\"action\": \"block\", \"message\": \"' . HermesInstall::GUARD_BLOCK . '\"}\n"; }
           if ($argv[1] === "config") { echo "Cannot set it: ' . HermesInstall::MANAGED_REFUSAL . '\n"; exit(1); }'
        : '';
    file_put_contents("$dir/hermes/.hermes/bin/hermes", '#!' . PHP_BINARY . "\n<?php\n" . $answers . "\n");
    chmod("$dir/hermes/.hermes/bin/hermes", 0700);
    return $dir;
}

function doctorGateRun($dir)
{
    $result = null;
    testWithEnv(['HOME' => $dir, 'PIKKUMIES_CONFIG' => null, 'PIKKUMIES_DEPTH' => null, 'PIKKUMIES_ENDPOINT' => null, 'PIKKUMIES_KEY' => null],
        function () use ($dir, &$result) {
            $result = runPhp("$dir/bin/pikkumies", ['doctor']);
        });
    return $result;
}

return [
    'doctor (GH #17): a start check that fails is said in the doctor output, with the problem the start prints, and the exit code stays 0' => function () {
        $dir = doctorGateWorld(false);
        try {
            list($code, $out) = doctorGateRun($dir);
            check($code === 0, "doctor exit $code");
            check(strpos($out, 'Not starting: pikkumies/hermes changed and fails the Pikkumies checks: the delete guard did not block mkfs in `hermes hooks test` (exit 0)') !== false, "doctor: $out");
            check(!is_file("$dir/local/hermes-verified.json"), 'a failing check was recorded as verified');
        } finally {
            testRmTree($dir);
        }
    },
    'doctor (GH #17): a launcher that cannot run is said in the doctor output' => function () {
        $dir = doctorGateWorld(true);
        try {
            chmod("$dir/hermes/.hermes/bin/hermes", 0600);
            list($code, $out) = doctorGateRun($dir);
            check($code === 0 && strpos($out, 'Not starting: pikkumies/hermes is installed but its launcher (.hermes/bin/hermes) is missing or not executable') !== false, "doctor: $code $out");
        } finally {
            testRmTree($dir);
        }
    },
    'doctor (GH #17): when the start gate passes the doctor prints no start line and the gate result is recorded like a start would' => function () {
        $dir = doctorGateWorld(true);
        try {
            list($code, $out) = doctorGateRun($dir);
            check($code === 0 && strpos($out, 'hermes config keys: ok') !== false, "doctor: $code $out");
            check(strpos($out, 'pikkumies/hermes changed') === false && strpos($out, 'launcher (.hermes') === false, "a passing gate printed a refusal: $out");
            check(is_file("$dir/local/hermes-verified.json"), 'the passing check was not recorded');
        } finally {
            testRmTree($dir);
        }
    },
];
