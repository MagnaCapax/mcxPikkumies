<?php
/**
 * mdraid create and tune (ADR-0095): --plan writes nothing; the refusals no flag clears (sdX, in use) and the ones a
 * flag clears (RAID0, mixed sizes, data on a disk); the order of a build and its read-back; a failed step that says
 * what was done; a second create on the same disks refused; tune's unit, values and read-back.
 */
require_once __DIR__ . '/mdraidSupport.php';

if (!function_exists('mdBuildFix')) {
    /** Four blank 8 GiB disks, sdb..sde, serials D0..D3. */
    function mdBuildFix()
    {
        $f = new MdFix();
        foreach (['sdb', 'sdc', 'sdd', 'sde'] as $i => $k) {
            $f->disk($k, 'D' . $i);
        }
        return $f;
    }

    function mdCreate(MdFix $f, array $opt)
    {
        return $f->run(function () use ($opt) {
            try {
                return MdBuild::create($opt);
            } catch (CliError $e) {
                return 'REFUSED: ' . $e->getMessage();
            }
        });
    }
}

return [
    'create: --plan prints the geometry, the exact commands and the pinned memory, and runs nothing that writes' => function () {
        $f = mdBuildFix();
        try {
            $r = mdCreate($f, ['level' => '5', 'disks' => 'D0,D1,ata-TESTDISK_D2,ata-TESTDISK_D3', 'profile' => 'big-file', 'plan' => true]);
            check(is_array($r) && !empty($r['status']['plan']), 'plan: ' . json_encode($r));
            $p = $r['records'][0];
            check($p['array'] === 'md0' && $p['geometry']['stride'] === 512 && $p['geometry']['stripe_width'] === 1536, 'geometry: ' . json_encode($p['geometry']));
            check(strpos($p['commands'][0], "'mdadm' '--create' '/dev/md0' '--metadata=1.2' '--level=raid5' '--raid-devices=4' '--chunk=2048' '--bitmap=internal' '--run'") === 0, 'create line: ' . $p['commands'][0]);
            check(strpos($p['commands'][1], "'-E' 'stride=512,stripe_width=1536'") !== false, 'mkfs line: ' . $p['commands'][1]);
            check($p['tune_after']['stripe_cache_size'] === 32768 && $p['tune_after']['pinned_mib'] == 512, 'tune_after: ' . json_encode($p['tune_after']));
            check(!$f->writes() && !is_dir("$f->root/var/lib/pikkumies-mdraid"), 'plan wrote: ' . json_encode($f->writes()));
        } finally {
            $f->clean();
        }
    },
    'create: refuses sdX names, too few disks, odd RAID10, RAID0 without --no-redundancy, in-use disks, and data without --destroy, writing nothing' => function () {
        $f = mdBuildFix();
        try {
            $f->disk('sdf', 'USED', 8, [], 'xfs');
            $f->disk('sdg', 'BIG', 16);
            file_put_contents("$f->root/proc/mounts", "/dev/sde /srv ext4 rw 0 0\n");
            $cases = [
                [['level' => '1', 'disks' => '/dev/sdb,D1', 'profile' => 'typical'], 'kernel name'],
                [['level' => '5', 'disks' => 'D0,D1', 'profile' => 'big-file'], 'at least 3'],
                [['level' => '10', 'disks' => 'D0,D1,D2,USED', 'profile' => 'small-file', 'destroy' => 'USED'], null],
                [['level' => '10', 'disks' => 'D0,D1,D2', 'profile' => 'small-file'], 'even number'],
                [['level' => '0', 'disks' => 'D0,D1', 'profile' => 'typical'], 'no redundancy'],
                [['level' => '1', 'disks' => 'D0,D3', 'profile' => 'typical'], 'in use'],
                [['level' => '1', 'disks' => 'D0,USED', 'profile' => 'typical'], 'holds data'],
                [['level' => '1', 'disks' => 'D0,D1', 'profile' => 'typical', 'destroy' => 'USED'], 'not one of the selected disks'],
                [['level' => '1', 'disks' => 'D0,D0', 'profile' => 'typical'], 'named twice'],
                [['level' => '1', 'disks' => 'D0,BIG', 'profile' => 'typical'], 'differ in size'],
                [['level' => '6', 'disks' => 'D0,D1,D2,BIG', 'profile' => 'typical'], 'not offered'],
                [['level' => '1', 'disks' => 'D0,D1'], '--profile is missing'],
            ];
            foreach ($cases as $c) {
                if ($c[1] === null) {
                    $c[0]['plan'] = true; // --destroy covers it: the plan goes through
                    $r = mdCreate($f, $c[0]);
                    check(is_array($r) && strpos(json_encode($r), 'xfs') !== false, 'a destroyed disk must be planned with its evidence: ' . json_encode($r));
                    continue;
                }
                $r = mdCreate($f, $c[0]);
                check(is_string($r) && strpos($r, $c[1]) !== false, json_encode($c[0]) . " must refuse with '{$c[1]}': " . json_encode($r));
            }
            check(!$f->writes(), 'a refused create wrote: ' . json_encode($f->writes()));
            check(is_array(mdCreate($f, ['level' => '1', 'disks' => 'D0,BIG', 'profile' => 'typical', 'accept-size-loss' => true, 'plan' => true])), '--accept-size-loss must clear the size refusal');
        } finally {
            $f->clean();
        }
    },
    'create: builds in order (snapshot, wipe, create, mkfs -n, mkfs, conf line, initramfs), reads it back, and a second create on the same disks is refused' => function () {
        $f = mdBuildFix();
        try {
            $f->disk('sdf', 'OLD', 8, [], 'ext4');
            $r = mdCreate($f, ['level' => '5', 'disks' => 'D0,D1,OLD', 'profile' => 'typical', 'destroy' => 'OLD']);
            check(is_array($r) && $r['status']['verified'] === true, 'create result: ' . json_encode($r));
            $l = array_values(array_filter(array_map(function ($a) {
                return implode(' ', $a);
            }, $f->writes()), function ($x) {
                return strpos($x, 'mdadm --detail') !== 0;
            }));
            $want = ['wipefs -a /dev/disk/by-id/ata-TESTDISK_OLD', 'mdadm --create /dev/md0', 'mkfs.ext4 -n', 'mkfs.ext4 -E stride=128,stripe_width=256 /dev/md0', 'update-initramfs -u'];
            $pos = -1;
            foreach ($want as $w) {
                $hit = null;
                foreach ($l as $i => $x) {
                    if ($hit === null && $i > $pos && strpos($x, $w) === 0) {
                        $hit = $i;
                    }
                }
                check($hit !== null, "step '$w' missing or out of order: " . json_encode($l));
                $pos = $hit;
            }
            $conf = file_get_contents("$f->root/etc/mdadm/mdadm.conf");
            check(substr_count($conf, 'ARRAY /dev/md0') === 1 && strpos($conf, $f->arrays['md0']['uuid']) !== false && strpos($conf, 'MAILADDR root') !== false, 'conf: ' . $conf);
            check(count(glob("$f->root/var/lib/pikkumies-mdraid/*create-md0.txt")) === 1 && count(glob("$f->root/var/lib/pikkumies-mdraid/*mdadm.conf-before.txt")) === 1, 'snapshot and conf copy must be kept');
            check(strpos($r['records'][1]['fstab_line'], 'UUID=fs-md0') === 0 && strpos($r['records'][1]['fstab_line'], 'nofail') !== false, 'fstab line: ' . $r['records'][1]['fstab_line']);
            $again = mdCreate($f, ['level' => '5', 'disks' => 'D0,D1,OLD', 'profile' => 'typical', 'array' => 'md1']);
            check(is_string($again) && strpos($again, 'in use') !== false, 'a second create on member disks must refuse: ' . json_encode($again));
            check(is_string(mdCreate($f, ['level' => '1', 'disks' => 'D2,D3', 'profile' => 'typical', 'array' => 'md0'])), 'a taken md name must refuse');
        } finally {
            $f->clean();
        }
    },
    'create: a failed step stops and says what was done before it; the conf line is never doubled' => function () {
        $f = mdBuildFix();
        try {
            $f->fail = ['mkfs.ext4 /dev/md0' => [1, 'mkfs.ext4: Device size reported to be zero']];
            $r = mdCreate($f, ['level' => '1', 'disks' => 'D0,D1', 'profile' => 'typical']);
            check(is_string($r) && strpos($r, 'mkfs.ext4 failed') !== false && strpos($r, 'Done before it') !== false && strpos($r, 'created /dev/md0') !== false, 'failure report: ' . $r);
            check(!preg_grep('/update-initramfs/', array_map('implode', array_fill(0, count($f->calls), ' '), $f->calls)) && strpos(file_get_contents("$f->root/etc/mdadm/mdadm.conf"), 'ARRAY') === false,
                'nothing after the failed step may run');
        } finally {
            $f->clean();
        }
        $f = mdBuildFix();
        try {
            $f->conf('ARRAY /dev/md/9 metadata=1.2 name=host:3 UUID=1:2:3:4'); // another device line already carries md3's name
            $r = mdCreate($f, ['level' => '1', 'disks' => 'D0,D1', 'profile' => 'typical', 'array' => 'md3']);
            check(is_string($r) && strpos($r, 'taken') !== false && !$f->writes(), 'a name already in mdadm.conf must refuse before anything runs: ' . json_encode($r));
        } finally {
            $f->clean();
        }
    },
    'tune: writes one unit with this profile, keeps other arrays\' lines, applies the values now and reads them back' => function () {
        $f = mdBuildFix();
        try {
            $f->addArray('md0', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2]);
            $f->addArray('md1', 'raid1', 2, ['sde' => 0], 100);
            $unit = '/etc/systemd/system/pikkumies-mdraid-tune.service';
            mkdir("$f->root/etc/systemd/system", 0700, true);
            file_put_contents($f->root . $unit, "[Service]\n# md9 typical\nExecStart=/bin/sh -c 'echo 4096 > /sys/block/md9/md/stripe_cache_size'\n");
            $plan = $f->run(function () {
                return MdBuild::tune(['profile' => 'big-file', 'array' => 'md0', 'plan' => true]);
            });
            check(!empty($plan['status']['plan']) && !$f->writes(), 'tune --plan wrote: ' . json_encode($f->writes()));
            $r = $f->run(function () {
                return MdBuild::tune(['profile' => 'big-file', 'array' => 'md0']);
            });
            $text = file_get_contents($f->root . $unit);
            check($r['status']['verified'] === true, 'tune: ' . json_encode($r));
            check(strpos($text, "ExecStart=/bin/sh -c 'echo 32768 > /sys/block/md0/md/stripe_cache_size'") !== false && strpos($text, "echo 4096 > /sys/block/md0/queue/read_ahead_kb") !== false, 'unit: ' . $text);
            check(strpos($text, 'md9') !== false && strpos($text, 'Type=oneshot') !== false && strpos($text, 'WantedBy=multi-user.target') !== false, 'unit keeps md9 and is a oneshot: ' . $text);
            check(trim(file_get_contents("$f->root/sys/block/md0/md/stripe_cache_size")) === '32768' && trim(file_get_contents("$f->root/sys/block/md0/queue/read_ahead_kb")) === '4096', 'values not applied');
            $l = array_map('implode', array_fill(0, count($f->calls), ' '), $f->calls);
            check(in_array('systemctl daemon-reload', $l, true) && in_array('systemctl enable pikkumies-mdraid-tune.service', $l, true), 'systemd not told: ' . json_encode($l));
            $r = $f->run(function () {
                return MdBuild::tune(['profile' => 'typical']);
            });
            $text = file_get_contents($f->root . $unit);
            check(strpos($text, 'echo 4096 > /sys/block/md0/md/stripe_cache_size') !== false && strpos($text, 'md0/queue/read_ahead_kb') === false && strpos($text, 'md1/md/stripe_cache_size') === false,
                'typical over every array: md0 4096, no read-ahead line, no stripe cache on the mirror: ' . $text);
        } finally {
            $f->clean();
        }
    },
    'tune: big-file falls back to 4096 when the pinned stripe cache would pass 5% of RAM, and refuses without systemd' => function () {
        $f = mdBuildFix();
        try {
            file_put_contents("$f->root/proc/meminfo", "MemTotal:        8388608 kB\n"); // 8 GiB
            $f->addArray('md0', 'raid5', 4, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2, 'sde' => 3]);
            $r = $f->run(function () {
                return MdBuild::tune(['profile' => 'big-file', 'array' => 'md0', 'plan' => true]);
            });
            check($r['records'][0]['values']['md0']['md/stripe_cache_size'][0] === 4096, 'memory rule: ' . json_encode($r['records'][0]['values']));
            rmdir("$f->root/run/systemd/system");
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdBuild::tune(['profile' => 'big-file']);
                });
            }), 'systemd') !== false, 'tune without systemd must refuse');
        } finally {
            $f->clean();
        }
    },
];
