<?php
/**
 * mdraid disks (ADR-0095): the verdict per disk (blank, holds-data, in-use) from partitions, signatures, an md
 * superblock, mounts, holders, fstab and mdadm.conf, and the sampler, which reports counts and never bytes.
 */
require_once __DIR__ . '/mdraidSupport.php';

if (!function_exists('mdDisksByDisk')) {
    function mdDisksByDisk(array $records)
    {
        $out = [];
        foreach ($records as $r) {
            $out[$r['kernel_name_now']] = $r;
        }
        return $out;
    }
}

return [
    'mdraid disks: blank, holds-data and in-use verdicts with the evidence for each' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'BLANK');
            $old = [];
            for ($off = MdFix::GIB; $off < 6 * MdFix::GIB; $off += 256 * 1048576) {
                $old[$off] = str_repeat('x', 65536); // an old array's data: no signature at its start, data spread deep inside
            }
            $f->disk('sdc', 'DEEPDATA', 8, $old);
            $f->disk('sdd', 'SIGNED', 8, [], 'ext4');
            $f->disk('sde', 'PARTED', 8, [], 'gpt', ['sde1']);
            $f->disk('sdf', 'MOUNTED');
            $f->disk('sdg', 'MEMBER');
            $f->disk('sdh', 'MEMBER2');
            $f->disk('sdi', 'FSTAB', 8, [], 'ext4');
            $f->disk('sdj', 'OLDMEMBER');
            $f->disk('sdk', 'CONFMEMBER');
            $f->addArray('md0', 'raid1', 2, ['sdg' => 0, 'sdh' => 1]);
            $f->disks['sdj']['examine'] = ['uuid' => '9:9:9:9', 'md' => 'md9', 'level' => 'raid5', 'devices' => 3, 'role' => 1, 'events' => 4];
            $f->disks['sdk']['examine'] = ['uuid' => '7:7:7:7', 'md' => 'md7', 'level' => 'raid1', 'devices' => 2, 'role' => 0, 'events' => 4];
            $f->conf('ARRAY /dev/md7 metadata=1.2 UUID=7:7:7:7');
            file_put_contents("$f->root/proc/mounts", "/dev/sdf /srv/data ext4 rw 0 0\n");
            file_put_contents("$f->root/etc/fstab", "/dev/disk/by-id/ata-TESTDISK_FSTAB /mnt/x ext4 defaults 0 2\n");
            $d = mdDisksByDisk($f->run(function () {
                return array_values(MdDisks::all());
            }));
            check($d['sdb']['verdict'] === 'blank' && $d['sdb']['samples']['nonzero_samples'] === 0 && $d['sdb']['samples']['samples'] >= 12, 'sdb blank: ' . json_encode($d['sdb']));
            check($d['sdc']['verdict'] === 'holds-data' && !$d['sdc']['signatures'] && $d['sdc']['samples']['nonzero_samples'] > 0, 'no signature is not empty: ' . json_encode($d['sdc']));
            check($d['sdd']['verdict'] === 'holds-data' && $d['sdd']['signatures'] === ['sdd:ext4'], 'signature: ' . json_encode($d['sdd']['signatures']));
            check($d['sde']['verdict'] === 'holds-data' && $d['sde']['partitions'] === ['sde1'], 'partitions: ' . json_encode($d['sde']));
            check($d['sdf']['verdict'] === 'in-use' && strpos($d['sdf']['in_use'][0], 'mounted at /srv/data') !== false, 'mounted: ' . json_encode($d['sdf']['in_use']));
            check($d['sdg']['verdict'] === 'in-use' && strpos($d['sdg']['in_use'][0], 'held by md0') !== false, 'array member: ' . json_encode($d['sdg']['in_use']));
            check($d['sdi']['verdict'] === 'in-use' && strpos(implode(' ', $d['sdi']['in_use']), '/etc/fstab') !== false, 'fstab: ' . json_encode($d['sdi']['in_use']));
            check($d['sdj']['verdict'] === 'holds-data' && $d['sdj']['md_superblock']['uuid'] === '9:9:9:9', 'old superblock: ' . json_encode($d['sdj']));
            check($d['sdk']['verdict'] === 'in-use' && strpos(implode(' ', $d['sdk']['in_use']), 'mdadm.conf') !== false, 'conf member: ' . json_encode($d['sdk']['in_use']));
            check(!$f->writes(), 'disks ran a write: ' . json_encode($f->writes()));
        } finally {
            $f->clean();
        }
    },
    'mdraid disks: an inactive array assembled from a used disk is reported as "stop that array first"' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'RESOLD');
            $f->addArray('md127', 'raid5', 3, ['sdb' => 1], 50, ['active' => false]);
            $d = mdDisksByDisk($f->run(function () {
                return array_values(MdDisks::all());
            }));
            check($d['sdb']['verdict'] === 'in-use' && strpos($d['sdb']['in_use'][0], 'stop that array first') !== false, 'inactive holder: ' . json_encode($d['sdb']['in_use']));
        } finally {
            $f->clean();
        }
    },
    'mdraid disks: the sampler returns counts only, never byte content, and counts what it cannot read as not empty' => function () {
        $tmp = testTempDir('pk-mdsample');
        MdRun::$access = 'uid0';
        try {
            $secret = 'PASSWORD=hunter2-very-secret';
            $h = fopen("$tmp/disk", 'c+');
            ftruncate($h, 64 * 1048576);
            fseek($h, 1048576);
            fwrite($h, $secret);
            fclose($h);
            $r = testWithEnv(['PIKKUMIES_MDRAID_ROOT' => $tmp], function () {
                return MdDisks::sample('/disk', 64 * 1048576);
            });
            check(array_keys($r) === ['samples', 'nonzero_samples', 'nonzero_bytes', 'unreadable'], 'sampler fields: ' . json_encode($r));
            check($r['nonzero_samples'] === 1 && $r['nonzero_bytes'] === strlen($secret), 'sampler counts: ' . json_encode($r));
            check(strpos(json_encode($r), 'hunter2') === false && strpos(json_encode($r), 'PASSWORD') === false, 'the sampler leaked bytes');
            $short = testWithEnv(['PIKKUMIES_MDRAID_ROOT' => $tmp], function () {
                return MdDisks::sample('/disk', 128 * 1048576); // claims more than the file holds
            });
            check($short['unreadable'] > 0 && $short['nonzero_samples'] >= $short['unreadable'], 'unreadable samples must count as not empty: ' . json_encode($short));
            $missing = testWithEnv(['PIKKUMIES_MDRAID_ROOT' => $tmp], function () {
                return MdDisks::sample('/nope', 1048576 * 8);
            });
            check($missing['samples'] === 0 && $missing['nonzero_samples'] > 0, 'an unreadable disk is never blank: ' . json_encode($missing));
        } finally {
            MdRun::$access = null;
            testRmTree($tmp);
        }
    },
    'mdraid disks: the exec seam refuses sdX paths, stale by-id names and options the tool never passes to mdadm' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'A');
            $f->run(function () {
                foreach ([['mdadm', '--examine', '/dev/sdb'], ['mdadm', '--examine', '/dev/disk/by-id/ata-GONE'], ['mdadm', '--create', '/dev/md0', '--assume-clean'],
                    ['mdadm', '--grow', '/dev/md0'], ['mdadm', '-G', '/dev/md0'], ['mdadm', '--update=resync'], ['mdadm', '--action=repair', '/dev/md0'], ['rm', '-rf', '/']] as $argv) {
                    $msg = mdRefusal(function () use ($argv) {
                        MdRun::run($argv);
                    });
                    check($msg !== '', 'not refused: ' . implode(' ', $argv));
                }
                check(MdRun::run(['mdadm', '--examine', '/dev/disk/by-id/ata-TESTDISK_A'])['code'] === 1, 'a live by-id path must pass');
                foreach (['/etc/passwd', '/home/fixtureuserx/pikkumies/x', '/sys/block/md0/md/array_state', '/etc/mdadm/../shadow'] as $p) {
                    check(mdRefusal(function () use ($p) {
                        MdRun::write($p, 'x');
                    }) !== '', "write to $p not refused");
                }
            });
            check(!array_filter($f->calls, function ($a) {
                return in_array('--assume-clean', $a, true) || $a[0] === 'rm';
            }), 'a refused argv reached the program');
        } finally {
            $f->clean();
        }
        $msg = testWithEnv(['PIKKUMIES_MDRAID_ROOT' => sys_get_temp_dir() . '/pk-none'], function () {
            return mdRefusal(function () {
                MdRun::run(['uname', '-r']);
            });
        });
        check(strpos($msg, 'test tree') !== false, 'with the test root set and no fake, no real program may run: ' . $msg);
    },
];
