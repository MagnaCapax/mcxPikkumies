<?php
/**
 * lib/HermesUserConfig.php and HermesConfig::writeConfig(): the user's Hermes config.yaml and .env persist across
 * starts; Pikkumies' pins live in the managed scope beside them; every edit Pikkumies makes to the user's files is
 * backed up and logged; existing installs move over once (ADR-0062). A fake hermes stands in for `config get/set`.
 */

/** A temp local/ with hermes/ in it, and a fake hermes that logs argv and answers `config get` from PK_FAKE_GET. */
function userCfgWorld()
{
    $t = testTempDir('pk-hcfg');
    mkdir("$t/local/hermes", 0700, true);
    $bin = "$t/fake-hermes";
    file_put_contents($bin, '#!' . PHP_BINARY . "\n<?php\n" . '
file_put_contents(getenv("PK_FAKE_LOG"), implode(" ", array_slice($argv, 1)) . " | managed=" . getenv("HERMES_MANAGED_DIR") . "\n", FILE_APPEND);
if ($argv[1] === "config" && $argv[2] === "get") { echo getenv("PK_FAKE_GET"), "\n"; exit(0); }
if ($argv[1] === "config" && $argv[2] === "set") { file_put_contents(getenv("HERMES_HOME") . "/config.yaml", "# set " . $argv[3] . " " . $argv[4] . "\n", FILE_APPEND); exit(0); }
exit(2);
');
    chmod($bin, 0755);
    return ['t' => $t, 'local' => "$t/local", 'home' => "$t/local/hermes", 'bin' => $bin, 'log' => "$t/fake.log"];
}

/** Run $fn with the fake hermes ($bin '' = not built), its log, and notes kept out of the test output. */
function userCfgIn(array $w, $bin, $get, callable $fn)
{
    HermesUserConfig::$bin = $bin;
    HermesConfigBackup::$notes = fopen('php://memory', 'w+');
    try {
        return testWithEnv(['PK_FAKE_LOG' => $w['log'], 'PK_FAKE_GET' => $get], $fn);
    } finally {
        HermesUserConfig::$bin = null;
        HermesConfigBackup::$notes = null;
    }
}

function userCfgProv()
{
    return ['endpoint' => 'https://e/v1', 'model' => 'm', 'key' => 'sk-secret-value-123'];
}

function userCfgLogLines(array $w)
{
    return is_file($w['local'] . '/state/hermes-config.jsonl') ? file($w['local'] . '/state/hermes-config.jsonl', FILE_IGNORE_NEW_LINES) : [];
}

return [
    'hermes user config: the first start seeds config.yaml with every setting, the pins go to the managed dir, the key to its 0600 .env' => function () {
        $w = userCfgWorld();
        try {
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                $seed = (string) file_get_contents($w['home'] . '/config.yaml');
                $pins = (string) file_get_contents($w['local'] . '/hermes-managed/config.yaml');
                check(strpos($seed, HermesConfig::seedHeader()) === 0 && strpos($seed, HermesConfig::q(Units::generatedDir())) !== false, 'seed: ' . $seed);
                check(strpos($seed, "checkpoints:\n  enabled: true\n") !== false && strpos($seed, "approvals:\n  mode: \"off\"\n") !== false, 'the seed carries every setting');
                check(strpos($pins, "approvals:\n  mode: \"off\"\n") !== false && strpos($pins, "hooks:\n") !== false && strpos($pins, 'external_dirs') === false, 'managed: ' . $pins);
                check((string) file_get_contents($w['local'] . '/hermes-managed/.env') === "PIKKUMIES_API_KEY=sk-secret-value-123\n"
                    && (fileperms($w['local'] . '/hermes-managed/.env') & 0777) === 0600, 'the key goes to the managed .env, 0600');
                check(!file_exists($w['home'] . '/.env'), 'Pikkumies no longer writes the user\'s .env');
                check(strpos(implode("\n", userCfgLogLines($w)), '"action":"seed"') !== false, 'the seed is logged');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: a change made in Hermes (config.yaml and .env) survives the next start byte for byte' => function () {
        $w = userCfgWorld();
        try {
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                file_put_contents($w['home'] . '/config.yaml', "display:\n  skin: mono\n", FILE_APPEND);
                file_put_contents($w['home'] . '/.env', "EXA_API_KEY=user-search-key\n");
                $cfg = hash_file('sha256', $w['home'] . '/config.yaml');
                $env = hash_file('sha256', $w['home'] . '/.env');
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check(hash_file('sha256', $w['home'] . '/config.yaml') === $cfg, 'config.yaml was rewritten: ' . file_get_contents($w['home'] . '/config.yaml'));
                check(hash_file('sha256', $w['home'] . '/.env') === $env, '.env was rewritten: ' . file_get_contents($w['home'] . '/.env'));
                check(!is_dir($w['local'] . '/backups/hermes'), 'nothing to back up on a start that changed nothing');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: a missing skills dir goes back through hermes config get/set, keeping the user\'s entries, backed up and logged' => function () {
        $w = userCfgWorld();
        try {
            $before = "# mine\nskills:\n  external_dirs:\n    - /home/u/more-skills\n" . HermesTelemetry::seedYaml(HermesTelemetry::DEFAULT_OFFER_VERSION); // answer settled: only the skills dir is due
            file_put_contents($w['home'] . '/config.yaml', $before);
            @mkdir($w['local'] . '/state', 0700);
            HermesConfig::put(HermesUserConfig::marker($w['local']), "{}\n");
            userCfgIn($w, $w['bin'], '["/home/u/more-skills"]', function () use ($w, $before) {
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', []);
                $log = (string) file_get_contents($w['log']);
                check(strpos($log, "config get skills.external_dirs --json --raw | managed=" . $w['local'] . "/hermes-managed\n") !== false, 'get, with the managed dir: ' . $log);
                check(strpos($log, 'config set skills.external_dirs ["/home/u/more-skills","/gen/skills"]') !== false, 'the user\'s entry kept, ours appended: ' . $log);
                $backups = glob($w['local'] . '/backups/hermes/config.yaml.*');
                check(count($backups) === 1 && file_get_contents($backups[0]) === $before && (fileperms($backups[0]) & 0777) === 0600, 'backup of the old bytes, 0600');
                $lines = userCfgLogLines($w);
                $line = json_decode((string) end($lines), true);
                check($line['action'] === 'repair-external-dirs' && $line['sha256_before'] === hash('sha256', $before)
                    && $line['sha256_after'] === hash_file('sha256', $w['home'] . '/config.yaml') && $line['key'] === 'skills.external_dirs', 'log: ' . json_encode($line));
                file_put_contents($w['log'], '');
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', []);
                check((string) file_get_contents($w['log']) === '', 'the path is there now: no Hermes process');
            });
            file_put_contents($w['home'] . '/config.yaml', $before);
            userCfgIn($w, $w['bin'], '["/home/u/more-skills","/gen/skills"]', function () use ($w) {
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', []);
                check(strpos((string) file_get_contents($w['log']), 'config set') === false, 'Hermes already lists it (written some other way): never added twice');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: an old install moves over once: header line and our key line go, every other byte stays' => function () {
        $w = userCfgWorld();
        try {
            $body = "model:\n  provider: \"custom:pikkumies\"\nskills:\n  external_dirs:\n    - \"/gen/skills\"\n";
            file_put_contents($w['home'] . '/config.yaml', "# Generated by pikkumies. Edit ~/pikkumies/local/config.php instead; this file is rewritten.\n" . $body);
            file_put_contents($w['home'] . '/.env', "PIKKUMIES_API_KEY=sk-old\nFOO=bar\n");
            chmod($w['home'] . '/.env', 0600);
            userCfgIn($w, '', '', function () use ($w, $body) {
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', []);
                check((string) file_get_contents($w['home'] . '/config.yaml') === HermesConfig::seedHeader() . $body, 'config.yaml: ' . file_get_contents($w['home'] . '/config.yaml'));
                check((string) file_get_contents($w['home'] . '/.env') === "FOO=bar\n" && (fileperms($w['home'] . '/.env') & 0777) === 0600, '.env: only our line goes, mode kept');
                check(count(glob($w['local'] . '/backups/hermes/*')) === 2 && is_file(HermesUserConfig::marker($w['local'])), 'two backups and the marker');
                $lines = count(userCfgLogLines($w));
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', []);
                check(count(glob($w['local'] . '/backups/hermes/*')) === 2 && count(userCfgLogLines($w)) === $lines, 'a second start changes nothing');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: without Hermes built the migration waits (no marker), and finishes once Hermes is there' => function () {
        $w = userCfgWorld();
        try {
            file_put_contents($w['home'] . '/config.yaml', "# Generated by pikkumies. x; this file is rewritten.\nskills:\n  external_dirs:\n    - \"/gen/skills\"\n  disabled:\n    - \"seedbox-apps\"\n");
            userCfgIn($w, '', '', function () use ($w) {
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', ['seedbox-apps']);
                check(strpos((string) file_get_contents($w['home'] . '/config.yaml'), HermesConfig::seedHeader()) === 0, 'the header step does not need Hermes');
                check(!is_file(HermesUserConfig::marker($w['local'])), 'the marker waits for the skills.disabled step');
                $backups = count(glob($w['local'] . '/backups/hermes/*'));
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', ['seedbox-apps']);
                check(count(glob($w['local'] . '/backups/hermes/*')) === $backups, 'a retry while Hermes builds adds no backup');
            });
            userCfgIn($w, $w['bin'], '["seedbox-apps"]', function () use ($w) {
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', ['seedbox-apps']);
                check(strpos((string) file_get_contents($w['log']), 'config set skills.disabled []') !== false, 'log: ' . file_get_contents($w['log']));
                check(is_file(HermesUserConfig::marker($w['local'])), 'marker once every step is done');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: only hidden shipped skills leave Hermes\' skills.disabled; a JSON-string answer is read too' => function () {
        $w = userCfgWorld();
        try {
            file_put_contents($w['home'] . '/config.yaml', "skills:\n  external_dirs:\n    - \"/gen/skills\"\n  disabled:\n    - \"seedbox-apps\"\n    - hermes-own\n");
            userCfgIn($w, $w['bin'], json_encode(json_encode(['seedbox-apps', 'hermes-own'])), function () use ($w) {
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', ['seedbox-apps']);
                $log = (string) file_get_contents($w['log']);
                check(strpos($log, 'config set skills.disabled ["hermes-own"]') !== false, 'the user\'s own name stays: ' . $log);
                check(is_file(HermesUserConfig::marker($w['local'])), 'marker written');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: a managed file that cannot be written stops the start (no run without the hooks)' => function () {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            skip('running as root; permission bits do not block the write');
        }
        $w = userCfgWorld();
        mkdir($w['local'] . '/hermes-managed', 0500);
        $thrown = null;
        try {
            userCfgIn($w, '', '', function () use ($w, &$thrown) {
                try {
                    HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                } catch (Exception $x) {
                    $thrown = $x;
                }
            });
            check($thrown !== null && strpos($thrown->getMessage(), 'hermes-managed') !== false, 'must throw naming the managed file');
        } finally {
            chmod($w['local'] . '/hermes-managed', 0700);
            testRmTree($w['t']);
        }
    },
    'hermes user config: no key value and no .env content reaches the log or a backup name' => function () {
        $w = userCfgWorld();
        try {
            file_put_contents($w['home'] . '/config.yaml', "# Generated by pikkumies. x; this file is rewritten.\nskills:\n  external_dirs:\n    - \"/gen/skills\"\n");
            file_put_contents($w['home'] . '/.env', "PIKKUMIES_API_KEY=sk-secret-value-123\nOTHER=other-secret-456\n");
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                $log = implode("\n", userCfgLogLines($w)) . implode("\n", glob($w['local'] . '/backups/hermes/*'));
                check(strpos($log, 'sk-secret') === false && strpos($log, 'other-secret') === false && strpos($log, 'migrate-env-key') !== false, 'log: ' . $log);
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes user config: the old header seen again (an older version ran) runs the migration again' => function () {
        $w = userCfgWorld();
        try {
            @mkdir($w['local'] . '/state', 0700);
            HermesConfig::put(HermesUserConfig::marker($w['local']), "{}\n");
            file_put_contents($w['home'] . '/config.yaml', "# Generated by pikkumies. y; this file is rewritten.\nskills:\n  external_dirs:\n    - \"/gen/skills\"\n");
            userCfgIn($w, '', '', function () use ($w) {
                check(HermesUserConfig::migrationDue($w['home'] . '/config.yaml'), 'old line 1 means due, marker or not');
                HermesUserConfig::apply($w['home'], 'seed', '/gen/skills', []);
                check(!HermesUserConfig::oldHeader($w['home'] . '/config.yaml') && !HermesUserConfig::migrationDue($w['home'] . '/config.yaml'), 'moved over again');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
];
