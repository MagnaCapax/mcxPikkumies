<?php
require_once __DIR__ . '/ModelStartTest.php';

/** Real provisioning in a private tree; intercept background downloads and never use the real crontab. */
function modelInstallCheck($command, $keyFile, $endpoint = '', $existing = false)
{
    $dir = modelStartWorld();
    $repo = testTempDir('model-install');
    rename($dir, "$repo/pikkumies");
    $dir = "$repo/pikkumies";
    try {
        // Provisioning starts without an installed runtime, so ensure() must honor the skip flag.
        unlink("$dir/include/.hermes-ready");
        $config = [
            'autoUpdate' => ['disabled' => true], 'cron' => ['disabled' => true],
            'provider' => ['keyFile' => "$dir/.pikkumiesKey", 'virsilipasKeyFile' => "$dir/.virsilipasKey"],
            'update' => ['channel' => 'git/main'],
        ];
        file_put_contents("$dir/local/config.php", '<?php return ' . var_export($config, true) . ';');
        if ($keyFile !== null) {
            file_put_contents("$dir/.virsilipasKey", $keyFile);
            chmod("$dir/.virsilipasKey", 0600);
        }
        $files = ['hermes/config.yaml', 'hermes/.env', 'hermes-managed/config.yaml', 'hermes-managed/.env', 'llm.json'];
        if ($existing) {
            foreach ($files as $file) {
                @mkdir(dirname("$dir/local/$file"), 0700, true);
                file_put_contents("$dir/local/$file", 'previous value');
            }
        }
        // The real docs-fill launcher is exercised, but nohup records its argv instead of fetching anything.
        file_put_contents("$dir/bin/nohup", '#!/bin/sh' . "\nprintf '%s\\n' \"\$@\" > " . escapeshellarg("$dir/docs-called") . "\n");
        file_put_contents("$dir/bin/crontab", "#!/bin/sh\necho 'no crontab' >&2\nexit 1\n");
        chmod("$dir/bin/nohup", 0700);
        chmod("$dir/bin/crontab", 0700);
        if ($command === 'update') {
            // A real update, using only a private local origin; the child must propagate post-update's status.
            chanGit($repo, 'init -q -b main');
            chanGit($repo, 'add pikkumies/bin/pikkumies pikkumies/lib');
            chanGit($repo, 'commit -q -m fixture');
            chanGit($repo, 'clone -q --bare . origin.git');
            chanGit($repo, 'remote add origin ' . escapeshellarg("$repo/origin.git"));
        }
        return testWithEnv(['HOME' => $dir, 'PIKKUMIES_CONFIG' => null, 'PIKKUMIES_ENDPOINT' => $endpoint,
            'PATH' => "$dir/bin:" . getenv('PATH'), 'OPENCLAW_STATE_DIR' => "$dir/openclaw",
            'PIKKUMIES_KEY' => null, 'PIKKUMIES_DEPTH' => null, 'PIKKUMIES_SKIP_HERMES' => '1'], function () use ($dir, $command, $files, $existing) {
            list($code, $out) = runPhp("$dir/bin/pikkumies", [$command]);
            foreach ($files as $file) {
                check($existing ? file_get_contents("$dir/local/$file") === 'previous value' : !file_exists("$dir/local/$file"),
                    "$command changed $file without a model");
            }
            if (in_array($command, ['install', 'update', 'post-update'], true)) {
                check($code === 0, "$command exited $code: " . strtok($out, "\n"));
                check(is_file("$dir/tools/INDEX.md") && count(glob("$dir/local/generated/skills/*/*/SKILL.md")) > 0, 'skills or tool index missing');
                foreach (['auto-update:', 'cron-tick:', 'hermes:', 'docs:', 'model:'] as $label) {
                    check(strpos($out, $label) !== false, "$command skipped $label");
                }
                check(strpos($out, 'pikkumies doctor') !== false, 'missing diagnostic hint');
                check(strpos($out, 'model: unavailable;') !== false, 'summary hid the unavailable model');
                check(strpos($out, 'skipped (PIKKUMIES_SKIP_HERMES=1)') !== false, 'runtime setup was skipped silently');
                for ($i = 0; $i < 100 && !is_file("$dir/docs-called"); $i++) {
                    usleep(10000);
                }
                check(is_file("$dir/docs-called") && strpos(file_get_contents("$dir/docs-called"), 'docs-fill') !== false, 'docs fill was not launched');
            }
            return [$code, $out, $dir];
        });
    } finally {
        testRmTree($repo);
    }
}

return [
    'keyless install finishes provisioning without model defaults' => function () {
        foreach ([null, "endpoint=\nkey=\n", "[invalid\n"] as $key) {
            foreach ([false, true] as $existing) {
                list(, $out) = modelInstallCheck('install', $key, '', $existing);
                check(strpos($out, 'no model key yet') !== false, 'install hid the missing key');
            }
        }
    },
    'keyless update and post-update finish and preserve previous model files' => function () {
        foreach (['update', 'post-update'] as $command) {
            foreach ([false, true] as $existing) {
                list(, $out) = modelInstallCheck($command, "key=\n", '', $existing);
                check(strpos($out, 'no model key yet') !== false, "$command hid the missing key");
            }
        }
    },
    'endpoint-less key file reports its path and true reason in generate' => function () {
        list($code, $out, $dir) = modelInstallCheck('generate', "key=fixture-key\n");
        check($code === 1 && strpos($out, 'no endpoint= line') !== false && strpos($out, "$dir/.virsilipasKey") !== false
            && strpos($out, 'unreachable') === false, "generate: $code " . strtok($out, "\n"));
    },
    'keyless generate still fails with the launch reason' => function () {
        list($code, $out) = modelInstallCheck('generate', null);
        check($code === 1 && strpos($out, 'no model key yet') !== false && strpos($out, '~/.virsilipasKey') !== false
            && strpos($out, 'unreachable') === false, "generate: $code $out");
    },
    'keyless doctor uses the launch reason without a false HTTP failure' => function () {
        list($code, $out) = modelInstallCheck('doctor', null);
        check($code === 0 && strpos($out, Launch::missingKeyMessage(['key' => ''])) !== false
            && strpos($out, 'provider: not reachable (HTTP 0)') === false, 'doctor omitted the no-key reason or reported HTTP 0');
    },
    'discovery distinguishes unsent requests from a closed local port' => function () {
        if (!function_exists('curl_init')) {
            skip('curl is required for model discovery');
        }
        foreach ([['endpoint' => '', 'key' => 'fixture-key'], ['endpoint' => '', 'key' => '']] as $prov) {
            $prov['model'] = '';
            check(Provider::model($prov, $ids, $http) === '' && $ids === [], 'unexpected model');
            check($http < 0 && strpos(Provider::modelFailure($http), 'unreachable') === false, 'unsent request was classified as unreachable');
        }
        $prov = ['endpoint' => 'http://127.0.0.1:' . testFreePort(), 'key' => 'fixture-key', 'model' => ''];
        check(Provider::model($prov, $ids, $http) === '' && $http === 0, 'connection failure lost its status');
        check(strpos(Provider::modelFailure($http), 'the model endpoint is unreachable (connection failed or timed out)') === 0, 'connection failure lost its reason');
    },
    'install and post-update continue for every failed discovery reason' => function () {
        if (!function_exists('curl_init')) {
            skip('curl is required for model discovery');
        }
        list($proc, $base, $serverDir) = coProviderStart('http_response_code((int) trim($_SERVER["REQUEST_URI"], "/")); echo "{\"data\": []}";');
        try {
            foreach (['install', 'post-update'] as $command) {
                foreach (['' => 'no endpoint= line', "$base/401" => 'key was rejected', "$base/403" => 'key was rejected',
                    "$base/200" => 'lists no models', "$base/503" => 'could not list models',
                    'http://127.0.0.1:' . testFreePort() => 'unreachable'] as $endpoint => $reason) {
                    list(, $out) = modelInstallCheck($command, "key=fixture-key\n", $endpoint, true);
                    check(strpos($out, $reason) !== false, "$command hid $reason");
                }
            }
        } finally {
            coProviderServerDone($proc, $serverDir);
        }
    },
];
