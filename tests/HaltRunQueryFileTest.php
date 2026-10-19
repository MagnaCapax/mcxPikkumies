<?php
/**
 * lib/HaltRun.php with a prompt from a file or stdin (`--query-file`, ADR-0052 amendment 2026-10-19), against the fake
 * hermes of tests/haltRunSupport.php: the first run hands Hermes the file or stdin, a resume never repeats the file (its
 * own --query is the judge's sentence, and Hermes refuses both together), and a Hermes that cannot be started at all
 * prints one line naming the prompt file as the way around an oversized command line.
 */
require_once __DIR__ . '/haltRunSupport.php';

list($continueReply, $doneReply, $chatReply) = haltRunReplies();

/** Runs $body (PHP code using $tree) with a fake model answering $replies and a fake hermes following $plan. */
function haltRunQueryFileCase(array $replies, array $plan, callable $body)
{
    $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
    list($proc, $port) = haltRunFakeModel($fakeModel);
    $tree = haltRunTree($port);
    try {
        file_put_contents("$fakeModel/replies.json", json_encode($replies));
        putenv('PK_FAKE_HERMES_DIR=' . $tree);
        file_put_contents("$tree/plan.json", json_encode($plan));
        $body($tree, haltRunFakeHermes($tree));
    } finally {
        putenv('PK_FAKE_HERMES_DIR');
        proc_terminate($proc, 9);
        proc_close($proc);
        testRmTree($tree);
        testRmTree($fakeModel);
    }
}

/** The calls the fake hermes logged, decoded. */
function haltRunQueryFileCalls($tree)
{
    return array_values(array_filter(array_map(function ($l) {
        return json_decode($l, true);
    }, explode("\n", (string) @file_get_contents("$tree/calls.jsonl")))));
}

/** Runs PHP code in a subprocess with stderr merged into stdout: [exit code, output]. */
function haltRunQueryFileStderr($tree, $code)
{
    $script = "$tree/start-" . mt_rand() . '.php';
    file_put_contents($script, "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n" . $code);
    $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), $out];
}

return [
    'HaltRun::run: a --query-file first run judged "continue" is resumed with --query and without the file' => function () use ($continueReply, $chatReply) {
        haltRunQueryFileCase([$continueReply, $chatReply], [['output' => 'partial', 'code' => 0, 'session_id' => 'fakesess']], function ($tree, $hermes) {
            file_put_contents("$tree/prompt.txt", 'please fix the thing');
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess', false, ['chat', '--query-file=' . "$tree/prompt.txt", '--format=stream-json']);
            $calls = haltRunQueryFileCalls($tree);
            check($code === 0 && count($calls) === 2, 'expected the first run and one resume: ' . $code . ' ' . json_encode($calls) . ' ' . $out);
            check($calls[0]['query_file_text'] === 'please fix the thing', 'the first run must hand Hermes the file: ' . json_encode($calls[0]));
            check(in_array('--resume=fakesess', $calls[1]['argv'], true) && $calls[1]['query_file_text'] === null && preg_grep('/^--query=/', $calls[1]['argv']) && in_array('--format=stream-json', $calls[1]['argv'], true),
                'the resume must carry --query (and the caller\'s format), never the file: ' . json_encode($calls[1]['argv']));
        });
    },
    'HaltRun::run: --query-file=- hands Hermes the piped stdin; an empty one stops with Hermes\' own exit 2' => function () use ($chatReply) {
        haltRunQueryFileCase([$chatReply], [['output' => 'answer', 'code' => 0, 'session_id' => 'fakesess']], function ($tree, $hermes) {
            list($code, $out) = haltRunDrive($tree, $hermes, 'fakesess', false, ['chat', '--query-file=-'], "a prompt that came on stdin\n");
            $calls = haltRunQueryFileCalls($tree);
            check($code === 0 && isset($calls[0]) && $calls[0]['query_file_text'] === "a prompt that came on stdin\n", 'stdin must reach Hermes: ' . json_encode($calls) . ' ' . $out);
            list($code) = haltRunDrive($tree, $hermes, 'fakesess2', false, ['chat', '--query-file=-'], '');
            check($code === 2, 'an empty stdin prompt must stop with exit 2, as Hermes does: ' . $code);
        });
    },
    'HaltRun::run: a Hermes that cannot be started prints the could-not-start line; one that starts and fails does not' => function () use ($chatReply) {
        haltRunQueryFileCase([$chatReply], [['output' => '', 'code' => 3, 'session_id' => '']], function ($tree, $hermes) {
            list($code, $out) = haltRunQueryFileStderr($tree, 'exit(HaltRun::run(' . var_export("$tree/no-such-hermes", true) . ", ['chat', '--query=hi']));");
            check($code === 127 && strpos($out, 'Could not start Hermes.') !== false && strpos($out, '--prompt-file') !== false, 'a missing hermes: ' . $code . ' ' . $out);
            list($code, $out) = haltRunQueryFileStderr($tree, 'exit(HaltRun::run(' . var_export($hermes, true) . ", ['chat', '--query=hi']));");
            check($code === 3 && strpos($out, 'Could not start Hermes') === false, 'a Hermes that ran and failed is not a start failure: ' . $code . ' ' . $out);
            exec('getconf PAGESIZE 2>/dev/null', $page);
            if (!isset($page[0]) || $page[0] !== '4096') {
                skip('the 128 KiB argument limit is 32 pages of 4 KiB; this kernel\'s page size is ' . json_encode($page));
            }
            list($code, $out) = haltRunQueryFileStderr($tree, 'exit(HaltRun::run(' . var_export($hermes, true) . ", ['chat', '--query=' . str_repeat('a', 140000)]));");
            check($code !== 0 && strpos($out, 'Could not start Hermes.') !== false && haltRunCalls($tree) === 1, 'a 140 000-byte prompt word must not start Hermes and must say so: ' . $code . ' ' . substr($out, 0, 300));
        });
    },
    'bin/pikkumies: the interactive passthru prints the same line and exits 1 when sh itself could not start (-1)' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match('/@passthru\(escapeshellarg\(hermesBin\(\)\)[^\n]*\$code\);\n\s*if \(\$code === -1\) \{\n\s*fwrite\(STDERR, HaltRun::START_FAILED\);\n\s*exit\(1\);/', $bin) === 1, 'the passthru start-failure branch is missing');
    },
];
