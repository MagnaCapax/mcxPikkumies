<?php
/**
 * hooks/review/antipattern-check (ADR-0088): the background common-mistakes check's own unit script, run directly
 * the way tests/MutationCheckTest.php runs hooks/guard/mutation-check.php -- with a crafted stdin payload, not
 * through the full orchestrator/worker pipeline (already covered generically by tests/HookIntervalTest.php and
 * tests/HookAsyncRunTest.php).
 */
require_once __DIR__ . '/asyncSupport.php';
if (!function_exists('haltRunFakeModel')) {
    require __DIR__ . '/haltRunSupport.php'; // its indexed replies.json fixture stubs the light model
}

if (!function_exists('apcTree')) {
    /** An async tree (lib/, local/) holding the shipped unit and doctrine/antipatterns.md, provider pointed at the fake model. */
    function apcTree($modelPort, array $config = [])
    {
        $tree = asyncTree([]);
        asyncCopy(dirname(__DIR__) . '/pikkumies/hooks/review/antipattern-check', "$tree/pikkumies/hooks/review/antipattern-check");
        @mkdir("$tree/pikkumies/doctrine", 0700, true);
        copy(dirname(__DIR__) . '/pikkumies/doctrine/antipatterns.md', "$tree/pikkumies/doctrine/antipatterns.md");
        file_put_contents("$tree/key", "key=k\n");
        $cfg = array_merge(['provider' => ['endpoint' => "http://127.0.0.1:$modelPort/v1", 'model' => 'm', 'keyFile' => "$tree/key"]], $config);
        file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export($cfg, true) . ";\n");
        return $tree;
    }

    /** Runs the unit script on $records/$state, as hooks/worker.php would feed it: [decoded answer or null, raw stdout]. */
    function apcRun($tree, array $records, array $state = [])
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/review/antipattern-check/antipattern-check.php", [],
            json_encode(['unit' => 'review/antipattern-check', 'session' => sha1('sess-apc'), 'interval' => 300, 'records' => $records, 'state' => (object) $state]));
        return [json_decode($out, true), $out];
    }

    function apcReplyRecord($text)
    {
        return ['seq' => 1, 'ts' => '2026-10-20T08:00:00Z', 'kind' => 'reply', 'text' => $text];
    }

    function apcToolRecord($tool, array $args)
    {
        return ['seq' => 1, 'ts' => '2026-10-20T08:00:00Z', 'kind' => 'tool', 'tool' => $tool, 'args' => json_encode($args)];
    }
}

return [
    'antipattern-check unit: a reply record with a flagged phrase, stubbed model, answers {"context": lines(ids), "state": {"shown": ids}} exactly' => function () {
        $fakeModel = sys_get_temp_dir() . '/pk-apc-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = apcTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode(['load-is-not-cpu | pegging every one of the four cpu cores']));
            $records = [apcReplyRecord('the agent replied: load of 40.02 is basically pegging every one of the four cpu cores to the max')];
            list($d, $raw) = apcRun($tree, $records);
            check(is_array($d), 'no JSON answer: ' . $raw);
            check($d['context'] === Antipatterns::lines(['load-is-not-cpu']), 'context must equal lines(ids) exactly, the file\'s own text, never the model\'s words: ' . json_encode($d));
            check($d['state'] === ['shown' => ['load-is-not-cpu']], 'state must carry the shown id, for the next run and the end-of-turn callers alike: ' . json_encode($d['state']));
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'antipattern-check unit: no reply record in the window -> {"skip":true} (the cursor stays put, so a tool-only window is retried, not lost -- review finding L8), model never asked' => function () {
        $fakeModel = sys_get_temp_dir() . '/pk-apc-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = apcTree($port);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode(['load-is-not-cpu | pegging every one of the four cpu cores']));
            list($d, $raw) = apcRun($tree, [apcToolRecord('terminal', ['command' => 'uptime'])]);
            check($d === ['skip' => true], 'no reply record must answer exactly {"skip":true}: ' . $raw);
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 0, 'the model must never be asked with no reply in the window');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
    'antipattern-check unit: antipatterns.disabled silences the unit too, before the model is ever asked' => function () {
        $fakeModel = sys_get_temp_dir() . '/pk-apc-model-' . getmypid() . '-' . mt_rand();
        list($proc, $port) = haltRunFakeModel($fakeModel);
        $tree = apcTree($port, ['antipatterns' => ['disabled' => true]]);
        try {
            file_put_contents("$fakeModel/replies.json", json_encode(['load-is-not-cpu | pegging every one of the four cpu cores']));
            $records = [apcReplyRecord('the agent replied: load of 40.02 is basically pegging every one of the four cpu cores to the max')];
            list($d, $raw) = apcRun($tree, $records);
            check($d === null && trim($raw) === '', 'antipatterns.disabled must silence the unit: ' . $raw);
            check(count(array_filter(explode("\n", (string) @file_get_contents("$fakeModel/requests.jsonl")), 'strlen')) === 0, 'the model must never be asked when disabled');
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
            testRmTree($fakeModel);
        }
    },
];
