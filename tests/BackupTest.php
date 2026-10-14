<?php
/** tools/backup/restic (and the shared verbs in tools/backup/lib) against a fake restic that checks the secret file and logs every argv. */
require_once __DIR__ . '/fixtures/backup/harness.php';

return [
    'restic: the repository secret never reaches output or any argv; it goes only as RESTIC_PASSWORD_FILE, and a stray RESTIC_PASSWORD is removed' => function () {
        $fx = bkFixture();
        try {
            $all = '';
            foreach ([['stats'], ['list'], ['show', 'aaaaaaaa'], ['show', 'aaaaaaaa', '--files'], ['show', '--prune-plan',
                '--keep-daily', '7'], ['check'], ['backup', $fx['home'] . '/data'],
                ['restore', 'cccccccc', '--target', $fx['home'] . '/r1'], ['prune', '--keep-daily', '7', '--expect', '2'], ['login',
                    '--repo', 'x'], ['stats', '--human']] as $args) {
                list(, , $out) = bkRun($fx, 'restic', $args, ['RESTIC_PASSWORD' => 'not-this-one']);
                $all .= $out;
            }
            check(strpos($all, BK_ORACLE) === false, 'the secret reached the output');
            check(strpos((string) file_get_contents($fx['dir'] . '/argv.log'), BK_ORACLE) === false,
                'the secret reached an argv or the log');
            $n = 0;
            foreach (stLog($fx) as $c) {
                if ($c['bin'] === 'restic' && $c['argv'] !== ['version']) {
                    $n++;
                    check($c['env']['RESTIC_PASSWORD_FILE'] === $fx['secrets'] . '/restic'
                        && $c['env']['RESTIC_PASSWORD_set'] === false && $c['env']['RESTIC_REPOSITORY'] === 'sftp:me@box:repo', 'env: '
                        . json_encode($c));
                }
            }
            check($n >= 10, "too few restic calls checked: $n");
        } finally {
            stDone($fx);
        }
    },
    'restic login: refused without a terminal and stores nothing; a missing login names the step; a refused stored secret is reported' => function () {
        $fx = bkFixture();
        try {
            unlink($fx['secrets'] . '/restic');
            list($code, $l) = bkRun($fx, 'restic', ['login', '--repo', 'sftp:me@box:new']);
            check($code === 1 && strpos(end($l)['error'], 'terminal') !== false && !is_file($fx['secrets'] . '/restic') && !stCalls($fx,
                'restic'), 'login without a terminal: ' . json_encode($l));
            list($code, $l) = bkRun($fx, 'restic', ['list']);
            check($code === 1 && strpos(end($l)['hint'], 'login --repo') !== false, 'no login: ' . json_encode($l));
            file_put_contents($fx['secrets'] . '/restic', 'not-it');
            list($code, $l) = bkRun($fx, 'restic', ['list']);
            check($code === 1 && strpos(end($l)['error'], 'wrong password') !== false, 'refused secret: ' . json_encode($l));
            list($code, $l) = bkRun($fx, 'restic', ['stats']);
            check($code === 0 && $l[0]['reachable'] === false && $l[0]['version'] === '0.14.0', 'stats with a refused secret: '
                . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
    'restic prune: a missing or different --expect removes nothing (no forget, no prune); a matching one forgets exactly the planned ids' => function () {
        $fx = bkFixture();
        try {
            list($code, $l) = bkRun($fx, 'restic', ['show', '--prune-plan', '--keep-daily', '7']);
            check($code === 0 && count($l) === 3 && end($l)['expect'] === 2 && $l[0]['id'] === 'aaaaaaaa'
                && $l[0]['time'] === strtotime('2026-10-01T03:00:00+03:00'), 'plan: ' . json_encode($l));
            check(stCalls($fx, 'restic') === ['forget --dry-run --json --keep-daily 7'], 'the plan must be a dry run: '
                . json_encode(stCalls($fx, 'restic')));
            foreach ([['prune', '--keep-daily', '7'], ['prune', '--keep-daily', '7', '--expect', '1'], ['prune', '--keep-daily', '7',
                '--expect', '2GiB'], ['prune', '--expect', '2']] as $args) {
                stClearLog($fx);
                list($code) = bkRun($fx, 'restic', $args);
                $calls = stCalls($fx, 'restic');
                check($code === 1 && ($calls === [] || $calls === ['forget --dry-run --json --keep-daily 7']),
                    'removed without a matching --expect: ' . json_encode([$args, $calls]));
            }
            check(count(stState($fx)['snapshots']) === 3, 'a snapshot went missing');
            stClearLog($fx);
            list($code, $l, $out) = bkRun($fx, 'restic', ['prune', '--keep-daily', '7', '--keep-within', '2w', '--expect', '2']);
            $calls = stCalls($fx, 'restic');
            check($code === 0 && $calls[0] === 'forget --dry-run --json --keep-daily 7 --keep-within 14d' && $calls[1] === 'forget -- '
                . str_repeat('a', 64) . ' ' . str_repeat('b', 64) && $calls[2] === 'prune', 'prune calls: ' . json_encode($calls));
            check(end($l)['removed'] === 2 && end($l)['verified'] === true && count(stState($fx)['snapshots']) === 1, 'prune result: '
                . $out);
        } finally {
            stDone($fx);
        }
    },
    'restic restore: only into a new or empty directory inside the home, never a FUSE mount; never without --target' => function () {
        $fx = bkFixture();
        try {
            mkdir($fx['home'] . '/full');
            file_put_contents($fx['home'] . '/full/keep.txt', "mine\n");
            mkdir($fx['home'] . '/mnt/gd', 0700, true);
            file_put_contents($fx['mountinfo'], "90 22 0:50 / {$fx['home']}/mnt/gd rw - fuse.rclone gdrive: rw,user_id=" . stUid() . "\n");
            $outside = $fx['dir'] . '/outside';
            foreach ([[], ['--target', $fx['home'] . '/full'], ['--target', $outside], ['--target', $fx['home'] . '/mnt/gd/r'],
                ['--target', 'relative'], ['--target', $fx['home'] . '/../x']] as $t) {
                stClearLog($fx);
                list($code, $l) = bkRun($fx, 'restic', array_merge(['restore', 'cccccccc'], $t));
                check($code === 1 && !in_array('restore', bkVerbs($fx, 'restic'), true), 'restore ran with ' . json_encode($t) . ': '
                    . json_encode($l));
            }
            check(file_get_contents($fx['home'] . '/full/keep.txt') === "mine\n" && !file_exists($outside),
                'an existing file or an outside path was touched');
            list($code, $l, $out) = bkRun($fx, 'restic', ['restore', 'cccccccc', '--target', $fx['home'] . '/restore-1', '--include',
                '/home/u/data']);
            check($code === 0 && stCalls($fx, 'restic') === ['restore --target ' . $fx['home']
                . '/restore-1 --include /home/u/data -- cccccccc'] && end($l)['verified'] === true, 'restore: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'restic check, backup, files, list: never --repair; backup at low priority and one job at a time; not installed is an error' => function () {
        $fx = bkFixture();
        try {
            bkRun($fx, 'restic', ['check']);
            bkRun($fx, 'restic', ['check', '--read-data-subset', '5%']);
            list($code) = bkRun($fx, 'restic', ['check', '--repair']);
            $calls = array_values(array_diff(stCalls($fx, 'restic'), ['version'])); // a percentage asks the version first
            check($code === 2 && $calls === ['check', 'check --read-data-subset 5%'], 'check calls: ' . json_encode(stCalls($fx,
                'restic')));
            stClearLog($fx);
            list($code, $l, $out) = bkRun($fx, 'restic', ['backup', $fx['home'] . '/data']);
            check($code === 0 && stCalls($fx, 'nice') === ['-n 19 ionice -c3 restic backup --json -- ' . $fx['home'] . '/data']
                && $l[0]['id'] === 'abcd1234' && $l[0]['added_bytes'] === 4096, 'backup: ' . $out);
            list($code) = bkRun($fx, 'restic', ['backup', '/etc']);
            check($code === 1, 'a path outside the home was backed up');
            mkdir($fx['proc'] . '/4242');
            file_put_contents($fx['proc'] . '/4242/comm', "restic\n");
            stClearLog($fx);
            list($code, $l) = bkRun($fx, 'restic', ['backup', $fx['home'] . '/data']);
            check($code === 1 && strpos(end($l)['error'], 'already running') !== false && !stCalls($fx, 'restic'),
                'a second job started: ' . json_encode($l));
            list($code, $l) = bkRun($fx, 'restic', ['show', 'aaaaaaaa', '--files']);
            check($code === 0 && $l[0]['path'] === '/home/u/data/a.txt' && $l[0]['bytes'] === 2, 'files: ' . json_encode($l));
            list($code, $l) = bkRun($fx, 'restic', ['list']);
            check($code === 0 && $l[0]['id'] === 'cccccccc' && $l[2]['id'] === 'aaaaaaaa' && $l[0]['snapshot_id'] === str_repeat('c',
                64), 'list newest first: ' . json_encode($l));
        } finally {
            stDone($fx);
        }
        $fx = bkFixture('restic', [], array_diff(ST_FAKES, ['restic']));
        try {
            list($code, $l) = bkRun($fx, 'restic', ['list']);
            check($code === 1 && strpos(end($l)['error'], 'restic is not installed') === 0, 'not installed: ' . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
];
