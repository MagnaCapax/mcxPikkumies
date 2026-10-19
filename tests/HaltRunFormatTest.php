<?php
/** lib/HaltRun.php keeps the caller's --format on its resumes (ADR-0047 amendment of 2026-10-19), against the fake hermes of haltRunSupport.php. */
require_once __DIR__ . '/haltRunSupport.php';

list($continueReply, $doneReply, $chatReply) = haltRunReplies();

return [
    'HaltRun::run: the caller\'s --format reaches every resume, so a stream-json caller can grade the continued turns (QA run 3)' => function () use ($continueReply, $doneReply, $chatReply) {
        foreach ([['--format', 'stream-json'], ['--format=stream-json'], ['--forma', 'stream-json']] as $format) {
            $fakeModel = sys_get_temp_dir() . '/pk-haltrun-model-' . getmypid() . '-' . mt_rand();
            list($proc, $port) = haltRunFakeModel($fakeModel);
            $tree = haltRunTree($port);
            try {
                file_put_contents("$fakeModel/replies.json", json_encode([$continueReply, $doneReply, $chatReply]));
                putenv('PK_FAKE_HERMES_DIR=' . $tree);
                file_put_contents("$tree/plan.json", json_encode([['output' => 'partial', 'code' => 0, 'session_id' => 'fakesess']]));
                $hermes = haltRunFakeHermes($tree);
                haltRunDrive($tree, $hermes, 'fakesess', false, array_merge(['chat', '--query=please fix the thing'], $format));
                $calls = array_values(array_filter(array_map(function ($l) {
                    return json_decode($l, true);
                }, explode("\n", file_get_contents("$tree/calls.jsonl")))));
                check(count($calls) === 3, 'expected the first call, one continue and one wrap-up: ' . count($calls));
                foreach ([1, 2] as $i) {
                    check(in_array('--resume=fakesess', $calls[$i]['argv'], true) && end($calls[$i]['argv']) === '--format=stream-json', "resume $i lost the caller's format " . json_encode($format) . ': ' . json_encode($calls[$i]['argv']));
                }
            } finally {
                putenv('PK_FAKE_HERMES_DIR');
                proc_terminate($proc, 9);
                proc_close($proc);
                testRmTree($tree);
                testRmTree($fakeModel);
            }
        }
    },
    'HaltRun::formatArgs: --format read as argparse reads it (two words, =, abbreviations), never another option\'s value' => function () {
        $cases = [
            [['chat', '--query=x', '--format', 'stream-json'], ['--format=stream-json']],
            [['chat', '--format=stream-json', '--query=x'], ['--format=stream-json']],
            [['chat', '--form', 'stream-json'], ['--format=stream-json']],
            [['chat', '--forma=stream-json'], ['--format=stream-json']],
            [['chat', '--f', 'text'], ['--format=text']],
            [['chat', '-q', '--format', '--format', 'stream-json'], ['--format=stream-json']], // argparse rejects this call; the skipped word is never the format
            [['chat', '-q', '--format'], []],
            [['chat', '--query=--format=x'], []],
            [['chat', '--query=x'], []],
            [['chat', '--formats', 'x'], []],
            [['chat', '--format=text', '--format', 'stream-json'], ['--format=stream-json']], // the last one wins
        ];
        foreach ($cases as $c) {
            check(HaltRun::formatArgs($c[0]) === $c[1], json_encode($c[0]) . ' -> ' . json_encode(HaltRun::formatArgs($c[0])));
        }
    },
];
