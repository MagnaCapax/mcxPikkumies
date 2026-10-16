<?php
return [
    'uid map identity is real root' => function () {
        check(Detect::uidMapIsIdentity("         0          0 4294967295\n"), 'identity map not recognised');
    },
    'user namespace map is not root' => function () {
        check(!Detect::uidMapIsIdentity("         0       1000          1\n"), 'userns map treated as root');
        check(!Detect::uidMapIsIdentity(false), 'unreadable map treated as root');
    },
    'CAP_SYS_ADMIN decides full caps' => function () {
        check(Detect::capsAreFull('000001ffffffffff'), 'full set rejected');
        check(!Detect::capsAreFull('0000000000000000'), 'empty set accepted');
        check(!Detect::capsAreFull('00000000001fffff'), 'set without bit 21 accepted');
    },
    'profile has the expected keys' => function () {
        $p = Detect::run();
        foreach (['os', 'user', 'uid', 'pmss', 'root', 'apps', 'resources'] as $k) {
            check(array_key_exists($k, $p), "missing $k");
        }
        check(is_array($p['apps']), 'apps not a list');
    },
    'config merge: local scalars and lists replace defaults' => function () {
        $m = Config::merge(['a' => ['b' => 1, 'c' => 2], 'l' => ['x']], ['a' => ['b' => 9], 'l' => ['y', 'z']]);
        check($m['a']['b'] === 9 && $m['a']['c'] === 2, 'nested merge wrong');
        check($m['l'] === ['y', 'z'], 'list not replaced');
    },
    'tools: tools.disabled leaves categories out; root.disabled hides server-admin' => function () {
        check(Generate::disabledCategories(Config::defaults()) === [], 'defaults must load every category');
        check(Generate::disabledCategories(['tools' => ['disabled' => ['seedbox']], 'root' => ['disabled' => false]]) === ['seedbox'], 'tools.disabled ignored');
        check(in_array('server-admin', Generate::disabledCategories(['root' => ['disabled' => true]]), true), 'root.disabled must hide the server-admin category');
    },
    'soul stays within its size budget (sent to the model on every turn)' => function () {
        // Core (without tool doctrine) <= 8000, total <= 16000; every profile is checked in DoctrineTest (ADR-0029).
        $core = HermesConfig::renderSoul(['pmss' => true, 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults(), false);
        check(strlen($core) <= 8000, 'core SOUL.md is ' . strlen($core) . ' bytes; keep it at or under 8000');
        $s = HermesConfig::renderSoul(['pmss' => true, 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults());
        check(strlen($s) <= 16000, 'SOUL.md with tool doctrine is ' . strlen($s) . ' bytes; keep it at or under 16000');
        // ADR-0007: a live run saved a lesson into the built-in memory; the doctrine must steer away from it.
        check(strpos($s, 'never the built-in memory tool') !== false, 'the built-in memory steer is missing from SOUL.md');
    },
    'soul starts with what this machine allows, and nothing else from the profile' => function () {
        $p = ['pmss' => true, 'root' => ['available' => false, 'via' => 'none'], 'apps' => ['secretapp'], 'user' => 'someone'];
        $s = HermesConfig::renderSoul($p, Config::defaults());
        check(strpos($s, "# This machine\n\n- Root: not available") === 0, 'capability block must open SOUL.md');
        check(strpos($s, '- PMSS seedbox platform: yes') !== false, 'pmss line');
        check(strpos($s, 'secretapp') === false && strpos($s, 'someone') === false, 'profile details leaked into SOUL.md');
        $r = HermesConfig::capabilities(['pmss' => false, 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults());
        check(strpos($r, 'Root: available (via sudo)') !== false, 'root via sudo');
        $off = HermesConfig::capabilities(['pmss' => false, 'root' => ['available' => true, 'via' => 'sudo']], ['root' => ['disabled' => true]]);
        check(strpos($off, 'turned off in config') !== false && strpos($off, 'available (via') === false, 'root.disabled must win over detection');
        // ADR-0023: only the shared yes/no reaches the model, never a hostname or an account count.
        $sh = HermesConfig::capabilities(['pmss' => false, 'root' => ['available' => false], 'tenancy' => ['shared' => true],
            'identity' => ['hostname' => 'box.example', 'rdns' => 'box.example']], Config::defaults());
        check(strpos($sh, '- Shared server: yes') !== false, 'shared-server line missing');
        check(strpos($sh, 'box.example') === false, 'hostname leaked into SOUL.md');
        $solo = HermesConfig::capabilities(['pmss' => false, 'root' => ['available' => false], 'tenancy' => ['shared' => false]], Config::defaults());
        check(strpos($solo, 'Shared server') === false, 'shared-server line on a single-tenant host');
    },
    'soul tells the model scheduled jobs run only where the tick line is installed (ADR-0025)' => function () {
        $p = ['pmss' => false, 'root' => ['available' => false]];
        CronTick::$setprivPresentOverride = true;
        check(strpos(HermesConfig::capabilities($p, Config::defaults()), '- Cron jobs run from crontab; ignore') !== false,
            'tick installed: the model must be told jobs run, or it repeats Hermes\' "will NOT fire" warning to the user');
        check(strpos(HermesConfig::capabilities($p, ['cron' => ['disabled' => true]]), 'Cron jobs run') === false, 'cron.disabled: no tick line, no claim that jobs run');
        CronTick::$setprivPresentOverride = false;
        check(strpos(HermesConfig::capabilities(['pmss' => false, 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults()), 'Cron jobs run') === false,
            'tick refused (root, no setpriv): no claim that jobs run');
        CronTick::$setprivPresentOverride = null;
    },
    'hermes yaml: named provider with key_env, model, external dirs, consented hooks' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'https://x/v1', 'model' => 'm', 'key' => 'secret'], ['/a/skills'], ['pre_tool_call' => ['command' => '/usr/bin/php /h/orchestrator.php pre_tool_call', 'failClosed' => false, 'timeout' => 12]]);
        // Hermes reads key_env under providers:<name>; a bare `provider: custom` ignored
        // model.key_env when first verified and the request went out without the key (ADR-0005).
        check(strpos($y, "model:\n  provider: \"custom:pikkumies\"\n  default: \"m\"\n") !== false, 'model must name the custom:pikkumies provider and the model');
        check(preg_match('/^providers:\n  pikkumies:\n    base_url: "https:\/\/x\/v1"\n    key_env: PIKKUMIES_API_KEY\n    default_model: "m"\n/m', $y) === 1, 'providers.pikkumies block');
        check(preg_match('/^model:\n(  .*\n)*  key_env:/m', $y) === 0, 'key_env must not sit under model:');
        check(strpos($y, 'secret') === false, 'key leaked into config.yaml');
        check(strpos($y, '"/a/skills"') !== false, 'external dir');
        check(strpos($y, "security:\n  allow_lazy_installs: false\n") !== false, 'runtime installs must be off by default (ADR-0021)');
        check(strpos($y, "approvals:\n  mode: manual\n") !== false, 'dangerous commands must ask the user, not a second model (ADR-0020)');
        $lazy = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], [], [], true);
        check(strpos($lazy, "allow_lazy_installs: true") !== false, 'hermes.lazyInstalls switch ignored');
        // Unapproved shell hooks are silently skipped in a non-interactive run.
        check(strpos($y, "hooks_auto_accept: true\nhooks:\n  pre_tool_call:\n    - command: \"/usr/bin/php /h/orchestrator.php pre_tool_call\"\n      timeout: 12\n") !== false, 'hook with consent');
        $noHooks = HermesConfig::renderYaml(['endpoint' => 'https://x/v1', 'model' => 'm', 'key' => 'secret'], ['/a/skills'], []);
        check(strpos($noHooks, 'hooks_auto_accept') === false, 'no consent line without hooks');
        check(strpos($noHooks, 'disabled:') === false, 'no disabled list when none set');
        $off = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], [], ['seedbox-apps']);
        check(strpos($off, "skills:\n  external_dirs:\n    - \"/a/skills\"\n  disabled:\n    - \"seedbox-apps\"\n") !== false, 'skills.disabled must render under skills:');
        check(strpos($y, 'fail_closed') === false, 'fail_closed stays at the Hermes default for prompt hooks');
        // The shipped units: one orchestrator entry per event; pre_tool_call fails closed because the delete guard does (ADR-0030).
        $entries = HermesConfig::hookEntries();
        check(array_keys($entries) === ['pre_llm_call', 'pre_tool_call'], 'one entry per event: ' . json_encode(array_keys($entries)));
        check($entries['pre_tool_call']['failClosed'] === true && $entries['pre_llm_call']['failClosed'] === false, 'only the event with the delete guard fails closed');
        check($entries['pre_tool_call']['timeout'] === 22 && $entries['pre_llm_call']['timeout'] === 12, 'timeout = sum of unit timeouts + 2: ' . json_encode($entries));
        check(preg_match('#/hooks/orchestrator\.php pre_tool_call$#', $entries['pre_tool_call']['command']) === 1, 'entry runs the orchestrator');
        $guard = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], $entries);
        check(substr_count($guard, '    - command: ') === 2 && substr_count($guard, 'fail_closed') === 1
            && strpos($guard, "orchestrator.php pre_tool_call\"\n      timeout: 22\n      fail_closed: true\n") !== false, 'the guarded event must fail closed, and only it: ' . $guard);
        check(strpos($noHooks, "updates:\n  check: false\n") !== false, 'update banners must be off: Hermes is pinned');
        check(strpos($noHooks, "web:\n  keyless_fallback: false\n  keyless_rescue: false\n") !== false, 'web search must not fall back to outside services unasked');
    },
    'hermes yaml: feature pins at install (ADR-0024, ADR-0026)' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], []);
        check(strpos($y, "  guard_agent_created: true\n") !== false, 'skills.guard_agent_created must be pinned on');
        check(strpos($y, "checkpoints:\n  enabled: true\n") !== false, 'checkpoints.enabled must be pinned on');
        check(strpos($y, "plugins:\n  enabled:\n    - \"security-guidance\"\n") !== false, 'plugins.enabled must list security-guidance');
        check(strpos($y, "auxiliary:\n  background_review:\n    enabled: true\n") !== false, 'auxiliary.background_review.enabled must be pinned on');
        check(strpos($y, "curator:\n  enabled: true\n") !== false, 'curator.enabled must be pinned on');
        check(strpos($y, "auth:\n  adopt_external_logins: false\n") !== false, 'auth.adopt_external_logins must be pinned off');
        // No invented limits, no model selection, no cron-spawned scheduling, no browser keys (ADR-0024).
        check(strpos($y, 'max_input_tokens') === false, 'max_input_tokens must not be set: no measured basis');
        check(strpos($y, 'consolidate') === false, 'curator.consolidate must stay at the Hermes default');
        check(strpos($y, 'allow_agent_scheduling') === false, 'cron.allow_agent_scheduling must stay at the Hermes default (false)');
        check(strpos($y, "\nmodels:") === false, 'no extra model-selection key beyond the existing provider block');
        check(strpos($y, 'browser') === false, 'no browser key: ADR-0021 already covers it');
    },
    'hermes build: pinned tag and checksum, verified before unpacking, atomic switch' => function () {
        $sha = str_repeat('a', 64);
        check(HermesInstall::buildCommand('v1;rm -rf ~', $sha) === '', 'unsafe tag accepted');
        check(HermesInstall::buildCommand('v2026.9.24', 'abc') === '', 'bad checksum accepted');
        $c = HermesInstall::buildCommand('v2026.9.24', $sha);
        check($c !== '' && strpos($c, 'set -e') === 0, 'build must stop at the first failure');
        $check = strpos($c, 'sha256sum -c');
        $tar = strpos($c, 'tar -xzf');
        check($check !== false && $tar !== false && $check < $tar, 'checksum must be verified before unpacking');
        check(strpos($c, '--anchored --no-wildcards-match-slash') !== false, 'excludes must only drop top-level directories');
        check(strpos($c, 'archive/refs/tags/v2026.9.24.tar.gz') !== false, 'source must be the pinned tag');
        $touch = strpos($c, '.complete');
        $switch = strpos($c, 'mv -T');
        check($touch !== false && $switch !== false && $touch < $switch, 'switch only after a complete build');
        $py = HermesInstall::includeDir() . '/python-3.13.16';
        $s = HermesInstall::buildCommand('v2026.9.24', $sha, $py . '/bin/python3');
        $pyCheck = strpos($s, 'sha256sum -c ' . escapeshellarg($py . '.sha256'));
        $pyTar = strpos($s, 'tar -xzf ' . escapeshellarg($py . '.tgz'));
        check(strpos($s, HermesInstall::PY_STANDALONE_SHA256) !== false && $pyCheck !== false && $pyTar !== false && $pyCheck < $pyTar, 'standalone Python must be checksum-verified before unpacking');
        check(strpos($s, escapeshellarg($py . '/bin/python3') . ' -m venv') !== false && strpos($c, "'python3' -m venv") !== false, 'venv must use the chosen python');
        check(HermesInstall::python('aarch64')[0] === '' || HermesInstall::python('aarch64')[0] === 'python3', 'no standalone build outside x86_64');
        check(preg_match('/^[0-9a-f]{64}$/', HermesInstall::PY_STANDALONE_SHA256) === 1, 'standalone Python pin must be a checksum');
        $cfg = Config::defaults();
        check(preg_match(HermesInstall::TAG_RE, $cfg['hermes']['version']) === 1 && preg_match('/^[0-9a-f]{64}$/', $cfg['hermes']['sha256']) === 1, 'default pin must be a tag plus checksum');
    },
    'yaml quoting escapes quotes and backslashes' => function () {
        check(HermesConfig::q('a"b\\c') === '"a\\"b\\\\c"', 'bad quoting');
        check(HermesConfig::q("m\nx: 1\x01") === '"m\\nx: 1"', 'newline must be escaped and control characters dropped');
    },
    'provider key file: ini read, symlink ignored, key masked' => function () {
        $dir = sys_get_temp_dir() . '/pk-test-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/key", "endpoint=https://e/v1\nkey=vsk-abcdef0123456789\n");
        $r = Provider::readKeyFile("$dir/key");
        check($r['endpoint'] === 'https://e/v1' && $r['key'] === 'vsk-abcdef0123456789', 'ini not read');
        @symlink("$dir/key", "$dir/link");
        check(Provider::readKeyFile("$dir/link")['key'] === '', 'symlinked key file was read');
        $m = Provider::mask('vsk-abcdef0123456789');
        check(strpos($m, 'abcdef') === false && substr($m, 0, 4) === 'vsk-', 'mask leaks key');
        @unlink("$dir/link");
        @unlink("$dir/key");
        @rmdir($dir);
    },
];
