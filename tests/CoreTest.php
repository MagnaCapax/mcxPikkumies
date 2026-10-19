<?php
/** A local php -S server for Provider tests, with a PK_DIR constant the router body can use for its own state. */
if (!function_exists('coProviderStart')) {
    function coProviderStart($routerBody)
    {
        $dir = sys_get_temp_dir() . '/pk-provider-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/router.php", "<?php\ndefine('PK_DIR', " . var_export($dir, true) . ");\n$routerBody");
        $srv = stream_socket_server('tcp://127.0.0.1:0'); // a free port: a fixed one could already answer
        $port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
        fclose($srv);
        $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/router.php"),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($s) {
                fclose($s);
                break;
            }
            usleep(100000);
        }
        return [$proc, "http://127.0.0.1:$port", $dir];
    }
}

if (!function_exists('coProviderServerDone')) {
    function coProviderServerDone($proc, $dir)
    {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

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
        Generate::$dockerPresentOverride = true; // the docker CLI gate has its own test (GenerateTest)
        try {
            check(Generate::disabledCategories(Config::defaults()) === [], 'defaults must load every category');
            check(Generate::disabledCategories(['tools' => ['disabled' => ['seedbox']], 'root' => ['disabled' => false]]) === ['seedbox'], 'tools.disabled ignored');
            check(in_array('server-admin', Generate::disabledCategories(['root' => ['disabled' => true]]), true), 'root.disabled must hide the server-admin category');
        } finally {
            Generate::$dockerPresentOverride = null;
        }
    },
    'soul fits under the Hermes context-file cut and keeps its load-bearing rules' => function () {
        // The only size limit is Hermes' own: every profile is checked in DoctrineTest (ADR-0029 amendment).
        $s = HermesConfig::renderSoul(['pmss' => true, 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults());
        check(strlen($s) <= HermesConfig::SOUL_MAX, 'SOUL.md is ' . strlen($s) . ' bytes; Hermes would cut it past ' . HermesConfig::SOUL_MAX);
        // ADR-0007: a live run saved a lesson into the built-in memory; the doctrine must steer away from it.
        check(strpos($s, 'never the built-in memory tool') !== false, 'the built-in memory steer is missing from SOUL.md');
        check(strpos($s, "Don't know? Check. Can't check? Say \"I don't know\"") !== false, 'the accuracy rule is missing from SOUL.md');
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
        check(strpos($y, "approvals:\n  mode: \"off\"\n") !== false, 'approvals off: Hermes never shows its approval prompt, hook questions are decided by the ask policy (ADR-0073)');
        $lazy = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], [], true);
        check(strpos($lazy, "allow_lazy_installs: true") !== false, 'hermes.lazyInstalls switch ignored');
        // Unapproved shell hooks are silently skipped in a non-interactive run.
        check(strpos($y, "hooks_auto_accept: true\nhooks:\n  pre_tool_call:\n    - command: \"/usr/bin/php /h/orchestrator.php pre_tool_call\"\n      timeout: 12\n") !== false, 'hook with consent');
        $noHooks = HermesConfig::renderYaml(['endpoint' => 'https://x/v1', 'model' => 'm', 'key' => 'secret'], ['/a/skills'], []);
        check(strpos($noHooks, 'hooks_auto_accept') === false, 'no consent line without hooks');
        // A hidden shipped skill is not generated at all; the user's skills.disabled is theirs (ADR-0062).
        check(strpos($noHooks, 'disabled:') === false, 'Pikkumies never writes skills.disabled');
        check(strpos($y, 'fail_closed') === false, 'fail_closed stays at the Hermes default for prompt hooks');
        // The shipped units: one orchestrator entry per event; pre_tool_call fails closed because the delete guard does (ADR-0030).
        $entries = HermesConfig::hookEntries();
        // post_llm_call: the shipped change check is an interval unit, so the orchestrator records each turn (ADR-0067, ADR-0070).
        check(array_keys($entries) === ['post_api_request', 'post_llm_call', 'post_tool_call', 'pre_llm_call', 'pre_tool_call', 'pre_verify'], 'one entry per event: ' . json_encode(array_keys($entries)));
        check($entries['pre_tool_call']['failClosed'] === true && $entries['pre_llm_call']['failClosed'] === false
            && $entries['post_tool_call']['failClosed'] === false && $entries['pre_verify']['failClosed'] === false && $entries['post_api_request']['failClosed'] === false
            && $entries['post_llm_call']['failClosed'] === false && $entries['post_llm_call']['timeout'] === 5, 'only the event with the delete guard fails closed');
        check($entries['post_tool_call']['timeout'] === 9 && $entries['post_api_request']['timeout'] === 4, 'command log 5 s + session counters 2 s + 2; session context 2 s + 2: ' . json_encode($entries));
        check($entries['pre_tool_call']['timeout'] === 51 && $entries['pre_llm_call']['timeout'] === 26 && $entries['pre_verify']['timeout'] === 32,
            'timeout = sum of unit timeouts + 2, plus the ask review (8 s) on pre_tool_call (ADR-0073): ' . json_encode($entries));
        check(preg_match('#/hooks/orchestrator\.php pre_tool_call 51$#', $entries['pre_tool_call']['command']) === 1, 'entry runs the orchestrator');
        $guard = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], $entries);
        check(substr_count($guard, '    - command: ') === 6 && substr_count($guard, 'fail_closed') === 1
            && strpos($guard, "orchestrator.php pre_tool_call 51\"\n      timeout: 51\n      fail_closed: true\n") !== false, 'the guarded event must fail closed, and only it: ' . $guard);
        check(strpos($noHooks, "updates:\n  check: false\n") !== false, 'no update check at session start: an unasked call to GitHub (AGENTS.md, ADR-0033)');
        check(strpos($noHooks, "cli:\n  expose_on_path: false\n") !== false, 'Hermes updates must not write launchers into ~/.local/bin (ADR-0033)');
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
        // The one browser key is the seed's JavaScript denylist (ADR-0077); nothing else about the browser is configured here.
        check(substr_count($y, 'browser') === 1 && strpos($y, "browser:\n  restrict_evaluate: true\n") !== false, 'only browser.restrict_evaluate is seeded');
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
    'provider resolve(): PIKKUMIES_ENDPOINT/PIKKUMIES_KEY env wins over config, config wins over the key file when env is absent, and a trailing slash is stripped' => function () {
        $dir = sys_get_temp_dir() . '/pk-presolve-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        $keyFile = "$dir/key";
        file_put_contents($keyFile, "endpoint=https://file-endpoint/v1/\nkey=file-key\n");
        $config = ['provider' => ['endpoint' => '', 'model' => 'm1', 'keyFile' => $keyFile]];
        try {
            $r = Provider::resolve($config);
            check($r === ['endpoint' => 'https://file-endpoint/v1', 'key' => 'file-key', 'model' => 'm1'],
                'with no env/config endpoint, the key file must win, and a trailing slash must be stripped: ' . json_encode($r));
            $config['provider']['endpoint'] = 'https://config-endpoint/v1';
            check(Provider::resolve($config)['endpoint'] === 'https://config-endpoint/v1', 'config endpoint must win over the key file when set');
            testWithEnv(['PIKKUMIES_ENDPOINT' => 'https://env-endpoint/v1', 'PIKKUMIES_KEY' => 'env-key'], function () use ($config) {
                $r = Provider::resolve($config);
                check($r['endpoint'] === 'https://env-endpoint/v1' && $r['key'] === 'env-key',
                    'env must win over both config and the key file: ' . json_encode($r));
            });
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'provider check(): true only for a 2xx GET /models; 401 and a dead port both report false with the real HTTP code (0 for a dead port)' => function () {
        list($proc, $base, $dir) = coProviderStart('
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (preg_match("#^/(\\d+)/models$#", $p, $m)) {
    http_response_code((int) $m[1]);
    echo json_encode(["data" => [["id" => "m-a"]]]);
    exit;
}
http_response_code(404);
');
        try {
            list($ok, $code) = Provider::check(['endpoint' => "$base/200", 'key' => 'k', 'model' => '']);
            check($ok === true && $code === 200, '2xx must report ok=true: ' . json_encode([$ok, $code]));
            list($ok, $code) = Provider::check(['endpoint' => "$base/401", 'key' => 'k', 'model' => '']);
            check($ok === false && $code === 401, '401 must report ok=false with the real code: ' . json_encode([$ok, $code]));
            list($ok, $code) = Provider::check(['endpoint' => "$base/500", 'key' => 'k', 'model' => '']);
            check($ok === false && $code === 500, '500 must report ok=false with the real code: ' . json_encode([$ok, $code]));
            list($ok, $code) = Provider::check(['endpoint' => 'http://127.0.0.1:1', 'key' => 'k', 'model' => '']);
            check($ok === false && $code === 0, 'a dead port must report ok=false with code 0, never a 2xx false positive: ' . json_encode([$ok, $code]));
        } finally {
            coProviderServerDone($proc, $dir);
        }
    },
    'provider model(): a configured model needs zero HTTP calls ($ids stays untouched); an unconfigured one is /models\' first id, and $ids is populated by reference' => function () {
        list($proc, $base, $dir) = coProviderStart('
$calls = (int) @file_get_contents(PK_DIR . "/calls");
file_put_contents(PK_DIR . "/calls", (string) ($calls + 1));
echo json_encode(["data" => [["id" => "first-model"], ["id" => "second-model"]]]);
');
        try {
            $configured = ['endpoint' => $base, 'key' => 'k', 'model' => 'already-configured'];
            $ids = null;
            $m = Provider::model($configured, $ids);
            check($m === 'already-configured' && $ids === null, '$ids must stay null: models() must never be called for a configured model');
            check(!is_file("$dir/calls"), 'a configured model must make ZERO HTTP calls');
            $unconfigured = ['endpoint' => $base, 'key' => 'k', 'model' => ''];
            $ids2 = null;
            $m2 = Provider::model($unconfigured, $ids2);
            check($m2 === 'first-model', '/models\' first id must be used when no model is configured: ' . $m2);
            check($ids2 === ['first-model', 'second-model'], '$ids must be populated by reference from models(): ' . json_encode($ids2));
        } finally {
            coProviderServerDone($proc, $dir);
        }
    },
    'provider models(): zero HTTP requests are made when the endpoint or the key is blank' => function () {
        list($proc, $base, $dir) = coProviderStart('
$calls = (int) @file_get_contents(PK_DIR . "/calls");
file_put_contents(PK_DIR . "/calls", (string) ($calls + 1));
echo json_encode(["data" => []]);
');
        try {
            list($code, $ids) = Provider::models(['endpoint' => '', 'key' => 'k', 'model' => '']);
            check($code === 0 && $ids === [] && !is_file("$dir/calls"), 'a blank endpoint must make no request: ' . json_encode([$code, $ids]));
            list($code, $ids) = Provider::models(['endpoint' => $base, 'key' => '', 'model' => '']);
            check($code === 0 && $ids === [] && !is_file("$dir/calls"), 'a blank key must make no request: ' . json_encode([$code, $ids]));
            list($code, $ids) = Provider::models(['endpoint' => $base, 'key' => 'k', 'model' => '']);
            check($code === 200 && is_file("$dir/calls"), 'a real endpoint+key must actually call /models: ' . json_encode([$code, $ids]));
        } finally {
            coProviderServerDone($proc, $dir);
        }
    },
    'provider models(): malformed /models JSON yields only valid string ids, never a crash' => function () {
        list($proc, $base, $dir) = coProviderStart('
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$bodies = [
    "/a/models" => json_encode(["data" => "not-an-array"]),
    "/b/models" => json_encode(["nope" => []]),
    "/c/models" => json_encode(["data" => [["id" => "ok-1"], ["id" => 42], ["id" => ""], ["noId" => true], ["id" => "ok-2"]]]),
];
echo isset($bodies[$p]) ? $bodies[$p] : "not json at all {{{";
');
        try {
            list($code, $ids) = Provider::models(['endpoint' => "$base/a", 'key' => 'k', 'model' => '']);
            check($code === 200 && $ids === [], '"data" not a list must yield no ids, not a crash: ' . json_encode($ids));
            list($code, $ids) = Provider::models(['endpoint' => "$base/b", 'key' => 'k', 'model' => '']);
            check($code === 200 && $ids === [], 'a missing "data" key must yield no ids');
            list($code, $ids) = Provider::models(['endpoint' => "$base/c", 'key' => 'k', 'model' => '']);
            check($code === 200 && $ids === ['ok-1', 'ok-2'], 'a non-string id, a numeric id, an empty id and a missing id must all be '
                . 'dropped, keeping only the valid string ids in order: ' . json_encode($ids));
            list($code, $ids) = Provider::models(['endpoint' => "$base/d-not-json", 'key' => 'k', 'model' => '']);
            check($code === 200 && $ids === [], 'non-JSON /models output must yield no ids, not a crash');
        } finally {
            coProviderServerDone($proc, $dir);
        }
    },
    'provider llmFile() resolves under Config::localDir(); writeLight() still returns a value (never throws) when its directory is unwritable' => function () {
        check(Provider::llmFile() === Config::localDir() . '/llm.json', 'llmFile() must resolve under Config::localDir()');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            skip('running as root; permission bits do not block the write');
        }
        $dir = sys_get_temp_dir() . '/pk-unwritable-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0500, true); // read+execute, no write
        $file = "$dir/llm.json";
        $prov = ['endpoint' => '', 'key' => '', 'model' => 'm-main'];
        try {
            $light = Provider::writeLight($prov, [], ['m-main'], $file);
            check($light === 'm-main', 'writeLight() must still return the decided light model when the write fails: ' . $light);
            check(!is_file($file), 'an unwritable directory must leave no llm.json behind (and no crash)');
        } finally {
            chmod($dir, 0700);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
