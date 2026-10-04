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
    'packs: every pack loads; packs.disabled and root.disabled leave packs out' => function () {
        $all = array_keys(Packs::all());
        check(in_array('root', $all, true) && in_array('pmss', $all, true), 'shipped packs missing');
        check(Packs::enabled(Config::defaults()) === $all, 'defaults must load every pack');
        $names = Packs::enabled(['packs' => ['disabled' => ['pmss']], 'root' => ['disabled' => false]]);
        check(!in_array('pmss', $names, true) && in_array('root', $names, true), 'packs.disabled ignored');
        $names = Packs::enabled(['packs' => ['disabled' => []], 'root' => ['disabled' => true]]);
        check(!in_array('root', $names, true) && in_array('pmss', $names, true), 'root.disabled must leave the root pack out');
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
    },
    'packs index lists every pack' => function () {
        $idx = Packs::renderIndex();
        foreach (array_keys(Packs::all()) as $name) {
            check(strpos($idx, '[' . $name . ']') !== false, "index misses $name");
        }
        $committed = (string) @file_get_contents(Packs::dir() . '/INDEX.md');
        check($committed === $idx, 'packs/INDEX.md is stale: run `pikkumies packs index` and commit it');
    },
    'hermes yaml: named provider with key_env, model, external dirs, consented hooks' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'https://x/v1', 'model' => 'm', 'key' => 'secret'], ['/a/skills'], ['pre_tool_call' => ['/h/g.php']]);
        // Hermes 0.19.0 reads key_env only under providers:<name>; a bare `provider: custom`
        // ignores model.key_env and the request goes out without the key (HTTP 401).
        check(strpos($y, "model:\n  provider: \"custom:pikkumies\"\n  default: \"m\"\n") !== false, 'model must name the custom:pikkumies provider and the model');
        check(preg_match('/^providers:\n  pikkumies:\n    base_url: "https:\/\/x\/v1"\n    key_env: PIKKUMIES_API_KEY\n    default_model: "m"\n/m', $y) === 1, 'providers.pikkumies block');
        check(preg_match('/^model:\n(  .*\n)*  key_env:/m', $y) === 0, 'key_env must not sit under model:');
        check(strpos($y, 'secret') === false, 'key leaked into config.yaml');
        check(strpos($y, '"/a/skills"') !== false, 'external dir');
        // Unapproved shell hooks are silently skipped in a non-interactive run.
        check(strpos($y, "hooks_auto_accept: true\nhooks:\n  pre_tool_call:\n") !== false && strpos($y, '/h/g.php') !== false, 'hook with consent');
        $noHooks = HermesConfig::renderYaml(['endpoint' => 'https://x/v1', 'model' => 'm', 'key' => 'secret'], ['/a/skills'], []);
        check(strpos($noHooks, 'hooks_auto_accept') === false, 'no consent line without hooks');
        check(strpos($noHooks, 'disabled:') === false, 'no disabled list when none set');
        $off = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a/skills'], [], ['seedbox-apps']);
        check(strpos($off, "skills:\n  external_dirs:\n    - \"/a/skills\"\n  disabled:\n    - \"seedbox-apps\"\n") !== false, 'skills.disabled must render under skills:');
        check(strpos($y, 'fail_closed') === false, 'fail_closed stays at the Hermes default');
        check(strpos($noHooks, "updates:\n  check: false\n") !== false, 'update banners must be off: Hermes is pinned');
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
