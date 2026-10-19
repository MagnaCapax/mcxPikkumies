<?php
/**
 * lib/HermesTelemetry.php (ADR-0072): Hermes' shared-metrics "No thanks" is in the user's config.yaml, where Hermes
 * reads it, so the dashboard shows no "Send to Nous" banner and a chat never asks; only send is pinned. Uses the fake
 * hermes of HermesUserConfigTest for `config set`.
 */
if (!function_exists('userCfgWorld')) {
    require __DIR__ . '/HermesUserConfigTest.php';
}

/**
 * Hermes v0.22.2 consent_state (hermes_cli/observability/shared_metrics_consent.py:58-71) over the section as read
 * from the user's file: the dashboard banner shows unless decided (SharedMetricsConsentBanner.tsx:49).
 */
function telemetryConsentState(array $section, $offerVersion = 2)
{
    $enabled = isset($section['enabled']) && $section['enabled'] === 'true';
    $answered = isset($section['enabled']) || isset($section['send']);
    $reask = $answered && !$enabled && (!isset($section['offer_version']) || $section['offer_version'] !== (string) $offerVersion);
    return ['enabled' => $enabled, 'send' => $enabled && isset($section['send']) && $section['send'] === 'true', 'decided' => $answered && !$reask];
}

/** A user config.yaml of an install that already ran: marker written, skills dir listed, then $telemetry. */
function telemetryInstalled(array $w, $telemetry)
{
    @mkdir($w['local'] . '/state', 0700);
    HermesConfig::put(HermesUserConfig::marker($w['local']), "{}\n");
    HermesConfig::put(HermesUserConfig::skinMarker($w['local']), "{}\n"); // the skin step already ran (ADR-0061)
    file_put_contents($w['home'] . '/config.yaml', HermesConfig::seedHeader() . "skills:\n  external_dirs:\n    - " . HermesConfig::q(Units::generatedDir()) . "\n" . $telemetry);
}

return [
    'hermes telemetry: the config Pikkumies writes settles Hermes\' question as "No thanks" in the user\'s file; the managed file pins send only' => function () {
        $w = userCfgWorld();
        try {
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                $seed = (string) file_get_contents($w['home'] . '/config.yaml');
                $pins = (string) file_get_contents($w['local'] . '/hermes-managed/config.yaml');
                check(strpos($seed, "telemetry:\n  shared_metrics:\n    enabled: false\n    send: false\n    offer_version: 2\n") !== false, 'seed: ' . $seed);
                $state = telemetryConsentState(HermesTelemetry::leaves($seed));
                check($state === ['enabled' => false, 'send' => false, 'decided' => true], 'Hermes would offer the banner: ' . json_encode($state));
                // enabled pinned would be dropped from the user's file by Hermes' save_config and reopen the question.
                check(strpos($pins, "telemetry:\n  shared_metrics:\n    send: false\n") !== false && strpos($pins, "shared_metrics:\n    enabled") === false, 'managed: ' . $pins);
                check(telemetryConsentState(['send' => 'false', 'enabled' => 'false'])['decided'] === false, 'the old seed (no offer_version) is a re-ask: the banner the pilot saw');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes telemetry: an install from before the fix gets the answer through Hermes\' own config set, backed up and logged' => function () {
        $w = userCfgWorld();
        try {
            telemetryInstalled($w, "telemetry:\n  shared_metrics:\n    enabled: false\n    send: false\n");
            $before = (string) file_get_contents($w['home'] . '/config.yaml');
            userCfgIn($w, $w['bin'], '', function () use ($w, $before) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                $log = (string) file_get_contents($w['log']);
                check(strpos($log, 'config set telemetry.shared_metrics.offer_version 2 | managed=' . $w['local'] . "/hermes-managed\n") !== false, 'offer_version set with the managed dir: ' . $log);
                check(strpos($log, 'telemetry.shared_metrics.enabled') === false && strpos($log, 'shared_metrics.send') === false, 'only what differs: ' . $log);
                $backups = glob($w['local'] . '/backups/hermes/config.yaml.*');
                check(count($backups) === 1 && file_get_contents($backups[0]) === $before, 'one backup of the old bytes');
                $lines = userCfgLogLines($w);
                $line = json_decode((string) end($lines), true);
                check($line['action'] === 'repair-telemetry-answer' && $line['key'] === 'telemetry.shared_metrics.offer_version', 'log: ' . json_encode($line));
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes telemetry: an opt-in in the user\'s file goes back to off; without Hermes built nothing runs and the start goes on; a settled answer runs nothing' => function () {
        $w = userCfgWorld();
        try {
            telemetryInstalled($w, "telemetry:\n  shared_metrics:\n    enabled: true\n    send: true\n    offer_version: 2\n");
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check(!is_file($w['log']), 'Hermes not built: no process');
            });
            userCfgIn($w, $w['bin'], '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                $log = (string) file_get_contents($w['log']);
                check(strpos($log, 'config set telemetry.shared_metrics.enabled false |') !== false && strpos($log, 'offer_version') === false, 'enabled off: ' . $log);
            });
            telemetryInstalled($w, "telemetry:\n  # the answer\n  shared_metrics:\n    enabled: false   # no\n    offer_version: 2\n");
            unlink($w['log']);
            userCfgIn($w, $w['bin'], '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check(!is_file($w['log']), 'settled (send dropped by a Hermes save, a comment): no Hermes process');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes telemetry: the reader takes only telemetry.shared_metrics in block YAML; anything else reads unsettled' => function () {
        $t = testTempDir('pk-tele');
        try {
            $cases = [
                ["telemetry:\n  shared_metrics:\n    enabled: false\n    offer_version: 2\n", true],
                ["other:\n  shared_metrics:\n    enabled: false\n    offer_version: 2\n", false],
                ["telemetry:\n  other:\n    enabled: false\n    offer_version: 2\n", false],
                ["telemetry: {shared_metrics: {enabled: false, offer_version: 2}}\n", false],
                ["telemetry:\n  shared_metrics:\n    enabled: false\n    offer_version: 1\n", false],
                ["telemetry:\n  shared_metrics:\n    enabled: False\n    offer_version: 2\n", false],
                ["telemetry:\n  shared_metrics:\n    offer_version: 2\n", false],
                ["telemetry:\n  shared_metrics:\n    enabled: false\n  offer_version: 2\n", false],
            ];
            foreach ($cases as $c) {
                file_put_contents("$t/c.yaml", $c[0]);
                check(HermesTelemetry::isOff("$t/c.yaml", 2) === $c[1], 'isOff ' . json_encode($c));
            }
            check(!HermesTelemetry::isOff("$t/missing.yaml", 2), 'no file: unsettled');
            @mkdir("$t/tree/hermes_cli/observability", 0700, true);
            file_put_contents("$t/tree/" . HermesTelemetry::SOURCE, "x = 1\nOFFER_VERSION = 3\n");
            check(HermesTelemetry::offerVersion("$t/tree") === 3 && HermesTelemetry::offerVersion("$t/none") === 2 && HermesTelemetry::offerVersion('') === 2, 'offer version from the source, else 2');
        } finally {
            testRmTree($t);
        }
    },
    'hermes contract: the installed banner and sender still read the answer from the user\'s file the way ADR-0072 relies on' => function () {
        $src = HermesInstall::sourceDir();
        if ($src === '') {
            skip('Hermes is not installed in pikkumies/hermes here (CI); checked wherever it is installed');
        }
        $read = function ($f) use ($src) {
            return (string) @file_get_contents("$src/$f");
        };
        check(strpos($read('web/src/components/SharedMetricsConsentBanner.tsx'), 'consent.decided || consent.managed') !== false, 'the banner no longer hides on a decided answer');
        check(strpos($read('hermes_cli/web_routers/shared_metrics.py'), 'consent_state(read_raw_config())') !== false, 'the consent route reads another layer');
        check(preg_match('/answered = "enabled" in section or "send" in section/', $read(HermesTelemetry::SOURCE)) === 1, 'what counts as answered changed');
    },
];
