<?php
/**
 * ADR-0073: the Hermes settings that stop first-contact offers and questions. The managed scope pins
 * onboarding.profile_build off and onboarding.run false (the welcome profile offer and the desktop onboarding chat);
 * approvals.mode "off" is tested in ApprovalsOffTest. The user's seed alone carries clarify in agent.disabled_toolsets,
 * so that line stays the user's.
 */
return [
    'no questions: the managed scope pins the first-contact offers off, in both layers' => function () {
        $prov = ['endpoint' => 'e', 'model' => 'm', 'key' => 'k'];
        foreach ([true, false] as $pinsOnly) {
            $y = HermesConfig::renderYaml($prov, ['/a'], [], false, 'light', $pinsOnly);
            check(strpos($y, "onboarding:\n  profile_build: \"off\"\n  run: false\n") !== false, 'onboarding not pinned off (pinsOnly ' . var_export($pinsOnly, true) . '): ' . $y);
            check(substr_count($y, "\nonboarding:\n") === 1, 'one onboarding block: ' . $y);
            check(strpos($y, 'seen:') === false, 'onboarding.seen is Hermes\' own latch, never pinned: ' . $y);
        }
    },
    'no questions: clarify is off in the seeded user file only, never pinned' => function () {
        $prov = ['endpoint' => 'e', 'model' => 'm', 'key' => 'k'];
        $seed = HermesConfig::renderYaml($prov, ['/a'], [], false, 'light', false);
        $pins = HermesConfig::renderYaml($prov, ['/a'], [], false, 'light', true);
        check(strpos($seed, "agent:\n  disabled_toolsets:\n    - \"clarify\"\n") !== false, 'seed lacks clarify off: ' . $seed);
        check(strpos($pins, 'disabled_toolsets') === false, 'a managed list would replace the user\'s own disabled_toolsets: ' . $pins);
    },
];
