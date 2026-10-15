<?php
/** lib/Launch.php: argv routing for bin/pikkumies (ADR-0022). */
return [
    'launch: no args is a plain interactive session, same as `run`' => function () {
        list($cmd, $args) = Launch::route(['pikkumies']);
        check($cmd === 'run' && $args === [], 'expected bare run: ' . json_encode([$cmd, $args]));
    },
    'launch: help and its aliases print usage, never a prompt' => function () {
        foreach (['help', '--help', '-h'] as $w) {
            list($cmd, $args) = Launch::route(['pikkumies', $w]);
            check($cmd === 'help' && $args === [], "$w misrouted: " . json_encode([$cmd, $args]));
        }
    },
    'launch: a known subcommand wins, and its own words pass through unchanged' => function () {
        foreach (['install', 'update', 'post-update', 'detect', 'doctor', 'generate'] as $w) {
            list($cmd, $args) = Launch::route(['pikkumies', $w]);
            check($cmd === $w && $args === [], "$w misrouted: " . json_encode([$cmd, $args]));
        }
        list($cmd, $args) = Launch::route(['pikkumies', 'run', 'sessions', 'list']);
        check($cmd === 'run' && $args === ['sessions', 'list'], 'explicit run passthrough changed: ' . json_encode($args));
        list($cmd, $args) = Launch::route(['pikkumies', 'run']);
        check($cmd === 'run' && $args === [], 'explicit run with no args changed: ' . json_encode($args));
    },
    'launch: free text becomes a seeded prompt, words rejoined with single spaces' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', 'check', 'my', 'stats', 'and', 'can', 'we', 'increase', 'performance']);
        check($cmd === 'run' && $args === ['chat', '--query=check my stats and can we increase performance'], 'free text misrouted: ' . json_encode([$cmd, $args]));
    },
    'launch: a prompt that starts with a Pikkumies subcommand word stays a prompt' => function () {
        // Natural requests ("update my plex", "install radarr please", "detect what is slow") must
        // never start a self-update or re-run the installer.
        foreach ([['update', 'my', 'plex'], ['install', 'radarr', 'please'], ['detect', 'what', 'is', 'slow'], ['doctor', 'my', 'jellyfin']] as $words) {
            list($cmd, $args) = Launch::route(array_merge(['pikkumies'], $words));
            check($cmd === 'run' && $args === ['chat', '--query=' . implode(' ', $words)], 'subcommand word swallowed a prompt: ' . json_encode([$cmd, $args]));
        }
        // Flags after a subcommand keep it a subcommand.
        list($cmd, $args) = Launch::route(['pikkumies', 'detect', '--refresh']);
        check($cmd === 'detect' && $args === ['--refresh'], 'subcommand with flags misrouted: ' . json_encode([$cmd, $args]));
        list($cmd, $args) = Launch::route(['pikkumies', '--', 'update', 'the', 'kernel']);
        check($cmd === 'run' && $args === ['chat', '--query=update the kernel'], '-- did not force a prompt: ' . json_encode([$cmd, $args]));
    },
    'launch: -- with nothing after it, or only blank words, is a plain session' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', '--']);
        check($cmd === 'run' && $args === [], '-- alone should be plain run: ' . json_encode($args));
        list($cmd, $args) = Launch::route(['pikkumies', '--', '', ' ']);
        check($cmd === 'run' && $args === [], '-- with only blank words should be plain run: ' . json_encode($args));
    },
    'launch: quoting is never re-split: a word with spaces/quotes/$( ) stays one argv entry, one prompt word' => function () {
        list(, $args) = Launch::route(['pikkumies', 'rm -rf /; echo "hi" $(whoami)']);
        check($args === ['chat', '--query=rm -rf /; echo "hi" $(whoami)'], 'a single argv word was split or evaluated: ' . json_encode($args));
    },
    'launch: a prompt that starts like a flag stays one --query token' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', '--', '-v', 'means', 'what']);
        check($cmd === 'run' && $args === ['chat', '--query=-v means what'], 'flag-like prompt split: ' . json_encode($args));
    },
];
