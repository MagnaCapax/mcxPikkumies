<?php
if (!function_exists('coProviderStart')) {
    require __DIR__ . '/CoreTest.php';
}

/** A private runtime tree: a ready launcher records any execution; only the real PHP entrypoint is exercised. */
function modelStartWorld()
{
    $dir = testTempDir('model-start');
    foreach (['bin', 'lib', 'tools', 'hooks', 'doctrine', 'config'] as $part) {
        exec('cp -R ' . escapeshellarg(dirname(__DIR__) . '/pikkumies/' . $part) . ' ' . escapeshellarg($dir . '/' . $part), $out, $code);
        check($code === 0, 'could not copy the runtime fixture');
    }
    mkdir("$dir/hermes/.hermes/bin", 0700, true);
    mkdir("$dir/include", 0700);
    mkdir("$dir/local", 0700);
    file_put_contents("$dir/hermes/.git", '');
    file_put_contents("$dir/include/.hermes-ready", 'fixture');
    file_put_contents("$dir/hermes/.hermes/bin/hermes", '#!' . PHP_BINARY . "\n<?php file_put_contents(" . var_export("$dir/hermes-called", true) . ", 'called');\n");
    chmod("$dir/hermes/.hermes/bin/hermes", 0700);
    file_put_contents("$dir/local/config.php", '<?php return ' . var_export([
        'autoUpdate' => ['disabled' => true], 'cron' => ['disabled' => true],
        'provider' => ['keyFile' => '', 'virsilipasKeyFile' => ''],
    ], true) . ';');
    return $dir;
}

return [
    'model start: failed discovery refuses a gateway with the cause, preserves configs, and doctor agrees' => function () {
        if (!function_exists('curl_init')) {
            skip('curl is required for model discovery');
        }
        list($proc, $base, $serverDir) = coProviderStart('
if (substr($_SERVER["REQUEST_URI"], -7) === "/models") {
    file_put_contents(PK_DIR . "/calls", "models\n", FILE_APPEND);
    http_response_code((int) trim($_SERVER["REQUEST_URI"], "/"));
    echo (int) trim($_SERVER["REQUEST_URI"], "/") === 201 ? "{\"data\": [{\"id\": \"fixture\"}]}" : "{\"data\": []}"; return;
}
http_response_code(404); echo "{}";
');
        $dir = modelStartWorld();
        try {
            testWithEnv(['HOME' => $dir, 'PIKKUMIES_CONFIG' => null, 'PIKKUMIES_DEPTH' => null, 'PIKKUMIES_ENDPOINT' => null,
                'PIKKUMIES_KEY' => 'fixture-key'], function () use ($dir, $base, $serverDir) {
                foreach ([200 => 'lists no models', 401 => 'key was rejected', 503 => 'could not list models', 0 => 'unreachable'] as $status => $reason) {
                    putenv('PIKKUMIES_ENDPOINT=' . ($status === 0 ? '' : "$base/$status"));
                    file_put_contents("$serverDir/calls", '');
                    list($code, $out) = runPhp("$dir/bin/pikkumies", ['run', 'gateway']);
                    check($code === 1 && strpos($out, 'Not starting: ') !== false && strpos($out, $reason) !== false
                        && strpos($out, 'pikkumies doctor') !== false, "gateway: $code $out");
                    check(!file_exists("$dir/hermes-called"), 'Hermes ran without a default model');
                    check(!file_exists("$dir/local/llm.json"), 'a failed discovery overwrote the light-model decision');
                    foreach (['hermes', 'hermes-managed'] as $layer) {
                        $file = "$dir/local/$layer/config.yaml";
                        if ($status === 200) {
                            check(!file_exists($file), 'a failed first discovery seeded an empty default');
                            @mkdir(dirname($file), 0700, true);
                            file_put_contents($file, "model:\n  default: fixture\n");
                        } else {
                            check(file_get_contents($file) === "model:\n  default: fixture\n", 'the previous default changed');
                        }
                    }
                    check(substr_count(file_get_contents("$serverDir/calls"), "models\n") === ($status ? 1 : 0), 'start repeated discovery');
                    file_put_contents("$serverDir/calls", '');
                    list($doctorCode, $doctor) = runPhp("$dir/bin/pikkumies", ['doctor']);
                    check($doctorCode === 0 && strpos($doctor, 'model:    (none)') !== false
                        && strpos($doctor, 'Not starting: ' . Provider::modelFailure($status)) !== false, "doctor: $doctorCode $doctor");
                    check(substr_count(file_get_contents("$serverDir/calls"), "models\n") === ($status ? 1 : 0), 'doctor repeated discovery');
                }
                putenv('PIKKUMIES_ENDPOINT=' . "$base/201");
                file_put_contents("$serverDir/calls", '');
                foreach (['hermes', 'hermes-managed'] as $layer) {
                    unlink("$dir/local/$layer/config.yaml");
                }
                list($code, $out) = runPhp("$dir/bin/pikkumies", ['generate']);
                check($code === 0, "non-empty discovery failed: $code $out");
                foreach (['hermes', 'hermes-managed'] as $layer) {
                    $yaml = file_get_contents("$dir/local/$layer/config.yaml");
                    check(strpos($yaml, 'default: "fixture"') !== false && strpos($yaml, 'default_model: "fixture"') !== false, 'non-empty defaults changed');
                }
                check(substr_count(file_get_contents("$serverDir/calls"), "models\n") === 1, 'generation repeated discovery');
                file_put_contents("$serverDir/calls", '');
                list($code, $out) = runPhp("$dir/bin/pikkumies", ['doctor']);
                check($code === 0 && strpos($out, 'model:    fixture') !== false && strpos($out, 'Not starting:') === false, "non-empty doctor: $code $out");
                check(substr_count(file_get_contents("$serverDir/calls"), "models\n") === 1, 'doctor repeated successful discovery');
            });
        } finally {
            coProviderServerDone($proc, $serverDir);
            testRmTree($dir);
        }
    },
];
