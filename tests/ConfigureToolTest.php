<?php
/**
 * tools/system/pikkumies-configure/ConfigureSettings.php and its CLI, beyond the happy-path
 * round trip already covered by tests/ConfigTest.php (ADR-0032).
 *
 * The CLI-layer tests here run the real pikkumies-configure.php script with `show`/`get`/`review` —
 * all read-only (Config::load() reads, never writes). They deliberately avoid a real `set` through the
 * script, since that verb has no $file override and would write the real pikkumies/local/config.php
 * (forbidden); that scenario is covered one layer down, directly against
 * ConfigureSettings::set($key, $value, $file), which takes the same override Config::set() does.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';

return [
    "configure: show()/get() list settings in INFO's declared order and tolerate a config missing an entire section" => function () {
        // #30 a future Config::defaults() reorder must not silently reorder what a person sees.
        $reversed = array_reverse(Config::defaults(), true);
        $show = array_column(ConfigureSettings::show($reversed), 'key');
        check($show === array_keys(ConfigureSettings::INFO), 'show() must follow INFO order, not config order: ' . json_encode($show));
        // #32 a trimmed/hand-edited config must not raise a notice leaking onto the CLI's stderr/JSON.
        $missing = Config::defaults();
        unset($missing['sessions']);
        check(ConfigureSettings::get($missing, 'sessions.retentionDays')['value'] === null, 'get() on a missing section must return null, not notice/crash');
        $showMissing = array_column(ConfigureSettings::show($missing), 'value', 'key');
        check($showMissing['sessions.retentionDays'] === null && count($showMissing) === count(ConfigureSettings::INFO), 'show() on a missing section: ' . json_encode($showMissing));
    },
    'configure: get() on a real-but-unexposed key throws, listing the actual setting names verbatim' => function () {
        // #31 distinguishes "not a setting at all" (this) from "a setting but refused for writing"
        // (ConfigTest.php's existing refusal coverage).
        $threw = false;
        try {
            ConfigureSettings::get(Config::defaults(), 'host.family');
        } catch (CliError $x) {
            $threw = true;
            check($x->hint === 'settings: ' . implode(', ', array_keys(ConfigureSettings::INFO)), 'hint must list the real setting names verbatim: ' . $x->hint);
        }
        check($threw, 'get() on an unexposed key must throw');
    },
    "configure: how() for a key or direction the person owns names where they change it (ADR-0059)" => function () {
        // #33 pins the wording, since a wording regression that drops the place leaves a person with no self-serve path.
        foreach (['provider.endpoint', 'host.family', 'root.disabled', 'halt.disabled'] as $key) {
            check(strpos(ConfigureChange::how($key), '~/pikkumies/local/config.php') !== false, "how($key) must name local/config.php: " . ConfigureChange::how($key));
        }
        check(strpos(ConfigureChange::how('terms.accepted'), '--accept-terms') !== false, 'the terms: the first-start question or --accept-terms');
    },
    'configure: review() warnings — the name-set boundary, strict terms typing, and the retention state.db size' => function () {
        // #34 boundary: a 32-char (NAME_MAX) name must read as "named", same as any shorter valid name.
        foreach (['Helper', str_repeat('x', Config::NAME_MAX)] as $name) {
            $config = Config::defaults();
            $config['agent']['name'] = $name;
            $rows = array_column(ConfigureSettings::review($config), null, 'key');
            check(!isset($rows['agent.name']['warning']), "a name must never warn, length " . strlen($name) . ': ' . json_encode($rows['agent.name']));
        }
        // #35 guards intentional strictness: only acceptTerms()'s literal boolean true counts, so a
        // hand-edited truthy-but-not-true value (a string, here) must still warn.
        // Off a Pulsed Media server: there the terms are not asked (ADR-0032 amendment 2026-10-24), so nothing warns.
        $config = Config::defaults();
        $config['terms']['accepted'] = 'true';
        ConfigureTiers::$pmssOverride = false;
        $rows = array_column(ConfigureSettings::review($config), null, 'key');
        ConfigureTiers::$pmssOverride = null;
        check(isset($rows['terms.accepted']['warning']), 'a string "true" must still warn (only the literal boolean counts)');
        // #36 independent oracle: filesize() of a file the test itself created, not a re-derivation.
        $dir = testTempDir('pk-configure-statedb');
        try {
            $db = "$dir/state.db";
            file_put_contents($db, str_repeat('x', 2097152)); // exactly 2 MiB
            $rows = array_column(ConfigureSettings::review(Config::defaults(), $db), null, 'key');
            check(strpos($rows['sessions.retentionDays']['warning'], '2 MB') !== false, 'the real file size must appear: ' . $rows['sessions.retentionDays']['warning']);
            $rowsNoDb = array_column(ConfigureSettings::review(Config::defaults(), "$dir/missing.db"), null, 'key');
            check(strpos($rowsNoDb['sessions.retentionDays']['warning'], 'MB') === false, 'a missing state.db must omit a size: ' . $rowsNoDb['sessions.retentionDays']['warning']);
        } finally {
            testRmTree($dir);
        }
    },
    "configure: set() on a bad value refuses before writing and points to review; on success it notes the next-session note" => function () {
        // #37 distinguishes "settable key, bad value" from the already-covered "unsettable key" path;
        // nothing must be written when the value is refused.
        $dir = testTempDir('pk-configure-set');
        try {
            $file = "$dir/config.php";
            $threw = false;
            try {
                ConfigureSettings::set('sessions.retentionDays', 'banana', $file);
            } catch (CliError $x) {
                $threw = strpos($x->hint, 'review') !== false;
            }
            check($threw, 'a bad value must refuse and point to review');
            check(!is_file($file), 'a refused set() must write nothing');
            // #38 a dropped note misleads a person expecting a live change.
            $r = ConfigureSettings::set('agent.name', 'Helper', $file);
            check(strpos($r['note'], 'next session') !== false, 'set() must note the change takes effect next session: ' . $r['note']);
        } finally {
            testRmTree($dir);
        }
    },
    'configure: set() round-trips a value containing spaces unchanged (the regex captures everything after the first "=" verbatim)' => function () {
        // #41 adapted from "through real argv": the CLI script's `set` verb has no $file override and
        // would write the real pikkumies/local/config.php (forbidden). The quoting
        // guarantee itself (a shell preserves one argv word) is runPhp()/escapeshellarg()'s job, already
        // exercised throughout this suite; what this test actually proves — that the value reaches
        // Config::set() exactly as given, spaces and all — lives in ConfigureSettings::set(), one layer
        // below the script, which does take the same $file override Config::set() does.
        $dir = testTempDir('pk-configure-spaces');
        try {
            $file = "$dir/config.php";
            ConfigureSettings::set('agent.name', 'Little Helper', $file);
            $config = Config::merge(Config::defaults(), require $file);
            check(ConfigureSettings::get($config, 'agent.name')['value'] === 'Little Helper', 'a value with spaces must round-trip unchanged');
        } finally {
            testRmTree($dir);
        }
    },
    'docs: the update skill, the skill SOP and the *arr not-installed hints point at the configure tool, not at editing local/config.php (ADR-0059)' => function () {
        $p = dirname(__DIR__) . '/pikkumies';
        foreach (["$p/tools/system/pikkumies-update/skill.md", "$p/doctrine/SOP/make-a-skill-tool-hook-or-pack.md"] as $f) {
            $t = (string) file_get_contents($f);
            check(strpos($t, 'skills disable <shipped-name>') !== false && strpos($t, '`skills.disabled` in `~/pikkumies/local/config.php`') === false, "$f must point at skills disable");
        }
        foreach (['arr/lib/ArrLocate.php' => 'set arr.', 'arr/bazarr/BazarrConfig.php' => 'set arr.bazarr.config', 'arr/jackett/JackettConfig.php' => 'set arr.jackett.config',
            'arr/autobrr/AutobrrConfig.php' => 'set arr.autobrr.config', 'arr/bazarr/skill.md' => 'set arr.bazarr.config'] as $f => $want) {
            $t = (string) file_get_contents("$p/tools/$f");
            check(strpos($t, $want) !== false && strpos($t, "in pikkumies/local/config.php: 'arr'") === false && strpos($t, 'config.yaml in `pikkumies/local/config.php`') === false, "$f must name $want");
        }
    },
    'configure: review says which direction of a switch is whose, and for a *.disabled key that true turns it off (ADR-0059)' => function () {
        check(ConfigureSettings::changes('halt.disabled') === 'turning it off (true): only the person; back on (false): the agent may', 'halt: ' . ConfigureSettings::changes('halt.disabled'));
        check(ConfigureSettings::changes('hooks.reword') === 'on (true): the agent may; off (false): the safety check decides', 'reword: ' . ConfigureSettings::changes('hooks.reword'));
        check(ConfigureSettings::changes('cron.disabled') === 'turning it off (true): the safety check decides; back on (false): the safety check decides', 'cron: ' . ConfigureSettings::changes('cron.disabled'));
    },
    "CLI: 'get' with no arguments is a usage error at exit 1, distinct from an unrecognized verb at exit 2" => function () {
        // #39, #40: wrong-arg-count is deliberately exit 1 ("error", CliError); an unknown VERB is exit 2
        // ("usage", Cli::run's own unknown-verb branch) — conflating the two breaks exit-code-branching
        // scripts. Both verbs here only read (no args reach Config::load() before the error).
        $script = dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php';
        list($code, $out) = runPhp($script, ['get']);
        check($code === 1 && strpos($out, '"ok":false') !== false && strpos($out, 'get needs one key') !== false, "get with no args: $code / $out");
        list($code, $out) = runPhp($script, ['delete', 'agent.name']);
        check($code === 2 && strpos($out, 'unknown verb: delete') !== false, "unrecognized verb: $code / $out");
    },
    "CLI: 'review --human' exits 0 with no stderr and shows every setting's key" => function () {
        // #42 --human is documented, had zero coverage; catches a notice from a null/bool reaching
        // CliHuman::render(). Read-only: review() only reads Config::load() and checks is_file($stateDb).
        $script = dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' review --human';
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        check($code === 0 && $err === '', "review --human must exit 0 with no stderr: code=$code err=$err");
        foreach (array_keys(ConfigureSettings::INFO) as $key) {
            check(strpos($out, $key) !== false, "review --human must show $key: $out");
        }
    },
    'CLI: the "key" field in show() output survives Cli::clean\'s secret-key redaction' => function () {
        // #43 near-miss: this tool's own field name sits one shared-regex-tweak away from being
        // swallowed by code it doesn't own (Cli::SECRET_KEY_RE). Read-only (show() only reads).
        $script = dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php';
        list($code, $out) = runPhp($script, ['show']);
        $lines = array_values(array_filter(explode("\n", $out), 'strlen'));
        check($code === 0 && count($lines) >= 6, "show must print 5 records + a status line: $out");
        for ($i = 0; $i < 5; $i++) {
            check(strpos($lines[$i], '"key":') !== false, "record $i is missing its key field: {$lines[$i]}");
        }
    },
];
