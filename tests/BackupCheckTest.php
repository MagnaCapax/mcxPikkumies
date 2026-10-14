<?php
/**
 * tools/backup check, restore and paths (review 2026-10-14 findings 4, 8, 10, 11, 13). The fakes print what restic
 * and borg print for each case (cmd_check.go:273/374 at restic 0.11.0/0.14.0; borg archiver.py:343-351 and
 * return-codes.rst.inc), so the oracle is the program's own verdict, never the unit's exit-code arithmetic.
 */
require_once __DIR__ . '/fixtures/backup/harness.php';

function bcCheck($tool, array $state, array $args = ['check'])
{
    $fx = bkFixture($tool, $state);
    try {
        list($code, $l, $out) = bkRun($fx, $tool, $args);
        return [$code, $code === 0 ? $l[0] : end($l), $out, stCalls($fx, $tool), stCalls($fx, 'nice')]; // the record, or the error line
    } finally {
        stDone($fx);
    }
}

return [
    'backup check: errors_found only when restic or borg reports damage; a lock or a lost connection is a check that did not finish' => function () {
        $lock = 'Fatal: unable to create lock in backend: repository is already locked by PID 7 on box by u (UID 1000, GID 1000)';
        list($code, $st, $out) = bcCheck('restic', ['check_code' => 1, 'check_err' => $lock]);
        check($code === 0 && $st['errors_found'] === false && $st['finished'] === false && strpos($st['note'],
            'nothing about damage') !== false, 'restic lock: ' . $out);
        list($code, $st, $out) = bcCheck('restic', ['check_code' => 1,
            'check_err' => "error: pack 1a2b3c: does not match\nFatal: repository contains errors"]);
        check($code === 0 && $st['errors_found'] === true && $st['finished'] === true, 'restic damage: ' . $out);
        list($code, $st, $out) = bcCheck('borg', ['check_code' => 2,
            'check_err' => 'Failed to create/acquire the lock /repo/lock.exclusive (timeout).']);
        check($code === 0 && $st['errors_found'] === false && $st['finished'] === false, 'borg lock: ' . $out);
        list($code, $st, $out) = bcCheck('borg', ['check_code' => 1, 'check_err' => 'Index object count mismatch.']);
        check($code === 0 && $st['errors_found'] === true && $st['finished'] === true, 'borg damage: ' . $out);
        list($code, $st) = bcCheck('restic', []);
        check($code === 0 && $st['errors_found'] === false && $st['finished'] === true, 'restic clean: ' . json_encode($st));
    },
    'restic 0.11 check --read-data-subset 5%: a version error before restic runs, never a damaged repository; n/m still runs' => function () {
        list($code, $st, $out, $calls) = bcCheck('restic', ['version' => '0.11.0'], ['check', '--read-data-subset', '5%']);
        check($code === 1 && strpos($st['error'], '0.12.0') !== false && !in_array('check --read-data-subset 5%', $calls, true),
            '0.11 percent: ' . $out);
        list($code, $st, $out, $calls) = bcCheck('restic', ['version' => '0.11.0'], ['check', '--read-data-subset', '1/20']);
        check($code === 0 && in_array('check --read-data-subset 1/20', $calls, true), '0.11 n/m: ' . $out);
    },
    'backup check and restore run at nice 19 and idle I/O (restic, borg)' => function () {
        foreach (['restic' => 'cccccccc', 'borg' => 'box-3'] as $tool => $id) {
            $fx = bkFixture($tool);
            try {
                bkRun($fx, $tool, ['check']);
                list($code, , $out) = bkRun($fx, $tool, ['restore', $id, '--target', $fx['home'] . '/r1']);
                $nice = implode("\n", stCalls($fx, 'nice'));
                check($code === 0 && strpos($nice, "-n 19 ionice -c3 $tool check") !== false
                    && strpos($nice, "-n 19 ionice -c3 $tool " . ($tool === 'borg' ? 'extract' : 'restore')) !== false, "$tool: "
                    . $nice . $out);
            } finally {
                stDone($fx);
            }
        }
    },
    'restic: a RESTIC_REPOSITORY_FILE in the user\'s environment does not break every verb' => function () {
        $fx = bkFixture('restic');
        try {
            list($code, $l, $out) = bkRun($fx, 'restic', ['list'], ['RESTIC_REPOSITORY_FILE' => $fx['dir'] . '/elsewhere']);
            check($code === 0 && count($l) === 4, 'list: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'borg: a path that would turn into an option (/--stdout) is refused before borg runs' => function () {
        $fx = bkFixture('borg');
        try {
            list($code) = bkRun($fx, 'borg', ['show', 'box-1', '--files', '/--stdout']);
            list($code2) = bkRun($fx, 'borg', ['restore', 'box-3', '--target', $fx['home'] . '/r2', '--include', '/--stdout']);
            check($code === 1 && $code2 === 1 && !array_intersect(['list --json-lines', 'extract ::box-3'], array_map(function ($c) {
                return implode(' ', array_slice(explode(' ', $c), 0, 2));
            }, stCalls($fx, 'borg'))) && !file_exists($fx['home'] . '/r2'), 'calls: ' . json_encode(stCalls($fx, 'borg')));
        } finally {
            stDone($fx);
        }
    },
    'restore: a symlinked target or parent is refused; an existing empty directory is used' => function () {
        $fx = bkFixture('restic');
        try {
            mkdir($fx['dir'] . '/outside');
            symlink($fx['dir'] . '/outside', $fx['home'] . '/link');
            mkdir($fx['home'] . '/empty');
            foreach ([$fx['home'] . '/link', $fx['home'] . '/link/new'] as $t) {
                list($code) = bkRun($fx, 'restic', ['restore', 'cccccccc', '--target', $t]);
                check($code === 1 && !in_array('restore', bkVerbs($fx, 'restic'), true), "restore ran into $t");
            }
            check(scandir($fx['dir'] . '/outside') === ['.', '..'], 'something was written outside the home');
            list($code, $l, $out) = bkRun($fx, 'restic', ['restore', 'cccccccc', '--target', $fx['home'] . '/empty']);
            check($code === 0 && is_file($fx['home'] . '/empty/home/u/data/restored.txt') && end($l)['verified'] === true,
                'empty dir: ' . $out);
        } finally {
            stDone($fx);
        }
    },
];
