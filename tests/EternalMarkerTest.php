<?php
return [
    'eternal marker: config, facts and verification id change only on enabled hosts' => function () {
        $root = testTempDir('eternal-config-marker');
        Eternal::$hostMarker = $root . '/enabled';
        $provider = ['endpoint' => 'https://example.invalid', 'model' => 'fixture', 'key' => 'fixture'];
        $config = ['sessions' => ['retentionDays' => 0]];
        try {
            $plainSeed = HermesConfig::renderYaml($provider, [], []);
            $plainPins = HermesConfig::sessionsYaml($config, true);
            $plainId = HermesInstall::checksId();
            check(strpos($plainSeed, 'busy_input_mode') === false, 'queue leaked to markerless seed');
            check(strpos($plainPins, 'terminal_continue') === false, 'terminal continue leaked to markerless pins');
            check(HermesInstall::configFacts() === HermesInstall::CONFIG_FACTS, 'markerless facts changed');
            check($plainId === substr(sha1(json_encode([HermesInstall::CONFIG_FACTS, HermesInstall::LAYER_FACTS, HermesInstall::GUARD_BLOCK, HermesInstall::MANAGED_REFUSAL])), 0, 12), 'markerless verification id changed');
            file_put_contents($root . '/enabled', "1\n");
            $enabledSeed = HermesConfig::renderYaml($provider, [], []);
            $enabledPins = HermesConfig::sessionsYaml($config, true);
            check(strpos($enabledSeed, 'busy_input_mode: queue') !== false, 'enabled seed missed queue');
            check(strpos($enabledPins, 'terminal_continue: true') !== false, 'enabled pins missed terminal continue');
            check(isset(HermesInstall::configFacts()['session.terminal_continue']), 'enabled facts missed terminal continue');
            check(HermesInstall::checksId() !== $plainId, 'enabled verification id did not change');
        } finally {
            Eternal::$hostMarker = null;
            testRmTree($root);
        }
    },
    'eternal marker: doctor reads no Eternal records or limits without the marker' => function () {
        $root = testTempDir('eternal-doctor-marker');
        $source = dirname(__DIR__) . '/pikkumies';
        foreach (['bin', 'lib', 'tools', 'doctrine'] as $dir) {
            exec('cp -R ' . escapeshellarg($source . '/' . $dir) . ' ' . escapeshellarg($root) . '/');
        }
        @mkdir($root . '/fixtureuser/bin', 0700, true);
        file_put_contents($root . '/fixtureuser/bin/userShowLimits', "#!/bin/sh\necho called >> '" . $root . "/limits-called'\nexit 0\n");
        chmod($root . '/fixtureuser/bin/userShowLimits', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/missing-marker"; require __DIR__ . "/bin/pikkumies";');
        try {
            testWithEnv(['HOME' => $root . '/fixtureuser', 'PIKKUMIES_KEY' => null, 'PIKKUMIES_ENDPOINT' => null], function () use ($runner, $root) {
                list($newCode, $newOut) = runPhp($runner, ['doctor']);
                $eternalLines = function ($out) {
                    return array_values(array_filter(explode("\n", $out), function ($line) {
                        return strpos($line, 'eternals:') === 0 || strpos($line, 'OOM kills:') !== false;
                    }));
                };
                // 8b17c97 has no Eternals or OOM doctor line.
                check($newCode === 0 && $eternalLines($newOut) === [], 'markerless doctor added Eternal/OOM output: ' . $newOut);
                check(!is_file($root . '/limits-called'), 'markerless doctor ran userShowLimits');
            });
        } finally {
            testRmTree($root);
        }
    },
];
