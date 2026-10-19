<?php
/** Interval hook units (ADR-0067): hook.md fields, the interval as set, local refusal, the Hermes entries and the settings' tiers. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'interval: every and wake are valid only with event transcript; every 59 or 3601, every not above timeout + 15, timeout 111, failClosed true and an unknown wake kind are refused' => function () {
        $tree = asyncTree([
            'guard/ok' => ['every' => 60, 'timeout' => 10, 'wake' => 'change, reply'], 'guard/low' => ['every' => 59], 'guard/high' => ['every' => 3601],
            'guard/near' => ['every' => 60, 'timeout' => 45], 'guard/slow' => ['every' => 600, 'timeout' => 111], 'guard/closed' => ['failClosed' => true],
            'guard/wakebad' => ['wake' => 'tool,bogus'], 'guard/noevery' => ['every' => null], 'guard/notr' => ['event' => 'pre_llm_call', 'every' => 60],
            'guard/plain' => ['event' => 'post_tool_call', 'every' => null, 'body' => ''],
        ]);
        try {
            $errors = [];
            foreach (HookUnits::all("$tree/pikkumies/hooks") as $u) {
                $errors[$u['name']] = implode('; ', $u['errors']);
            }
            check($errors['ok'] === '' && $errors['plain'] === '', 'valid units refused: ' . json_encode($errors));
            foreach (['low' => 'every must be whole seconds from 60 to 3600', 'high' => 'every must be whole seconds', 'near' => 'more than timeout + 15',
                'slow' => 'timeout must be whole seconds from 1 to 110', 'closed' => 'failClosed true is only meaningful', 'wakebad' => 'wake takes a comma list',
                'noevery' => 'every must be whole seconds', 'notr' => 'frontmatter must be exactly'] as $name => $why) {
                check(strpos($errors[$name], $why) !== false, "$name: " . $errors[$name]);
            }
            check(array_keys(HookInterval::on([], "$tree/pikkumies/hooks")) === ['guard/ok'], 'only the valid interval unit is on');
        } finally {
            testRmTree($tree);
        }
    },
    'interval: a broken unit that declares transcript still blocks every tool call; a valid one is no event\'s unit' => function () {
        $tree = asyncTree(['guard/broken' => ['every' => 5], 'guard/fine' => []]);
        try {
            list($out) = asyncRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls'], 'session_id' => 'x']);
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'guard/broken failed closed') !== false && strpos($d['message'], 'guard/fine') === false, 'answer: ' . $out);
            foreach (HookUnits::EVENTS as $event) {
                check(array_filter(HookUnits::forEvent($event, "$tree/pikkumies/hooks"), function ($u) { return $u['name'] === 'fine'; }) === [], "the interval unit ran as a $event unit");
            }
        } finally {
            testRmTree($tree);
        }
    },
    'interval: hooks.every.<name> overrides the interval, clamped to 60..3600 and above timeout + 15; 0 or hooks.async.disabled turns it off' => function () {
        $u = ['category' => 'search', 'name' => 'review', 'timeout' => 45, 'meta' => ['every' => '600']];
        $at = function (array $config) use ($u) {
            return HookInterval::interval($u, $config);
        };
        check($at([]) === 600, 'default');
        check($at(['hooks' => ['every' => ['review' => 300]]]) === 300, 'override');
        check($at(['hooks' => ['every' => ['review' => 10]]]) === 61, 'below the floor: above timeout + 15, at least 60: ' . $at(['hooks' => ['every' => ['review' => 10]]]));
        check($at(['hooks' => ['every' => ['review' => 99999]]]) === 3600, 'above 3600');
        check($at(['hooks' => ['every' => ['review' => 0]]]) === 0, '0 = off');
        check($at(['hooks' => ['every' => ['other' => 0]]]) === 600, 'another unit\'s setting');
        check($at(['hooks' => ['async' => ['disabled' => true]]]) === 0, 'hooks.async.disabled');
        check(HookInterval::describe($u, ['hooks' => ['every' => ['review' => 300]]]) === 'every 300s (600s by default)' && HookInterval::describe($u, []) === 'every 600s', 'describe');
    },
    'interval: a local unit with event transcript is refused and named; it never runs' => function () {
        $tree = asyncTree([]);
        try {
            $dir = "$tree/pikkumies/local/hooks/mine/bg";
            @mkdir($dir, 0700, true);
            file_put_contents("$dir/hook.md", "---\nname: bg\nevent: transcript\nevery: 60\ndescription: x\nfailClosed: false\ntimeout: 5\n---\nx\n");
            file_put_contents("$dir/README.md", "# bg\n");
            file_put_contents("$dir/bg.php", "<?php\n");
            $scan = LocalHooks::scan("$tree/pikkumies");
            check(isset($scan['problems']['local/mine/bg']) && strpos($scan['problems']['local/mine/bg'], 'ships only with Pikkumies') !== false && !$scan['units'] && !$scan['pending'], 'scan: ' . json_encode($scan['problems']));
        } finally {
            testRmTree($tree);
        }
    },
    'interval: while a unit is on, Hermes gets pre_llm_call, post_tool_call and post_llm_call entries (5 s where no unit has one); an interval unit adds no time and the config never names transcript' => function () {
        $tree = asyncTree(['guard/watch' => ['timeout' => 30]]);
        try {
            $e = HermesConfig::hookEntries("$tree/pikkumies/hooks");
            check(array_keys($e) === ['post_llm_call', 'post_tool_call', 'pre_llm_call'], 'entries: ' . json_encode(array_keys($e)));
            check($e['post_llm_call']['timeout'] === 5 && $e['post_llm_call']['failClosed'] === false && strpos($e['post_llm_call']['command'], 'orchestrator.php post_llm_call 5') !== false, json_encode($e['post_llm_call']));
            $y = HermesConfig::renderYaml(['endpoint' => 'https://example.org/v1', 'model' => 'm', 'key' => 'k'], ['/a'], $e);
            check(strpos($y, 'transcript') === false && strpos($y, "  post_llm_call:\n") !== false, 'yaml: ' . $y);
            file_put_contents("$tree/pikkumies/hooks/guard/watch/hook.md", str_replace('every: 60', 'every: 0', file_get_contents("$tree/pikkumies/hooks/guard/watch/hook.md")));
            $broken = HermesConfig::hookEntries("$tree/pikkumies/hooks");
            check(array_keys($broken) === ['pre_tool_call'], 'a broken interval unit adds no feed entries, only the pre_tool_call entry it blocks: ' . json_encode(array_keys($broken)));
        } finally {
            testRmTree($tree);
        }
    },
    'interval: post_llm_call records this turn although no unit declares that event' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            list($out) = asyncRun($tree, 'post_llm_call', ['session_id' => 'p', 'extra' => ['user_message' => 'hi', 'assistant_response' => 'hello', 'turn_id' => 't',
                'conversation_history' => [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello']]]]);
            check($out === '', 'post_llm_call printed: ' . $out);
            $spool = (string) @file_get_contents("$tree/pikkumies/local/state/async/transcript/" . sha1('p') . '.jsonl');
            check(strpos($spool, '"kind":"reply"') !== false && strpos($spool, '"text":"hello"') !== false, 'spool: ' . $spool);
        } finally {
            asyncWait(function () { return false; }, 0.5);
            testRmTree($tree);
        }
    },
    'interval: tiers: hooks.async.disabled off is the person\'s; hooks.every.<name> takes 0 or 60 to 3600 seconds, and a unit that does not ship has no rule' => function () {
        $f = 'free';
        $p = 'person';
        check(ConfigureTiers::classify('hooks.async.disabled', false, true) === $p && ConfigureTiers::classify('hooks.async.disabled', true, false) === $f, 'async.disabled');
        check(Config::type('hooks.async.disabled') === 'bool' && Config::type('hooks.every.mutation-check') === 'seconds' && Config::type('hooks.every.a/b') === null, 'types');
        check(Config::parse('hooks.every.x', '300') === 300 && Config::parse('hooks.every.x', '0') === 0, 'parse');
        foreach (['59', '3601', 'ten', '-1'] as $bad) {
            $thrown = false;
            try {
                Config::parse('hooks.every.x', $bad);
            } catch (InvalidArgumentException $x) {
                $thrown = true;
            }
            check($thrown, "hooks.every.x accepted $bad");
        }
        check(ConfigureTiers::rule('hooks.every.no-such-unit') === null, 'an every key for a unit that does not ship has no rule');
    },
];
