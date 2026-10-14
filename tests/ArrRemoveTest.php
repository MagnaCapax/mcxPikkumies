<?php
/** `remove` when the app fails part way: every item is reported and the call ends ok:false (tests/fixtures/arr/). */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'arr remove: a failure on the second item still reports the first, and the call ends ok:false' => function () {
        $fx = arrFixture('radarr', ['failDelete' => [2]]); // the fake answers 500 to DELETE movie/2
        $b = $fx['home'] . '/media/Film B';
        $c = $fx['home'] . '/media/Film C';
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '2', '--with-data', '--expect', '2.5KiB:2548:2219fe']);
            $last = $l[count($l) - 1];
            check($code === 1 && $last['ok'] === false, 'a half-done delete ended ok: ' . $out);
            check(strpos($last['error'], '1 (Film B): deleted') !== false && strpos($last['error'], '2 (Film C): failed') !== false
                && strpos($last['error'], 'HTTP 500') !== false && strpos($last['error'], 'freed 2048 bytes') !== false, 'per item: ' . $out);
            check(!is_dir($b) && is_dir($c) && count(arrCalls($fx, 'DELETE', 'movie/1')) === 1,
                'fixture: the first folder is gone, the second kept');
        } finally {
            arrDone($fx);
        }
    },
    'arr remove: after a failure nothing more is sent; the rest is not_sent (plain remove and --with-data)' => function () {
        $fx = arrFixture('radarr', ['failDelete' => [1]]);
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '2']);
            $last = $l[count($l) - 1];
            check($code === 1 && $last['ok'] === false && strpos($last['error'], '1 (Film B): failed') !== false
                && strpos($last['error'], '2 (Film C): not_sent') !== false, 'plain remove: ' . $out);
            check(!arrCalls($fx, 'DELETE', 'movie/2'), 'a DELETE was sent after the failure');
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '2', '--with-data', '--expect', '2.5KiB:2548:2219fe']);
            $last = $l[count($l) - 1];
            check($code === 1 && strpos($last['error'], '2 (Film C): not_sent') !== false && !arrCalls($fx, 'DELETE', 'movie/2'),
                '--with-data: ' . $out);
            check(is_dir($fx['home'] . '/media/Film B') && is_dir($fx['home'] . '/media/Film C'), 'a folder was deleted');
        } finally {
            arrDone($fx);
        }
    },
    'arr remove: --expect binds the previewed ids; an extra item with an empty folder (same bytes) is refused' => function () {
        $fx = arrFixture('radarr');
        $b = $fx['home'] . '/media/Film B';
        $d = $fx['home'] . '/media/Film D';
        mkdir($d); // 0 bytes: `remove 1 3` totals what `show 1` previewed
        $items = arrState($fx)['items'];
        $items[] = ['id' => 3, 'title' => 'Film D', 'path' => $d, 'tmdbId' => 606] + $items[0];
        arrSet($fx, ['items' => $items]);
        try {
            list($code, $l, $out) = arrRun($fx, ['show', '1', '--files']);
            $expect = $l[count($l) - 1]['expect'];
            check($code === 0 && $expect === '2.0KiB:2048:356a19', 'preview of 1: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '3', '--with-data', '--expect', $expect]);
            check($code === 1 && strpos($out, 'printed for other ids than 1 3') !== false && strpos($out, '2.0KiB:2048:1f02f9') !== false,
                'the preview of 1 deleted 1 and 3: ' . $out);
            check(is_dir($b) && is_dir($d) && !arrCalls($fx, 'DELETE', 'movie/1') && !arrCalls($fx, 'DELETE', 'movie/3'),
                'something was deleted');
            list($code, $l, $out) = arrRun($fx, ['show', '3', '1', '--files']);
            $expect = $l[count($l) - 1]['expect'];
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '3', '--with-data', '--expect', $expect]);
            check($code === 0 && $expect === '2.0KiB:2048:1f02f9' && !is_dir($b) && !is_dir($d),
                'the preview of 3 1 is the one for 1 3: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
];
