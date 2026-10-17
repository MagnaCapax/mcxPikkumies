<?php
/**
 * Detect::userShowLimitsData (ADR-0035): the sections of PMSS userShowLimits for the performance unit, with the account
 * and server names dropped; Detect::userShowLimits keeps its shape for system-status and detection.
 */
require_once __DIR__ . '/fixtures/performance/harness.php';

return [
    'detect: userShowLimitsData returns the sections without the account or server name, and userShowLimits keeps its shape' => function () {
        $fx = perfFixture();
        try {
            $data = Detect::userShowLimitsData($fx['home']);
            check(is_array($data) && array_keys($data) === ['generated_at', 'sections'], 'shape: ' . json_encode($data));
            $json = json_encode($data);
            check(strpos($json, 'fake-user-name') === false && strpos($json, 'fake-host.example') === false, 'the account or server name got through: ' . $json);
            $names = array_column($data['sections']['account']['metrics'], 'metric');
            check(!in_array('username', $names, true) && !in_array('hostname', $names, true) && in_array('PMSS version', $names, true), 'account metrics: ' . json_encode($names));
            check(isset($data['sections']['traffic'], $data['sections']['disk I/O'], $data['sections']['server (shared by all accounts)']), 'sections dropped: ' . json_encode(array_keys($data['sections'])));
            check($data['generated_at'] === 1792224000, 'generated_at');
            $limits = Detect::userShowLimits($fx['home']);
            check($limits === ['cpuQuota' => ['value' => 2.0, 'source' => 'userShowLimits'], 'memMb' => ['value' => 2048.0, 'source' => 'userShowLimits']], 'userShowLimits shape changed: ' . json_encode($limits));
            $calls = perfFakeCalls($fx);
            check($calls === [] || $calls[0]['argv'] === ['--json'], 'userShowLimits argv: ' . json_encode($calls));
        } finally {
            perfDone($fx);
        }
    },
    'detect: userShowLimitsData is null for a missing binary, bad JSON, a failure exit or JSON without sections' => function () {
        $fx = perfFixture();
        try {
            check(Detect::userShowLimitsData($fx['home'], $fx['home'] . '/bin/nope') === null, 'missing binary');
            $bin = $fx['home'] . '/bin/userShowLimits';
            foreach (["echo 'not json'", 'exit 3', "echo '{\"user\":\"u\",\"host\":\"h\"}'"] as $body) {
                file_put_contents("$bin-alt", "#!/bin/sh\n$body\n");
                chmod("$bin-alt", 0700);
                check(Detect::userShowLimitsData($fx['home'], "$bin-alt") === null, "expected null for: $body");
                check(Detect::userShowLimits($fx['home'], "$bin-alt") === null, "userShowLimits expected null for: $body");
            }
        } finally {
            perfDone($fx);
        }
    },
];
