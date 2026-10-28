<?php
if (!function_exists('coProviderStart')) {
    require __DIR__ . '/CoreTest.php';
}

return [
    'model discovery: the original status explains a missing model without a second request' => function () {
        if (!function_exists('curl_init')) {
            skip('curl is required for model discovery');
        }
        list($proc, $base, $dir) = coProviderStart('
file_put_contents(PK_DIR . "/calls", "models\n", FILE_APPEND);
http_response_code((int) trim($_SERVER["REQUEST_URI"], "/"));
echo "{\"data\": []}";
');
        try {
            foreach ([401 => 'key was rejected', 403 => 'key was rejected', 200 => 'lists no models', 503 => 'could not list models'] as $status => $reason) {
                $prov = ['endpoint' => "$base/$status", 'key' => 'fixture-key', 'model' => ''];
                // The router's path includes /models; its leading number selects the response status.
                check(Provider::model($prov, $ids, $http) === '' && $ids === [] && $http === $status, 'discovery lost its status');
                $message = Provider::modelFailure($http);
                check(strpos($message, $reason) !== false && strpos($message, 'pikkumies doctor') !== false, $message);
                check(strpos($message, 'fixture-key') === false, 'the reason must not expose the key');
            }
            check(count(file("$dir/calls")) === 4, 'explaining discovery must not repeat it');
            $prov['model'] = 'fixture';
            check(Provider::model($prov, $ids, $http) === 'fixture' && $http === null, 'configured model changed');
            check(count(file("$dir/calls")) === 4, 'configured models must not need discovery');
            $prov['model'] = '';
            $prov['endpoint'] = ''; // No request can be sent; this is distinct from a connection failure.
            check(Provider::model($prov, $ids, $http) === '' && $http < 0, 'no endpoint must yield an unsent result');
            check(strpos(Provider::modelFailure($http), 'no endpoint= line') !== false, 'missing endpoint needs its own reason');
        } finally {
            coProviderServerDone($proc, $dir);
        }
    },
    'model config: empty defaults are refused before either layer changes; existing values survive' => function () {
        $dir = testTempDir('model-config');
        $home = "$dir/hermes";
        $managed = HermesConfig::managedDir($home);
        $prov = ['endpoint' => 'fixture', 'key' => 'fixture-key', 'model' => ''];
        try {
            foreach ([false, true] as $existing) {
                if ($existing) {
                    mkdir($home, 0700, true);
                    foreach ([$home, $managed] as $layer) {
                        file_put_contents("$layer/config.yaml", "model:\n  default: fixture\n");
                    }
                    file_put_contents("$managed/.env", "previous key\n");
                }
                $error = '';
                try {
                    HermesConfig::writeConfig($home, $prov, Config::defaults());
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
                check(strpos($error, 'pikkumies doctor') !== false, 'an empty model must refuse config generation');
                foreach ([$home, $managed] as $layer) {
                    check($existing ? file_get_contents("$layer/config.yaml") === "model:\n  default: fixture\n"
                        : !file_exists("$layer/config.yaml"), 'an empty model created or replaced a default');
                }
                check(!$existing || file_get_contents("$managed/.env") === "previous key\n", 'a failed generation changed the key');
            }
            foreach ([false, true] as $pins) {
                $error = '';
                try {
                    HermesConfig::renderYaml($prov, [], [], false, '', $pins);
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
                check($error !== '', 'neither YAML layer may render an empty default');
                $yaml = HermesConfig::renderYaml(array_merge($prov, ['model' => 'fixture']), [], [], false, '', $pins);
                check(strpos($yaml, 'default: "fixture"') !== false && strpos($yaml, 'default_model: "fixture"') !== false, 'non-empty defaults changed');
            }
        } finally {
            testRmTree($dir);
        }
    },
];
