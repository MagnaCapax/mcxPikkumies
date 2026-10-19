<?php
/**
 * lib/SessionCounters.php (ADR-0061): which pane ids are accepted, the counters written only for a pane an interactive
 * start zeroed, adds and sets under a lock with nothing lost between parallel writers, private files, the week-old
 * prune, and the rendered status segment.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsFormat.php';

return [
    'session counters: only the pane ids tmux gives name a file; anything else is refused' => function () {
        check(SessionCounters::file('/l', '%3') === '/l/state/session-counters/pane3.json' && SessionCounters::file('/l', '%123456') !== null, 'tmux pane ids must be accepted');
        foreach (["%3\n", '3', '%', '%1234567', '%3a', '%../x', '', '%3/'] as $bad) {
            check(SessionCounters::file('/l', $bad) === null, json_encode($bad) . ' must be refused');
        }
    },
    'session counters: nothing is written for a pane no interactive start zeroed; after reset, adds and sets land; files are private' => function () {
        $dir = testTempDir('pk-counters');
        try {
            check(SessionCounters::update($dir, '%4', ['tools' => 1]) === false && !is_dir(SessionCounters::dir($dir)), 'a pane without a reset must get no file');
            check(SessionCounters::reset($dir, '%4', 1000) === true, 'reset');
            check(SessionCounters::update($dir, '%5', ['tools' => 1]) === false && !is_file(SessionCounters::file($dir, '%5')), 'another pane in the same directory must still get no file');
            check(SessionCounters::read($dir, '%4') === ['since' => 1000, 'tools' => 0, 'added' => 0, 'removed' => 0, 'ctx' => 0, 'ctxMax' => 0], 'zeroed: ' . json_encode(SessionCounters::read($dir, '%4')));
            SessionCounters::update($dir, '%4', ['tools' => 1, 'added' => 5, 'removed' => 2]);
            SessionCounters::update($dir, '%4', ['tools' => 1, 'added' => 1, 'nonsense' => 9], ['ctx' => 48211, 'ctxMax' => 1000000]);
            SessionCounters::update($dir, '%4', [], ['ctx' => 50000]);
            check(SessionCounters::read($dir, '%4') === ['since' => 1000, 'tools' => 2, 'added' => 6, 'removed' => 2, 'ctx' => 50000, 'ctxMax' => 1000000], 'adds add, sets replace, unknown keys are dropped: ' . json_encode(SessionCounters::read($dir, '%4')));
            check((fileperms(SessionCounters::dir($dir)) & 0777) === 0700 && (fileperms(SessionCounters::file($dir, '%4')) & 0777) === 0600, 'directory 0700, file 0600');
            file_put_contents(SessionCounters::file($dir, '%4'), '{"tools":"x","added":[1],"ctx":7}');
            check(SessionCounters::read($dir, '%4') === ['since' => 0, 'tools' => 0, 'added' => 0, 'removed' => 0, 'ctx' => 7, 'ctxMax' => 0], 'a damaged file reads as integers: ' . json_encode(SessionCounters::read($dir, '%4')));
            check(SessionCounters::read($dir, '%9') === null && SessionCounters::read($dir, 'x') === null, 'no file, no counters');
        } finally {
            testRmTree($dir);
        }
    },
    'session counters: 40 writers at once lose no tool call' => function () {
        $dir = testTempDir('pk-counters');
        try {
            SessionCounters::reset($dir, '%1');
            $script = "$dir/bump.php";
            file_put_contents($script, "<?php\nrequire " . var_export(dirname(__DIR__) . '/pikkumies/lib/SessionCounters.php', true) . ";\n"
                . 'SessionCounters::update(' . var_export($dir, true) . ", '%1', ['tools' => 1, 'added' => 2]);\n");
            $procs = [];
            for ($i = 0; $i < 40; $i++) {
                $procs[] = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [], $pipes);
            }
            foreach ($procs as $p) {
                proc_close($p);
            }
            $c = SessionCounters::read($dir, '%1');
            check($c['tools'] === 40 && $c['added'] === 80, 'parallel updates were lost: ' . json_encode($c));
        } finally {
            testRmTree($dir);
        }
    },
    'session counters: reset zeroes its own pane and removes only other panes untouched for over a week' => function () {
        $dir = testTempDir('pk-counters');
        try {
            foreach (['%1', '%2', '%3'] as $p) {
                SessionCounters::reset($dir, $p);
                SessionCounters::update($dir, $p, ['tools' => 3]);
            }
            touch(SessionCounters::file($dir, '%2'), time() - 8 * 86400);
            touch(SessionCounters::file($dir, '%3'), time() - 6 * 86400);
            touch(SessionCounters::file($dir, '%1'), time() - 30 * 86400);
            SessionCounters::reset($dir, '%1');
            check(SessionCounters::read($dir, '%1')['tools'] === 0, 'own pane zeroed and kept, however old');
            check(!is_file(SessionCounters::file($dir, '%2')), 'a pane untouched for 8 days is removed');
            check(SessionCounters::read($dir, '%3')['tools'] === 3, 'a pane touched 6 days ago is kept');
            check(SessionCounters::reset($dir, 'nope') === false, 'an invalid pane resets nothing');
        } finally {
            testRmTree($dir);
        }
    },
    'session counters: the status segment, memories today by file name, Ctx rounded down, ASCII separator off UTF-8' => function () {
        $short = ['CreditsFormat', 'short'];
        check(SessionCounters::memories(['/m/20261019-a.md', '/m/lessons/x/20261019-b.md', '/m/20261018-c.md', '/m/notes.md'], '20261019') === [4, 2], 'two of four saved today');
        check(SessionCounters::render(42, 0, null, true, $short) === 'Memories: 42', 'memories only: ' . SessionCounters::render(42, 0, null, true, $short));
        $c = ['since' => 1, 'tools' => 17, 'added' => 120, 'removed' => 8, 'ctx' => 48211, 'ctxMax' => 1000000];
        check(SessionCounters::render(42, 3, $c, true, $short) === 'Memories: 42 +3 today · Ctx: 48.2k/1M 4% · Tools: 17 · Code: +120 -8', 'full: ' . SessionCounters::render(42, 3, $c, true, $short));
        check(SessionCounters::render(42, 3, ['ctx' => 199999, 'ctxMax' => 200000] + $c, false, $short) === 'Memories: 42 +3 today | Ctx: 199.9k/200k 99% | Tools: 17 | Code: +120 -8',
            'rounded down, never more than used; ASCII: ' . SessionCounters::render(42, 3, ['ctx' => 199999, 'ctxMax' => 200000] + $c, false, $short));
        check(SessionCounters::render(1, 0, ['ctx' => 950, 'ctxMax' => 0] + $c, true, $short) === 'Memories: 1 · Ctx: 950 · Tools: 17 · Code: +120 -8', 'no window known: used only');
        check(SessionCounters::render(1, 0, ['ctx' => 0] + $c, true, $short) === 'Memories: 1 · Tools: 17 · Code: +120 -8', 'no request yet: no Ctx');
    },
];
