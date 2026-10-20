<?php
/**
 * mdraid plan arithmetic (ADR-0095): stride and stripe_width per level, profiles, the pinned stripe-cache memory and
 * its 5% rule, mixed sizes, -T news, and the kernel table for the raid5 bitmap rounding fix.
 */
require_once __DIR__ . '/mdraidSupport.php';

return [
    'mdraid plan: geometry gives stride and stripe_width from chunk and data disks per level' => function () {
        $big = MdPlan::profile('big-file');
        $g = MdPlan::geometry('raid5', 6, $big);
        check($g['stride'] === 512 && $g['stripe_width'] === 2560 && $g['data_disks'] === 5 && $g['bitmap'], 'raid5 x6 at 2048K: ' . json_encode($g));
        check(in_array('stride=512,stripe_width=2560', $g['mkfs_options'], true) && in_array('0.1', $g['mkfs_options'], true), 'mkfs options: ' . json_encode($g['mkfs_options']));
        $t = MdPlan::geometry('raid10', 4, MdPlan::profile('typical'));
        check($t['stride'] === 128 && $t['stripe_width'] === 256 && $t['data_disks'] === 2, 'raid10 x4 at 512K: ' . json_encode($t));
        $m = MdPlan::geometry('raid1', 2, MdPlan::profile('small-file'));
        check($m['chunk_kib'] === null && $m['stride'] === null && $m['mkfs_options'] === ['-T', 'news'], 'raid1 has no chunk; small-file adds -T news: ' . json_encode($m));
        $z = MdPlan::geometry('raid0', 3, MdPlan::profile('typical'));
        check($z['stripe_width'] === 384 && !$z['bitmap'], 'raid0 x3: ' . json_encode($z));
    },
    'mdraid plan: levels, profiles and minimum disks; RAID6 is not offered' => function () {
        check(MdPlan::level('5') === 'raid5' && MdPlan::level('raid10') === 'raid10', 'level names normalise');
        check(strpos(mdRefusal(function () {
            MdPlan::level('6');
        }), 'not offered') !== false, 'RAID6 must be refused');
        check(strpos(mdRefusal(function () {
            MdPlan::profile('fastest');
        }), 'unknown profile') !== false, 'an unknown profile must be refused');
        check(MdPlan::PROFILES['big-file']['chunk_kib'] === 2048 && MdPlan::PROFILES['typical']['chunk_kib'] === 512, 'profile chunks changed');
        foreach (MdPlan::PROFILES as $name => $p) {
            check(trim($p['label']) !== '' && $p['stripe_cache'] >= 17 && $p['stripe_cache'] <= 32768, "$name: a label and a stripe cache inside the kernel's 17..32768");
        }
    },
    'mdraid plan: pinned memory is value x members x 4 KiB and big-file drops to 4096 at 5% of RAM' => function () {
        check(MdPlan::pinned(32768, 4) === 512 * 1048576, '32768 on 4 members pins 512 MiB');
        check(MdPlan::pinned(4096, 6) === 96 * 1048576, '4096 on 6 members pins 96 MiB');
        $big = MdPlan::profile('big-file');
        list($v) = MdPlan::stripeCache($big, 4, 64 * 1073741824, 0);
        check($v === 32768, '512 MiB of 64 GiB is under 5%: keep 32768');
        list($v, $why) = MdPlan::stripeCache($big, 4, 8 * 1073741824, 0);
        check($v === 4096 && strpos($why, '5%') !== false, '512 MiB of 8 GiB is over 5%: 4096, got ' . $v);
        list($v) = MdPlan::stripeCache($big, 4, 32 * 1073741824, 1200 * 1048576);
        check($v === 4096, 'other arrays count toward the 5%');
        list($v) = MdPlan::stripeCache(MdPlan::profile('typical'), 12, 1073741824, 0);
        check($v === 4096, 'typical stays 4096');
    },
    'mdraid plan: mixed sizes report the loss to the smallest member' => function () {
        list($min, $lost, $share) = MdPlan::sizes([4000, 4000, 4000]);
        check($min === 4000 && $lost === 0 && $share === 0.0, 'equal sizes lose nothing');
        list($min, $lost, $share) = MdPlan::sizes([4000, 4000, 8000]);
        check($min === 4000 && $lost === 4000 && abs($share - 0.25) < 1e-9 && $share > MdPlan::SIZE_LOSS, 'one double-size disk loses a quarter');
        list(, , $share) = MdPlan::sizes([4000000, 4000000, 4000100]);
        check($share < MdPlan::SIZE_LOSS, 'a few sectors of difference stays under the threshold');
    },
    'mdraid plan: the kernel table matches the stable tags read on 2026-10-20, and an ABI-only name without an upstream number is unknown' => function () {
        $cases = [
            ['6.1.0-52-amd64', '', 'unknown'], ['6.1.0-52-amd64', 'x Debian 6.1.176-1 (2026-09-01)', 'fixed'], ['6.6.78', '', 'fixed'], ['6.6.79', '', 'affected'], ['6.6.156', '', 'affected'], ['6.6.157', '', 'fixed'],
            ['6.12.12', '', 'fixed'], ['6.12.13', '', 'affected'], ['6.12.95+deb12-amd64', '', 'affected'], ['6.12.109', '', 'affected'], ['6.12.110', '', 'fixed'],
            ['6.13.1', '', 'fixed'], ['6.13.2', '', 'affected'], ['6.14.0', '', 'affected'], ['6.17.9', '', 'affected'], ['6.18.51', '', 'affected'], ['6.18.52', '', 'fixed'],
            ['6.19.3', '', 'affected'], ['7.0.1', '', 'affected'], ['7.1.5', '', 'affected'], ['7.2.5', '', 'affected'], ['7.2.6', '', 'fixed'], ['7.3.0', '', 'fixed'],
            ['6.12.0-1-amd64', '', 'unknown'], ['6.12.0-1-amd64', 'Linux version x #1 SMP Debian 6.12.111-1 (2026-10-01)', 'fixed'],
            ['6.8.0-45-generic', "Linux version 6.8.0-45-generic\nUbuntu 6.8.0-45.45-generic 6.8.12", 'fixed'], ['6.14.0-30-generic', '', 'unknown'], ['6.14.11', '', 'affected'], ['garbage', '', 'unknown'],
        ];
        foreach ($cases as $c) {
            check(MdPlan::kernelBitmap($c[0], $c[1]) === $c[2], "{$c[0]}: expected {$c[2]}, got " . MdPlan::kernelBitmap($c[0], $c[1]));
        }
        check(MdPlan::bitmapRisk('raid5', 4, true) && MdPlan::bitmapRisk('raid5', 6, true) && MdPlan::bitmapRisk('raid6', 5, true), '3 and 5 data disks with a bitmap are at risk');
        check(!MdPlan::bitmapRisk('raid5', 5, true) && !MdPlan::bitmapRisk('raid5', 3, true) && !MdPlan::bitmapRisk('raid5', 4, false) && !MdPlan::bitmapRisk('raid1', 3, true), 'power-of-two data disks, no bitmap, or mirrors are not');
    },
    'mdraid plan: the SOP the skill, the hook and the tool name exists, and the two common-mistakes entries are there' => function () {
        $root = dirname(__DIR__) . '/pikkumies';
        $sop = 'doctrine/SOP/recover-a-degraded-or-failed-array.md';
        check(is_file("$root/$sop"), "$sop is missing");
        foreach (['tools/server-admin/mdraid/skill.md', 'hooks/system/raid-degraded/raid-degraded.php', 'tools/server-admin/mdraid/MdRecover.php'] as $f) {
            check(strpos((string) file_get_contents("$root/$f"), 'recover-a-degraded-or-failed-array.md') !== false, "$f no longer names the SOP");
        }
        $ap = (string) file_get_contents("$root/doctrine/antipatterns.md");
        check(strpos($ap, '[recovery-is-assemble]') !== false && strpos($ap, '[no-signature-is-not-empty]') !== false, 'the two RAID entries are missing from antipatterns.md');
    },
];
