<?php
/**
 * lib/HermesConfig.php: put()'s atomic-write safety, hookEntries() derivation, renderYaml()'s YAML
 * quoting/injection safety, sessionsYaml()'s retentionDays edge cases, capabilities() branch
 * combinations not covered by CoreTest/DoctrineTest, agentName() boundaries, and renderSoul()'s
 * section order. Reviewed against the current source this session (see build brief); three ideas
 * from the original 50-row table (unit-level failClosed propagation and failClosed "OR"ing across
 * units) are dropped here because tests/HookUnitsTest.php's "hook entries: pre_tool_call always
 * fails closed..." and "...the summed timeout is clamped to 300..." tests already cover them
 * exactly as hookEntries() behaves today (failClosed is purely event-based, never unit-based).
 */
if (!function_exists('hookUnitsAdd')) {
    require __DIR__ . '/HookUnitsTest.php';
}

return [
    'hermes write: put() writes new content successfully when the directory is writable' => function () {
        $dir = testTempDir('pk-put');
        try {
            $file = "$dir/config.yaml";
            HermesConfig::put($file, "hello world\n");
            check(file_get_contents($file) === "hello world\n", 'content not written exactly');
            check(!glob("$dir/*.tmp-*"), 'temporary file left behind after a successful write');
        } finally {
            testRmTree($dir);
        }
    },
    'hermes write: put() applies the given file mode exactly (e.g. .env at 0600)' => function () {
        $dir = testTempDir('pk-put');
        try {
            $file = "$dir/.env";
            HermesConfig::put($file, "KEY=secret\n", 0600);
            check((fileperms($file) & 0777) === 0600, 'mode not applied: ' . sprintf('%o', fileperms($file) & 0777));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: a provider key file written with the process umask (often 0644) instead
        // of the requested 0600 would leak the API key to every other local account on a shared seedbox.
    },
    'hermes write: put() throws and removes its temp file when the write itself fails, old file untouched' => function () {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            skip('running as root; permission bits do not block the write');
        }
        $dir = testTempDir('pk-put');
        $file = "$dir/config.yaml";
        file_put_contents($file, "old content\n");
        chmod($dir, 0500); // read+execute, no write: creating the new .tmp-<pid> name must fail
        $thrown = null;
        try {
            HermesConfig::put($file, "new content\n");
        } catch (Exception $e) {
            $thrown = $e;
        }
        chmod($dir, 0700);
        check($thrown !== null && strpos($thrown->getMessage(), $file) !== false, 'put() did not throw naming the path: ' . ($thrown ? $thrown->getMessage() : 'no exception'));
        check(!glob("$dir/*.tmp-*"), 'a stray .tmp-<pid> file was left behind');
        check(file_get_contents($file) === "old content\n", 'the old file must stay untouched on a failed write');
        testRmTree($dir);
        // defect this catches: if put() skipped @unlink($tmp) before throwing, a disk-full write would
        // leave a stray temp file behind every time config.yaml/.env/SOUL.md regeneration is retried.
    },
    'hermes write: put() throws and cleans up on rename failure, old file untouched' => function () {
        $dir = testTempDir('pk-put');
        try {
            $file = "$dir/config.yaml";
            mkdir($file, 0700); // the final path is already a directory: rename() onto it must fail
            $thrown = null;
            try {
                HermesConfig::put($file, "new content\n");
            } catch (Exception $e) {
                $thrown = $e;
            }
            check($thrown !== null, 'put() did not throw when rename() must fail');
            check(!glob("$dir/*.tmp-*"), 'a stray .tmp-<pid> file was left behind after a failed rename');
            check(is_dir($file), 'the pre-existing path must be left alone');
        } finally {
            testRmTree($dir);
        }
        // defect this catches: put()'s own docstring claims "a full disk leaves the old file, never an
        // empty config"; a missing @unlink($tmp) in the failure branch would leave that tmp file forever.
    },
    'hermes hooks: hookEntries() with no hook units anywhere returns no entries' => function () {
        $dir = testTempDir('pk-hooks-empty');
        try {
            check(HermesConfig::hookEntries($dir) === [], 'an empty hooks tree must yield zero entries');
        } finally {
            testRmTree($dir);
        }
    },
    'hermes hooks: an event with several units still produces exactly one orchestrator command, not one per unit' => function () {
        $dir = testTempDir('pk-hooks-cmd');
        try {
            foreach (['a', 'b', 'c'] as $n) {
                hookUnitsAdd($dir, "cat$n/$n", hookUnitsMd($n, ['event' => 'pre_llm_call', 'timeout' => '3']));
            }
            $cmd = HermesConfig::hookEntries($dir)['pre_llm_call']['command'];
            check(substr_count($cmd, 'orchestrator.php') === 1, 'command mentions the orchestrator more than once with 3 units: ' . $cmd);
        } finally {
            testRmTree($dir);
        }
        // regression guard: a future refactor that built the command string inside the per-unit timeout
        // loop (instead of once per event) would duplicate or multiply this text.
    },
    'hermes yaml: a hostile model id cannot inject raw YAML structure via embedded quotes and newlines' => function () {
        $hostile = 'm" ' . "\n" . 'providers:' . "\n" . '  evil:' . "\n" . '    base_url: "http://evil';
        $safe = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a'], []);
        $y = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => $hostile, 'key' => 'k'], ['/a'], []);
        check(substr_count($y, "\n") === substr_count($safe, "\n"), 'the hostile model id added real newline characters to config.yaml: ' . $y);
        check(strpos($y, "\nevil:\n") === false && strpos($y, "\n  evil:") === false, 'an "evil" provider key leaked in as real YAML structure: ' . $y);
        // defect this catches: q()'s str_replace must escape both '"' and "\n" from a model id the
        // custom endpoint returned; skipping either would let a malicious /models reply inject config keys.
    },
    'hermes yaml: disabled skills render one list line per name, in order, for more than one entry' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a'], [], ['zeta', 'alpha', 'mid']);
        check(strpos($y, "  disabled:\n    - \"zeta\"\n    - \"alpha\"\n    - \"mid\"\n") !== false, 'disabled skills not rendered one per line in input order: ' . $y);
    },
    'hermes yaml: all nine install-time feature pins appear exactly once in a single non-trivial render' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a', '/b'],
            ['pre_tool_call' => ['command' => 'x', 'failClosed' => true, 'timeout' => 5], 'pre_llm_call' => ['command' => 'y', 'failClosed' => false, 'timeout' => 3]],
            ['one', 'two'], true);
        foreach (['web', 'security', 'approvals', 'checkpoints', 'plugins', 'auxiliary', 'curator', 'auth', 'updates'] as $pin) {
            check(substr_count($y, "\n$pin:\n") === 1, "$pin: did not appear exactly once in: " . $y);
        }
    },
    'hermes yaml quoting: a literal backslash followed by the letter n is distinguished from a real newline' => function () {
        $literalInput = 'a' . chr(92) . 'nb';    // 4 chars: a, backslash, n, b (no real newline)
        $realInput = 'a' . chr(10) . 'b';         // 3 chars: a, real newline, b
        $literalOut = HermesConfig::q($literalInput);
        $realOut = HermesConfig::q($realInput);
        check($literalOut === '"a' . chr(92) . chr(92) . 'nb"', 'a literal backslash+n must render as a doubled backslash then a bare n: ' . $literalOut);
        check($realOut === '"a' . chr(92) . 'nb"', 'a real newline must render as a single escaped backslash-n: ' . $realOut);
        check($literalOut !== $realOut, 'a literal backslash-n must not be confused with a real newline');
    },
    'hermes yaml quoting: non-string scalars are cast before quoting, without a PHP warning' => function () {
        check(HermesConfig::q(42) === '"42"', 'int not cast: ' . HermesConfig::q(42));
        check(HermesConfig::q(true) === '"1"', 'bool true not cast: ' . HermesConfig::q(true));
        check(HermesConfig::q(false) === '""', 'bool false not cast: ' . HermesConfig::q(false));
        check(HermesConfig::q(null) === '""', 'null not cast: ' . HermesConfig::q(null));
    },
    'hermes yaml quoting: multi-byte UTF-8 passes through unescaped' => function () {
        $s = "mod\xC3\xA8le-\xE6\x97\xA5\xE6\x9C\xAC"; // "modèle-日本" in UTF-8 bytes
        check(HermesConfig::q($s) === '"' . $s . '"', 'multi-byte UTF-8 was altered: ' . HermesConfig::q($s));
    },
    'hermes sessions: a negative retentionDays does not silently turn pruning on' => function () {
        $y = HermesConfig::sessionsYaml(['sessions' => ['retentionDays' => -5]]);
        check(strpos($y, "sessions:\n  auto_prune: false\n") === 0, 'a negative retentionDays turned pruning on: ' . $y);
        check(strpos($y, 'retention_days') === false, 'a negative retentionDays must not render a retention_days line');
    },
    'hermes sessions: a non-numeric retentionDays casts to 0, not an error' => function () {
        $y = HermesConfig::sessionsYaml(['sessions' => ['retentionDays' => 'soon']]);
        check(strpos($y, "sessions:\n  auto_prune: false\n") === 0, 'a non-numeric retentionDays did not degrade to "never prune": ' . $y);
    },
    'hermes capabilities: shared + root available + not disabled renders the "touch only this user\'s files" suffix' => function () {
        $profile = ['pmss' => false, 'root' => ['available' => true, 'via' => 'sudo'], 'tenancy' => ['shared' => true]];
        $s = HermesConfig::capabilities($profile, Config::defaults());
        check(strpos($s, "Shared server: yes; other accounts live here, so touch only this user's files and processes") !== false, 'suffix missing: ' . $s);
    },
    'hermes capabilities: root.disabled suppresses the shared-host suffix even when root is available' => function () {
        $profile = ['pmss' => false, 'root' => ['available' => true, 'via' => 'sudo'], 'tenancy' => ['shared' => true]];
        $s = HermesConfig::capabilities($profile, ['root' => ['disabled' => true]]);
        check(strpos($s, 'Shared server: yes; other accounts live here') !== false, 'shared line missing entirely: ' . $s);
        check(strpos($s, 'touch only') === false, 'root.disabled must suppress the suffix too: ' . $s);
    },
    'hermes capabilities: pmss without a family key still shows a generic Host line alongside the PMSS line' => function () {
        $profile = ['pmss' => true, 'root' => ['available' => false]];
        $s = HermesConfig::capabilities($profile, Config::defaults());
        check(strpos($s, '- PMSS seedbox platform: yes') !== false, 'PMSS line missing: ' . $s);
        check(strpos($s, '- Host: generic') !== false, 'Host: generic line missing when family is unset: ' . $s);
    },
    'hermes capabilities: a present cron tick plus available root still shows the cron line' => function () {
        CronTick::$setprivPresentOverride = true;
        try {
            $s = HermesConfig::capabilities(['pmss' => false, 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults());
            check(strpos($s, '- Cron jobs run from crontab; ignore') !== false, 'cron line missing when root is available but setpriv exists: ' . $s);
        } finally {
            CronTick::$setprivPresentOverride = null;
        }
    },
    'hermes capabilities: an entirely empty profile array renders without warnings' => function () {
        $s = HermesConfig::capabilities([], Config::defaults());
        check(is_string($s) && strpos($s, '# This machine') === 0, 'an empty profile must still render a valid capability block: ' . var_export($s, true));
        check(strpos($s, 'Root: not available') !== false, 'an empty profile must default to root not available');
    },
    'hermes name: a name exactly at NAME_MAX length is accepted unmodified' => function () {
        $name = str_repeat('a', Config::NAME_MAX);
        check(HermesConfig::agentName(['agent' => ['name' => $name]]) === $name, 'a name at exactly NAME_MAX was rejected');
    },
    'hermes name: a non-string agent.name value falls back to Pikkumies safely' => function () {
        check(HermesConfig::agentName(['agent' => ['name' => 42]]) === 'Pikkumies', 'an int name value was not rejected safely');
        check(HermesConfig::agentName(['agent' => ['name' => ['x']]]) === 'Pikkumies', 'an array name value was not rejected safely');
    },
    'hermes soul: renderSoul places soul before voice before doctrine before safety' => function () {
        $p = ['pmss' => true, 'family' => 'pmss', 'root' => ['available' => true, 'via' => 'sudo'], 'tenancy' => ['shared' => false]];
        $s = HermesConfig::renderSoul($p, Config::defaults(), false);
        $soul = strpos($s, '# Pikkumies');
        $voice = strpos($s, '# Voice');
        $doctrine = strpos($s, '# Doctrine');
        $safety = strpos($s, '# Safety');
        check($soul !== false && $voice !== false && $doctrine !== false && $safety !== false, 'one of the four static-doctrine sections is missing: ' . $s);
        check($soul < $voice && $voice < $doctrine && $doctrine < $safety, 'soul/voice/doctrine/safety are out of order');
    },
];
