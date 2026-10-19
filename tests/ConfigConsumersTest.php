<?php
/**
 * Config keys that reach Generate/Units and the halt-guard hook, beyond what tests/GenerateTest.php,
 * tests/DoctrineTest.php and tests/HaltHookTest.php already cover (ADR-0004, ADR-0015, ADR-0047). New
 * file: tests/GenerateTest.php, tests/DoctrineTest.php and tests/HaltHookTest.php belong to another
 * package for this round and are not edited here, so each fixture below is self-contained.
 */
return [
    'config: root.disabled suppresses the server-admin category from Generate::disabledCategories, additively with tools.disabled' => function () {
        check(!in_array('server-admin', Generate::disabledCategories(['root' => ['disabled' => false]]), true), 'server-admin must be on by default');
        check(in_array('server-admin', Generate::disabledCategories(['root' => ['disabled' => true]]), true), 'root.disabled must turn off server-admin');
        $both = Generate::disabledCategories(['root' => ['disabled' => true], 'tools' => ['disabled' => ['storage']]]);
        check(in_array('server-admin', $both, true) && in_array('storage', $both, true), 'root.disabled must add to, not replace, tools.disabled: ' . json_encode($both));
    },
    "config: tools.disabled, read through Generate::disabledCategories, removes a category's generated skill and its DOCTRINE.md fragment alike" => function () {
        $dir = testTempDir('pk-configconsumers-tools');
        try {
            foreach (['alpha', 'beta'] as $cat) {
                @mkdir("$dir/tools/$cat/u$cat", 0700, true);
                file_put_contents("$dir/tools/$cat/u$cat/skill.md", "---\nname: u$cat\ndescription: d\n---\nbody\n");
                file_put_contents("$dir/tools/$cat/u$cat/USAGE.md", "# u$cat\n\nNo verbs.\n");
                file_put_contents("$dir/tools/$cat/u$cat/README.md", "# u$cat\n");
                file_put_contents("$dir/tools/$cat/DOCTRINE.md", "- $cat rule\n");
            }
            $config = Config::merge(Config::defaults(), ['tools' => ['disabled' => ['alpha']]]);
            $disabled = Generate::disabledCategories($config);
            $r = Generate::run($disabled, "$dir/tools", "$dir/out", "$dir/tools/INDEX.md");
            check($r['error'] === '' && !is_file("$dir/out/alpha/ualpha/SKILL.md") && is_file("$dir/out/beta/ubeta/SKILL.md"),
                'tools.disabled must remove the skill of the disabled category only: ' . json_encode($r));
            $doctrines = Units::doctrines($disabled, "$dir/tools");
            check(!isset($doctrines['alpha']) && $doctrines['beta'] === '- beta rule',
                "tools.disabled must remove the disabled category's DOCTRINE.md fragment alongside its skill: " . json_encode($doctrines));
        } finally {
            testRmTree($dir);
        }
    },
    'config: halt.disabled silences halt-guard.php itself (the pre_verify half), not only halt-snapshot.php and the HaltRun wrapper gate' => function () {
        $tree = testTempDir('pk-configconsumers-halt');
        try {
            @mkdir("$tree/pikkumies/lib", 0700, true);
            foreach ((array) glob(dirname(__DIR__) . '/pikkumies/lib/*.php') as $f) {
                copy($f, "$tree/pikkumies/lib/" . basename($f));
            }
            @mkdir("$tree/pikkumies/hooks/halt/halt-guard", 0700, true);
            copy(dirname(__DIR__) . '/pikkumies/hooks/halt/halt-guard/halt-guard.php', "$tree/pikkumies/hooks/halt/halt-guard/halt-guard.php");
            @mkdir("$tree/pikkumies/local", 0700, true);
            file_put_contents("$tree/pikkumies/local/config.php", "<?php\nreturn ['halt' => ['disabled' => true]];\n");
            // A real snapshot, seeded by hand (HaltState's own shape), so there is something for the guard to
            // judge if halt.disabled were NOT respected: a bare "prints nothing" check alone cannot tell a
            // disabled-respecting exit apart from the ordinary fail-open-and-print-nothing path with no
            // provider configured. markJudged() stamps last_judged_turn unconditionally once past the
            // halt.disabled gate (before it ever reaches the model), so an unchanged last_judged_turn is
            // the proof the gate, not a lucky fail-open, is what kept this quiet.
            $sessionId = 'sess-halt-disabled';
            $stateFile = "$tree/pikkumies/local/state/halt/" . sha1($sessionId) . '.json';
            @mkdir(dirname($stateFile), 0700, true);
            $seed = ['user_message' => 'do the thing', 'tail' => '[user] do the thing', 'turn_started' => 1700000000,
                'continues' => 0, 'wrapup_sent' => false, 'last_judged_turn' => 0];
            file_put_contents($stateFile, json_encode($seed));
            $payload = ['session_id' => $sessionId, 'extra' => ['final_response' => 'done']];
            list($code, $out) = runPhp("$tree/pikkumies/hooks/halt/halt-guard/halt-guard.php", [], json_encode($payload));
            check($code === 0 && trim($out) === '', "halt.disabled must make halt-guard.php exit silently even with a valid session id, got exit $code: $out");
            $after = json_decode((string) file_get_contents($stateFile), true);
            check($after['last_judged_turn'] === 0, 'halt.disabled must exit before markJudged() touches the state file (a bypass would stamp last_judged_turn=1700000000): ' . json_encode($after));
        } finally {
            testRmTree($tree);
        }
    },
];
