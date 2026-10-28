<?php
/** hooks/search/goal-review (ADR-0096) with a fake light model: the gates by time and tokens, the call, the skips, the retry, and the note across a compression. */
require_once __DIR__ . '/asyncSupport.php';
require_once __DIR__ . '/hookSearchSupport.php';
require_once __DIR__ . '/goalReviewSupport.php';

if (!function_exists('grTree')) {
    /** A tree with the shipped goal review and session-context, a fake model answering <tree>/fake/reply, a tool unit and a documentation page. */
    function grTree(array $hooks = [])
    {
        $tree = asyncTree([]);
        asyncCopy(dirname(__DIR__) . '/pikkumies/hooks/search/goal-review', "$tree/pikkumies/hooks/search/goal-review");
        asyncCopy(dirname(__DIR__) . '/pikkumies/hooks/trace/session-context', "$tree/pikkumies/hooks/trace/session-context");
        asyncCopy(dirname(__DIR__) . '/pikkumies/tools/system/credits', "$tree/pikkumies/tools/system/credits");
        asyncCopy(dirname(__DIR__) . '/pikkumies/config', "$tree/pikkumies/config"); // the documentation source format; the shipped sources have no copy here
        @mkdir("$tree/fake", 0700);
        list($proc, $port) = hookSearchFakeProvider("$tree/fake");
        file_put_contents("$tree/key", "key=test-key\n");
        file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['provider' => ['endpoint' => "http://127.0.0.1:$port/v1", 'model' => 'm', 'keyFile' => "$tree/key"]]
            + ($hooks ? ['hooks' => $hooks] : []), true) . ";\n");
        $unit = "$tree/pikkumies/tools/storage/diskmove";
        @mkdir($unit, 0700, true);
        file_put_contents("$unit/skill.md", "---\nname: diskmove\ndescription: Moves torrents and data between disks and checks free space.\n---\nx\n");
        file_put_contents("$unit/USAGE.md", "| verb | arguments | changes | what it does |\n|---|---|---|---|\n| move | | yes | moves |\n");
        file_put_contents("$unit/README.md", "# diskmove\n");
        file_put_contents("$unit/diskmove.php", "<?php\n");
        @mkdir("$tree/pikkumies/local/docs-cache/kb", 0700, true);
        file_put_contents("$tree/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n");
        file_put_contents("$tree/pikkumies/local/docs-cache/kb/move.md", "---\ntitle: \"x\"\nurl: \"https://example.org/move\"\n---\n# Moving torrents to a new disk\n\nMove torrents to a new disk and check the free space first.\n");
        return [$tree, $proc];
    }

    /** The run file of the test spool (no launcher: derived from the hash): started $ago seconds back with $tokens. */
    function grCounter($tree, $ago, $tokens, array $more = [])
    {
        $f = SessionCounters::runFile("$tree/pikkumies/local", HookRunCount::key(sha1('g')));
        @mkdir(dirname($f), 0700, true);
        file_put_contents($f, json_encode(['since' => time() - $ago, 'tokens' => $tokens, 'reqs' => 9, 'asked' => 0] + $more));
    }

    function grRun($tree, array $records, array $state = [])
    {
        list(, $out) = testWithEnv(['PIKKUMIES_RUN' => null], function () use ($tree, $records, $state) {
            return runPhp("$tree/pikkumies/hooks/search/goal-review/goal-review.php", [],
                json_encode(['unit' => 'search/goal-review', 'session' => sha1('g'), 'interval' => 120, 'records' => $records, 'state' => (object) $state]));
        });
        return [json_decode($out, true), $out];
    }

    function grWindow()
    {
        return [grRec(1, 'user', 'Please move my torrents to the new disk', ['first_turn' => true]), grRec(2, 'tool', '', ['tool' => 'terminal', 'args' => '{"command":"df -h"}', 'status' => 'ok']),
            grRec(3, 'reply', 'Moved most of them.'), grRec(4, 'user', 'Did it move all of them? Check the free space too'), grRec(5, 'reply', 'Yes, all moved.')];
    }

    /** The orchestrator started the way Hermes starts it, without a wrapper process: its parent is this test process for every call. */
    function grOrch($tree, $event, array $payload, array $env)
    {
        $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/pikkumies/hooks/orchestrator.php") . ' ' . escapeshellarg($event),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        clearstatcache();
        return [$out];
    }

    /** This process as the orchestrator it starts sees its parent: "<pid>:<start time>" (proc(5) field 22). */
    function grSelfId()
    {
        $stat = (string) file_get_contents('/proc/self/stat');
        $f = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));
        return getmypid() . ':' . $f[19];
    }

    /** The account's last goal-review call, moved past the ledger's 600 s gap, so a test may review again at once. */
    function grAge($tree)
    {
        $f = "$tree/pikkumies/local/state/async/ledger.json";
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d) && isset($d['last']['goal-review'])) {
            $d['last']['goal-review'] = time() - 601;
            file_put_contents($f, json_encode($d));
        }
    }

    function grLog($tree)
    {
        $f = "$tree/pikkumies/local/logs/goal-review/" . gmdate('Ymd') . '.jsonl';
        return is_file($f) ? array_map(function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES)) : [];
    }
}

return [
    'goal review flow: when due, one call of at most 30k estimated tokens with every request, the activity and the candidates; the note is the fixed header and sentences; quotes by number; the log keeps scores, never texts' => function () {
        list($tree, $proc) = grTree();
        try {
            grCounter($tree, 400, 80000);
            file_put_contents("$tree/fake/reply", grAnswers(['CURRENT' => 'R2', 'OPEN' => 'yes R2', 'TOOLS' => 'T1', 'PAGES' => 'D1', 'KIND' => 'diagnostics', 'ONTASK' => '300']));
            list($d, $raw) = grRun($tree, grWindow());
            $req = hookSearchRequests("$tree/fake");
            $user = $req[0]['body']['messages'][1]['content'];
            check(count($req) === 1 && $req[0]['body']['max_tokens'] === 300 + HookLlm::REASONING_ALLOWANCE && $req[0]['body']['messages'][0]['content'] === GoalReview::SYSTEM, 'request: ' . json_encode($req));
            check(preg_match('/^Marker: ([0-9a-f]{8})\n<<<DATA-\1\n/', $user) === 1 && strpos($user, "R1: Please move my torrents to the new disk\n") !== false
                && strpos($user, 'R2: Did it move all of them? Check the free space too') !== false && strpos($user, 'T: terminal: df -h') !== false
                && strpos($user, 'T1: diskmove') !== false && strpos($user, 'D1: Moving torrents to a new disk') !== false, 'data: ' . $user);
            check(GoalReviewPrompt::est(GoalReview::SYSTEM . $user) <= 30000, 'over the cap');
            check(strpos($d['context'], 'Goal check (fixed text; a light model gave the numbers, 0-1000): on task 300') === 0 && strpos($d['context'], GoalReview::SAY['driftMild']) !== false
                && strpos($d['context'], 'request (2)') !== false && strpos($d['context'], 'pikkumies diskmove --help') !== false && strpos($d['context'], GoalReview::CHECKLIST) !== false, 'answer: ' . $raw);
            check($d['quotes'] === ['Please move my torrents to the new disk', 'Did it move all of them? Check the free space too'], 'quotes: ' . json_encode($d['quotes']));
            check(abs($d['state']['lastReviewAt'] - time()) < 5 && $d['state']['tokensAtLastReview'] === 80000 && $d['state']['checklistShown'] === true && count($d['state']['shown']) === 2, 'state: ' . json_encode($d['state']));
            $log = grLog($tree);
            check($log[0]['outcome'] === 'ran' && $log[0]['scores']['ONTASK'] === 300 && $log[0]['current'] === 2 && in_array('driftMild', $log[0]['fired'], true) && $log[0]['calls'] === 1
                && $log[0]['requests'] === ['total' => 2, 'shown' => 2, 'omitted' => 0] && $log[0]['est_in'] > 900 && $log[0]['gates']['used'] === 80000
                && strpos(json_encode($log), 'torrents') === false && strpos(json_encode($log), 'move the torrents') === false, 'log: ' . json_encode($log));
            // The next review logs the follow-through of this note: a memory search made since, and the new on-task score.
            grCounter($tree, 4000, 700000, ['memory' => 1, 'memoryAt' => time()]);
            file_put_contents("$tree/fake/reply", grAnswers(['ONTASK' => '800']));
            grAge($tree);
            grRun($tree, grWindow(), ['lastReviewAt' => time() - 1800] + $d['state']);
            $follow = grLog($tree)[1]['follow'];
            check($follow === ['memoryRose' => true, 'outsideRose' => false, 'prevOnTask' => 300, 'onTask' => 800], 'follow-through: ' . json_encode($follow));
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
        }
    },
    'goal review flow: the gates: the first review needs 5 minutes and 75 000 tokens, later ones 30 minutes and 600 000 more; not due answers skip without a call' => function () {
        list($tree, $proc) = grTree();
        try {
            file_put_contents("$tree/fake/reply", grAnswers());
            foreach ([[290, 900000], [400, 74999]] as $g) {
                grCounter($tree, $g[0], $g[1]);
                list(, $raw) = grRun($tree, grWindow());
                check(trim($raw) === '{"skip":true}', json_encode($g) . ': ' . $raw);
            }
            check(hookSearchRequests("$tree/fake") === [], 'a gate that was not met called the model');
            grCounter($tree, 300, 75000);
            list($d) = grRun($tree, grWindow());
            check(count(hookSearchRequests("$tree/fake")) === 1 && substr($d['context'], -strlen(GoalReview::ON_TRACK)) === GoalReview::ON_TRACK, 'first review at 300 s and 75 000');
            $st = $d['state'];
            grCounter($tree, 4000, 75000 + 900000);
            list(, $raw) = grRun($tree, grWindow(), ['lastReviewAt' => time() - 1790] + $st);
            check(trim($raw) === '{"skip":true}', '29:50 since the last: ' . $raw);
            grCounter($tree, 4000, 75000 + 599999);
            list(, $raw) = grRun($tree, grWindow(), ['lastReviewAt' => time() - 1800] + $st);
            check(trim($raw) === '{"skip":true}', '599 999 more tokens: ' . $raw);
            grCounter($tree, 4000, 75000 + 600000);
            grAge($tree);
            list($d) = grRun($tree, grWindow(), ['lastReviewAt' => time() - 1800] + $st);
            check(count(hookSearchRequests("$tree/fake")) === 2 && $d['state']['tokensAtLastReview'] === 675000 && strpos($d['context'], GoalReview::CHECKLIST) === false, 'second review; no checklist again');
            check(array_column(grLog($tree), 'outcome') === ['skipped:not-due', 'skipped:not-due', 'ran', 'skipped:not-due', 'skipped:not-due', 'ran'], json_encode(array_column(grLog($tree), 'outcome')));
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
        }
    },
    'goal review flow: a reply that is not the 13 lines leaves no note and waits 10 minutes; the token mark stays; the first turn is reviewed too; rounds is ignored' => function () {
        list($tree, $proc) = grTree(['goalReview' => ['rounds' => 10]]);
        try {
            grCounter($tree, 400, 80000);
            file_put_contents("$tree/fake/reply", "Here is my review:\n" . grAnswers());
            list($d, $raw) = grRun($tree, [grRec(1, 'user', 'Please move my torrents to the new disk', ['first_turn' => true]), grRec(2, 'reply', 'Moved.')]);
            check(!isset($d['context']) && abs($d['state']['lastAttemptAt'] - time()) < 5 && !isset($d['state']['lastReviewAt']) && count(hookSearchRequests("$tree/fake")) === 1, 'discarded: ' . $raw);
            list(, $raw) = grRun($tree, grWindow(), $d['state']);
            check(trim($raw) === '{"skip":true}' && count(hookSearchRequests("$tree/fake")) === 1, 'within 10 minutes: ' . $raw);
            file_put_contents("$tree/fake/reply", grAnswers());
            grAge($tree);
            list($d) = grRun($tree, grWindow(), ['lastAttemptAt' => time() - 601]);
            check(isset($d['context']) && !isset($d['state']['lastAttemptAt']) && count(hookSearchRequests("$tree/fake")) === 2, 'after 10 minutes: one call, not ten');
            check(array_column(grLog($tree), 'outcome') === ['discarded', 'skipped:retry-wait', 'ran'], json_encode(array_column(grLog($tree), 'outcome')));
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
        }
    },
    'goal review flow: skips without a model call: a scheduled session, hooks.llm.disabled, no counter, no request, the daily cap; only chat leaves no note' => function () {
        list($tree, $proc) = grTree(['dailyCalls' => ['goal-review' => 1]]);
        try {
            list(, $raw) = grRun($tree, grWindow());
            check(trim($raw) === '', 'no counter: ' . $raw);
            grCounter($tree, 400, 80000);
            $sched = grWindow();
            $sched[3]['scheduled'] = true;
            list(, $raw) = grRun($tree, $sched);
            check(trim($raw) === '', 'scheduled: ' . $raw);
            list(, $raw) = grRun($tree, [grRec(1, 'reply', 'hello')]);
            check(trim($raw) === '', 'no request: ' . $raw);
            check(hookSearchRequests("$tree/fake") === [], 'a skip called the model');
            file_put_contents("$tree/fake/reply", grAnswers(['CHAT' => 'yes', 'ONTASK' => '0']));
            list($d) = grRun($tree, grWindow());
            check(!isset($d['context']) && isset($d['state']['lastReviewAt']), 'only chat: no note, but reviewed');
            grAge($tree);
            list(, $raw) = grRun($tree, grWindow());
            check(count(hookSearchRequests("$tree/fake")) === 1 && trim($raw) === '', 'the cap of 1 allowed a second call');
            check(array_column(grLog($tree), 'outcome') === ['skipped:no-counter', 'skipped:scheduled', 'skipped:no-request', 'ran', 'skipped:daily-cap'], json_encode(array_column(grLog($tree), 'outcome')));
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
        }
        list($tree, $proc) = grTree(['llm' => ['disabled' => true]]);
        try {
            grCounter($tree, 400, 80000);
            list(, $raw) = grRun($tree, grWindow());
            check(trim($raw) === '' && hookSearchRequests("$tree/fake") === [] && grLog($tree)[0]['outcome'] === 'skipped:llm-disabled', 'llm disabled: ' . $raw);
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
        }
    },
    'goal review flow: end to end under a launcher key: tokens counted by session-context, the first turn reviewed, the spool kept across a compression, the note held from a delegated agent and shown at the rotated session\'s next turn' => function () {
        list($tree, $proc) = grTree();
        $key = 'feedfacecafebeef';
        $env = ['PIKKUMIES_RUN' => $key, 'PIKKUMIES_DEPTH' => '1'];
        $local = "$tree/pikkumies/local";
        try {
            file_put_contents("$tree/fake/reply", grAnswers(['OPEN' => 'yes R1', 'MEMORY' => '900']));
            SessionCounters::resetRun($local, $key, time() - 400);
            grOrch($tree, 'post_api_request', ['session_id' => 's1', 'extra' => ['platform' => 'cli', 'usage' => ['total_tokens' => 60000]]], $env);
            grOrch($tree, 'post_api_request', ['session_id' => 'sub', 'extra' => ['platform' => 'subagent', 'usage' => ['total_tokens' => 20000]]], $env);
            check(SessionCounters::readRun($local, $key)['tokens'] === 80000, 'counted: ' . json_encode(SessionCounters::readRun($local, $key)));
            $h1 = [['role' => 'user', 'content' => 'Please move my torrents to the new disk']];
            // The orchestrator's parent stands in for the Hermes process: here it is this test process for every call.
            $hash = testWithEnv($env, function () { return HookRunCount::hash('any', grSelfId()); });
            grOrch($tree, 'pre_llm_call', ['session_id' => 's1', 'extra' => ['user_message' => $h1[0]['content'], 'is_first_turn' => true, 'turn_id' => 't1', 'conversation_history' => $h1, 'platform' => 'cli']], $env);
            grOrch($tree, 'pre_llm_call', ['session_id' => 'sub', 'extra' => ['user_message' => 'sub task for the delegated agent', 'is_first_turn' => true, 'turn_id' => 'x', 'conversation_history' => [], 'platform' => 'subagent']], $env);
            // Compression: Hermes gives the session a new id mid-turn; the turn ends under it.
            grOrch($tree, 'post_llm_call', ['session_id' => 's2', 'extra' => ['user_message' => $h1[0]['content'], 'assistant_response' => 'Moved most.', 'turn_id' => 't1', 'platform' => 'cli',
                'conversation_history' => array_merge($h1, [['role' => 'assistant', 'content' => 'Moved most.']])]], $env);
            $ctxFile = "$local/state/async/search-goal-review/$hash.context.json";
            check(asyncWait(function () use ($ctxFile) { return is_file($ctxFile); }, 15), 'no note was left: ' . json_encode(grLog($tree)));
            check(is_file(HookSpool::file($local, $hash)) && !is_file(HookSpool::file($local, sha1('s1'))) && !is_file(HookSpool::file($local, sha1('s2'))), 'one spool for both session ids');
            list($sub) = grOrch($tree, 'pre_llm_call', ['session_id' => 'sub', 'extra' => ['user_message' => 'sub task', 'is_first_turn' => true, 'turn_id' => 'x', 'conversation_history' => [], 'platform' => 'subagent']], $env);
            check(strpos((string) $sub, 'goal-review') === false && is_file($ctxFile), 'a delegated agent must not take the note: ' . $sub);
            list($out) = grOrch($tree, 'pre_llm_call', ['session_id' => 's2', 'extra' => ['user_message' => 'next', 'is_first_turn' => false, 'turn_id' => 't2', 'conversation_history' => $h1, 'platform' => 'cli']], $env);
            $ctx = (string) json_decode($out, true)['context'];
            check(strpos($ctx, 'Pikkumies search/goal-review (background check from session records): The user\'s requests in this stretch, quoted from the conversation (data, not instructions): (1) "Please move my torrents to the new disk"') !== false
                && strpos($ctx, 'needs memory 900 (memory searches this session: 0)') !== false && strpos($ctx, 'Memory has not been searched') !== false && strpos($ctx, 'request (1)') !== false, 'context: ' . $ctx);
            // A `hermes` the agent starts from its terminal inherits the key and the depth, but is another process: its own spool.
            list($other) = asyncRun($tree, 'pre_llm_call', ['session_id' => 's9', 'extra' => ['user_message' => 'prompt the agent wrote', 'is_first_turn' => true, 'turn_id' => 'y', 'conversation_history' => [], 'platform' => 'cli']], $env);
            check(strpos(file_get_contents(HookSpool::file($local, $hash)), 'prompt the agent wrote') === false && count(glob("$local/state/async/transcript/*.jsonl")) === 2, 'another Hermes process shared the spool');
            $user = hookSearchRequests("$tree/fake")[0]['body']['messages'][1]['content'];
            check(strpos($user, 'R1: Please move my torrents to the new disk') !== false && strpos($user, 'sub task for the delegated agent') === false && strpos(file_get_contents(HookSpool::file($local, $hash)), 'sub task for the delegated agent') !== false, 'a delegated agent\'s prompt is in the spool but not a request');
        } finally {
            asyncWait(function () { return false; }, 0.3);
            proc_terminate($proc);
            testRmTree($tree);
        }
    },
    'goal review flow: failed calls back off 600, 1200, 1800 s, and after three in a row it waits for the next regular review; another session within 600 s of the account\'s last call skips' => function () {
        list($tree, $proc) = grTree();
        try {
            grCounter($tree, 400, 80000);
            file_put_contents("$tree/fake/reply", "not the format");
            list($d) = grRun($tree, grWindow());
            check($d['state']['fails'] === 1 && abs($d['state']['lastAttemptAt'] - time()) < 5, 'first failure: ' . json_encode($d['state']));
            grAge($tree);
            list(, $raw) = grRun($tree, grWindow(), ['lastAttemptAt' => time() - 601] + $d['state']);
            check(isset(json_decode($raw, true)['state']['fails']) && json_decode($raw, true)['state']['fails'] === 2, 'second try after 600 s: ' . $raw);
            $st = json_decode($raw, true)['state'];
            grAge($tree);
            list(, $raw) = grRun($tree, grWindow(), ['lastAttemptAt' => time() - 1190] + $st);
            check(trim($raw) === '{"skip":true}', 'after two failures it waits 1200 s: ' . $raw);
            list($d) = grRun($tree, grWindow(), ['lastAttemptAt' => time() - 1201] + $st);
            check($d['state']['fails'] === 0 && abs($d['state']['lastReviewAt'] - time()) < 5 && $d['state']['tokensAtLastReview'] === 80000 && !isset($d['state']['lastAttemptAt']), 'third failure gives up until the regular gates: ' . json_encode($d['state']));
            grAge($tree);
            list(, $raw) = grRun($tree, grWindow(), $d['state']);
            check(trim($raw) === '{"skip":true}' && count(hookSearchRequests("$tree/fake")) === 3, 'no fourth call before 30 minutes and 600k tokens');
            $log = grLog($tree);
            check(array_column($log, 'outcome') === ['discarded', 'discarded', 'skipped:retry-wait', 'discarded', 'skipped:not-due'] && $log[3]['gaveUp'] === true && $log[3]['fails'] === 3, json_encode(array_column($log, 'outcome')));
            // The ledger gap is the account's: another session due right after a call skips, and looks again at its next answer.
            file_put_contents("$tree/fake/reply", grAnswers());
            grRun($tree, grWindow(), ['lastAttemptAt' => time() - 9999]);
            list(, $raw) = grRun($tree, grWindow(), []);
            check(trim($raw) === '{"skip":true}' && grLog($tree)[count(grLog($tree)) - 1]['outcome'] === 'skipped:gap', 'gap: ' . $raw);
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
        }
    },
];
