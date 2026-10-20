<?php
/**
 * ADR-0090: the per-session scratchpad. Runs the real tool and hook as subprocesses in a throwaway
 * tree (the MemoryMetricsTest pattern): calling Scratchpad:: in-process here would resolve against
 * this checkout's own Config::localDir() (pikkumies/local), which tests must never touch
 * (ArchitectureTest); paths into the throwaway tree are built by the small helpers below instead.
 */
if (!function_exists('scratchpadTree')) {
    /** A throwaway pikkumies tree with lib/, the scratchpad tool and its session hook; $config becomes local/config.php. */
    function scratchpadTree(array $config = null)
    {
        $tmp = testTempDir('pk-scratch');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/session/scratchpad', 'hooks/session/scratchpad-session'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/local", 0700, true);
        if ($config !== null) {
            file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        }
        return $tmp;
    }

    function scratchpadTool($tree)
    {
        return "$tree/pikkumies/tools/session/scratchpad/scratchpad.php";
    }

    function scratchpadDir($tree)
    {
        return "$tree/pikkumies/local/state/scratchpad";
    }

    function scratchpadFile($tree, $sessionId)
    {
        return scratchpadDir($tree) . '/' . sha1($sessionId) . '.md';
    }

    function scratchpadMetricsDir($tree)
    {
        return "$tree/pikkumies/local/state/scratchpad-metrics";
    }

    /** Records $sessionId as the live session (the hook's own job), through the hook subprocess. */
    function scratchpadTouch($tree, $sessionId)
    {
        $hook = "$tree/pikkumies/hooks/session/scratchpad-session/scratchpad-session.php";
        list($code, $out) = runPhp($hook, [], json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => $sessionId]));
        check($code === 0 && trim($out) === '', 'scratchpad-session must print nothing and exit 0: ' . $code . ' ' . $out);
    }

    /**
     * Runs the tool; [exit code, the verb's own record merged with the status line's fields (so
     * $r['ok'] and, say, $r['bytes'] both read directly), every raw JSONL record (for a multi-record
     * verb like `list`). On a refusal there is only the status line, so that alone is the merge.
     */
    function scratchpadRun($tree, array $args)
    {
        list($code, $out) = runPhp(scratchpadTool($tree), $args);
        $lines = cliLines($out);
        $n = count($lines);
        $status = $n ? $lines[$n - 1] : null;
        $merged = $n >= 2 && is_array($lines[$n - 2]) ? $lines[$n - 2] + (array) $status : $status;
        return [$code, $merged, $lines];
    }
}

return [
    'scratchpad: write sets the pinned head, show returns it, and the file is 0700/0600' => function () {
        $tree = scratchpadTree();
        try {
            list($code, $r) = scratchpadRun($tree, ['write', '--text', 'migrating arr configs, 2 of 5 done', '--session', 'sess-a']);
            check($code === 0 && $r['ok'] === true && $r['dropped'] === 0, 'write failed: ' . json_encode($r));
            list($code, $r) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check($code === 0 && $r['exists'] === true, 'show failed: ' . json_encode($r));
            check(strpos($r['text'], "## Task\nmigrating arr configs, 2 of 5 done") === 0, 'the pinned head was not set as given: ' . var_export($r['text'], true));
            $file = scratchpadFile($tree, 'sess-a');
            check(is_file($file), 'the pad file was not written where expected: ' . $file);
            check((fileperms(dirname($file)) & 0777) === 0700, 'the scratchpad directory must be 0700');
            check((fileperms($file) & 0777) === 0600, 'the pad file must be 0600');
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: append grows the log below the pinned head, oldest first, without touching the head' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadRun($tree, ['write', '--text', 'task A', '--session', 'sess-a']);
            scratchpadRun($tree, ['append', '--text', 'step 1 done', '--session', 'sess-a']);
            scratchpadRun($tree, ['append', '--text', 'step 2 done', '--session', 'sess-a']);
            list(, $r) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check(strpos($r['text'], "## Task\ntask A\n\n## Log\n") === 0, 'the head must stay untouched by append: ' . $r['text']);
            $afterLog = substr($r['text'], strlen("## Task\ntask A\n\n## Log\n"));
            $lines = explode("\n", rtrim($afterLog, "\n"));
            check(count($lines) === 2 && strpos($lines[0], 'step 1 done') !== false && strpos($lines[1], 'step 2 done') !== false,
                'log lines must be oldest first: ' . json_encode($lines));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: over the byte cap the oldest log lines are dropped first, with one marker line, and the head survives' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadRun($tree, ['write', '--text', 'cap test', '--session', 'sess-a']);
            $sawDrop = false;
            for ($i = 0; $i < 400 && !$sawDrop; $i++) {
                list(, $r) = scratchpadRun($tree, ['append', '--text', "checkpoint line number $i with some filler text to grow the pad", '--session', 'sess-a']);
                $sawDrop = $sawDrop || $r['dropped'] > 0;
            }
            check($sawDrop, 'the cap was never reached in 400 appends; the test fixture may be wrong');
            list(, $show) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check(strlen($show['text']) <= Scratchpad::MAX_BYTES, 'the pad exceeded its own cap: ' . strlen($show['text']));
            check(strpos($show['text'], "## Task\ncap test\n\n## Log\n") === 0, 'dropping log lines must never touch the pinned head: ' . $show['text']);
            check(preg_match('/\(\d+ lines dropped\)/', $show['text']) === 1, 'no drop marker in the kept log: ' . $show['text']);
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: clear deletes the pad outright; show then reports no pad; clearing an already-empty session is not an error' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadRun($tree, ['write', '--text', 'to be cleared', '--session', 'sess-a']);
            list($code, $r) = scratchpadRun($tree, ['clear', '--session', 'sess-a']);
            check($code === 0 && $r['ok'] === true && $r['deleted'] === true, 'clear must report that it actually deleted a pad: ' . json_encode($r));
            list(, $show) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check($show['exists'] === false && $show['text'] === '', 'show must report no pad after clear: ' . json_encode($show));
            list($code, $r) = scratchpadRun($tree, ['clear', '--session', 'sess-a']);
            check($code === 0 && $r['ok'] === true && $r['deleted'] === false, 'clearing nothing must still be ok, with deleted:false: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: list shows every session, newest first, never the session id in the clear' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadRun($tree, ['write', '--text', 'first', '--session', 'sess-old']);
            scratchpadRun($tree, ['write', '--text', 'second', '--session', 'sess-new']);
            touch(scratchpadFile($tree, 'sess-old'), time() - 3600);
            list(, , $lines) = scratchpadRun($tree, ['list']);
            $records = array_slice($lines, 0, count($lines) - 1); // the status line is last
            check(count($records) === 2, 'list must show both pads: ' . json_encode($lines));
            check($records[0]['file'] === basename(scratchpadFile($tree, 'sess-new')), 'list must be newest first: ' . json_encode($records));
            foreach ($records as $rec) {
                check(strpos($rec['file'], 'sess-') === false, 'list must never print a session id in the clear, only its file name: ' . json_encode($rec));
            }
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: write prunes pads untouched for 14 days; show/clear/list never prune on their own' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadRun($tree, ['write', '--text', 'stale', '--session', 'sess-stale']);
            $staleFile = scratchpadFile($tree, 'sess-stale');
            touch($staleFile, time() - 15 * 86400);
            scratchpadRun($tree, ['list']); // read-only verbs must not prune
            check(is_file($staleFile), 'list must not prune a stale pad on its own');
            scratchpadRun($tree, ['write', '--text', 'fresh', '--session', 'sess-fresh']);
            check(!is_file($staleFile), 'a write elsewhere must prune a pad untouched for 14 days');
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: without --session the tool reads the hook-written "current" file' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadTouch($tree, 'sess-live');
            list($code, $r) = scratchpadRun($tree, ['write', '--text', 'picked up from current']);
            check($code === 0 && $r['ok'] === true, 'write without --session must succeed once a session has touched current: ' . json_encode($r));
            check(is_file(scratchpadFile($tree, 'sess-live')), 'the write must have landed in the session current named, not elsewhere');
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: with no session recorded yet, and with no pad, the tool refuses with a hint rather than guessing' => function () {
        $tree = scratchpadTree();
        try {
            list($code, $r) = scratchpadRun($tree, ['show']);
            check($code !== 0 && $r['ok'] === false && strpos($r['hint'], '--session') !== false, 'no current file yet must refuse with a --session hint: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: two different sessions touching "current" within 10 minutes of each other is refused as ambiguous' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadTouch($tree, 'sess-one');
            scratchpadTouch($tree, 'sess-two');
            list($code, $r) = scratchpadRun($tree, ['show']);
            check($code !== 0 && $r['ok'] === false && strpos($r['hint'], '--session') !== false, 'two different recent writers must refuse, not guess: ' . json_encode($r));
            // an explicit --session is never second-guessed, even while that refusal would otherwise apply
            list($code, $r) = scratchpadRun($tree, ['show', '--session', 'sess-one']);
            check($code === 0 && $r['ok'] === true, '--session must always win outright: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: the same session touching "current" repeatedly is never flagged as a conflict' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadTouch($tree, 'sess-same');
            scratchpadTouch($tree, 'sess-same');
            scratchpadTouch($tree, 'sess-same');
            list($code, $r) = scratchpadRun($tree, ['show']);
            check($code === 0 && $r['ok'] === true, 'one session writing current repeatedly must never be ambiguous: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: an invalid --session is refused before any file is touched' => function () {
        $tree = scratchpadTree();
        try {
            list($code, $r) = scratchpadRun($tree, ['show', '--session', '../etc/passwd']);
            check($code !== 0 && $r['ok'] === false, 'a session id outside the allowed shape must be refused: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: write/append without --text is a usage error; show/clear/list take no positional arguments' => function () {
        $tree = scratchpadTree();
        try {
            list($code, $r) = scratchpadRun($tree, ['write', '--session', 'sess-a']);
            check($code !== 0 && $r['ok'] === false, 'write without --text must fail: ' . json_encode($r));
            list($code, $r) = scratchpadRun($tree, ['show', 'extra', '--session', 'sess-a']);
            check($code !== 0 && $r['ok'] === false, 'show with a stray positional argument must fail: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: write/append log one metrics line (ts, bytes, dropped) only while privacy.perfLogging is on; the pad text is never in it' => function () {
        $tree = scratchpadTree(['privacy' => ['perfLogging' => false]]);
        try {
            scratchpadRun($tree, ['write', '--text', 'secret task details', '--session', 'sess-a']);
            $metricsDir = scratchpadMetricsDir($tree);
            check(!is_dir($metricsDir) || !glob("$metricsDir/writes-*.jsonl"), 'perfLogging off must write no metrics file');
        } finally {
            testRmTree($tree);
        }
        $tree = scratchpadTree(['privacy' => ['perfLogging' => true]]);
        try {
            scratchpadRun($tree, ['write', '--text', 'secret task details', '--session', 'sess-a']);
            $files = (array) glob(scratchpadMetricsDir($tree) . '/writes-*.jsonl');
            check(count($files) === 1, 'perfLogging on must write exactly one metrics file: ' . json_encode($files));
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($files[0])), 'strlen'));
            check(count($lines) === 1, 'expected one metrics line: ' . implode('|', $lines));
            $rec = json_decode($lines[0], true);
            check(array_keys($rec) === ['ts', 'bytes', 'dropped'], 'unexpected metrics fields: ' . $lines[0]);
            check(strpos($lines[0], 'secret task details') === false, 'the pad text must never reach the metrics line: ' . $lines[0]);
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: write/append redact secrets before storing (S1); ordinary text is reported as not redacted' => function () {
        // Built by concatenation (not a literal credential in this source file), the RedactTest.php/ConfigureNotesTest.php convention.
        $secret1 = 'deploy key: ' . 'pass' . 'word=' . 'hunter2hunter2' . ' token ' . 'ghp' . '_' . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ab';
        $secret2 = 'pass' . 'word=' . 's3cr3t-value-here';
        $tree = scratchpadTree();
        try {
            list($code, $r) = scratchpadRun($tree, ['write', '--text', $secret1, '--session', 'sess-a']);
            check($code === 0 && $r['ok'] === true && $r['redacted'] === true, 'a secret-looking write must report redacted:true: ' . json_encode($r));
            list(, $show) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check(strpos($show['text'], 'hunter2hunter2') === false && strpos($show['text'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ab') === false,
                'the raw secret must never reach the pad on disk: ' . $show['text']);
            check(strpos($show['text'], 'REDACTED') !== false, 'the pad must show that something was redacted: ' . $show['text']);
            list($code, $r) = scratchpadRun($tree, ['append', '--text', $secret2, '--session', 'sess-a']);
            check($code === 0 && $r['ok'] === true && $r['redacted'] === true, 'a secret-looking append must report redacted:true: ' . json_encode($r));
            list(, $show) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check(strpos($show['text'], 's3cr3t-value-here') === false, 'an appended secret must never reach the pad on disk: ' . $show['text']);
            // ordinary text must never be flagged, and must reach the pad unchanged
            list($code, $r) = scratchpadRun($tree, ['write', '--text', 'migrating arr configs, 2 of 5 done', '--session', 'sess-b']);
            check($code === 0 && $r['ok'] === true && $r['redacted'] === false, 'ordinary text must not be flagged as redacted: ' . json_encode($r));
            list(, $show) = scratchpadRun($tree, ['show', '--session', 'sess-b']);
            check(strpos($show['text'], 'migrating arr configs, 2 of 5 done') !== false, 'ordinary text must reach the pad unchanged: ' . $show['text']);
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: show carries a fixed data notice, whether or not a pad exists yet (S2)' => function () {
        $tree = scratchpadTree();
        try {
            list(, $r) = scratchpadRun($tree, ['show', '--session', 'sess-empty']);
            check($r['ok'] === true && is_string($r['notice']) && $r['notice'] !== '', 'show with no pad must still carry a notice: ' . json_encode($r));
            scratchpadRun($tree, ['write', '--text', 'a task', '--session', 'sess-a']);
            list(, $r) = scratchpadRun($tree, ['show', '--session', 'sess-a']);
            check($r['notice'] === HaltJudge::DATA_NOTICE || strpos($r['notice'], 'data') !== false || strpos($r['notice'], 'instructions') !== false,
                'the notice must read as a data-not-instructions caution: ' . json_encode($r));
            check(strpos(strtolower($r['notice']), 'instruction') !== false, 'the notice must say the text is not an instruction: ' . json_encode($r));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: the skill tells the agent to treat the pad as data, not a new instruction (S2)' => function () {
        $skill = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/session/scratchpad/skill.md');
        check(preg_match('/\bdata\b.{0,60}\binstruction/si', $skill) === 1 || preg_match('/\binstruction.{0,60}\bdata\b/si', $skill) === 1,
            'skill.md must tell the agent the pad is data, not an instruction: ' . $skill);
    },
    'scratchpad: at most MAX_PADS pads exist at once, always including the one just written, even when every pad shares one mtime second (S4)' => function () {
        // A real run of this test's own loop measured every pad landing on the SAME filemtime() second
        // (PHP's mtime has no sub-second resolution): capPads() must never depend on mtime to tell the
        // pad just written apart from the others, only on excluding it by path outright.
        $tree = scratchpadTree();
        try {
            for ($i = 0; $i < Scratchpad::MAX_PADS + 10; $i++) {
                list($code, $r) = scratchpadRun($tree, ['append', '--text', 'y', '--session', "s$i"]);
                check($code === 0 && $r['ok'] === true, "append for session s$i failed: " . json_encode($r));
                $pads = (array) glob(scratchpadDir($tree) . '/*.md');
                check(count($pads) <= Scratchpad::MAX_PADS, "pad count exceeded MAX_PADS after session s$i: " . count($pads));
                check(in_array(scratchpadFile($tree, "s$i"), $pads, true), "the pad just written for s$i must never be the one removed: " . json_encode($pads));
            }
            $pads = (array) glob(scratchpadDir($tree) . '/*.md');
            check(count($pads) === Scratchpad::MAX_PADS, 'pad count must settle at exactly MAX_PADS: got ' . count($pads));
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: an orphaned *.md.tmp-PID file is pruned the same way a stale pad is (S4)' => function () {
        $tree = scratchpadTree();
        try {
            scratchpadRun($tree, ['write', '--text', 'first', '--session', 'sess-a']);
            $orphan = scratchpadFile($tree, 'crashed') . '.tmp-99999';
            file_put_contents($orphan, 'leftover from a killed write');
            touch($orphan, time() - 15 * 86400);
            check(is_file($orphan), 'the orphan fixture must exist before the prune runs');
            scratchpadRun($tree, ['write', '--text', 'second', '--session', 'sess-b']); // any write runs prune()
            check(!file_exists($orphan), 'a *.md.tmp-PID file older than 14 days must be pruned, not kept forever: ' . $orphan);
        } finally {
            testRmTree($tree);
        }
    },
    'scratchpad: the halt guard\'s wrap-up message tells the agent to checkpoint the scratchpad when a task continues' => function () {
        check(strpos(HaltJudge::WRAPUP, 'checkpoint it with the scratchpad tool') !== false, 'HaltJudge::WRAPUP must mention the scratchpad tool: ' . HaltJudge::WRAPUP);
        check(strpos(HaltJudge::WRAPUP, 'not a question that waits for an answer') !== false, 'the appended sentence must not have replaced the existing wrap-up text');
    },
    'scratchpad: the tool\'s own skill tells the agent to show first when continuing a task' => function () {
        $skill = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/session/scratchpad/skill.md');
        check(strpos($skill, 'show') !== false && preg_match('/\bshow\b.*\bfirst\b|\bfirst\b.*\bshow\b/s', $skill) === 1,
            'skill.md must tell the agent to run show first when continuing a task: ' . $skill);
    },
];
