<?php
/** `remove` when the app fails part way: every item is reported and the call ends ok:false (tests/fixtures/arr/). */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'arr remove: a failure on the second item still reports the first, and the call ends ok:false' => function () {
        $fx = arrFixture('radarr', ['failDelete' => [2]]); // the fake answers 500 to DELETE movie/2
        $b = $fx['home'] . '/media/Film B';
        $c = $fx['home'] . '/media/Film C';
        try {
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '2', '--with-data', '--expect', '2.5KiB:2548']);
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
            list($code, $l, $out) = arrRun($fx, ['remove', '1', '2', '--with-data', '--expect', '2.5KiB:2548']);
            $last = $l[count($l) - 1];
            check($code === 1 && strpos($last['error'], '2 (Film C): not_sent') !== false && !arrCalls($fx, 'DELETE', 'movie/2'),
                '--with-data: ' . $out);
            check(is_dir($fx['home'] . '/media/Film B') && is_dir($fx['home'] . '/media/Film C'), 'a folder was deleted');
        } finally {
            arrDone($fx);
        }
    },
];
