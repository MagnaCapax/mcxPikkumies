<?php
/** tools/storage/storage: quota from lib/Quota.php, bounded scans, and the guard's answer to every USAGE example of the storage and backup units. */
require_once __DIR__ . '/fixtures/storage/harness.php';

const ST_QUOTA_HEAD = "Disk quotas for user u (uid 1001):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";

function stStorage(array $fx, array $args)
{
    list($code, $out) = stRun($fx, 'storage/storage/storage.php', $args, ['storageLocalDir' => $fx['local']]);
    return [$code, stLines($out), $out];
}

return [
    'storage stats: the quota comes from lib/Quota (~/.quota), with its state; quota off on the host and the PMSS placeholder are named' => function () {
        $fx = stFixture(['quota_out' => '', 'quota_err' => '', 'quota_code' => 1]);
        try {
            $g = 1073741824;
            file_put_contents($fx['home'] . '/.quota', ST_QUOTA_HEAD
                . "       /dev/md2    100G   1024G   1280G               5       0       0\n");
            list($code, $l) = stStorage($fx, ['stats']);
            $r = $l[0];
            Quota::$live = function () { return null; }; // this process's own PATH/account quota must never leak in; QuotaTest.php:25 does the same
            try {
                $q = Quota::fields($fx['home']);
            } finally {
                Quota::$live = null;
            }
            check($code === 0 && $r['quota_source'] === '~/.quota' && $r['quota_free_bytes'] === $q['quota_free_bytes']
                && $r['quota_free_bytes'] === 924 * $g, 'figures not from Quota: ' . json_encode($r));
            check($r['quota_state'] === 'ok' && $r['quota_hard_bytes'] === 1280 * $g && $r['quota_used_percent'] === 9.8
                && is_int($r['fs_free_bytes']), 'state: ' . json_encode($r));
            file_put_contents($fx['home'] . '/.quota', ST_QUOTA_HEAD
                . "       /dev/md2    1300G*  1024G   1280G  none   5       0       0\n");
            list(, $l) = stStorage($fx, ['stats']);
            check($l[0]['quota_state'] === 'over_hard' && strpos($l[0]['hint'], 'hard limit') !== false, 'over hard: '
                . json_encode($l[0]));
            // Kernel quota off: the live quota fails with this text while ~/.quota keeps old figures.
            $s = stState($fx);
            $s['quota_err'] = "quota: Cannot open quotafile /home/aquota.user: Permission denied\nquota: Cannot find any quota file to work on.\n";
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list(, $l) = stStorage($fx, ['stats']);
            check($l[0]['quota_state'] === 'off_on_host' && $l[0]['quota_source'] === '~/.quota' && strpos($l[0]['hint'],
                'support') !== false, 'quota off: ' . json_encode($l[0]));
            file_put_contents($fx['home'] . '/.quota',
                "Disk quotas for user u (uid 0):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n       /dev/null      0K      0K      0K               0       0       0\n");
            $s['quota_err'] = '';
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list(, $l) = stStorage($fx, ['stats']);
            check($l[0]['quota_state'] === 'no_data' && $l[0]['quota_used_bytes'] === null, 'placeholder read as usage: '
                . json_encode($l[0]));
        } finally {
            stDone($fx);
        }
    },
    'storage topdirs: du runs under nice 19, ionice idle and timeout 300 on one filesystem; results are ranked and cached; --fresh rescans' => function () {
        $fx = stFixture();
        try {
            mkdir($fx['home'] . '/data/big', 0700, true);
            mkdir($fx['home'] . '/data/small', 0700, true);
            file_put_contents($fx['home'] . '/data/big/a.bin', str_repeat('a', 300000));
            file_put_contents($fx['home'] . '/data/small/b.bin', str_repeat('b', 5000));
            list($code, $l, $out) = stStorage($fx, ['topdirs', '--depth', '2']);
            $calls = array_map(function ($c) {
                return $c['bin'] . ' ' . implode(' ', $c['argv']);
            }, stLog($fx));
            check($code === 0 && $calls === ['nice -n 19 ionice -c3 timeout 300 du -0 -x --max-depth=2 -k -- ' . $fx['home'],
                'ionice -c3 timeout 300 du -0 -x --max-depth=2 -k -- ' . $fx['home'],
                'timeout 300 du -0 -x --max-depth=2 -k -- ' . $fx['home'], 'du -0 -x --max-depth=2 -k -- ' . $fx['home']],
                    'scan not wrapped: ' . json_encode($calls));
            check($l[0]['path'] === $fx['home'] . '/data' && $l[1]['path'] === $fx['home'] . '/data/big' && $l[0]['bytes'] >= 300000
                && end($l)['cached'] === false, 'ranking: ' . $out);
            stClearLog($fx);
            list(, $l) = stStorage($fx, ['topdirs', '--depth', '2']);
            check(end($l)['cached'] === true && !stLog($fx), 'second call rescanned instead of using the cache');
            list(, $l) = stStorage($fx, ['topdirs', '--depth', '2', '--fresh']);
            check(end($l)['cached'] === false && count(stCalls($fx, 'du')) === 1, '--fresh did not rescan');
            list($code, $l) = stStorage($fx, ['topdirs', '--inodes']);
            check($code === 0 && isset($l[0]['files']) && strpos(implode(' ', stCalls($fx, 'du')), '--inodes') !== false, '--inodes: '
                . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
    'storage bigfiles: find -xdev with an exact size, hard links reported as freeing nothing; one scan at a time; system dirs refused' => function () {
        $fx = stFixture();
        try {
            mkdir($fx['home'] . '/data/tv', 0700, true);
            mkdir($fx['home'] . '/data/media', 0700, true);
            file_put_contents($fx['home'] . '/data/tv/show.mkv', str_repeat('x', 2 * 1048576));
            link($fx['home'] . '/data/tv/show.mkv', $fx['home'] . '/data/media/show.mkv');
            file_put_contents($fx['home'] . '/data/solo.iso', str_repeat('y', 3 * 1048576));
            list($code, $l, $out) = stStorage($fx, ['bigfiles', '--min-size', '1M']);
            $find = stCalls($fx, 'find');
            check($code === 0 && $find === [$fx['home'] . ' -xdev -type f -size +1048575c -printf %s\t%n\t%T@\t%p\0'], 'find argv: '
                . json_encode($find));
            check(count(stCalls($fx, 'nice')) === 1 && strpos(stCalls($fx, 'timeout')[0], '300 find ') === 0, 'find not wrapped');
            check($l[0]['path'] === $fx['home'] . '/data/solo.iso' && $l[0]['frees_space'] === true && $l[1]['links'] === 2
                && $l[1]['frees_space'] === false, 'records: ' . $out);
            list($code, $l) = stStorage($fx, ['bigfiles', '--path', '/etc']);
            check($code === 1 && strpos(end($l)['error'], 'system directory') !== false, '/etc scanned');
            list($code) = stStorage($fx, ['bigfiles', '--path', '/']);
            check($code === 1, '/ scanned');
            list($code) = stStorage($fx, ['bigfiles', '--dry-run']);
            check($code === 2, 'an unknown option was accepted');
            @mkdir($fx['local'] . '/storage', 0700, true);
            $lock = fopen($fx['local'] . '/storage/scan.lock', 'c');
            flock($lock, LOCK_EX);
            stClearLog($fx);
            list($code, $l) = stStorage($fx, ['bigfiles', '--fresh']);
            flock($lock, LOCK_UN);
            fclose($lock);
            check($code === 1 && strpos(end($l)['error'], 'another scan') !== false && !stCalls($fx, 'find'), 'two scans ran at once');
        } finally {
            stDone($fx);
        }
    },
    'storage and backup USAGE examples: every one that deletes or replaces asks the user (approve), every read example gets no answer' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php';
        $root = dirname(__DIR__) . '/pikkumies/tools';
        $seen = ['approve' => 0, 'read' => 0];
        foreach (array_merge((array) glob("$root/storage/*/USAGE.md"), (array) glob("$root/backup/*/USAGE.md")) as $usage) {
            preg_match_all('/```sh\n(.*?)```/s', (string) file_get_contents($usage), $blocks);
            foreach (explode("\n", implode("\n", $blocks[1])) as $cmd) {
                if (!preg_match('/^php \S+\.php (\S+)/', $cmd, $m)) {
                    continue;
                }
                list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
                $d = json_decode($out, true);
                if (in_array($m[1], ['prune', 'sync', 'move', 'setup', 'schedule'], true) || ($m[1] === 'copy' && strpos($cmd, '--replace') !== false)) {
                    check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], $cmd) !== false, "not asked: $cmd");
                    $seen['approve']++;
                } elseif (in_array($m[1], ['list', 'show', 'stats', 'check', 'topdirs', 'bigfiles', 'status'], true)) {
                    check(trim($out) === '', "a read example was stopped or asked: $cmd -> $out");
                    $seen['read']++;
                } else {
                    // Additive verbs (copy without --replace, backup, restore to a new folder, login, unmount): never blocked;
                    // the guard may ask (it asks for `restic ... restore`, not for borg's). A verb in none of the lists fails.
                    check(in_array($m[1], ['copy', 'backup', 'restore', 'login', 'unmount', 'run', 'snap'], true), "unclassified USAGE verb: $cmd");
                    check(trim($out) === '' || (is_array($d) && $d['action'] === 'approve'),
                        "an additive example was blocked: $cmd -> $out");
                }
            }
        }
        check($seen['approve'] >= 6 && $seen['read'] >= 22, 'too few examples found: ' . json_encode($seen));
    },
];
