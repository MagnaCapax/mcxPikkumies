<?php
/**
 * Regressions from the security review of the mdraid unit (ADR-0095): M-2 a disk whose superblock belongs to a live
 * array (running or inactive in /proc/mdstat, or one other disks can still assemble) is in use, not "holds data";
 * M-3 a disk a process holds open, a btrfs device, a loop backing file, or one that cannot be opened exclusively is in
 * use, and the in-use check runs again just before the wipe; M-5 tune keeps only lines of its own exact shape; M-6 a
 * kernel name without an upstream point release is unknown; M-7 the memory rule holds when RAM cannot be read;
 * M-8 every write is logged to /var/lib/pikkumies-mdraid/actions.log (0600). Fixtures only.
 */
require_once __DIR__ . '/mdraidSupport.php';

if (!function_exists('mdReviewTry')) {
    function mdReviewTry(MdFix $f, callable $fn, $access = 'uid0')
    {
        return $f->run(function () use ($fn) {
            try {
                return $fn();
            } catch (CliError $e) {
                return 'REFUSED: ' . $e->getMessage();
            }
        }, $access);
    }

    function mdReviewDestroyed(MdFix $f)
    {
        return preg_grep('/(--zero-superblock|wipefs -a|--create|--add) /', array_map(function ($a) {
            return implode(' ', $a);
        }, $f->writes()));
    }
}

return [
    'review M-2a: a removed member of a running md1 that mdadm.conf does not list is in use; --destroy does not wipe it' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd', 'sde'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $f->addArray('md1', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2]);
            unset($f->arrays['md1']['members']['sdd']);
            $f->sync();
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S2,S3', 'profile' => 'typical', 'destroy' => 'S2']);
            });
            check(is_string($r) && strpos($r, 'in use') !== false && strpos($r, 'md1') !== false, 'must refuse naming md1: ' . json_encode($r));
            check(!mdReviewDestroyed($f), 'something was destroyed: ' . json_encode(mdReviewDestroyed($f)));
        } finally {
            $f->clean();
        }
    },
    'review M-2b: a member of an inactive md127 (not held, not in mdadm.conf) is in use while its other members can still assemble' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd', 'sde'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $f->addArray('md127', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2], 100, ['active' => false]);
            unset($f->arrays['md127']['members']['sdc'], $f->arrays['md127']['members']['sdd']);
            $f->sync();
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S1,S3', 'profile' => 'typical', 'destroy' => 'S1']);
            });
            check(is_string($r) && strpos($r, 'in use') !== false && strpos($r, 'md127') !== false, 'must refuse naming md127: ' . json_encode($r));
            check(!mdReviewDestroyed($f), 'something was destroyed: ' . json_encode(mdReviewDestroyed($f)));
        } finally {
            $f->clean();
        }
    },
    'review M-2b: an old array none of whose disks is live can be destroyed only when every disk carrying it is in --destroy' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd'] as $i => $k) {
                $f->disk($k, 'S' . $i);
                $f->disks[$k]['examine'] = ['uuid' => '5:5:5:5', 'md' => 'md5', 'level' => 'raid1', 'devices' => 3, 'role' => $i, 'events' => 9];
            }
            $part = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S0,S1', 'profile' => 'typical', 'destroy' => 'S0,S1']);
            });
            check(is_string($part) && strpos($part, 'other disk') !== false && strpos($part, 'S2') !== false, 'a partial destroy of an assemblable set must refuse naming the rest: ' . json_encode($part));
            check(!mdReviewDestroyed($f), 'something was destroyed on the partial attempt');
            $all = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '5', 'disks' => 'S0,S1,S2', 'profile' => 'typical', 'destroy' => 'S0,S1,S2']);
            });
            check(is_array($all) && $all['status']['verified'], 'destroying every member of a dead set must work: ' . json_encode($all));
        } finally {
            $f->clean();
        }
    },
    'review M-2c: add --destroy refuses a removed member of another running array' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd', 'sde', 'sdf'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1]);
            unset($f->arrays['md0']['members']['sdc']);
            $f->addArray('md1', 'raid5', 3, ['sdd' => 0, 'sde' => 1, 'sdf' => 2]);
            unset($f->arrays['md1']['members']['sdf']);
            $f->sync();
            $r = mdReviewTry($f, function () {
                return MdRecover::add('md0', 'S4', ['S4']);
            });
            check(is_string($r) && strpos($r, 'in use') !== false && strpos($r, 'md1') !== false, 'must refuse naming md1: ' . json_encode($r));
            check(!mdReviewDestroyed($f), 'something was destroyed');
        } finally {
            $f->clean();
        }
    },
    'review M-2: an ARRAY line in /etc/mdadm.conf (the RHEL path) counts too' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'S0');
            $f->disk('sdc', 'S1');
            $f->disks['sdb']['examine'] = ['uuid' => '8:8:8:8', 'md' => 'md8', 'level' => 'raid1', 'devices' => 2, 'role' => 0, 'events' => 3];
            file_put_contents("$f->root/etc/mdadm.conf", "ARRAY /dev/md8 metadata=1.2 UUID=8:8:8:8\n");
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S0,S1', 'profile' => 'typical', 'destroy' => 'S0', 'plan' => true]);
            });
            check(is_string($r) && strpos($r, 'mdadm.conf') !== false, '/etc/mdadm.conf must be read: ' . json_encode($r));
        } finally {
            $f->clean();
        }
    },
    'review M-3: a disk a process holds open, a btrfs device, or a loop backing file is in use, as uid 0 and through sudo' => function () {
        foreach (['uid0', 'sudo'] as $access) {
            $f = new MdFix();
            try {
                foreach (['sdb', 'sdc', 'sdd', 'sde'] as $i => $k) {
                    $f->disk($k, 'S' . $i, 8, [], 'ext4');
                }
                mkdir("$f->root/proc/4242/fd", 0700, true);
                symlink('/dev/sdb', "$f->root/proc/4242/fd/7");
                mkdir("$f->root/sys/fs/btrfs/aaaa/devices", 0700, true);
                symlink('../../../../block/sdc', "$f->root/sys/fs/btrfs/aaaa/devices/sdc");
                mkdir("$f->root/sys/block/loop0/loop", 0700, true);
                file_put_contents("$f->root/sys/block/loop0/loop/backing_file", "/dev/sdd\n");
                foreach (['S0' => 'open by process 4242', 'S1' => 'btrfs', 'S2' => 'loop0'] as $serial => $why) {
                    $r = mdReviewTry($f, function () use ($serial) {
                        return MdBuild::create(['level' => '1', 'disks' => "$serial,S3", 'profile' => 'typical', 'destroy' => "$serial,S3"]);
                    }, $access);
                    check(is_string($r) && strpos($r, 'in use') !== false && strpos($r, $why) !== false, "$access $serial must be in use ($why): " . json_encode($r));
                }
                check(!mdReviewDestroyed($f), "$access: something was destroyed");
            } finally {
                $f->clean();
            }
        }
    },
    'review M-3: the wipe waits for an exclusive open and re-checks in-use just before it; a disk that became busy is left alone' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'S0', 8, [], 'ext4');
            $f->disk('sdc', 'S1');
            $f->busy = ['sdb'];
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S0,S1', 'profile' => 'typical', 'destroy' => 'S0']);
            });
            check(is_string($r) && strpos($r, 'exclusively') !== false, 'a disk that cannot be opened exclusively must refuse: ' . json_encode($r));
            check(!mdReviewDestroyed($f), 'destroyed before the exclusive open was possible: ' . json_encode(mdReviewDestroyed($f)));
            $f->busy = [];
            $f->onExec = function (array $argv) use ($f) { // the snapshot runs after the first check and before the wipe
                if (implode(' ', $argv) === 'mdadm --examine /dev/disk/by-id/ata-TESTDISK_S0' && is_dir("$f->root/var/lib/pikkumies-mdraid") && !is_link("$f->root/proc/77/fd/3")) {
                    @mkdir("$f->root/proc/77/fd", 0700, true);
                    symlink('/dev/sdb', "$f->root/proc/77/fd/3");
                }
            };
            $f->calls = [];
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S0,S1', 'profile' => 'typical', 'destroy' => 'S0']);
            });
            check(is_string($r) && strpos($r, 'open by process 77') !== false, 'the re-check before the wipe must catch the new holder: ' . json_encode($r));
            check(!mdReviewDestroyed($f), 'destroyed despite the new holder: ' . json_encode(mdReviewDestroyed($f)));
        } finally {
            $f->clean();
        }
    },
    'review M-5: tune keeps only unit lines of its own exact shape' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $f->addArray('md0', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2]);
            $unit = '/etc/systemd/system/pikkumies-mdraid-tune.service';
            mkdir("$f->root/etc/systemd/system", 0700, true);
            file_put_contents($f->root . $unit, "# md9 typical\nExecStart=/bin/sh -c 'echo 4096 > /sys/block/md9/md/stripe_cache_size'\n"
                . "ExecStart=/bin/sh -c 'echo 4096 > /sys/block/md8/md/stripe_cache_size'; touch /tmp/planted\n# md7 typical; rm -rf /\n");
            mdReviewTry($f, function () {
                return MdBuild::tune(['profile' => 'typical', 'array' => 'md0']);
            });
            $text = file_get_contents($f->root . $unit);
            check(strpos($text, 'md9/md/stripe_cache_size') !== false && strpos($text, 'planted') === false && strpos($text, 'rm -rf') === false && strpos($text, 'md8') === false, 'unit: ' . $text);
        } finally {
            $f->clean();
        }
    },
    'review M-6: a kernel name without an upstream point release is unknown, whatever the series' => function () {
        foreach ([['5.14.0-503.el9.x86_64', ''], ['6.8.0-45-generic', 'Linux version 6.8.0-45-generic'], ['6.1.0-52-amd64', '']] as $c) {
            check(MdPlan::kernelBitmap($c[0], $c[1]) === 'unknown', "{$c[0]}: " . MdPlan::kernelBitmap($c[0], $c[1]));
        }
        check(MdPlan::kernelBitmap('6.1.0-52-amd64', 'Linux version 6.1.0-52-amd64 #1 SMP Debian 6.1.176-1') === 'fixed', 'the Debian upstream number still decides');
    },
    'review M-7: with MemTotal unreadable the big-file stripe cache takes the low value' => function () {
        list($v) = MdPlan::stripeCache(MdPlan::profile('big-file'), 16, 0, 0);
        check($v === 4096, 'MemTotal 0 must pick 4096, got ' . $v);
    },
    'review M-8: every write and its exit code goes to /var/lib/pikkumies-mdraid/actions.log, mode 0600' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'S0', 8, [], 'ext4');
            $f->disk('sdc', 'S1');
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S0,S1', 'profile' => 'typical', 'destroy' => 'S0']);
            });
            $log = "$f->root/var/lib/pikkumies-mdraid/actions.log";
            check(is_array($r) && is_file($log), 'no actions.log: ' . json_encode($r));
            $text = file_get_contents($log);
            foreach (['wipefs -a /dev/disk/by-id/ata-TESTDISK_S0', 'mdadm --create /dev/md0', 'mkfs.ext4 /dev/md0', 'update-initramfs -u', 'write /etc/mdadm/mdadm.conf'] as $w) {
                check(strpos($text, $w) !== false, "actions.log lacks '$w': $text");
            }
            check(preg_match('/ exit=0$/m', $text) === 1 && (fileperms($log) & 0777) === 0600, 'exit codes and mode 0600: ' . decoct(fileperms($log) & 0777));
            $f->disk('sdd', 'S2');
            $f->disk('sde', 'S3');
            $f->fail = ['update-initramfs' => [3, 'boom']];
            $r = mdReviewTry($f, function () {
                return MdBuild::create(['level' => '1', 'disks' => 'S2,S3', 'profile' => 'typical', 'array' => 'md5']);
            });
            check(is_string($r) && strpos(file_get_contents($log), 'update-initramfs -u exit=3') !== false, 'a failed step must be logged with its exit code: ' . json_encode($r));
        } finally {
            $f->clean();
        }
    },
];
