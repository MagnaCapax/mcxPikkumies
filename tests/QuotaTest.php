<?php
/** lib/Quota.php: the user's free space comes from the quota, never from the filesystem (shared servers). */
$head = "Disk quotas for user fake (uid 1001):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";
return [
    'quota: one line, wrapped device line, over-quota star, plain KiB blocks' => function () use ($head) {
        $g = 1073741824;
        $q = Quota::parse($head . "       /dev/md2    100G   1024G   1280G               5       0       0\n");
        check($q === ['used' => 100 * $g, 'soft' => 1024 * $g, 'hard' => 1280 * $g], 'plain line: ' . json_encode($q));
        $q = Quota::parse($head . "/dev/mapper/vg-home\n                   1100G*  1024G   1280G  6days       5       0       0\n");
        check($q === ['used' => 1100 * $g, 'soft' => 1024 * $g, 'hard' => 1280 * $g], 'wrapped + star: ' . json_encode($q));
        $q = Quota::parse($head . "       /dev/md2  2048  4096  5120   1  0  0\n");
        check($q === ['used' => 2048 * 1024, 'soft' => 4096 * 1024, 'hard' => 5120 * 1024], 'KiB blocks: ' . json_encode($q));
    },
    'quota: none, the PMSS placeholder, no limits and garbage give no quota (never "0 used")' => function () use ($head) {
        check(Quota::parse("Disk quotas for user fake (uid 1001): none\n") === null, '"none" parsed');
        check(Quota::parse("Disk quotas for user root (uid 0):\n     Filesystem space quota limit\n      /dev/null    0K    0K    0K\n") === null, 'PMSS placeholder parsed as zero usage');
        check(Quota::parse($head . "       /dev/md2    100G      0K      0K   5 0 0\n") === null, 'no limits read as a quota');
        check(Quota::parse("quota: Cannot open quotafile\n") === null, 'error text parsed');
    },
    'quota: fields put the quota first; live quota beats ~/.quota; the filesystem figure is labelled' => function () use ($head) {
        $home = sys_get_temp_dir() . '/pk-quota-' . getmypid();
        @mkdir($home, 0700, true);
        file_put_contents("$home/.quota", $head . "       /dev/md2    100G   1024G   1280G   5 0 0\n");
        try {
            Quota::$live = function () { return null; }; // no quota binary
            $f = Quota::fields($home);
            check($f['quota_source'] === '~/.quota' && $f['quota_free_bytes'] === 924 * 1073741824 && $f['over_quota'] === false && is_int($f['fs_free_bytes']), 'file: ' . json_encode($f));
            Quota::$live = function () use ($head) { return $head . "       /dev/md2    2000G*  1024G   1280G   5 0 0\n"; };
            $f = Quota::fields($home);
            check($f['quota_source'] === 'quota -s' && $f['quota_free_bytes'] === 0 && $f['over_quota'] === true, 'live over quota: ' . json_encode($f));
            Quota::$live = function () { return null; };
            unlink("$home/.quota");
            $f = Quota::fields($home);
            check($f['quota_source'] === null && $f['quota_free_bytes'] === null && is_int($f['fs_free_bytes']), 'no quota: ' . json_encode($f));
        } finally {
            Quota::$live = null;
            @unlink("$home/.quota");
            @rmdir($home);
        }
    },
];
