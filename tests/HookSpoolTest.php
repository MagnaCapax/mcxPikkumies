<?php
/** lib/HookRecords.php and lib/HookSpool.php (ADR-0067): what the interval units are given about a session, and how it is kept. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'spool: each kind comes from its event: user from pre_llm_call, this turn\'s assistant rows, synthetic nudges and the reply from post_llm_call, tool from post_tool_call' => function () {
        $user = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => [['type' => 'text', 'text' => 'part one'], ['type' => 'image']], 'is_first_turn' => true, 'turn_id' => 't9']]);
        check($user === [['turn_id' => 't9', 'kind' => 'user', 'text' => 'part one', 'first_turn' => true]], 'user: ' . json_encode($user));
        $history = [['role' => 'user', 'content' => 'old request'], ['role' => 'assistant', 'content' => 'old answer'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'new request']]], ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c']]],
            ['role' => 'tool', 'content' => 'TOOL OUTPUT'], ['role' => 'assistant', 'content' => 'checking the disk'],
            ['role' => 'user', 'content' => 'verify your edit', '_pre_verify_synthetic' => true], ['role' => 'assistant', 'content' => 'All done.']];
        $turn = HookRecords::forEvent('post_llm_call', ['extra' => ['user_message' => 'new request', 'assistant_response' => 'All done.', 'conversation_history' => $history, 'turn_id' => 't9']]);
        check(array_column($turn, 'kind') === ['assistant', 'user', 'reply'] && $turn[0]['text'] === 'checking the disk' && $turn[1]['synthetic'] === true && $turn[2]['text'] === 'All done.', 'turn: ' . json_encode($turn));
        check(strpos(json_encode($turn), 'old answer') === false && strpos(json_encode($turn), 'TOOL OUTPUT') === false, 'an earlier turn or a tool output was recorded');
        $gone = HookRecords::forEvent('post_llm_call', ['extra' => ['user_message' => 'not in the history', 'assistant_response' => 'ok', 'conversation_history' => $history]]);
        check($gone === [['turn_id' => '', 'kind' => 'reply', 'text' => 'ok', 'compacted' => true]], 'compacted: ' . json_encode($gone));
        $tool = HookRecords::forEvent('post_tool_call', asyncTool('s', 'terminal', ['command' => 'rm -f x'], 'error') + []);
        check($tool[0]['kind'] === 'tool' && $tool[0]['change'] === true && $tool[0]['status'] === 'error' && $tool[0]['exit_code'] === 0 && !isset($tool[0]['output']), 'tool: ' . json_encode($tool));
        check(HookRecords::forEvent('pre_tool_call', asyncTool('s', 'terminal', ['command' => 'ls'])) === [], 'pre_tool_call records nothing');
    },
    'spool: every text is cut to its cap and redacted; a tool\'s output is never kept; a record whose redaction fails is dropped' => function () {
        $p = asyncTool('s', 'terminal', ['command' => 'mysql -psecretpw db; ' . str_repeat('x', 5000)]);
        $p['extra']['error_message'] = 'token=abcdefghijklmnop ' . str_repeat('e', 400);
        $r = HookRecords::forEvent('post_tool_call', $p)[0];
        check(strpos($r['args'], 'secretpw') === false && strpos($r['args'], 'REDACTED') !== false && strlen($r['args']) <= 2000 + 20, 'args: ' . substr($r['args'], 0, 120));
        check(strpos($r['error'], 'abcdefghijklmnop') === false && strlen($r['error']) <= 300 + 20 && strpos(json_encode($r), 'SECRET OUTPUT') === false, 'error/output: ' . json_encode($r));
        $u = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => str_repeat('é', 5000) . ' api_key=zzzz']]);
        check(preg_match_all('/./su', $u[0]['text']) === 4000, 'user text must be cut to 4000 characters, at a character boundary');
        // A line Redact cannot check (the regex engine gives up, forced here with a tiny backtrack limit) drops the record.
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $bomb = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => 'my password=hunter2 please']]);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
        check($bomb === [], 'an unredactable record was kept: ' . json_encode($bomb));
    },
    'spool: seq strictly increases across 8 parallel appenders; files are 0600 in 0700 directories; the wake flags follow the units\' wake kinds, change also meaning may-have-changed' => function () {
        $tree = asyncTree([]);
        try {
            $lib = "$tree/pikkumies/lib";
            $script = "$tree/append.php";
            file_put_contents($script, '<?php foreach (glob(' . var_export("$lib/*.php", true) . ') as $l) { require_once $l; } umask(022);'
                . ' for ($i = 0; $i < 20; $i++) { HookSpool::append(Config::localDir(), "par", [["turn_id" => "t", "kind" => "tool", "tool" => "terminal", "args" => "x", "change" => false]], []); }');
            $procs = [];
            for ($i = 0; $i < 8; $i++) {
                $procs[] = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [], $pipes);
            }
            foreach ($procs as $p) {
                proc_close($p);
            }
            $file = "$tree/pikkumies/local/state/async/transcript/" . sha1('par') . '.jsonl';
            $seqs = array_map(function ($l) { return json_decode($l, true)['seq']; }, file($file, FILE_IGNORE_NEW_LINES));
            check($seqs === range(1, 160), 'seq: ' . count($seqs) . ' lines, ' . json_encode(array_slice($seqs, -12)));
            check((fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'modes: ' . decoct(fileperms($file) & 0777) . ' ' . decoct(fileperms(dirname($file)) & 0777));
            $units = ['guard/a' => ['wakes' => ['change']], 'search/b' => ['wakes' => ['reply']], 'x/c' => ['wakes' => []]];
            HookSpool::append("$tree/pikkumies/local", 'w', [['kind' => 'tool', 'change' => 'unknown']], $units);
            $flag = function ($id) use ($tree) {
                return is_file("$tree/pikkumies/local/state/async/" . str_replace('/', '-', $id) . '/' . sha1('w') . '.wake');
            };
            check($flag('guard/a') && !$flag('search/b') && $flag('x/c'), 'wake flags after a may-have-changed call');
            check(HookSpool::wakes(['change'], [['kind' => 'tool', 'change' => false]]) === false && HookSpool::wakes(['change'], [['kind' => 'tool', 'change' => true]]) === true, 'change wake');
        } finally {
            testRmTree($tree);
        }
    },
    'spool: past 256 KB the oldest tool records go first and the newest stays; a unit is told how many of its records were dropped' => function () {
        $tree = asyncTree([]);
        try {
            $local = "$tree/pikkumies/local";
            HookSpool::append($local, 'big', [['kind' => 'user', 'text' => 'the goal', 'first_turn' => true]], []);
            for ($i = 0; $i < 200; $i++) {
                HookSpool::append($local, 'big', [['kind' => 'tool', 'tool' => 'terminal', 'args' => str_repeat('a', 1500), 'change' => false]], []);
            }
            $file = "$local/state/async/transcript/" . sha1('big') . '.jsonl';
            check(filesize($file) <= HookSpool::MAX, 'the spool kept growing: ' . filesize($file));
            list($recs, $last, $dropped) = HookSpool::read($local, sha1('big'), 0);
            check($recs[0]['kind'] === 'user' && $recs[0]['text'] === 'the goal' && $last === 201 && end($recs)['seq'] === 201, 'the user record must outlive tool records; last ' . $last);
            check($dropped === 201 - count($recs) && $dropped > 0, "dropped: $dropped of " . count($recs));
            list(, , $none) = HookSpool::read($local, sha1('big'), 200);
            check($none === 0, 'nothing above the cursor was dropped');
        } finally {
            testRmTree($tree);
        }
    },
];
