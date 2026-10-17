<?php
/** User settings, Config::set, the pikkumies-configure tool and the Hermes sessions block (ADR-0032). */
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';

/** A fresh local/config.php stand-in under the temp dir. */
function settingsFile($tag)
{
    $dir = sys_get_temp_dir() . '/pk-settings-' . $tag . '-' . getmypid();
    @mkdir($dir, 0700, true);
    @unlink("$dir/config.php");
    return "$dir/config.php";
}

/** Every dotted key of the defaults, down to the leaves (lists count as leaves). */
function configLeaves(array $a, $prefix = '')
{
    $out = [];
    foreach ($a as $k => $v) {
        if (is_array($v) && $v !== [] && !Config::isList($v)) {
            $out = array_merge($out, configLeaves($v, $prefix . $k . '.'));
        } else {
            $out[] = $prefix . $k;
        }
    }
    return $out;
}

return [
    'settings: defaults are unnamed, perf logging and telemetry on, sessions kept forever, terms not accepted' => function () {
        $d = Config::defaults();
        check($d['agent']['name'] === '' && $d['privacy']['perfLogging'] === true && $d['privacy']['telemetry'] === true, 'agent/privacy defaults');
        check($d['sessions']['retentionDays'] === 0 && $d['terms']['accepted'] === false, 'sessions/terms defaults');
    },
    'settings: Config::set writes only known keys of the right type, atomically, keeping other overrides' => function () {
        $file = settingsFile('set');
        file_put_contents($file, "<?php\nreturn ['provider' => ['endpoint' => 'https://e/v1']];\n");
        Config::set('agent.name', 'Little Helper', $file);
        Config::set('privacy.telemetry', false, $file);
        Config::set('sessions.retentionDays', 90, $file);
        $local = require $file;
        check($local['provider']['endpoint'] === 'https://e/v1', 'existing override lost');
        check($local['agent']['name'] === 'Little Helper' && $local['privacy']['telemetry'] === false && $local['sessions']['retentionDays'] === 90, 'values not written: ' . json_encode($local));
        check((fileperms($file) & 0777) === 0600, 'config.php must be 0600');
        check(!glob($file . '.tmp-*'), 'temporary file left behind');
        $bad = [['terms.accepted', true], ['hooks.reword', false], ['provider.endpoint', 'x'], ['privacy.telemetry', 'off'], ['sessions.retentionDays', -1],
            ['sessions.retentionDays', '30'], ['agent.name', "a\nb"], ['agent.name', str_repeat('x', 33)], ['agent.name', 'Bob' . "\n"], ['root.disabled', true]];
        foreach ($bad as $b) {
            $threw = false;
            try {
                Config::set($b[0], $b[1], $file);
            } catch (InvalidArgumentException $x) {
                $threw = true;
            }
            check($threw, 'Config::set accepted ' . json_encode($b));
        }
        check((require $file) === $local, 'a refused set changed the file');
    },
    'settings: terms are accepted only through acceptTerms, never Config::set' => function () {
        $file = settingsFile('terms');
        Config::acceptTerms($file);
        $local = require $file;
        check($local['terms']['accepted'] === true, 'acceptTerms did not write');
        check(!isset(Config::SETTABLE['terms.accepted']), 'terms.accepted must not be settable');
    },
    'settings: parse turns on/off, days and names into typed values and refuses the rest' => function () {
        check(Config::parse('privacy.telemetry', 'off') === false && Config::parse('privacy.perfLogging', 'ON') === true, 'bool parse');
        check(Config::parse('sessions.retentionDays', '0') === 0 && Config::parse('sessions.retentionDays', '365') === 365, 'days parse');
        check(Config::parse('agent.name', '  Väinö  ') === 'Väinö', 'unicode name');
        foreach ([['privacy.telemetry', 'maybe'], ['sessions.retentionDays', '1.5'], ['sessions.retentionDays', '99999'], ['agent.name', '-dash'], ['terms.accepted', 'yes']] as $b) {
            $threw = false;
            try {
                Config::parse($b[0], $b[1]);
            } catch (InvalidArgumentException $x) {
                $threw = true;
            }
            check($threw, 'parse accepted ' . json_encode($b));
        }
    },
    'configure: set is limited to name, perf logging, telemetry and retention; every other key is refused' => function () {
        $allowed = ['agent.name', 'privacy.perfLogging', 'privacy.telemetry', 'sessions.retentionDays'];
        check(array_keys(Config::SETTABLE) === $allowed, 'the settable list changed: ' . json_encode(array_keys(Config::SETTABLE)));
        $file = settingsFile('tool');
        $refuse = array_merge(['terms.accepted', 'hooks.reword', 'hooks.llm.disabled', 'searchFirst.disabled', 'root.disabled', 'provider.endpoint', 'provider.keyFile',
            'tools.disabled', 'skills.disabled', 'cron.disabled', 'autoUpdate.disabled', 'hermes.lazyInstalls', 'nonsense'], array_diff(configLeaves(Config::defaults()), $allowed));
        foreach (array_unique($refuse) as $key) {
            $threw = false;
            try {
                ConfigureSettings::set($key, 'on', $file);
            } catch (CliError $x) {
                $threw = $x->hint !== '';
            }
            check($threw, "configure set accepted $key (or refused it without saying how the person changes it)");
        }
        check(!is_file($file), 'a refused set wrote the config');
        try {
            ConfigureSettings::set('terms.accepted', 'yes', $file);
        } catch (CliError $x) {
            check(strpos($x->hint, '--accept-terms') !== false, 'terms refusal must name the human way');
        }
    },
    'configure: set, get, show and review round-trip; review warns about sessions kept forever' => function () {
        $file = settingsFile('round');
        $r = ConfigureSettings::set('agent.name', 'Helper', $file);
        check($r['value'] === 'Helper', 'set record');
        ConfigureSettings::set('privacy.telemetry', 'off', $file);
        $config = Config::merge(Config::defaults(), require $file);
        check(ConfigureSettings::get($config, 'agent.name')['value'] === 'Helper', 'get after set');
        $show = array_column(ConfigureSettings::show($config), 'value', 'key');
        check($show['privacy.telemetry'] === false && $show['sessions.retentionDays'] === 0 && count($show) === 5, 'show: ' . json_encode($show));
        $review = [];
        foreach (ConfigureSettings::review($config) as $row) {
            check($row['means'] !== '', $row['key'] . ' has no explanation');
            $review[$row['key']] = $row;
        }
        check(isset($review['sessions.retentionDays']['warning']) && strpos($review['sessions.retentionDays']['warning'], 'state.db') !== false, 'infinite retention must warn');
        check($review['terms.accepted']['settable'] === false && $review['agent.name']['settable'] === true, 'settable flags');
        ConfigureSettings::set('sessions.retentionDays', '30', $file);
        $config = Config::merge(Config::defaults(), require $file);
        $rows = array_column(ConfigureSettings::review($config), null, 'key');
        check(!isset($rows['sessions.retentionDays']['warning']), 'no retention warning with a limit');
    },
    'configure: the tool answers its verbs and refuses terms over the command line' => function () {
        $script = Units::toolsDir() . '/system/pikkumies-configure/pikkumies-configure.php';
        list($code, $out) = runPhp($script, ['set', 'terms.accepted', 'yes']);
        check($code === 1 && strpos($out, '"ok":false') !== false && strpos($out, 'accept-terms') !== false, 'terms set over CLI not refused: ' . $out);
        list($code, $out) = runPhp($script, ['show']);
        check($code === 0 && strpos($out, '"key":"privacy.telemetry"') !== false, 'show failed: ' . $out);
    },
    'hermes sessions: retentionDays 0 turns pruning off; N turns it on with retention_days N; Hermes telemetry stays off' => function () {
        $forever = HermesConfig::sessionsYaml(Config::defaults());
        check(strpos($forever, "sessions:\n  auto_prune: false\n") === 0 && strpos($forever, 'retention_days') === false, 'forever: ' . $forever);
        $n = HermesConfig::sessionsYaml(['sessions' => ['retentionDays' => 45]]);
        check(strpos($n, "sessions:\n  auto_prune: true\n  retention_days: 45\n") === 0, 'N days: ' . $n);
        check(strpos($forever, "telemetry:\n  shared_metrics:\n    enabled: false\n    send: false\n") !== false, 'Hermes telemetry must be pinned off');
        $src = (string) file_get_contents(Config::root() . '/lib/HermesConfig.php');
        check(strpos($src, '. self::sessionsYaml($config));') !== false, 'write() must append the sessions block to config.yaml');
    },
    'hermes sessions: the installed Hermes still has the sessions and telemetry keys we set' => function () {
        $src = HermesInstall::sourceDir();
        if ($src === '') {
            skip('Hermes is not installed in pikkumies/hermes here (CI); the key names were checked by hand (ADR-0032)');
        }
        $py = (string) file_get_contents($src . '/hermes_cli/config_defaults.py');
        check(preg_match('/"sessions": \{.*?"auto_prune": True,.*?"retention_days": \d+,/s', $py) === 1, 'Hermes sessions.auto_prune / retention_days moved');
        check(preg_match('/"shared_metrics": \{\s*"enabled": False,.*?"send": False,/s', $py) === 1, 'Hermes telemetry defaults changed');
    },
];
