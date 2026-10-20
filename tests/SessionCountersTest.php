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
    'session counters run: only a 16-hex key names a run file; resetRun zeroes the four keys, private, and prunes week-old runs' => function () {
        check(SessionCounters::runFile('/l', '0123456789abcdef') === '/l/state/session-counters/run-0123456789abcdef.json', 'a launcher key must be accepted');
        foreach (["0123456789abcdef\n", '0123456789ABCDEF', '0123456789abcde', '0123456789abcdef0', '../../x', '', null, '%3'] as $bad) {
            check(SessionCounters::runFile('/l', $bad) === null, json_encode($bad) . ' must be refused');
        }
        $dir = testTempDir('pk-run');
        $a = 'aaaaaaaaaaaaaaaa';
        $b = 'bbbbbbbbbbbbbbbb';
        $c = 'cccccccccccccccc';
        try {
            check(SessionCounters::updateRun($dir, $a, ['tokens' => 5]) === false && SessionCounters::readRun($dir, $a) === null && !is_dir(SessionCounters::dir($dir)), 'a run no start zeroed gets no file');
            check(SessionCounters::resetRun($dir, $a, 1000) === true && SessionCounters::readRun($dir, $a) === ['since' => 1000, 'tokens' => 0, 'reqs' => 0, 'asked' => 0], 'zeroed: ' . json_encode(SessionCounters::readRun($dir, $a)));
            check(json_decode(file_get_contents(SessionCounters::runFile($dir, $a)), true) === ['since' => 1000, 'tokens' => 0, 'reqs' => 0, 'asked' => 0], 'the file holds exactly the four keys');
            check((fileperms(SessionCounters::dir($dir)) & 0777) === 0700 && (fileperms(SessionCounters::runFile($dir, $a)) & 0777) === 0600, 'directory 0700, file 0600');
            check(SessionCounters::resetRun($dir, 'nope') === false, 'an invalid key resets nothing');
            SessionCounters::resetRun($dir, $b);
            SessionCounters::resetRun($dir, $c);
            SessionCounters::reset($dir, '%1');
            touch(SessionCounters::runFile($dir, $b), time() - 8 * 86400);
            touch(SessionCounters::runFile($dir, $c), time() - 6 * 86400);
            touch(SessionCounters::file($dir, '%1'), time() - 8 * 86400);
            SessionCounters::resetRun($dir, $a);
            check(!is_file(SessionCounters::runFile($dir, $b)) && is_file(SessionCounters::runFile($dir, $c)), 'a run untouched for 8 days is removed, one touched 6 days ago kept');
            check(is_file(SessionCounters::file($dir, '%1')), 'a run reset leaves pane files to the pane reset');
        } finally {
            testRmTree($dir);
        }
    },
    'session counters run: adds add, sets replace, readers may add integer keys; anything else is dropped; a damaged file reads as integers' => function () {
        $dir = testTempDir('pk-run');
        $k = '0123456789abcdef';
        try {
            SessionCounters::resetRun($dir, $k, 500);
            check(SessionCounters::updateRun($dir, $k, ['tokens' => 1200, 'reqs' => 1]) === true, 'update');
            SessionCounters::updateRun($dir, $k, ['tokens' => 800, 'reqs' => 1, 'memory' => 1, 'bad-key' => 4, 'text' => 'abc', 'x' => [1]], ['lastReviewAt' => 900]);
            SessionCounters::updateRun($dir, $k, [], ['tokensAtLastReview' => 2000, 'lastReviewAt' => 950]);
            check(SessionCounters::readRun($dir, $k) === ['since' => 500, 'tokens' => 2000, 'reqs' => 2, 'asked' => 0, 'memory' => 1, 'lastReviewAt' => 950, 'tokensAtLastReview' => 2000],
                'counted: ' . json_encode(SessionCounters::readRun($dir, $k)));
            file_put_contents(SessionCounters::runFile($dir, $k), '{"tokens":"12","reqs":[1],"since":3.9,"memory":true,"note":"hi"}');
            check(SessionCounters::readRun($dir, $k) === ['since' => 3, 'tokens' => 12, 'reqs' => 0, 'asked' => 0], 'damaged: ' . json_encode(SessionCounters::readRun($dir, $k)));
        } finally {
            testRmTree($dir);
        }
    },
    'session counters run: 40 writers at once lose no token' => function () {
        $dir = testTempDir('pk-run');
        $k = 'feedfacecafebeef';
        try {
            SessionCounters::resetRun($dir, $k);
            $script = "$dir/bump.php";
            file_put_contents($script, "<?php\nrequire " . var_export(dirname(__DIR__) . '/pikkumies/lib/SessionCounters.php', true) . ";\n"
                . 'SessionCounters::updateRun(' . var_export($dir, true) . ", '$k', ['tokens' => 1000, 'reqs' => 1]);\n");
            $procs = [];
            for ($i = 0; $i < 40; $i++) {
                $procs[] = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), [], $pipes);
            }
            foreach ($procs as $p) {
                proc_close($p);
            }
            $c = SessionCounters::readRun($dir, $k);
            check($c['tokens'] === 40000 && $c['reqs'] === 40, 'parallel updates were lost: ' . json_encode($c));
        } finally {
            testRmTree($dir);
        }
    },
    'launcher: an interactive start makes one run key, exports it before Hermes starts and zeroes its run file' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $block = strpos($bin, 'if (Launch::isTty() && Launch::isSession($passArgs)) {');
        $key = strpos($bin, '$runKey = bin2hex(random_bytes(8));');
        $env = strpos($bin, "putenv('PIKKUMIES_RUN=' . \$runKey);");
        $reset = strpos($bin, 'SessionCounters::resetRun(Config::localDir(), $runKey);');
        $hermes = strpos($bin, '@passthru(escapeshellarg(hermesBin())');
        check($block !== false && $key > $block && $env > $key && $reset > $env && $hermes > $reset, 'key, export and reset must sit in the interactive start block, before Hermes starts');
        check(strlen(bin2hex(random_bytes(8))) === 16 && preg_match(SessionCounters::RUN_RE, bin2hex(random_bytes(8))) === 1, 'the key shape matches RUN_RE');
    },
    'session counters: a symlinked run or pane file is never written through; a reset replaces the link with a file' => function () {
        $dir = testTempDir('pk-runlink');
        $k = 'abababababababab';
        try {
            $victim = "$dir/config.php";
            file_put_contents($victim, "<?php return [];\n");
            SessionCounters::resetRun($dir, $k);
            unlink(SessionCounters::runFile($dir, $k));
            symlink($victim, SessionCounters::runFile($dir, $k));
            check(SessionCounters::updateRun($dir, $k, ['tokens' => 5]) === false && file_get_contents($victim) === "<?php return [];\n", 'updateRun wrote through a link');
            check(SessionCounters::resetRun($dir, $k) === true && file_get_contents($victim) === "<?php return [];\n" && !is_link(SessionCounters::runFile($dir, $k))
                && SessionCounters::readRun($dir, $k)['tokens'] === 0, 'resetRun wrote through a link');
            symlink($victim, SessionCounters::file($dir, '%5'));
            check(SessionCounters::update($dir, '%5', ['tools' => 1]) === false && file_get_contents($victim) === "<?php return [];\n", 'update wrote through a link');
            check((fileperms(SessionCounters::runFile($dir, $k)) & 0777) === 0600 && !glob(SessionCounters::dir($dir) . '/*.tmp-*'), 'private, no temporary left');
        } finally {
            testRmTree($dir);
        }
    },
];
