<?php
/**
 * The launcher's --prompt-file / --query-file and --resume flags (lib/RunFlags.php, ADR-0052 amendment 2026-10-19): the
 * forms and spellings, the path made absolute, the errors that start nothing, the order and last-wins rules, and the
 * prompts that must stay prompts. The HaltRun side (Hermes reading the file or stdin) is tests/HaltRunQueryFileTest.php.
 */
return [
    'launch: --prompt-file and --query-file, with a space or "=", become one --query-file=<absolute path>; "-" stays stdin' => function () {
        $dir = sys_get_temp_dir() . '/pk-promptfile-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        $file = realpath($dir) . '/p.txt';
        file_put_contents($file, 'check my disk');
        try {
            foreach ([['--prompt-file', $file], ['--prompt-file=' . $file], ['--query-file', $file], ['--query-file=' . $file]] as $w) {
                check(Launch::route(array_merge(['pikkumies'], $w)) === ['run', ['chat', '--query-file=' . $file]], json_encode($w) . ': ' . json_encode(Launch::route(array_merge(['pikkumies'], $w))));
            }
            check(Launch::route(['pikkumies', '--prompt-file', '-']) === ['run', ['chat', '--query-file=-']], '"-" reads stdin');
            check(Launch::route(['pikkumies', '--query-file=-']) === ['run', ['chat', '--query-file=-']], '"=-" reads stdin');
            check(Launch::route(['pikkumies', '--prompt-file', $file, '--']) === ['run', ['chat', '--query-file=' . $file]], 'a -- with nothing after it is fine');
            $cwd = getcwd();
            chdir($dir);
            try { // relative to where pikkumies was started: Hermes may change directory before it reads the file
                check(Launch::route(['pikkumies', '--prompt-file', 'p.txt']) === ['run', ['chat', '--query-file=' . $file]], 'a relative path must become absolute: ' . json_encode(Launch::route(['pikkumies', '--prompt-file', 'p.txt'])));
            } finally {
                chdir($cwd);
            }
            symlink($file, "$dir/link.txt"); // an absolute path is kept as given: a /dev/fd/N path from <(...) has no realpath
            check(Launch::route(['pikkumies', '--prompt-file', "$dir/link.txt"]) === ['run', ['chat', '--query-file=' . "$dir/link.txt"]], 'an absolute path must be passed as given');
            check(Launch::isSession(Launch::route(['pikkumies', '--prompt-file', $file])[1]), 'a prompt-file run is a session: every first-start check applies');
        } finally {
            testRmTree($dir);
        }
    },
    'launch: --resume ID and --resume=ID; the order is -m, --reasoning, --resume, --query-file; the last value of each wins' => function () {
        $file = tempnam(sys_get_temp_dir(), 'pk-pf');
        $other = tempnam(sys_get_temp_dir(), 'pk-pf');
        try {
            check(Launch::route(['pikkumies', '--resume', 'S1']) === ['run', ['chat', '--resume', 'S1']], '--resume alone: an interactive resumed session');
            check(Launch::route(['pikkumies', '--resume=S1', 'what', 'next']) === ['run', ['chat', '--resume', 'S1', '--query=what next']], '--resume= with text');
            check(Launch::route(['pikkumies', '--prompt-file', $file, '--resume', 'S1', '-m', 'X', '--reasoning=low']) === ['run', ['chat', '-m', 'X', '--reasoning', 'low', '--resume', 'S1', '--query-file=' . $file]],
                'fixed order: ' . json_encode(Launch::route(['pikkumies', '--prompt-file', $file, '--resume', 'S1', '-m', 'X', '--reasoning=low'])));
            check(Launch::route(['pikkumies', '--resume', 'a', '--resume=b']) === ['run', ['chat', '--resume', 'b']], 'last --resume wins');
            check(Launch::route(['pikkumies', '--prompt-file', $file, '--query-file', $other]) === ['run', ['chat', '--query-file=' . $other]], 'last prompt file wins, either spelling');
            check(NestedAgent::refusal(['chat', '-m', 'X', '--resume', 'S1', '--query-file=' . $file], '1') === '', 'inside an agent the launcher\'s own output is never a safety-off flag');
        } finally {
            @unlink($file);
            @unlink($other);
        }
    },
    'launch: a missing prompt file, words after one, or a flag without its value is an error that starts nothing (exit 2)' => function () {
        $file = tempnam(sys_get_temp_dir(), 'pk-pf');
        $missing = sys_get_temp_dir() . '/pk-no-such-prompt-' . getmypid() . '-' . mt_rand();
        try {
            $cases = [[['--prompt-file', $missing], 'no such file'], [['--query-file=' . $missing], 'no such file'], [['--prompt-file', $file, 'and', 'words'], 'not both'],
                [['--prompt-file', $file, '--', 'words'], 'not both'], [['--prompt-file'], 'needs a file'], [['--prompt-file='], 'needs a file'], [['--prompt-file', '--resume', 'S1'], 'needs a file'],
                [['--prompt-file', '-x'], 'needs a file'], [['--resume'], 'needs a session id'], [['--resume', '-v'], 'needs a session id'], [['--resume='], 'needs a session id']];
            foreach ($cases as $c) {
                list($cmd, $args) = Launch::route(array_merge(['pikkumies'], $c[0]));
                check($cmd === 'error' && count($args) === 1 && strpos($args[0], $c[1]) !== false, json_encode($c[0]) . " must be an error with '$c[1]': " . json_encode([$cmd, $args]));
            }
            list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/bin/pikkumies', ['--prompt-file', $missing]);
            check($code === 2 && strpos($out, 'Not starting: --prompt-file: no such file: ' . $missing) === 0, 'a missing prompt file must stop with exit 2: ' . $code . ' ' . $out);
        } finally {
            @unlink($file);
        }
    },
    'launch: the new flags count only at the start; after a prompt word, after a leading --, or as a value they stay text' => function () {
        check(Launch::route(['pikkumies', '--', '--prompt-file', 'x']) === ['run', ['chat', '--query=--prompt-file x']], '-- first forces the prompt');
        check(Launch::route(['pikkumies', 'hi', '--prompt-file', 'x']) === ['run', ['chat', '--query=hi --prompt-file x']], 'flags only count at the start');
        check(Launch::route(['pikkumies', '--resume', 'S1', '--yolo']) === ['run', ['chat', '--resume', 'S1', '--query=--yolo']], '--yolo after the flags is prompt text, never a flag');
        check(Launch::route(['pikkumies', '-v', 'x']) === ['run', ['chat', '--query=-v x']], '-v is unchanged');
        check(Launch::route(['pikkumies', '--resumes', 'x']) === ['run', ['chat', '--query=--resumes x']], 'no abbreviations or look-alikes');
    },
];
