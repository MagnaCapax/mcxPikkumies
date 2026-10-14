<?php
/** tools/backup/borg against a fake borg that runs BORG_PASSCOMMAND like borg and logs every argv (helpers in tests/fixtures/backup/harness.php). */
require_once __DIR__ . '/fixtures/backup/harness.php';

return [
    'borg: the secret only through BORG_PASSCOMMAND (a stray BORG_PASSPHRASE is removed); never in output or argv; the archive command line is dropped' => function () {
        $fx = bkFixture('borg');
        try {
            $all = '';
            foreach ([['stats'], ['list'], ['show', 'box-1'], ['show', 'box-1', '--files'], ['show', '--prune-plan', '--keep-daily',
                '7'], ['check'], ['backup', $fx['home'] . '/data'],
                ['restore', 'box-3', '--target', $fx['home'] . '/r1'], ['prune', '--keep-daily', '7', '--expect', '2']] as $args) {
                list($code, , $out) = bkRun($fx, 'borg', $args, ['BORG_PASSPHRASE' => 'not-this-one']);
                check($code === 0, implode(' ', $args) . ': ' . $out);
                $all .= $out;
            }
            check(strpos($all, BK_ORACLE) === false && strpos((string) file_get_contents($fx['dir'] . '/argv.log'), BK_ORACLE) === false,
                'the secret leaked');
            check(strpos($all, 'command_line') === false, 'the archive command line was printed');
            foreach (stLog($fx) as $c) {
                if ($c['bin'] === 'borg' && $c['argv'] !== ['--version']) {
                    check($c['env']['BORG_PASSPHRASE_set'] === false && $c['env']['BORG_REPO'] === 'sftp:me@box:repo'
                        && strpos($c['env']['BORG_PASSCOMMAND'], $fx['secrets'] . '/borg') !== false, 'env: ' . json_encode($c));
                }
            }
        } finally {
            stDone($fx);
        }
    },
    'borg prune: no delete without the matching --expect; deletes exactly the planned names, then compact on 1.2 and not on 1.1' => function () {
        $fx = bkFixture('borg');
        try {
            list($code, $l) = bkRun($fx, 'borg', ['show', '--prune-plan', '--keep-daily', '7']);
            check($code === 0 && $l[0]['id'] === 'box-1' && $l[0]['snapshot_id'] === str_repeat('a', 64) && end($l)['expect'] === 2
                && end($l)['keep'] === 1, 'plan: ' . json_encode($l));
            foreach ([['prune', '--keep-daily', '7'], ['prune', '--keep-daily', '7', '--expect', '3']] as $args) {
                stClearLog($fx);
                list($code) = bkRun($fx, 'borg', $args);
                check($code === 1 && !array_intersect(['delete', 'compact'], bkVerbs($fx, 'borg')),
                    'deleted without a matching --expect: ' . json_encode(stCalls($fx, 'borg')));
            }
            stClearLog($fx);
            list($code, $l, $out) = bkRun($fx, 'borg', ['prune', '--keep-daily', '7', '--expect', '2']);
            $calls = stCalls($fx, 'borg');
            check($code === 0 && in_array('prune --dry-run --list --keep-daily 7', $calls, true) && in_array('delete :: box-1 box-2',
                $calls, true) && in_array('compact', $calls, true), 'calls: ' . json_encode($calls));
            check(end($l)['compacted'] === true && end($l)['verified'] === true && count(stState($fx)['archives']) === 1, 'result: '
                . $out);
        } finally {
            stDone($fx);
        }
        $fx = bkFixture('borg', ['version' => '1.1.16']);
        try {
            list($code, $l, $out) = bkRun($fx, 'borg', ['prune', '--keep-daily', '7', '--expect', '2']);
            check($code === 0 && !in_array('compact', bkVerbs($fx, 'borg'), true) && end($l)['compacted'] === false
                && strpos(end($l)['note'], '1.2') !== false, '1.1.16: ' . $out);
            list(, $l) = bkRun($fx, 'borg', ['stats']);
            check($l[0]['compact_supported'] === false && $l[0]['version'] === '1.1.16', 'stats: ' . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
    'borg restore and check: extract runs inside the new directory only; check never repairs and has no sampled mode' => function () {
        $fx = bkFixture('borg');
        try {
            mkdir($fx['home'] . '/full');
            touch($fx['home'] . '/full/x');
            list($code) = bkRun($fx, 'borg', ['restore', 'box-3', '--target', $fx['home'] . '/full']);
            check($code === 1 && !in_array('extract', bkVerbs($fx, 'borg'), true), 'extracted into a non-empty directory');
            list($code, $l, $out) = bkRun($fx, 'borg', ['restore', 'box-3', '--target', $fx['home'] . '/new', '--include', '/home/u/data']);
            $ex = array_values(array_filter(stLog($fx), function ($c) {
                return $c['bin'] === 'borg' && $c['argv'][0] === 'extract';
            }));
            check($code === 0 && $ex[0]['argv'] === ['extract', '::box-3', 'home/u/data'] && $ex[0]['cwd'] === $fx['home'] . '/new'
                && is_file($fx['home'] . '/new/home/u/data/restored.txt'), 'restore: ' . $out);
            stClearLog($fx);
            bkRun($fx, 'borg', ['check', '--read-data']);
            list($code) = bkRun($fx, 'borg', ['check', '--read-data-subset', '5%']);
            check($code === 1 && stCalls($fx, 'borg') === ['check --verify-data'], 'check calls: ' . json_encode(stCalls($fx, 'borg')));
        } finally {
            stDone($fx);
        }
    },
];
