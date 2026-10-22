<?php
/** The one-shot output switches --no-progress and --stats (lib/RunFlags.php, Launch::routeWithOptions, ADR-0109). */
return [
    'launch: --no-progress and --stats are read before the question in any order, never reach Hermes, and mark the question\'s options' => function () {
        $d = ['noProgress' => false, 'stats' => false];
        check(Launch::routeWithOptions(['pikkumies', 'how', 'much', 'disk']) === ['run', ['chat', '--query=how much disk'], $d], 'a plain question gets the default options');
        check(Launch::route(['pikkumies', 'how', 'much', 'disk']) === ['run', ['chat', '--query=how much disk']], 'route() keeps its two elements');
        check(Launch::routeWithOptions(['pikkumies', '--stats', 'q']) === ['run', ['chat', '--query=q'], ['noProgress' => false, 'stats' => true]], json_encode(Launch::routeWithOptions(['pikkumies', '--stats', 'q'])));
        $r = Launch::routeWithOptions(['pikkumies', '--no-progress', '-m', 'big', '--stats', 'q']);
        check($r === ['run', ['chat', '-m', 'big', '--query=q'], ['noProgress' => true, 'stats' => true]], json_encode($r));
        $r = Launch::routeWithOptions(['pikkumies', '--stats', '--prompt-file', '-']);
        check($r === ['run', ['chat', '--query-file=-'], ['noProgress' => false, 'stats' => true]], 'a prompt file is a question: ' . json_encode($r));
    },
    'launch: oneShotRender only for a prompt-path question without a terminal and without the caller\'s own --format' => function () {
        $o = ['noProgress' => false, 'stats' => false];
        check(Launch::oneShotRender(['chat', '--query=q'], $o, true, false) && Launch::oneShotRender(['chat', '--query=q'], $o, false, true), 'a pipe on either side');
        check(!Launch::oneShotRender(['chat', '--query=q'], $o, true, true), 'a terminal on both sides is the interactive session');
        check(!Launch::oneShotRender(['chat', '--query-file', '-'], null, false, false), 'run chat (no options) stays the passthrough');
        check(!Launch::oneShotRender(['chat', '--query=q', '--format', 'stream-json'], $o, false, false), 'a caller\'s own --format stays raw');
        check(!Launch::oneShotRender(['sessions', 'list'], $o, false, false), 'not a chat');
    },
    'launch: a switch after the question, or after --, is part of the question' => function () {
        check(Launch::routeWithOptions(['pikkumies', 'q', '--stats'])[1] === ['chat', '--query=q --stats'], 'trailing');
        check(Launch::routeWithOptions(['pikkumies', '--', '--stats'])[1] === ['chat', '--query=--stats'], 'after --');
    },
    'launch: --stats with no question is an error; --no-progress alone starts the session as -m alone does; run chat is never marked' => function () {
        $r = Launch::routeWithOptions(['pikkumies', '--stats']);
        check($r[0] === 'error' && strpos($r[1][0], '--stats needs a question') === 0, json_encode($r));
        check(Launch::routeWithOptions(['pikkumies', '--no-progress']) === ['run', ['chat'], null], json_encode(Launch::routeWithOptions(['pikkumies', '--no-progress'])));
        check(Launch::routeWithOptions(['pikkumies', 'run', 'chat', '--query-file', '-']) === ['run', ['chat', '--query-file', '-']], 'the run passthrough has no options');
        check(Launch::routeWithOptions(['pikkumies', '--resume', 'abc']) === ['run', ['chat', '--resume', 'abc'], null], 'a resume without a question is a session, not a one-shot');
        check(RunFlags::isFlag('--stats') && RunFlags::isFlag('--no-progress') && !RunFlags::isFlag('--progress') && !RunFlags::isFlag('--stats=1'), 'isFlag');
    },
];
