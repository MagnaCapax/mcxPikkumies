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
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/case 'doctor':(.*?)case 'generate':/s", $bin, $match) === 1, 'doctor case missing');
        check(preg_match('/if \(Eternal::enabled\(\)\) \{.*EternalCli::listing\(\).*Detect::userShowLimitsData\(/s', $match[1]) === 1, 'doctor touched Eternal state without its marker guard');
    },
];
