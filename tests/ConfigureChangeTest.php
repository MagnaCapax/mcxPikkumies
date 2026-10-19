<?php
/**
 * ConfigureChange (ADR-0059): a change is refused when it is the person's, a model id is checked against the
 * endpoint's list (fail closed), lists are checked against what is shipped, every change is logged with its old
 * value, and undo is a change like any other, never a trusted replay of the log. Everything writes into a temp dir
 * ($file and $log), never this checkout's local/.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';

if (!function_exists('cfgChangeDir')) {
    /** [config.php path, log path, temp dir]. */
    function cfgChangeDir($tag)
    {
        $dir = testTempDir("pk-cfgchange-$tag");
        return ["$dir/config.php", "$dir/state/configure-changes.jsonl", $dir];
    }

    /** A php -S endpoint whose GET /models lists m-1 (with rates) and m-2 (without); returns [proc, base url, dir]. */
    function cfgModelsServer()
    {
        $dir = testTempDir('pk-cfgmodels');
        file_put_contents("$dir/router.php", '<?php
header("Content-Type: application/json");
if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/v1/models") {
    echo json_encode(["data" => [["id" => "m-1", "credits_per_million_tokens" => ["input" => 100, "output" => 400]], ["id" => "m-2"]]]);
    return;
}
http_response_code(404);');
        $port = testFreePort();
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
        file_put_contents("$dir/key", "key=k-test\n");
        chmod("$dir/key", 0600);
        return [$proc, "http://127.0.0.1:$port/v1", $dir];
    }

    /** The defaults with the provider pointed at $endpoint and $dir/key. */
    function cfgModelsConfig($endpoint, $dir)
    {
        $c = Config::defaults();
        $c['provider'] = ['endpoint' => $endpoint, 'model' => '', 'keyFile' => "$dir/key", 'virsilipasKeyFile' => "$dir/none"];
        return $c;
    }

    /** The CliError message $fn throws, or '' when it throws none. */
    function cfgRefusal(callable $fn)
    {
        try {
            $fn();
        } catch (CliError $x) {
            return $x->getMessage() . ' | ' . $x->hint;
        }
        return '';
    }
}

return [
    'change: a free change is written and logged with its old value; a nested key and an *arr path are written at their depth' => function () {
        list($file, $log, $dir) = cfgChangeDir('free');
        try {
            $r = ConfigureChange::apply(Config::defaults(), 'halt.disabled', false, $file, $log);
            check($r['tier'] === 'free' && $r['old'] === false && $r['value'] === false, 'record: ' . json_encode($r));
            $c = Config::defaults();
            $c['hooks']['llm']['disabled'] = true;
            ConfigureChange::apply($c, 'hooks.llm.disabled', false, $file, $log);
            file_put_contents("$dir/sonarr.xml", '<Config/>');
            ConfigureChange::apply($c, 'arr.sonarr.config', "$dir/sonarr.xml", $file, $log);
            $local = require $file;
            check($local['hooks']['llm']['disabled'] === false && $local['arr']['sonarr']['config'] === "$dir/sonarr.xml", 'nested writes: ' . json_encode($local));
            $lines = cliLines((string) file_get_contents($log));
            check(count($lines) === 3 && $lines[1]['key'] === 'hooks.llm.disabled' && $lines[1]['old'] === true && $lines[1]['new'] === false, 'log: ' . json_encode($lines));
            check((fileperms($log) & 0777) === 0600, 'the log is the user\'s only');
        } finally {
            testRmTree($dir);
        }
    },
    'change: the person\'s direction and keys are refused with how, and nothing is written or logged' => function () {
        list($file, $log, $dir) = cfgChangeDir('person');
        try {
            foreach ([['searchFirst.disabled', true], ['hermes.lazyInstalls', true], ['autoUpdate.disabled', true], ['fileopsCheck.disabled', true]] as $c) {
                $why = cfgRefusal(function () use ($c, $file, $log) {
                    ConfigureChange::apply(Config::defaults(), $c[0], $c[1], $file, $log);
                });
                check(strpos($why, 'yours to change') !== false && strpos($why, 'local/config.php') !== false && strpos($why, 'comments in it are not kept') !== false, "$c[0]: $why");
            }
            check(cfgRefusal(function () use ($file, $log) {
                ConfigureChange::apply(Config::defaults(), 'nonsense.key', true, $file, $log);
            }) !== '', 'an unknown key');
            // Each key says what its own change does: no "safety layer" for a key that is not one.
            check(strpos(ConfigureChange::how('autoUpdate.disabled'), 'safety layer') === false && strpos(ConfigureChange::how('autoUpdate.disabled'), 'security') !== false, 'autoUpdate');
            check(strpos(ConfigureChange::how('halt.disabled'), 'halt guard') !== false && strpos(ConfigureChange::how('hermes.lazyInstalls'), 'download') !== false, 'per-key text');
            check(!is_file($file) && !is_file($log), 'a refused change wrote something');
        } finally {
            testRmTree($dir);
        }
    },
    'change: tool categories and skills are checked against what is shipped; disabling a category names the rules that leave SOUL.md' => function () {
        list($file, $log, $dir) = cfgChangeDir('lists');
        try {
            check(strpos(cfgRefusal(function () use ($file, $log) {
                ConfigureChange::apply(Config::defaults(), 'tools.disabled', ['no-such-category'], $file, $log);
            }), 'not among the tool categories') !== false, 'an unknown category');
            $plan = ConfigureChange::plan(Config::defaults(), 'tools.disabled', ['email']);
            check($plan['tier'] === 'approve' && strpos($plan['why'], 'the rules of email') !== false, 'email has a DOCTRINE.md: ' . json_encode($plan));
            check(strpos(cfgRefusal(function () use ($file, $log) {
                ConfigureChange::skills(Config::defaults(), 'disable', 'no-such-skill', $file, $log);
            }), 'not among the shipped skills') !== false, 'an unknown skill');
            $r = ConfigureChange::skills(Config::defaults(), 'disable', 'seedbox-apps', $file, $log);
            check($r['value'] === ['seedbox-apps'] && $r['tier'] === 'free', 'disable: ' . json_encode($r));
            $c = Config::merge(Config::defaults(), require $file);
            check(ConfigureChange::skills($c, 'enable', 'seedbox-apps', $file, $log)['value'] === [], 'enable');
            check(strpos(cfgRefusal(function () use ($file, $log) {
                ConfigureChange::apply(Config::defaults(), 'arr.nosuchapp.config', '/x', $file, $log);
            }), 'no shipped *arr tool') !== false, 'an *arr path for a tool that does not exist');
        } finally {
            testRmTree($dir);
        }
    },
    'change: a model id is checked against GET /models: listed is written with its rate, unlisted, unreachable or keyless is refused' => function () {
        list($file, $log, $dir) = cfgChangeDir('model');
        list($proc, $base, $sdir) = cfgModelsServer();
        try {
            testWithEnv(['PIKKUMIES_ENDPOINT' => null, 'PIKKUMIES_KEY' => null], function () use ($file, $log, $base, $sdir) {
                $c = cfgModelsConfig($base, $sdir);
                $r = ConfigureChange::apply($c, 'provider.model', 'm-1', $file, $log);
                check($r['value'] === 'm-1' && $r['credits_per_million_tokens'] === ['input' => 100, 'output' => 400] && $r['tier'] === 'approve', 'listed: ' . json_encode($r));
                check(!isset(ConfigureChange::apply($c, 'hooks.model', 'm-2', $file, $log)['credits_per_million_tokens']), 'no rate sent, none shown');
                $before = (string) file_get_contents($file);
                $why = cfgRefusal(function () use ($c, $file, $log) {
                    ConfigureChange::apply($c, 'provider.model', 'm-9', $file, $log);
                });
                check(strpos($why, 'does not list the model m-9') !== false, 'unlisted: ' . $why);
                $down = cfgModelsConfig('http://127.0.0.1:' . testFreePort() . '/v1', $sdir);
                check(strpos(cfgRefusal(function () use ($down, $file, $log) {
                    ConfigureChange::apply($down, 'provider.model', 'm-1', $file, $log);
                }), 'could not reach') !== false, 'unreachable endpoint: fail closed');
                $keyless = cfgModelsConfig($base, $sdir . '/nowhere');
                check(strpos(cfgRefusal(function () use ($keyless, $file, $log) {
                    ConfigureChange::apply($keyless, 'provider.model', 'm-1', $file, $log);
                }), 'no AI endpoint and key') !== false, 'no key: fail closed');
                check((string) file_get_contents($file) === $before, 'a refused model changed the config');
                check(ConfigureChange::apply($down, 'provider.model', '', $file, $log)['value'] === '', 'empty (the default) needs no list');
            });
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($sdir);
            testRmTree($dir);
        }
    },
    'change: a key the PIKKUMIES_CONFIG overlay forces is not changed, since the change would not show' => function () {
        list($file, $log, $dir) = cfgChangeDir('ovl');
        try {
            // The reviewer's case: the overlay forces a model, so setting that model looks like no change (free, no
            // model check) and would write it into local/config.php for good.
            file_put_contents("$dir/forced.php", "<?php return ['provider' => ['model' => 'm-forced']];\n");
            chmod("$dir/forced.php", 0600);
            $why = testWithEnv(['PIKKUMIES_CONFIG' => "$dir/forced.php"], function () use ($file, $log) {
                return cfgRefusal(function () use ($file, $log) {
                    ConfigureSettings::set('provider.model', 'm-forced', $file, Config::load(), $log);
                });
            });
            check(strpos($why, 'forced by the PIKKUMIES_CONFIG overlay') !== false && !is_file($file), 'under an overlay: ' . $why);
            check(ConfigureSettings::set('halt.disabled', 'off', $file, null, $log)['value'] === false, 'without one the same tool works');
        } finally {
            testRmTree($dir);
        }
    },
    'change: a question-suite overlay (provider and host only) does not stop telemetry-off or a rename; a key it forces is refused (re-review N2)' => function () {
        require_once dirname(__DIR__) . '/tests/testing/questions/lib/Targets.php';
        list($file, $log, $dir) = cfgChangeDir('qa');
        try {
            // The overlay the suite writes for every question (Targets::settings), with a light model and a host family.
            $qa = Targets::settings(['endpoint' => 'http://127.0.0.1:9/v1', 'keyFile' => "$dir/key", 'model' => 'qa-model', 'lightModel' => 'qa-mini'], 'pmss');
            file_put_contents("$dir/qa.php", '<?php return ' . var_export($qa, true) . ";\n");
            chmod("$dir/qa.php", 0600);
            testWithEnv(['PIKKUMIES_CONFIG' => "$dir/qa.php"], function () use ($file, $log) {
                check(Config::overlayPath() !== '', 'the QA overlay must be in effect for this test to mean anything');
                check(ConfigureSettings::set('privacy.telemetry', 'off', $file, null, $log)['value'] === false, 'telemetry-off under the QA overlay');
                check(ConfigureSettings::set('agent.name', 'Kalle', $file, null, $log)['value'] === 'Kalle', 'rename under the QA overlay');
                // A key the overlay itself forces is refused: a change in local/config.php would not show while it is in effect.
                check(strpos(cfgRefusal(function () use ($file, $log) {
                    ConfigureSettings::set('hooks.model', 'other-mini', $file, null, $log);
                }), 'forced by the PIKKUMIES_CONFIG overlay') !== false, 'an overlay-forced key');
            });
            $local = require $file;
            check($local['privacy']['telemetry'] === false && $local['agent']['name'] === 'Kalle' && !isset($local['hooks']), 'written: ' . json_encode($local));
        } finally {
            testRmTree($dir);
        }
    },
    'change: arr.<app>.config must name a file this account owns' => function () {
        list($file, $log, $dir) = cfgChangeDir('arrown');
        try {
            check(strpos(cfgRefusal(function () use ($file, $log, $dir) {
                ConfigureChange::apply(Config::defaults(), 'arr.sonarr.config', "$dir/missing.xml", $file, $log);
            }), 'not a file this account owns') !== false, 'a missing file');
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            if ($uid !== 0 && @fileowner('/etc/passwd') === 0) {
                check(strpos(cfgRefusal(function () use ($file, $log) {
                    ConfigureChange::apply(Config::defaults(), 'arr.sonarr.config', '/etc/passwd', $file, $log);
                }), 'not a file this account owns') !== false, 'a file of another account');
            }
            check(ConfigureChange::apply(Config::defaults(), 'arr.sonarr.config', '', $file, $log)['value'] === '', 'empty removes the override');
        } finally {
            testRmTree($dir);
        }
    },
    'change: the text form parses lists and models, and set() passes the person\'s keys to how' => function () {
        check(Config::parse('tools.disabled', 'email, backup email') === ['email', 'backup'] && Config::parse('tools.disabled', 'none') === [], 'names');
        check(Config::parse('provider.model', ' m-1 ') === 'm-1' && Config::parse('hooks.model', '') === '', 'model');
        check(Config::parse('arr.sonarr.config', '/a/config.xml') === '/a/config.xml', 'path');
        foreach ([['provider.model', 'two words'], ['tools.disabled', 'Email'], ['arr.sonarr.config', 'rel/path'], ['arr.sonarr.port', '1']] as $b) {
            $threw = false;
            try {
                Config::parse($b[0], $b[1]);
            } catch (InvalidArgumentException $x) {
                $threw = true;
            }
            check($threw, 'parse accepted ' . json_encode($b));
        }
        list($file, $log, $dir) = cfgChangeDir('text');
        try {
            check(strpos(cfgRefusal(function () use ($file, $log) {
                ConfigureSettings::set('host.family', 'pmss', $file, Config::defaults(), $log);
            }), 'detection') !== false, 'host.* is the person\'s');
            check(ConfigureSettings::set('tools.disabled', 'email', $file, Config::defaults(), $log)['value'] === ['email'], 'a list from text');
        } finally {
            testRmTree($dir);
        }
    },
    'change: SSH lists (ADR-0068) take host names and * ? patterns, lowercased; a bad pattern is refused; removing a deny entry is the person\'s' => function () {
        list($file, $log, $dir) = cfgChangeDir('ssh');
        try {
            check(ConfigureSettings::set('ssh.allow', 'NAS.example, *.home.example ::1', $file, null, $log)['value'] === ['nas.example', '*.home.example', '::1'], 'allow from text');
            foreach (['bad host!', '[a-z]*', '-oProxyCommand=x', 'a/b', "n\u{202e}as"] as $bad) {
                check(strpos(cfgRefusal(function () use ($file, $log, $bad) {
                    ConfigureSettings::set('ssh.allow', $bad, $file, null, $log);
                }), 'host names, addresses or patterns') !== false, "$bad must be refused");
            }
            check(ConfigureSettings::set('ssh.deny', 'evil.example', $file, null, $log)['value'] === ['evil.example'], 'adding a deny entry is free');
            foreach (['other.example', 'none'] as $swap) {
                $why = cfgRefusal(function () use ($file, $log, $swap) {
                    ConfigureSettings::set('ssh.deny', $swap, $file, null, $log);
                });
                check(strpos($why, 'removing a host from the SSH deny list') !== false, "deny -> $swap must be the person's: $why");
            }
            check(ConfigureSettings::set('ssh.deny', 'evil.example,other.example', $file, null, $log)['value'] === ['evil.example', 'other.example'], 'adding keeps the old entry: free');
            $threw = false;
            try {
                Config::set('ssh.deny', ['other.example'], $file);
            } catch (InvalidArgumentException $x) {
                $threw = strpos($x->getMessage(), "person's") !== false;
            }
            check($threw, 'Config::set must refuse a deny swap for every caller');
            check(Config::fileConfig($file)['ssh']['deny'] === ['evil.example', 'other.example'], 'the deny list is unchanged');
        } finally {
            testRmTree($dir);
        }
    },
];
