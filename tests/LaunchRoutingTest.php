<?php
/**
 * lib/Launch.php routing edges not covered by LaunchTest.php (ADR-0022, ADR-0032): route()'s tmux
 * digit/force-prompt boundaries, the small pure helpers (allFlags/promptArgs/tmuxPlan) called
 * directly, and the Launch::SUBCOMMANDS <-> bin/pikkumies dispatch wiring contract. installFlags,
 * askName, askTerms, settingsNote and isTty belong to another builder's package and are not touched
 * here.
 */
return [
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
];
