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
    'quota bytes(): lowercase units, explicit KiB/MiB suffixes, decimals and the E tier all convert; a bad unit, a negative value and trailing garbage are rejected' => function () {
        $g = 1073741824;
        check(Quota::bytes('5g') === 5 * $g, 'lowercase g must convert like G: ' . Quota::bytes('5g'));
        check(Quota::bytes('5GiB') === 5 * $g, 'explicit GiB suffix must convert: ' . Quota::bytes('5GiB'));
        check(Quota::bytes('5MiB') === 5 * 1048576, 'explicit MiB suffix must convert: ' . Quota::bytes('5MiB'));
        check(Quota::bytes('1.5G') === (int) round(1.5 * $g), 'a decimal value must convert: ' . Quota::bytes('1.5G'));
        check(Quota::bytes('2E') === (int) round(2 * pow(1024, 6)), 'the E (exbibyte) tier must convert: ' . Quota::bytes('2E'));
        check(Quota::bytes('5X') === null, 'an unrecognised unit letter must be rejected, not silently treated as KiB blocks');
        check(Quota::bytes('-5G') === null, 'a negative value must be rejected');
        check(Quota::bytes('5G extra') === null, 'trailing garbage after the unit must be rejected');
        check(Quota::bytes('') === null, 'an empty string must be rejected');
    },
    'quota build (via parse()): soft=0 with a nonzero hard limit uses the hard limit as the baseline, NOT "no quota" (only both-zero means no quota)' => function () use ($head) {
        $g = 1073741824;
        $q = Quota::parse($head . "       /dev/md2    50G      0K   1280G               5       0       0\n");
        check($q === ['used' => 50 * $g, 'soft' => 1280 * $g, 'hard' => 1280 * $g],
            'soft=0/hard=1280G must use the hard limit as the baseline, not read as no quota: ' . json_encode($q));
        check(Quota::parse($head . "       /dev/md2    50G      0K      0K               5       0       0\n") === null,
            'soft=0 AND hard=0 together must still mean no quota');
    },
];
