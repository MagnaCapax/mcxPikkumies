<?php
/**
 * drives, phase 4 (ADR-0094): `verify --fix-unreadable --with-data` after a pass that ended with read errors only
 * rewrites exactly the listed blocks, one `badblocks -wv -b <same size> <device> <block> <block>` each; a clean rewrite
 * is HOLD (a full pass again), a block that cannot be written is FAILED and BIN. It needs --with-data, and a preceding
 * read-errors pass.
 */
require_once __DIR__ . '/fixtures/drives/harness.php';
drRequireUnit();

/** A machine whose sda pass ended with read errors on blocks 1000 and 1001; $fix: how the rewrite goes. */
function drReadErrors($fix)
{
    $st = drState();
    $st['badblocks'] = ['mode' => 'read', 'sleep' => 0, 'fix' => $fix];
    $fx = drFixture($st);
    drRun($fx, ['verify', 'TSATA0001']);
    drWait($fx, 'TSATA0001');
    list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
    $r = drRecord($recs, 'TSATA0001');
    check($r['verdict'] === 'HOLD' && strpos($r['verdict_reason'], '--fix-unreadable') !== false, json_encode($r));
    return $fx;
}

return [
    'drives fix: the unreadable blocks of the last pass are rewritten one by one with the same block size, then HOLD for a full pass' => function () {
        $fx = drReadErrors('ok');
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001', '--fix-unreadable']);
        check($code === 1 && strpos($status['error'], 'writes over the unreadable blocks') !== false, 'needs --with-data: ' . json_encode($status));
        list($code, $recs, $status) = drRun($fx, ['verify', 'TSATA0001', '--fix-unreadable', '--with-data']);
        check($code === 0 && $recs[0]['mode'] === 'fix-unreadable' && $recs[0]['block_size'] === 4096 && $status['verified'] === true, json_encode([$recs, $status]));
        drWait($fx, 'TSATA0001');
        $calls = drCalls($fx, 'badblocks');
        check(array_slice($calls, 1) === ['-wv -b 4096 /dev/sda 1000 1000', '-wv -b 4096 /dev/sda 1001 1001'], json_encode($calls));
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        $r = drRecord($recs, 'TSATA0001');
        check($r['verdict'] === 'HOLD' && strpos($r['verdict_reason'], 'run a full verify pass again') !== false && $r['grade'] === 'undetermined', json_encode($r));
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001', '--fix-unreadable', '--with-data']);
        check($code === 1 && strpos($status['error'], 'read errors only') !== false, 'a rewrite follows only a read-errors pass: ' . json_encode($status));
        testRmTree($fx['dir']);
    },
    'drives fix: a block the drive cannot write makes the verdict FAILED and the grade BIN, with a lesson record' => function () {
        $fx = drReadErrors('fail');
        drRun($fx, ['verify', 'TSATA0001', '--fix-unreadable', '--with-data']);
        drWait($fx, 'TSATA0001');
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        $r = drRecord($recs, 'TSATA0001');
        check($r['verdict'] === 'FAILED' && $r['grade'] === 'BIN' && strpos($r['verdict_reason'], 'write-failure list') !== false, json_encode($r));
        check((bool) array_filter($recs, function ($x) {
            return isset($x['lesson']) && strpos($x['lesson'], 'verify FAILED') !== false;
        }), 'a lesson record for the verdict');
        testRmTree($fx['dir']);
    },
    'drives fix: without a completed read-errors pass there is nothing to rewrite' => function () {
        $fx = drFixture();
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001', '--fix-unreadable', '--with-data']);
        check($code === 1 && strpos($status['error'], 'read errors only') !== false, json_encode($status));
        check(drCalls($fx, 'badblocks') === [], 'nothing ran');
        testRmTree($fx['dir']);
    },
];
