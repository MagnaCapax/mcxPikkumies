<?php
/**
 * File (inode) quota parsing and the Hermes build room check. A Hermes build puts about 60,000 files in the account
 * (measured on PMSS 2026-10-17), and PMSS gives max(disk GiB x 500, 15000) files, so a small account would stop
 * half way with EDQUOT. Lines below are the real `quota -s` shape seen on a PMSS account.
 */
return [
    'quota files: parses the file columns of quota -s, decimal k units rounded up, grace column when over, wrapped device line' => function () {
        $head = "Disk quotas for user u (uid 1056): \n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";
        check(Quota::parseFiles($head . "       /dev/vda   2070M    250G    313G           62905    125k    157k        \n") === ['used' => 62905, 'soft' => 125000, 'hard' => 157000], 'plain line');
        check(Quota::parseFiles($head . "       /dev/vda   2070M    250G    313G           5947    125000    156250\n") === ['used' => 5947, 'soft' => 125000, 'hard' => 156250], 'raw numbers');
        check(Quota::parseFiles($head . "       /dev/vda   260G*    250G    313G   6days   130k*    125k    157k   6days\n") === ['used' => 130000, 'soft' => 125000, 'hard' => 157000], 'over the soft limit: grace column skipped');
        check(Quota::parseFiles($head . "  /dev/mapper/vg-home\n                  2070M    250G    313G           62905    125k    157k\n") === ['used' => 62905, 'soft' => 125000, 'hard' => 157000], 'wrapped device line');
        check(Quota::parseFiles("Disk quotas for user root (uid 0):\n /dev/null 0K 0K 0K\n") === null, 'PMSS uid 0 placeholder');
        check(Quota::parseFiles($head . "       /dev/vda   2070M    0    0           62905    0    0\n") === null, 'no file limit');
    },
    'hermes room: a build needs file and space headroom; an unknown quota does not block' => function () {
        check(HermesInstall::roomProblem(['used' => 2811, 'soft' => 125000, 'hard' => 157000], 260000000000) === '', '250 GiB account has room');
        $p = HermesInstall::roomProblem(['used' => 2785, 'soft' => 15000, 'hard' => 18750], 5000000000);
        check(strpos($p, 'files') !== false && strpos($p, '15,000') !== false, 'a 15000-file account has no room: ' . $p);
        $p = HermesInstall::roomProblem(['used' => 70000, 'soft' => 125000, 'hard' => 157000], 260000000000);
        check($p !== '', 'used + build over the soft limit must refuse');
        check(strpos(HermesInstall::roomProblem(null, 1000000000), 'GB') !== false, 'too little free space refuses');
        check(HermesInstall::roomProblem(null, null) === '', 'no quota found: the build is tried');
        // 1 TiB quota with 50 GiB free: room when the user starts it, but an unattended build must leave 10% (~102 GiB) free
        check(HermesInstall::roomProblem(null, 50 * 1073741824, 1024 * 1073741824) === '', 'attended build with 50 GiB free');
        $p = HermesInstall::roomProblem(null, 50 * 1073741824, 1024 * 1073741824, true);
        check(strpos($p, '10%') !== false, 'unattended build would leave the account over 90%: ' . $p);
        check(HermesInstall::roomProblem(null, 200 * 1073741824, 1024 * 1073741824, true) === '', 'unattended build with 200 GiB free of 1 TiB');
        check(HermesInstall::roomProblem(['used' => 60000, 'soft' => 125000, 'hard' => 157000], 260000000000) === '', 'attended: files fit');
        check(strpos(HermesInstall::roomProblem(['used' => 60000, 'soft' => 125000, 'hard' => 157000], 260000000000, 268435456000, true), 'files') !== false, 'unattended: 125,000 files would end over 90%');
        check(strpos((string) file_get_contents(dirname(__DIR__) . '/pikkumies/lib/HermesInstall.php'), "\$q['quota_limit_bytes'], \$unattended)") !== false, 'ensure() must pass unattended to the room check');
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/lib/HermesInstall.php');
        preg_match('/function ensure\(.*?\n    }\n/s', $src, $m);
        $refuse = isset($m[0]) ? strpos($m[0], "return 'not installed: ' . \$room;") : false;
        check($refuse !== false && $refuse < strpos($m[0], 'ls-remote'), 'ensure() must refuse a build without room before it fetches anything');
    },
];
