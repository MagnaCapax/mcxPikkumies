<?php
/** lib/HookRunCount.php (ADR-0096): which spool and run file an event belongs to, and the counted tokens and lookups. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'run count: with a launcher key the spool is one per run and depth and the run file is the key\'s; without one, per session with a derived file' => function () {
        $k = '0123456789abcdef';
        testWithEnv(['PIKKUMIES_RUN' => null, 'PIKKUMIES_DEPTH' => null], function () use ($k) {
            check(HookRunCount::hash('s1') === sha1('s1') && HookRunCount::key(sha1('s1')) === substr(sha1('spool:' . sha1('s1')), 0, 16), 'no launcher: per session');
            check(preg_match(SessionCounters::RUN_RE, HookRunCount::key(sha1('s1'))) === 1, 'a derived key is a run key');
        });
        testWithEnv(['PIKKUMIES_RUN' => $k, 'PIKKUMIES_DEPTH' => '1'], function () use ($k) {
            check(HookRunCount::hash('s1', '100:5') === sha1("run:$k:1:100:5") && HookRunCount::hash('s2-after-compression', '100:5') === sha1("run:$k:1:100:5"), 'one spool across session ids in one Hermes process');
            check(HookRunCount::hash('s1', '200:9') !== HookRunCount::hash('s1', '100:5'), 'a hermes the agent started (another process, same key and depth) has its own spool');
            check(HookRunCount::hash('s1', '') === sha1("run:$k:1") && HookRunCount::hash('s1') === HookRunCount::hash('s1', HookRunCount::hermes()), 'no /proc: run and depth only; by default the parent read from /proc');
            check(HookRunCount::key(HookRunCount::hash('s1')) === $k && HookRunCount::key(sha1('other')) === $k, 'under the launcher every spool counts in the run\'s file');
        });
        testWithEnv(['PIKKUMIES_RUN' => $k, 'PIKKUMIES_DEPTH' => '2'], function () use ($k) {
            check(HookRunCount::hash('s1', '100:5') === sha1("run:$k:2:100:5"), 'a nested agent has its own spool');
        });
        testWithEnv(['PIKKUMIES_RUN' => 'NOTHEX0123456789', 'PIKKUMIES_DEPTH' => '1'], function () {
            check(HookRunCount::hash('s1') === sha1('s1') && HookRunCount::run() === '', 'an invalid key is no key');
        });
    },
    'run count: what counts as a memory or an outside lookup' => function () {
        $rows = [
            ['terminal', ['command' => 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php search "rtorrent lock"'], 'memory'],
            ['terminal', ['command' => 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php save x'], ''],
            ['terminal', ['command' => 'php ~/pikkumies/tools/documentation/kb/kb.php search disk'], 'memory'],
            ['terminal', ['command' => 'php pikkumies/tools/documentation/wiki/wiki.php get x'], 'memory'],
            ['read_file', ['path' => '/home/fixtureuser/pikkumies/memory/lessons/x.md'], 'memory'],
            ['search_files', ['path' => 'memory/'], 'memory'],
            ['read_file', ['path' => '/etc/nginx/nginx.conf'], ''],
            ['session_search', ['query' => 'x'], 'memory'],
            ['web_search', ['query' => 'x'], 'outside'],
            ['web_extract', ['urls' => ['https://x']], 'outside'],
            ['browser_navigate', ['url' => 'https://x'], 'outside'],
            ['delegate_task', ['goal' => 'x'], 'outside'],
            ['skill_view', ['name' => 'deep-research'], 'outside'],
            ['skill_view', ['name' => 'other'], ''],
            ['terminal', ['command' => 'curl -s https://example.org/api'], 'outside'],
            ['terminal', ['command' => 'curl -s localhost:8080'], ''],
            ['terminal', ['command' => 'php ~/pikkumies/tools/system/browse/browse.php get https://x'], 'outside'],
            ['terminal', ['command' => 'ls -la'], ''],
            ['patch', ['path' => 'memory/x.md'], ''],
        ];
        foreach ($rows as $r) {
            check(HookRunCount::lookup($r[0], $r[1]) === $r[2], json_encode($r) . ' gave ' . HookRunCount::lookup($r[0], $r[1]));
        }
    },
    'run count: without the launcher a spool gets its own run file and post_api_request tokens; with it, tokens are session-context\'s; lookups count with their time either way' => function () {
        $dir = testTempDir('pk-runcount');
        $k = 'aaaaaaaaaaaaaaaa';
        $api = ['extra' => ['platform' => 'cli', 'usage' => ['total_tokens' => 5000]]];
        $mem = ['tool_name' => 'terminal', 'tool_input' => ['command' => 'php tools/memory/pikkumies/pikkumies.php search "x"']];
        try {
            testWithEnv(['PIKKUMIES_RUN' => null], function () use ($dir, $api, $mem) {
                $h = sha1('s');
                HookRunCount::record($dir, 'post_api_request', $api, $h);
                HookRunCount::record($dir, 'post_api_request', $api, $h);
                HookRunCount::record($dir, 'post_tool_call', $mem, $h);
                HookRunCount::record($dir, 'post_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']], $h);
                $c = SessionCounters::readRun($dir, HookRunCount::key($h));
                check($c['tokens'] === 10000 && $c['reqs'] === 2 && $c['memory'] === 1 && abs($c['memoryAt'] - time()) < 5 && !isset($c['outside']) && abs($c['since'] - time()) < 5, 'fallback: ' . json_encode($c));
            });
            SessionCounters::resetRun($dir, $k);
            testWithEnv(['PIKKUMIES_RUN' => $k, 'PIKKUMIES_DEPTH' => '1'], function () use ($dir, $api, $mem, $k) {
                $h = HookRunCount::hash('s');
                HookRunCount::record($dir, 'post_api_request', $api, $h);
                HookRunCount::record($dir, 'post_tool_call', $mem, $h);
                $skill = '[IMPORTANT: The user has invoked the "deep-research" skill, indicating they want you to follow its instructions. The full skill content is loaded below.]' . "\n\nbody\n\nThe user has provided the following instruction alongside the skill invocation: compare x";
                HookRunCount::record($dir, 'pre_llm_call', ['extra' => ['user_message' => $skill]], $h);
                $c = SessionCounters::readRun($dir, $k);
                check($c['tokens'] === 0 && $c['memory'] === 1 && $c['outside'] === 1, 'launcher: ' . json_encode($c));
                check(count((array) glob(SessionCounters::dir($dir) . '/run-*.json')) === 2, 'no derived file under the launcher');
            });
            testWithEnv(['PIKKUMIES_RUN' => 'bbbbbbbbbbbbbbbb'], function () use ($dir, $mem) {
                HookRunCount::record($dir, 'post_tool_call', $mem, HookRunCount::hash('s'));
                check(SessionCounters::readRun($dir, 'bbbbbbbbbbbbbbbb') === null, 'a key no start zeroed gets no file');
            });
        } finally {
            testRmTree($dir);
        }
    },
    'run count: the Hermes identity is the parent\'s pid and start time from /proc stat, read after the last ")"; anything unreadable is empty' => function () {
        $proc = testTempDir('pk-proc');
        try {
            mkdir("$proc/self");
            mkdir("$proc/4242");
            $fields = function ($ppid, $start) {
                return 'S ' . $ppid . ' 1 1 0 -1 4194560 100 0 0 0 1 2 0 0 20 0 1 0 ' . $start . " 1000 50 18446744073709551615\n";
            };
            file_put_contents("$proc/self/stat", '777 (php (x) y) ' . $fields(4242, 999));
            file_put_contents("$proc/4242/stat", '4242 (python3) ' . $fields(1, 123456));
            check(HookRunCount::hermes($proc) === '4242:123456', 'identity: ' . HookRunCount::hermes($proc));
            file_put_contents("$proc/4242/stat", 'garbage');
            check(HookRunCount::hermes($proc) === '' && HookRunCount::hermes("$proc/none") === '', 'unreadable: empty');
            // A parent of pid 1 (or none, or no /proc) is no Hermes: in a container php is often a child of init.
            $real = HookRunCount::hermes();
            $st = (string) @file_get_contents('/proc/self/stat');
            $ppid = preg_match('/^\S+\s+(\d+)/', trim(substr($st, (int) strrpos($st, ')') + 1)), $m) ? (int) $m[1] : 0;
            check(preg_match('/^\d+:\d+$/', $real) === 1 || ($real === '' && $ppid <= 1), 'the real /proc gives one unless the parent is init: ' . json_encode([$real, $ppid]));
        } finally {
            testRmTree($proc);
        }
    },
    'run count: PIKKUMIES_RUN is one of the variables the nested-agent guard will not let the agent set or unset' => function () {
        check(in_array('PIKKUMIES_RUN', NestedAgent::HOMES, true), 'HOMES');
        $hit = function ($cmd) {
            foreach (NestedAgent::guardPatterns() as $re => $why) {
                if (preg_match($re, $cmd)) {
                    return true;
                }
            }
            return false;
        };
        check($hit('PIKKUMIES_RUN=0123456789abcdef hermes chat -q hi') && $hit('unset PIKKUMIES_RUN; hermes') && $hit('env -u PIKKUMIES_RUN pikkumies run chat -q x') && !$hit('echo $PIKKUMIES_RUN'), 'guarded');
    },
    'run count: the ledger keeps a unit\'s spends a gap apart for the whole account, and says why it refused' => function () {
        $dir = testTempDir('pk-ledger');
        try {
            check(HookLedger::spend($dir, 'goal-review', 24, 1, 600, $why) === true && $why === '', 'first spend');
            check(HookLedger::spend($dir, 'goal-review', 24, 1, 600, $why) === false && $why === 'gap', 'a second within 600 s: ' . json_encode($why));
            check(HookLedger::spend($dir, 'other-unit', 24, 1, 600, $why) === true && HookLedger::spend($dir, 'goal-review', 24, 1, 0, $why) === true, 'per unit; no gap asked, none kept');
            $d = json_decode(file_get_contents(HookLedger::file($dir)), true);
            $d['last']['goal-review'] = time() - 601;
            file_put_contents(HookLedger::file($dir), json_encode($d));
            check(HookLedger::spend($dir, 'goal-review', 24, 1, 600, $why) === true && HookLedger::used($dir, 'goal-review') === 3, 'after 600 s');
            check(HookLedger::spend($dir, 'goal-review', 3, 1, 0, $why) === false && $why === 'daily-cap', 'the cap still says so');
        } finally {
            testRmTree($dir);
        }
    },
    'run count: a delegated agent\'s call is never held by a pending notice, known by its platform or by its session seen as one before' => function () {
        $tree = asyncTree(['guard/watch' => []]);
        try {
            $notice = function ($s) use ($tree) {
                $f = asyncFile($tree, 'guard/watch', $s, '.notice.json');
                @mkdir(dirname($f), 0700, true);
                file_put_contents($f, json_encode(['text' => 'check the request first', 'holds' => 1, 'ts' => time()]));
                return $f;
            };
            $call = function ($s, array $extra = []) use ($tree) {
                list($out) = asyncRun($tree, 'pre_tool_call', ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -f x'], 'session_id' => $s, 'extra' => $extra + ['turn_id' => 't1']]);
                return json_decode($out, true);
            };
            $e = $notice('sub0');
            check($call('sub0', ['platform' => 'subagent']) === null && is_file($e), 'a call saying subagent took the notice');
            $f = $notice('main');
            asyncRun($tree, 'pre_llm_call', ['session_id' => 'sub1', 'extra' => ['user_message' => 'sub task', 'is_first_turn' => true, 'turn_id' => 'x', 'platform' => 'subagent', 'conversation_history' => []]]);
            $g = $notice('sub1');
            check($call('sub1') === null && is_file($g), 'a session seen as subagent (pre_tool_call names no platform) took the notice');
            check($call('main')['action'] === 'block' && !is_file($f), 'the main agent\'s call is held as before');
        } finally {
            testRmTree($tree);
        }
    },
];
