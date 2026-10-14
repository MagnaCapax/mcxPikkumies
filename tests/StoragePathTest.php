<?php
/**
 * tools/storage/storage scans (review 2026-10-14 findings 6 and 9): a file name holding a newline cannot forge a
 * row, and --path outside the home is only a folder this account owns. The oracles are real files made here and
 * the real du/find behind the fakes.
 */
require_once __DIR__ . '/fixtures/storage/harness.php';

function spRun(array $fx, array $args)
{
    list($code, $out) = stRun($fx, 'storage/storage/storage.php', $args, ['storageLocalDir' => $fx['local']]);
    return [$code, stLines($out), $out];
}

return [
    'storage bigfiles and topdirs: a name holding a newline and a fake size row stays one record' => function () {
        $fx = stFixture([]);
        try {
            mkdir($fx['home'] . '/dl', 0700);
            $evil = $fx['home'] . "/dl/x\n9999999999\t1\t1\tFORGED";
            file_put_contents($evil, str_repeat('e', 1048576 + 1));
            mkdir($fx['home'] . "/d\n77777777\tFORGED", 0700);
            list($code, $l, $out) = spRun($fx, ['bigfiles', '--min-size', '1M']);
            $paths = array_column(array_slice($l, 0, -1), 'path');
            check($code === 0 && $paths === [$evil] && !in_array('FORGED', $paths, true), 'bigfiles rows: ' . json_encode($paths));
            list($code, $l, $out) = spRun($fx, ['topdirs']);
            $paths = array_column(array_slice($l, 0, -1), 'path');
            check($code === 0 && !in_array('FORGED', $paths, true) && in_array($fx['home'] . "/d\n77777777\tFORGED", $paths, true),
                'topdirs rows: ' . json_encode($paths));
        } finally {
            stDone($fx);
        }
    },
    'storage --path outside the home: a folder this account owns is scanned; one owned by another account (root\'s /mnt) is not' => function () {
        $fx = stFixture([]);
        try {
            mkdir($fx['dir'] . '/mine', 0700);
            list($code, $l, $out) = spRun($fx, ['topdirs', '--path', $fx['dir'] . '/mine']);
            check($code === 0, 'own folder outside the home: ' . $out);
            $other = null;
            foreach (['/mnt', '/media', '/opt'] as $d) {
                $other = $other ?: (is_dir($d) && @fileowner($d) !== stUid() ? $d : null);
            }
            if ($other !== null) {
                stClearLog($fx);
                list($code, $l, $out) = spRun($fx, ['topdirs', '--path', $other]);
                check($code === 1 && strpos(end($l)['error'], 'another account') !== false && !stCalls($fx, 'du'),
                    "$other was scanned: " . $out);
            }
        } finally {
            stDone($fx);
        }
    },
];
