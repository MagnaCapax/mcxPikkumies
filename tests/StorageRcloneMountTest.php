<?php
/** tools/storage/rclone unmount and stats against the fake rclone (tests/fixtures/storage/bin/rclone). */
require_once __DIR__ . '/fixtures/storage/rclone.php';

return [
    'rclone unmount and stats: only this account\'s FUSE mount, through fusermount3; PMSS WebUI read from ~/.rcloneEnable and ~/.rclonePort; not installed is an error' => function () {
        $fx = rcFixture();
        try {
            mkdir($fx['home'] . '/mnt/gd', 0700, true);
            mkdir($fx['home'] . '/mnt/other', 0700, true);
            $uid = stUid();
            $mnt = $fx['home'] . '/mnt/gd';
            file_put_contents($fx['mountinfo'],
                "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n90 22 0:50 / $mnt rw,nosuid - fuse.rclone gdrive: rw,user_id=$uid,group_id=$uid\n"
                . "91 22 0:51 / {$fx['home']}/mnt/other rw - fuse.sshfs x: rw,user_id=" . ($uid + 1) . ",group_id=0\n");
            touch($fx['home'] . '/.rcloneEnable');
            file_put_contents($fx['home'] . '/.rclonePort', "1\n");
            list($code, $l) = rcRun($fx, ['stats'], 'pmss');
            check($code === 0 && $l[0]['webui'] === ['enabled' => true, 'port' => 1, 'running' => false]
                && count($l[0]['mounts']) === 1 && $l[0]['mounts'][0]['mountpoint'] === $mnt, 'stats: ' . json_encode($l));
            list($code, $l) = rcRun($fx, ['unmount', $fx['home'] . '/mnt/other']);
            check($code === 1 && !stCalls($fx, 'fusermount3'), 'another account\'s mount was touched');
            list($code, $l, $out) = rcRun($fx, ['unmount', $mnt]);
            check($code === 0 && stCalls($fx, 'fusermount3') === ["-u $mnt"] && end($l)['verified'] === true, 'unmount: ' . $out);
        } finally {
            stDone($fx);
        }
        $fx = rcFixture([], array_diff(ST_FAKES, ['rclone']));
        try {
            foreach (['list', 'stats'] as $verb) {
                list($code, $l) = rcRun($fx, [$verb]);
                check($code === 1 && strpos(end($l)['error'], 'rclone is not installed') === 0 && strpos(end($l)['hint'],
                    'never installs') !== false, "$verb without rclone: " . json_encode($l));
            }
        } finally {
            stDone($fx);
        }
    },
    'rclone unmount: a FUSE mount this account owns (matching uid) but outside the home is still refused' => function () {
        $fx = rcFixture();
        try {
            $uid = stUid();
            $outside = $fx['dir'] . '/outside-mnt'; // this account's own uid, but not under $fx['home']
            mkdir($outside, 0700, true);
            file_put_contents($fx['mountinfo'],
                "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n90 22 0:50 / $outside rw,nosuid - fuse.rclone gdrive: rw,user_id=$uid,group_id=$uid\n");
            list($code, $l, $out) = rcRun($fx, ['unmount', $outside]);
            check($code === 1 && !stCalls($fx, 'fusermount3'), 'a same-uid mount outside the home was unmounted: ' . $out);
            check(strpos(end($l)['error'], 'not a FUSE mount of this account inside the home') !== false, 'wrong refusal reason: ' . $out);
        } finally {
            stDone($fx);
        }
    },
];
