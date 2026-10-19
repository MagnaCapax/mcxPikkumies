<?php
/** lib/HookPending.php through the orchestrator (ADR-0067): an interval unit's notice holds one call that may change data; its context reaches the next turn. */
require_once __DIR__ . '/asyncSupport.php';

if (!function_exists('noticePut')) {
    /** A pending item for unit $id and session $s, as a finished run leaves it. */
    function noticePut($tree, $id, $s, $kind, $text, $holds = 1)
    {
        $f = asyncFile($tree, $id, $s, ".$kind.json");
        @mkdir(dirname($f), 0700, true);
        file_put_contents($f, json_encode(['text' => $text, 'holds' => $holds, 'ts' => time()]));
        return $f;
    }

    function noticeCall($tree, $s, $command, $turn = 't1')
    {
        list($out, $took) = asyncRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => $command], 'session_id' => $s, 'extra' => ['turn_id' => $turn]]);
        return [json_decode($out, true), $took];
    }
}

return [
    'notice: held once on the next call that may change data, never on a read; not used up when another unit blocks; a dropped question leaves it to hold' => function () {
        $tree = asyncTree(['guard/watch' => [], 'guard/stop' => ['event' => 'pre_tool_call', 'every' => null, 'body' => '$p = json_decode(stream_get_contents(STDIN), true);'
            . ' if (strpos($p["tool_input"]["command"], "forbidden") !== false) { echo json_encode(["action" => "block", "message" => "no"]); }'
            . ' if (strpos($p["tool_input"]["command"], "ask") !== false) { echo json_encode(["action" => "approve", "message" => "sure?"]); }']]);
        try {
            $f = noticePut($tree, 'guard/watch', 'n1', 'notice', 'check the request first');
            list($d) = noticeCall($tree, 'n1', 'ls -la');
            check($d === null && is_file($f), 'a read was held: ' . json_encode($d));
            list($d) = noticeCall($tree, 'n1', 'rm forbidden');
            check($d['message'] === 'no' && is_file($f), 'the notice must stay when another unit blocks: ' . json_encode($d));
            // With approvals off a question of no kind is dropped (ADR-0073), so no unit has an opinion and the notice holds.
            list($d) = noticeCall($tree, 'n1', 'rm ask');
            check($d['action'] === 'block' && $d['message'] === 'Pikkumies guard/watch (background check from session records): check the request first' && !is_file($f), 'not held: ' . json_encode($d));
            list($d) = noticeCall($tree, 'n1', 'rm -f x');
            check($d === null, 'held twice');
        } finally {
            testRmTree($tree);
        }
    },
    'notice: holds stop at holdsPerTurn in one turn and never come twice within 60 s; a symlinked or locked notice holds nothing and answers at once' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            $held = asyncFile($tree, 'guard/watch', 'n2', '.held');
            noticePut($tree, 'guard/watch', 'n2', 'notice', 'one', 2);
            list($d) = noticeCall($tree, 'n2', 'rm a', 'tA');
            check($d['action'] === 'block', 'first hold');
            noticePut($tree, 'guard/watch', 'n2', 'notice', 'two', 2);
            list($d) = noticeCall($tree, 'n2', 'rm a', 'tA');
            check($d === null, 'a second hold within 60 s');
            file_put_contents($held, json_encode(['turn' => 'tA', 'count' => 1, 'at' => time() - 61]));
            list($d) = noticeCall($tree, 'n2', 'rm a', 'tA');
            check($d['action'] === 'block' && strpos($d['message'], 'two') !== false, 'holdsPerTurn 2 allows a second hold after 60 s');
            noticePut($tree, 'guard/watch', 'n2', 'notice', 'three', 2);
            file_put_contents($held, json_encode(['turn' => 'tA', 'count' => 2, 'at' => time() - 61]));
            list($d) = noticeCall($tree, 'n2', 'rm a', 'tA');
            check($d === null, 'a third hold in one turn with holdsPerTurn 2');
            list($d) = noticeCall($tree, 'n2', 'rm a', 'tB');
            check($d['action'] === 'block' && strpos($d['message'], 'three') !== false, 'a new turn may hold again');
            // A symlinked notice is refused, not followed.
            file_put_contents("$tree/elsewhere.json", json_encode(['text' => 'planted', 'holds' => 1]));
            symlink("$tree/elsewhere.json", asyncFile($tree, 'guard/watch', 'n3', '.notice.json'));
            list($d) = noticeCall($tree, 'n3', 'rm a');
            check($d === null && is_file("$tree/elsewhere.json"), 'a symlinked notice was used: ' . json_encode($d));
            symlink("$tree/elsewhere.json", asyncFile($tree, 'guard/watch', 'n3', '.context.json'));
            list($out) = asyncRun($tree, 'pre_llm_call', ['session_id' => 'n3', 'extra' => ['user_message' => 'next', 'is_first_turn' => false]]);
            check(strpos((string) $out, 'planted') === false, 'a symlinked context or notice was shown: ' . $out);
            // A notice whose lock another process holds: no hold, no wait, and it stays pending.
            $f = noticePut($tree, 'guard/watch', 'n4', 'notice', 'busy');
            $lock = fopen(asyncFile($tree, 'guard/watch', 'n4', '.plock'), 'c');
            flock($lock, LOCK_EX);
            list($d, $took) = noticeCall($tree, 'n4', 'rm a');
            flock($lock, LOCK_UN);
            fclose($lock);
            check($d === null && $took < 1 && is_file($f), "a locked notice: " . json_encode($d) . " in {$took}s");
        } finally {
            testRmTree($tree);
        }
    },
    'notice: two parallel tool calls deliver it exactly once; one not held by the next turn is shown there as context, after Pikkumies\' own notes' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            noticePut($tree, 'guard/watch', 'n5', 'notice', 'once only');
            $payload = json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm a'], 'session_id' => 'n5', 'extra' => ['turn_id' => 't']]);
            $procs = [];
            for ($i = 0; $i < 4; $i++) {
                $procs[$i] = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/pikkumies/hooks/orchestrator.php") . ' pre_tool_call', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes[$i]);
                fwrite($pipes[$i][0], $payload);
                fclose($pipes[$i][0]);
            }
            $holds = 0;
            foreach ($procs as $i => $p) {
                $holds += strpos((string) stream_get_contents($pipes[$i][1]), 'once only') !== false ? 1 : 0;
                proc_close($p);
            }
            check($holds === 1, "delivered $holds times");
            noticePut($tree, 'guard/watch', 'n6', 'notice', 'not held in time');
            noticePut($tree, 'guard/watch', 'n6', 'context', 'a context line');
            // A local unit with a problem: Pikkumies' own line about it must come before the background text (ADR-0054).
            @mkdir("$tree/pikkumies/local/hooks/mine/broken", 0700, true);
            file_put_contents("$tree/pikkumies/local/hooks/mine/broken/hook.md", "no frontmatter\n");
            list($out) = asyncRun($tree, 'pre_llm_call', ['session_id' => 'n6', 'extra' => ['user_message' => 'next', 'is_first_turn' => false]]);
            $ctx = json_decode($out, true)['context'];
            $own = strpos($ctx, 'Pikkumies: skipped the local hook local/mine/broken');
            $bg = strpos($ctx, 'Pikkumies guard/watch (background check from session records): a context line');
            check($own !== false && $bg !== false && $own < $bg
                && strpos($ctx, 'Pikkumies guard/watch (background check from session records): not held in time') !== false, 'context: ' . $ctx);
            list($out) = asyncRun($tree, 'pre_llm_call', ['session_id' => 'n6', 'extra' => ['user_message' => 'again', 'is_first_turn' => false]]);
            check(strpos((string) $out, 'background check') === false, 'shown twice: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
    'notice: a run\'s output is capped (context 4000, notice 900, state 4096 bytes; over a cap that part is dropped and logged) and noticeOn must be change' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            $out = asyncPhp($tree, '$u = HookInterval::on([])["guard/watch"]; $h = sha1("c1");'
                . ' $a = HookPending::store(Config::localDir(), $u, $h, ["context" => str_repeat("x", 4001), "notice" => str_repeat("n", 900), "state" => ["k" => str_repeat("s", 5000)]]);'
                . ' $b = HookPending::store(Config::localDir(), $u, $h, ["notice" => "x", "noticeOn" => "any", "state" => ["k" => 1]]);'
                . ' echo json_encode([$a, $b]);');
            check($out === '[null,{"k":1}]', 'states: ' . $out);
            check(!is_file(asyncFile($tree, 'guard/watch', 'c1', '.context.json')), 'a context over 4000 characters was kept');
            check(json_decode(file_get_contents(asyncFile($tree, 'guard/watch', 'c1', '.notice.json')), true)['text'] === str_repeat('n', 900), 'a 900-character notice must be kept, and noticeOn any refused');
            $log = (string) file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl');
            check(substr_count($log, '"outcome":"context-dropped"') === 1 && substr_count($log, '"outcome":"state-dropped"') === 1 && substr_count($log, '"outcome":"notice-dropped"') === 1, 'log: ' . $log);
        } finally {
            testRmTree($tree);
        }
    },
    'notice: files older than the retention go, and a context or notice that was never shown is logged as pruned-undelivered' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            $old = noticePut($tree, 'guard/watch', 'p1', 'context', 'stale');
            $spool = "$tree/pikkumies/local/state/async/transcript/" . sha1('p1') . '.jsonl';
            @mkdir(dirname($spool), 0700, true);
            file_put_contents($spool, "{}\n");
            $fresh = noticePut($tree, 'guard/watch', 'p2', 'notice', 'fresh');
            touch($old, time() - 3 * 86400);
            touch($spool, time() - 3 * 86400);
            asyncPhp($tree, 'HookFeed::prune(Config::localDir(), ["sessions" => ["retentionDays" => 2]], ["guard/watch" => []]);');
            clearstatcache();
            check(!is_file($old) && !is_file($spool) && is_file($fresh), 'prune: retention 2 days');
            $counts = HookAsyncStatus::counts("$tree/pikkumies/local/logs/hooks", time() - 60);
            check($counts === ['guard/watch' => ['pruned unshown' => 1]], 'counted: ' . json_encode($counts));
            touch($fresh, time() - 8 * 86400);
            asyncPhp($tree, 'HookFeed::prune(Config::localDir(), [], []);');
            clearstatcache();
            check(!is_file($fresh), 'with no retention set, 7 days');
        } finally {
            testRmTree($tree);
        }
    },
];
