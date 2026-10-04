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
    'pack detect rules' => function () {
        $profile = ['pmss' => true, 'root' => ['available' => false], 'apps' => ['rtorrent']];
        $cfg = ['root' => ['disabled' => false]];
        check(!Packs::matches([], $profile, $cfg), 'empty rule matched');
        check(Packs::matches(['pmss' => true], $profile, $cfg), 'pmss');
        check(!Packs::matches(['root' => true], $profile, $cfg), 'root without root');
        check(Packs::matches(['apps' => ['rtorrent']], $profile, $cfg), 'apps');
        $rootProfile = ['pmss' => false, 'root' => ['available' => true], 'apps' => []];
        check(!Packs::matches(['root' => true], $rootProfile, ['root' => ['disabled' => true]]), 'root disabled ignored');
    },
    'shipped packs: auto-enable on a pmss box without root' => function () {
        $profile = ['pmss' => true, 'root' => ['available' => false], 'apps' => []];
        $names = Packs::enabled($profile, ['packs' => ['enabled' => [], 'auto' => true], 'root' => ['disabled' => false]]);
        check(in_array('pmss', $names, true), 'pmss not enabled');
        check(!in_array('root', $names, true), 'root enabled without root');
    },
    'packs index lists every pack' => function () {
        $idx = Packs::renderIndex();
        foreach (array_keys(Packs::all()) as $name) {
            check(strpos($idx, '[' . $name . ']') !== false, "index misses $name");
        }
        $committed = (string) @file_get_contents(Packs::dir() . '/INDEX.md');
        check($committed === $idx, 'packs/INDEX.md is stale: run `pikkumies packs index` and commit it');
    },
    'hermes yaml: custom provider, key by env, external dirs, hooks' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'https://x/v1', 'model' => 'm', 'key' => 'secret'], ['/a/skills'], ['pre_tool_call' => ['/h/g.php']]);
        check(strpos($y, 'provider: custom') !== false, 'provider');
        check(strpos($y, 'key_env: PIKKUMIES_API_KEY') !== false, 'key_env');
        check(strpos($y, 'secret') === false, 'key leaked into config.yaml');
        check(strpos($y, '"/a/skills"') !== false, 'external dir');
        check(strpos($y, 'pre_tool_call:') !== false && strpos($y, '/h/g.php') !== false, 'hook');
    },
    'yaml quoting escapes quotes and backslashes' => function () {
        check(HermesConfig::q('a"b\\c') === '"a\\"b\\\\c"', 'bad quoting');
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
