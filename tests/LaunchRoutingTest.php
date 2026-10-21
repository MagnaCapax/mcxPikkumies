<?php
/**
 * lib/Launch.php routing edges not covered by LaunchTest.php (ADR-0022, ADR-0032, ADR-0052): the leading
 * -m/--model/--reasoning flags (lib/RunFlags.php), route()'s tmux
 * digit/force-prompt boundaries, the small pure helpers (allFlags/promptArgs/tmuxPlan) called
 * directly, and the Launch::SUBCOMMANDS <-> bin/pikkumies dispatch wiring contract. installFlags,
 * askTerms, settingsNote and isTty belong to another builder's package and are not touched
 * here.
 */
return [
    'launch: Eternal verbs claim only their exact command shapes' => function () {
        $root = testTempDir('launch-eternal-route');
        file_put_contents($root . '/enabled', "1\n");
        Eternal::$hostMarker = $root . '/enabled';
        try {
        foreach ([['ask'], ['ask', '--nonce=abcdefgh'], ['ui', 'start'], ['ui', 'web-build'], ['eternal'], ['eternal', 'list'], ['eternal', 'list', '--json'], ['eternal', 'ensure'], ['eternal', 'new'], ['eternal', 'on'], ['eternal', 'off'], ['eternal', 'quit', 'pm2'], ['eternal', 'hold', '--until', '123']] as $words) {
            check(Launch::route(array_merge(['pikkumies'], $words))[0] === $words[0], 'command rejected: ' . json_encode($words));
        }
        foreach ([['ask', 'how', 'big', 'is', 'my', 'disk'], ['ask', '--nonce='], ['ask', '--bad'], ['ask', '--nonce=abc', 'please'], ['ui', 'show', 'me'], ['ui', '--help'], ['ui', 'start', 'please'], ['ui', 'web-build', 'now'], ['eternal', 'life', 'is', 'short'], ['eternal', '--bad'], ['eternal', 'quit', 'bad'], ['eternal', 'hold', '--until', 'tomorrow']] as $words) {
            check(Launch::route(array_merge(['pikkumies'], $words)) === ['run', ['chat', '--query=' . implode(' ', $words)]], 'prompt claimed: ' . json_encode($words));
        }
        } finally {
            Eternal::$hostMarker = null;
            testRmTree($root);
        }
    },
    'launch: inside tmux switches the existing client' => function () {
        $plan = Launch::tmuxPlan('2', ['pm1', 'pm2'], true, 'ignored');
        check(end($plan) === ['tmux', 'switch-client', '-t', '=pikkumies'], 'an existing client was not switched');
        $root = testTempDir('launch-tmux-argv');
        $oldTmux = getenv('TMUX');
        putenv('TMUX=/foreign/socket,1,0');
        Eternal::$tmuxDirectory = $root;
        try {
            $argv = Eternal::tmuxArgv(array_slice(end($plan), 1));
            check(array_slice($argv, 0, 2) === ['env', '-i'] && !in_array('TMUX=/foreign/socket,1,0', $argv, true), 'attach inherited another client');
        } finally {
            Eternal::$tmuxDirectory = null;
            $oldTmux === false ? putenv('TMUX') : putenv('TMUX=' . $oldTmux);
            testRmTree($root);
        }
    },
    'launch: markerless Eternal words are prompts and private socket selects switch or attach' => function () {
        $root = testTempDir('launch-private-socket');
        Eternal::$hostMarker = $root . '/missing';
        Eternal::$tmuxDirectory = $root;
        try {
            foreach ([['ask'], ['ask', '--nonce=abcdefgh'], ['ui', 'start'], ['ui', 'web-build'], ['eternal', 'new'], ['eternal', '--bad']] as $words) {
                check(Launch::route(array_merge(['pikkumies'], $words)) === ['run', ['chat', '--query=' . implode(' ', $words)]], 'markerless word claimed: ' . json_encode($words));
            }
            check(Eternal::insidePrivateTmux($root . '/tmux/tmux-1000/default,1,0'), 'private socket missed');
            check(!Eternal::insidePrivateTmux('/ordinary/socket,1,0'), 'foreign socket claimed');
            $privatePlan = Launch::tmuxPlan('1', ['pm1'], Eternal::insidePrivateTmux($root . '/tmux/tmux-1000/default,1,0'), 'cmd');
            $foreignPlan = Launch::tmuxPlan('1', ['pm1'], Eternal::insidePrivateTmux('/ordinary/socket,1,0'), 'cmd');
            check(end($privatePlan)[1] === 'switch-client', 'private client did not switch');
            check(end($foreignPlan)[1] === 'attach-session', 'foreign client did not attach');
            $oldTmux = getenv('TMUX');
            try {
                putenv('TMUX=/ordinary/socket,1,0');
                check(!in_array('TMUX=/ordinary/socket,1,0', Eternal::tmuxArgv(['attach-session']), true), 'foreign client leaked into private tmux');
                putenv('TMUX=' . $root . '/tmux/tmux-1000/default,1,0');
                check(in_array('TMUX=' . $root . '/tmux/tmux-1000/default,1,0', Eternal::tmuxArgv(['switch-client']), true), 'private client identity lost');
            } finally {
                $oldTmux === false ? putenv('TMUX') : putenv('TMUX=' . $oldTmux);
            }
        } finally {
            Eternal::$hostMarker = null;
            Eternal::$tmuxDirectory = null;
            testRmTree($root);
        }
    },
    'launch: help ignores trailing words; subcommand matching is case-sensitive' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', 'help', 'ignored']);
        check($cmd === 'help' && $args === [], 'help with a trailing word must stay help, never a prompt: ' . json_encode([$cmd, $args]));
        list($cmd, $args) = Launch::route(['pikkumies', 'RUN']);
        check($cmd === 'run' && $args === ['chat', '--query=RUN'], "'RUN' must not match the lowercase 'run' subcommand: " . json_encode([$cmd, $args]));
    },
    "launch: tmux accepts any ctype_digit word ('0', '99'), looser than bin's own 1-9 check; a non-digit or dashed word is a prompt" => function () {
        check(Launch::route(['pikkumies', 'tmux', '0']) === ['tmux', ['0']], "tmux 0 should route to tmux (bin's preg_match('/^[1-9]\$/') rejects it later)");
        check(Launch::route(['pikkumies', 'tmux', '99']) === ['tmux', ['99']], 'tmux 99 should route to tmux');
        list($cmd, $args) = Launch::route(['pikkumies', 'tmux', '1a']);
        check($cmd === 'run' && $args === ['chat', '--query=tmux 1a'], "'1a' is not ctype_digit, must be a prompt: " . json_encode([$cmd, $args]));
        list($cmd, $args) = Launch::route(['pikkumies', 'tmux', '-3']);
        check($cmd === 'run' && $args === ['chat', '--query=tmux -3'], "'-3' is not ctype_digit, must be a prompt: " . json_encode([$cmd, $args]));
    },
    "launch: '--' forces a prompt even over the tmux branch" => function () {
        list($cmd, $args) = Launch::route(['pikkumies', '--', 'tmux', '3']);
        check($cmd === 'run' && $args === ['chat', '--query=tmux 3'], "'--' must win over the tmux branch, which is checked later in route(): " . json_encode([$cmd, $args]));
    },
    "launch: explicit 'run update' passthrough is not special-cased by Launch itself" => function () {
        // Launch::route() only decides *that* 'run' keeps every word after it; any update-specific
        // gating (official origin, setpriv wrap, lock) is bin/pikkumies's own dispatch, not Launch's.
        list($cmd, $args) = Launch::route(['pikkumies', 'run', 'update']);
        check($cmd === 'run' && $args === ['update'], "'run update' must pass through unchanged: " . json_encode([$cmd, $args]));
    },
    'launch: allFlags() direct matrix' => function () {
        check(Launch::allFlags([]) === true, 'an empty list counts as all flags');
        check(Launch::allFlags(['-a', '-b']) === true, 'two flags: ' . json_encode(Launch::allFlags(['-a', '-b'])));
        check(Launch::allFlags(['-a', 'x']) === false, 'a bare word after a flag is not all-flags');
        check(Launch::allFlags(['']) === false, 'an empty-string word is not a flag');
        check(Launch::allFlags(['-']) === true, 'a lone dash starts with "-", so it counts as a flag');
    },
    'launch: promptArgs() direct blanks, and a middle blank word leaves a double-space artifact' => function () {
        check(Launch::promptArgs([]) === [], 'no words: ' . json_encode(Launch::promptArgs([])));
        check(Launch::promptArgs(['']) === [], 'one blank word: ' . json_encode(Launch::promptArgs([''])));
        check(Launch::promptArgs([' ', '  ']) === [], 'only whitespace words: ' . json_encode(Launch::promptArgs([' ', '  '])));
        check(Launch::promptArgs(['a', '', 'b']) === ['chat', '--query=a  b'], 'a blank middle word must leave the implode-with-empty-element double space: ' . json_encode(Launch::promptArgs(['a', '', 'b'])));
    },
    'launch: tmuxPlan() with an empty-array windows list (not null) still creates the window' => function () {
        $cmd = "'php' 'pikkumies'";
        $plan = Launch::tmuxPlan('2', [], false, $cmd);
        check($plan[0] === ['tmux', 'new-window', '-d', '-t', '=pikkumies:', '-n', 'pm2', $cmd], 'an empty windows list must take the "window missing" branch, not the "no session" branch: ' . json_encode($plan[0]));
        check(count($plan) === 3, 'expected select-window + attach after the new-window: ' . json_encode($plan));
    },
    'launch/bin contract: every Launch::SUBCOMMANDS value has a matching bin/pikkumies case, and vice versa' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match_all("/\n    case '([a-z-]+)':/", $bin, $m) > 0, 'no case labels found in bin/pikkumies');
        $binCases = array_values(array_diff($m[1], ['help']));
        $missingFromBin = array_diff(Launch::SUBCOMMANDS, $binCases);
        $missingFromLaunch = array_diff($binCases, Launch::SUBCOMMANDS);
        check($missingFromBin === [] && $missingFromLaunch === [], 'wiring drift between Launch::SUBCOMMANDS and bin/pikkumies dispatch: '
            . 'in SUBCOMMANDS but no case=' . json_encode(array_values($missingFromBin)) . '; case but not in SUBCOMMANDS=' . json_encode(array_values($missingFromLaunch)));
    },
    'launch: leading -m/--model/--model=/--reasoning pick the model and reasoning for one session, with or without text (ADR-0052)' => function () {
        check(Launch::route(['pikkumies', '-m', 'X']) === ['run', ['chat', '-m', 'X']], '-m alone: ' . json_encode(Launch::route(['pikkumies', '-m', 'X'])));
        check(Launch::route(['pikkumies', '-m', 'X', 'fix', 'my', 'plex']) === ['run', ['chat', '-m', 'X', '--query=fix my plex']], '-m with text');
        check(Launch::route(['pikkumies', '--model', 'X', 'hi']) === ['run', ['chat', '-m', 'X', '--query=hi']], '--model X');
        check(Launch::route(['pikkumies', '--model=X', 'hi']) === ['run', ['chat', '-m', 'X', '--query=hi']], '--model=X');
        check(Launch::route(['pikkumies', '--reasoning', 'high']) === ['run', ['chat', '--reasoning', 'high']], '--reasoning alone');
        check(Launch::route(['pikkumies', '--reasoning=low', '-m', 'Y', 'why', 'slow']) === ['run', ['chat', '-m', 'Y', '--reasoning', 'low', '--query=why slow']], '--reasoning= with -m and text: ' . json_encode(Launch::route(['pikkumies', '--reasoning=low', '-m', 'Y', 'why', 'slow'])));
        check(Launch::isSession(Launch::route(['pikkumies', '-m', 'X'])[1]), 'a -m run is a session: every first-start check applies');
    },
    'launch: a launcher flag without a value, or with one starting with "-", is an error and starts nothing (exit 2)' => function () {
        foreach ([['-m'], ['--model'], ['--model='], ['--reasoning'], ['--reasoning='], ['-m', '-v'], ['--model', '--reasoning', 'high'], ['-m', ' '], ['-m', 'X', '--reasoning']] as $words) {
            list($cmd, $args) = Launch::route(array_merge(['pikkumies'], $words));
            check($cmd === 'error' && count($args) === 1 && strpos($args[0], 'needs a') !== false, json_encode($words) . ' must be an error: ' . json_encode([$cmd, $args]));
        }
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/bin/pikkumies', ['-m']);
        check($code === 2 && strpos($out, 'Not starting: -m needs a model name') === 0, '-m without a model must stop with exit 2: ' . $code . ' ' . $out);
    },
    'launch: a repeated launcher flag keeps the last value; `--` after the flags forces the rest to be the prompt' => function () {
        check(Launch::route(['pikkumies', '-m', 'A', '--model', 'B', 'hi']) === ['run', ['chat', '-m', 'B', '--query=hi']], 'last -m wins');
        check(Launch::route(['pikkumies', '--reasoning', 'low', '--reasoning=high']) === ['run', ['chat', '--reasoning', 'high']], 'last --reasoning wins');
        check(Launch::route(['pikkumies', '-m', 'X', '--', '-m', 'means', 'what']) === ['run', ['chat', '-m', 'X', '--query=-m means what']], '-- after the flags must force a prompt');
        check(Launch::route(['pikkumies', '-m', 'X', '--']) === ['run', ['chat', '-m', 'X']], '-- with nothing after it: a plain session with the model');
    },
    'launch: after leading flags a subcommand name is prompt text; other leading flags and `--` first behave as before (ADR-0022)' => function () {
        foreach (['update', 'install', 'configure', 'tmux', 'run', 'doctor'] as $w) {
            check(Launch::route(['pikkumies', '-m', 'X', $w]) === ['run', ['chat', '-m', 'X', '--query=' . $w]], "$w after -m must stay a prompt");
        }
        check(Launch::route(['pikkumies', '-v', 'hello']) === ['run', ['chat', '--query=-v hello']], '-v is not a launcher flag: unchanged prompt');
        check(Launch::route(['pikkumies', '-c', 'fix', 'it']) === ['run', ['chat', '--query=-c fix it']], '-c is not a launcher flag');
        check(Launch::route(['pikkumies', '--', '-m', 'X']) === ['run', ['chat', '--query=-m X']], '-- first still forces the prompt "-m X"');
        check(Launch::route(['pikkumies', 'hi', '-m', 'X']) === ['run', ['chat', '--query=hi -m X']], 'flags only count at the start');
    },
    'launch: RunFlags::model reads the -m of a chat run, the last one; other runs have none' => function () {
        check(RunFlags::model(['chat', '-m', 'A', '--query=x']) === 'A', '-m');
        check(RunFlags::model(['chat', '--model=B', '-m', 'C']) === 'C', 'last one wins');
        check(RunFlags::model(['chat', '--query=-m X']) === '', 'a prompt is never a flag');
        check(RunFlags::model(['sessions', '-m', 'A']) === '' && RunFlags::model([]) === '', 'not a chat run');
    },
    'launch: a leading --reasoning with its value space-separated, and no -m, still seeds the chat with free text (ADR-0052)' => function () {
        check(Launch::route(['pikkumies', '--reasoning', 'low', 'fix', 'it']) === ['run', ['chat', '--reasoning', 'low', '--query=fix it']],
            '--reasoning low + text, no -m: ' . json_encode(Launch::route(['pikkumies', '--reasoning', 'low', 'fix', 'it'])));
    },
    "launch: 'run chat --query-file -' and its --resume form pass through unchanged (the remote skill's calls, ADR-0051)" => function () {
        // integrations/remote/claude/SKILL.md sends the question on stdin this way; servers on older releases must read it the same.
        check(Launch::route(['pikkumies', 'run', 'chat', '--query-file', '-']) === ['run', ['chat', '--query-file', '-']], 'ask: ' . json_encode(Launch::route(['pikkumies', 'run', 'chat', '--query-file', '-'])));
        check(Launch::route(['pikkumies', 'run', 'chat', '--resume', 'S1', '--query-file', '-']) === ['run', ['chat', '--resume', 'S1', '--query-file', '-']], 'follow-up');
        check(NestedAgent::refusal(['chat', '--resume', 'S1', '--query-file', '-'], '1') === '', 'inside an agent these words are not a safety-off flag');
        $skill = (string) file_get_contents(dirname(__DIR__) . '/integrations/remote/claude/SKILL.md');
        check(preg_match_all("#~/pikkumies/bin/pikkumies ([^'\n]*)'#", $skill, $m) === 2 && strpos($skill, 'q=$(cat)') === false, 'the skill must make exactly these two calls, the question never in argv: ' . json_encode($m[1]));
        check(str_replace('"$id"', 'S1', $m[1]) === ['run chat --query-file -', 'run chat --resume S1 --query-file -'], 'the skill\'s calls: ' . json_encode($m[1]));
    },
    'launch: `browse up|down|status` routes to the browse unit; any other words after browse are a prompt (ADR-0077)' => function () {
        foreach (['up', 'down', 'status'] as $verb) {
            check(Launch::route(['pikkumies', 'browse', $verb]) === ['browse', [$verb]], $verb);
        }
        check(Launch::route(['pikkumies', 'browse', 'the', 'web', 'for', 'cats']) === ['run', ['chat', '--query=browse the web for cats']], 'a prompt');
        check(Launch::route(['pikkumies', 'browse']) === ['run', ['chat', '--query=browse']], 'browse alone is a prompt too');
    },
    'launch: --yolo is the default run mode for the person\'s own session, never added for an agent started by an agent' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'run':(.*?)\n    case '/s", $bin, $m) === 1, 'could not isolate the run case');
        $run = $m[1];
        // The check sits after the depth export, so it reads the exported depth: 1 means the launcher ran in the person's own shell.
        check(preg_match("/putenv\('PIKKUMIES_DEPTH=' \. NestedAgent::childDepth\(\)\);[^\n]*\n(\s*\/\/[^\n]*\n)*\s*if \(NestedAgent::depth\(\) === 1\) \{[^\n]*\n\s*putenv\('HERMES_YOLO_MODE=1'\);\s*\}/", $run) === 1,
            'HERMES_YOLO_MODE=1 must follow the depth export and apply only when the exported depth is 1');
        foreach (['' => true, '0' => true, '1' => false, '2' => false] as $raw => $own) {
            check((NestedAgent::childDepth((string) $raw) === 1) === $own, "launcher at PIKKUMIES_DEPTH='$raw': exported depth 1 means the person's own shell");
        }
        $yolo = strpos($run, "putenv('HERMES_YOLO_MODE=1')");
        $pass = strpos($run, '@passthru(escapeshellarg(hermesBin())');
        $halt = strpos($run, 'exit(HaltRun::run(hermesBin(), $passArgs));');
        check($yolo !== false && $pass !== false && $halt !== false && $yolo < $pass && $yolo < $halt, 'HERMES_YOLO_MODE must be set before Hermes starts, on both the plain and the one-shot path');
        check(strpos($run, "putenv('HERMES_YOLO_MODE=1')") === strrpos($run, "putenv('HERMES_YOLO_MODE=1')"), 'set in one place only');
        check(NestedAgent::approvalsOff('1') && !NestedAgent::approvalsOff(''), 'the flag Hermes reads is the one the launcher sets');
    },
];
