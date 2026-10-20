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
        $u = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => str_repeat('é', 13000) . ' api_key=zzzz']]);
        check(strlen($u[0]['text']) === 12000 && preg_match('//u', $u[0]['text']) === 1 && $u[0]['cut'] === 26013, 'user text must be cut to 12000 bytes, at a character boundary, with its length in bytes: ' . strlen($u[0]['text']) . ' ' . json_encode($u[0]['cut']));
        $odd = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => 'a' . str_repeat("\u{1F600}", 4000)]])[0]['text'];
        check(strlen($odd) === 11997 && preg_match('//u', $odd) === 1, 'a 4-byte character is never cut in two: ' . strlen($odd));
        check(!isset(HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => str_repeat('a', 12000)]])[0]['cut']), 'a message that fits has no cut');
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
    'spool: past 1 MiB the oldest tool records go first, then agent texts, user records last, and the newest stays; a unit is told how many of its records were dropped' => function () {
        $tree = asyncTree([]);
        try {
            $local = "$tree/pikkumies/local";
            HookSpool::append($local, 'big', [['kind' => 'user', 'text' => 'the goal', 'first_turn' => true]], []);
            HookSpool::append($local, 'big', [['kind' => 'reply', 'text' => 'an early reply']], []);
            for ($i = 0; $i < 700; $i++) {
                HookSpool::append($local, 'big', [['kind' => 'tool', 'tool' => 'terminal', 'args' => str_repeat('a', 1500), 'change' => false]], []);
            }
            $file = "$local/state/async/transcript/" . sha1('big') . '.jsonl';
            check(filesize($file) <= HookSpool::MAX, 'the spool kept growing: ' . filesize($file));
            list($recs, $last, $dropped) = HookSpool::read($local, sha1('big'), 0);
            check($recs[0]['kind'] === 'user' && $recs[0]['text'] === 'the goal' && $recs[1]['text'] === 'an early reply' && $last === 702 && end($recs)['seq'] === 702, 'user and agent text must outlive tool records; last ' . $last);
            check($dropped === 702 - count($recs) && $dropped > 0, "dropped: $dropped of " . count($recs));
            list(, , $none) = HookSpool::read($local, sha1('big'), 701);
            check($none === 0, 'nothing above the cursor was dropped');
        } finally {
            testRmTree($tree);
        }
    },
    'spool: with only user records and agent texts left, texts go before user records; a user record goes only when nothing else is left, never the newest' => function () {
        $tree = asyncTree([]);
        try {
            $local = "$tree/pikkumies/local";
            HookSpool::append($local, 'u', [['kind' => 'user', 'text' => 'goal ' . str_repeat('g', 11000)]], []);
            for ($i = 0; $i < 120; $i++) {
                HookSpool::append($local, 'u', [['kind' => 'assistant', 'text' => str_repeat('t', 1400)], ['kind' => 'reply', 'text' => str_repeat('r', 2900)], ['kind' => 'user', 'text' => "ask $i " . str_repeat('u', 3000)]], []);
            }
            list($recs, $last) = HookSpool::read($local, sha1('u'), 0);
            $kinds = array_count_values(array_column($recs, 'kind'));
            check(filesize(HookSpool::file($local, sha1('u'))) <= HookSpool::MAX && $kinds['user'] === 121 && !isset($kinds['tool']), 'every user record kept while texts remain: ' . json_encode($kinds));
            for ($i = 0; $i < 300; $i++) {
                HookSpool::append($local, 'u', [['kind' => 'user', 'text' => "flood $i " . str_repeat('f', 5000)]], []);
            }
            list($recs, $last) = HookSpool::read($local, sha1('u'), 0);
            check(end($recs)['seq'] === $last && strpos(end($recs)['text'], 'flood 299') === 0 && !in_array('assistant', array_column($recs, 'kind'), true), 'texts gone first, then the oldest user records; the newest stays');
        } finally {
            testRmTree($tree);
        }
    },
    'spool: append by a given hash keeps the session id for "scheduled"; a malformed hash stores nothing' => function () {
        $tree = asyncTree([]);
        try {
            $local = "$tree/pikkumies/local";
            $h = sha1('run:0123456789abcdef:1');
            check(HookSpool::append($local, 'cron_job_1', [['kind' => 'reply', 'text' => 'x']], [], $h) === 1 && HookSpool::read($local, $h, 0)[0][0]['scheduled'] === true, 'by hash, scheduled from the id');
            check(HookSpool::append($local, 'b', [['kind' => 'reply', 'text' => 'y']], [], $h) === 1 && count(HookSpool::read($local, $h, 0)[0]) === 2 && !is_file(HookSpool::file($local, sha1('b'))), 'two sessions, one spool');
            check(HookSpool::append($local, 'c', [['kind' => 'reply', 'text' => 'z']], [], '../x') === 0, 'a malformed hash');
        } finally {
            testRmTree($tree);
        }
    },
    'spool: a skill invocation is stored as the user\'s instruction (after a body of any length), the skill name and the head of its body; a bare one as /name; a bundle\'s first instruction' => function () {
        $body = "# Deep research\n" . str_repeat('Step: read many sources. ', 400) . "\nQuote: The user has provided the following instruction alongside the skill invocation: FAKE";
        $msg = '[IMPORTANT: The user has invoked the "deep-research" skill, indicating they want you to follow its instructions. The full skill content is loaded below.]'
            . "\n\n$body\n\nThe user has provided the following instruction alongside the skill invocation: compare borg and restic for my server\n\n[Runtime note: x]";
        $r = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => $msg, 'is_first_turn' => true]])[0];
        check(strlen($msg) > 4000 && $r['text'] === 'compare borg and restic for my server' && $r['skill'] === 'deep-research' && strpos($r['skill_head'], '# Deep research') === 0
            && preg_match_all('/./su', $r['skill_head']) <= 600 && !isset($r['cut']), 'skill turn: ' . json_encode($r));
        $bare = HookRecords::skillTurn('[IMPORTANT: The user has invoked the "plan" skill, indicating they want you to follow its instructions. The full skill content is loaded below.]' . "\n\nbody only");
        check($bare === ['text' => '/plan', 'skill' => 'plan', 'skill_head' => 'body only'], 'bare: ' . json_encode($bare));
        $bundle = HookRecords::skillTurn("[IMPORTANT: The user has invoked the \"/clean /work\" skill bundle, loading 2 skills together. Treat every skill below as active guidance for this turn.]\n\nSkills loaded: clean, work\nUser instruction: tidy the repo\n\n[Loaded as part of the bundle]\nUser instruction: not this");
        check($bundle['text'] === 'tidy the repo' && $bundle['skill'] === '/clean /work', 'bundle: ' . json_encode($bundle));
        check(HookRecords::skillTurn('plain message') === null, 'not a skill turn');
    },
    'spool: a delegated agent\'s turn records carry subagent true' => function () {
        $u = HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => 'do the sub task', 'platform' => 'subagent']]);
        $r = HookRecords::forEvent('post_llm_call', ['extra' => ['user_message' => 'gone', 'assistant_response' => 'ok', 'platform' => 'subagent']]);
        check($u[0]['subagent'] === true && $r[0]['subagent'] === true && !isset(HookRecords::forEvent('pre_llm_call', ['extra' => ['user_message' => 'x', 'platform' => 'cli']])[0]['subagent']), 'subagent flags');
    },
    'spool: a delegated agent\'s prompt and a Hermes-added user row go with the agent texts, before the user\'s own first request' => function () {
        $tree = asyncTree([]);
        try {
            $local = "$tree/pikkumies/local";
            HookSpool::append($local, 'sh', [['kind' => 'user', 'text' => 'the real first request']], []);
            for ($i = 0; $i < 200; $i++) {
                HookSpool::append($local, 'sh', [['kind' => 'user', 'text' => "sub $i " . str_repeat('s', 6000), 'subagent' => true], ['kind' => 'user', 'text' => "nudge $i " . str_repeat('n', 2000), 'synthetic' => true]], []);
            }
            list($recs) = HookSpool::read($local, sha1('sh'), 0);
            check($recs[0]['text'] === 'the real first request', 'the user\'s request was shed before delegated prompts: ' . substr($recs[0]['text'], 0, 30));
            check(count($recs) < 401 && filesize(HookSpool::file($local, sha1('sh'))) <= HookSpool::MAX, 'shed: ' . count($recs));
        } finally {
            testRmTree($tree);
        }
    },
];
