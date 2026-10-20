<?php
/**
 * The delete guard on raw drive writes (ADR-0094, GuardDrives.php): badblocks with -w in any flag cluster, hdparm
 * --write-sector / --security-erase / --make-bad-sector and `repair` written to an md sync_action are refused outright;
 * the read and non-destructive forms run unasked; the drives tool's selftest is asked as a load and its verify with
 * --with-data as a delete. guardKindTable() is defined in HookGuardContainersTest.php; this file loads it when run alone.
 */
if (!function_exists('guardKindTable')) {
    require __DIR__ . '/HookGuardContainersTest.php';
}

$d = 'php ~/pikkumies/tools/hardware/drives/drives.php';
return [
    'drives guard: raw drive writes are refused outright, in every flag cluster and spelling' => function () {
        guardKindTable([
            ['badblocks -wsv /dev/sdb', 'block', 'raw badblocks write test'],
            ['sudo badblocks -svw -b 4096 /dev/sdb', 'block', 'raw badblocks write test'],
            ['badblocks -b 4096 -w /dev/sdb', 'block', 'raw badblocks write test'],
            ['nohup badblocks -wv -b 4096 /dev/sdb 100 100 &', 'block', 'raw badblocks write test'],
            ['hdparm --write-sector 123456 --yes-i-know-what-i-am-doing /dev/sdb', 'block', 'raw write or erase'],
            ['sudo hdparm --user-master u --security-erase p /dev/sdb', 'block', 'raw write or erase'],
            ['hdparm --security-erase-enhanced p /dev/sdb', 'block', 'raw write or erase'],
            ['hdparm --make-bad-sector 1000 --yes-i-know-what-i-am-doing /dev/sdb', 'block', 'raw write or erase'],
            ['echo repair > /sys/block/md0/md/sync_action', 'block', 'md repair'],
            ['echo repair | sudo tee /sys/block/md127/md/sync_action', 'block', 'md repair'],
            ['echo "repair" > /sys/block/md0/md//sync_action', 'block', 'md repair'],
        ]);
    },
    'drives guard: the non-destructive and read forms run unasked' => function () {
        guardKindTable([
            ['badblocks -nsv /dev/sdb', 'none'],
            ['badblocks -sv -b 4096 /dev/sdw', 'none'],
            ['hdparm -I /dev/sdb', 'none'],
            ['smartctl -t long /dev/sdb', 'none'],
            ['smartctl -a /dev/sdb', 'none'],
            ['echo check > /sys/block/md0/md/sync_action', 'none'],
            ['cat /sys/block/md0/md/sync_action', 'none'],
            ['mdadm --detail /dev/md0', 'none'],
        ]);
    },
    'drives guard: the drives tool\'s selftest is asked as a load, its verify --with-data as a delete, smart unasked' => function () use ($d) {
        guardKindTable([
            ["$d selftest ZA1234AB long", 'approve', 'load'],
            ["$d verify ZA1234AB --with-data", 'approve', 'delete'],
            ["$d verify ZA1234AB --fix-unreadable --with-data", 'approve', 'delete'],
            ["$d smart --human", 'none'],
            ["$d verify ZA1234AB", 'none'],
        ]);
    },
    'drives guard: other whole-device writes are refused: discard, NVMe and SCSI format or sanitize, mkswap on a device, redirects and tee onto a disk' => function () {
        guardKindTable([
            ['blkdiscard /dev/sdb', 'block', 'discard'],
            ['sudo blkdiscard -f /dev/nvme0n1', 'block', 'discard'],
            ['nvme format /dev/nvme0n1', 'block', 'format'],
            ['nvme sanitize -a 2 /dev/nvme0', 'block', 'format'],
            ['nvme write-zeroes /dev/nvme0n1 -s 0 -c 100', 'block', 'format'],
            ['sg_format --format /dev/sg1', 'block', 'format'],
            ['sg_sanitize --overwrite /dev/sg1', 'block', 'format'],
            ['mkswap /dev/sdb', 'block', 'swap'],
            ['cat /dev/zero > /dev/sdb', 'block', 'onto a disk'],
            ['pv /dev/zero >/dev/nvme0n1', 'block', 'onto a disk'],
            ['cp /dev/zero /dev/sdb', 'block', 'onto a disk'],
            ['head -c 1G /dev/urandom | sudo tee /dev/sdb >/dev/null', 'block', 'onto a disk'],
            ['echo x | tee -a /dev/md0', 'block', 'onto a disk'],
            ['hdparm --trim-sector-ranges 0:65535 --please-destroy-my-drive /dev/sdb', 'block', 'raw write or erase'],
            ['hdparm --security-set-pass p /dev/sdb', 'block', 'raw write or erase'],
            ['mdadm --action=repair /dev/md0', 'block', 'mdadm that changes an array'], // mdadm syntax: GuardDisk.php owns it (ADR-0095)
            ['cd /sys/block/md0/md && echo repair > sync_action', 'block', 'md repair'],
            ['printf repair > /sys/devices/virtual/block/md0/md/sync_action', 'block', 'md repair'],
        ]);
        guardKindTable([["import subprocess; subprocess.run(['badblocks','-w','/dev/sdb'])", 'block', 'raw badblocks write test']], 'execute_code');
    },
    'drives guard: reads, files and the words alone are not refused' => function () {
        guardKindTable([
            ['grep -r repair /sys/block/md0/md/sync_action', 'none'],
            ['cat /sys/block/md0/md/sync_action; echo "run repair later"', 'none'],
            ['mkswap /swapfile', 'none'],
            ['cp /dev/sdb /tmp/disk.img', 'none'],
            ['ls /dev/sd* 2>/dev/null', 'none'],
            ['nvme list --output-format json', 'none'],
            ['nvme smart-log /dev/nvme0', 'none'],
            ['badblocks -nsv -o /tmp/list.www /dev/sdb', 'none'],
            ['badblocks -nsv /dev/disk/by-id/ata-WDC_WD40EFRX-68N32N0_WD-WCC7K0 -e 5', 'none'],
        ]);
    },
];
