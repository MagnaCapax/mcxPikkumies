<?php
/**
 * The delete guard on raw disk and md RAID commands (hooks/guard/guard-destructive/GuardDisk.php, ADR-0095): every
 * disk-destroying or array-changing command typed in the terminal is refused outright and names the mdraid verb;
 * reads (mdadm --detail/--examine, cat /proc/mdstat, e2fsck -n, xfs_repair -n) run unasked. mdadm is read word by
 * word: the mode from argv (short, long, combined and abbreviated forms, implicit manage mode), behind sudo, a path,
 * env or mdadm.static. guardEdgeExpect() is defined in HookGuardEdgeTest.php; this file loads it when run alone.
 */
if (!function_exists('guardEdgeExpect')) {
    require __DIR__ . '/HookGuardEdgeTest.php';
}

return [
    'disk guard: mke2fs and the other formatters the mkfs rule missed are refused (the 2026-10-20 gap)' => function () {
        guardEdgeExpect(['mke2fs -t ext4 /dev/sdb1', 'mke2fs /dev/md0', 'sudo mke2fs -F /dev/sdb', '/sbin/mke2fs -q /dev/sdc',
            'mkntfs -f /dev/sdb1', 'mkdosfs -F 32 /dev/sdb1', 'mkexfatfs /dev/sdb1', 'mkreiserfs /dev/sdb1', 'mkudffs /dev/sdb'], 'block', 'filesystem format');
        guardEdgeExpect(['mkswap /dev/sdb2', 'sudo mkswap -f /dev/disk/by-id/ata-X-part2'], 'block', 'mkswap on a device'); // owned by the drives guard (GuardDrives.php, ADR-0094): refused there, with its own reason
        guardEdgeExpect(['blkdiscard /dev/nvme1n1', 'sudo blkdiscard -f /dev/sdb'], 'block', 'whole-device discard'); // owned by the drives guard (GuardDrives.php, ADR-0094): refused there, with its own reason
        guardEdgeExpect(['cryptsetup luksFormat /dev/sdb', 'cryptsetup -q luksFormat --type luks2 /dev/sdb', 'cryptsetup erase /dev/sdb', 'cryptsetup luksErase /dev/sdb'], 'block', 'encrypted volume');
        guardEdgeExpect(['pvcreate /dev/sdb', 'sudo pvcreate -ff /dev/sdc'], 'block', 'LVM physical volume');
        guardEdgeExpect(['mkswap ~/swapfile', 'cryptsetup status cryptdata', 'cryptsetup open /dev/sdb data', 'pvs', 'lsblk -f'], 'none');
    },
    'disk guard: filesystem repair is refused unless it is read-only (-n)' => function () {
        guardEdgeExpect(['e2fsck -y /dev/md0', 'e2fsck -p /dev/md0', 'e2fsck -f /dev/md0', 'fsck /dev/md0', 'fsck -y /dev/sdb1', 'sudo fsck.ext4 -fy /dev/md0',
            'fsck.ext4 /dev/md0', 'e2fsck -n -c /dev/md0', 'e2fsck -fn -E discard /dev/md0', 'xfs_repair /dev/md0', 'xfs_repair -L /dev/md0', 'sudo /usr/sbin/xfs_repair /dev/md1'], 'block', 'filesystem repair without -n');
        guardEdgeExpect(['e2fsck -n /dev/md0', 'e2fsck -fn /dev/md0', 'sudo e2fsck -f -n /dev/md0', 'fsck -n /dev/md0', 'fsck.ext4 -fn /dev/md0', 'fsck -N /dev/sdb1',
            'xfs_repair -n /dev/md0', 'man e2fsck', 'apt-get install e2fsprogs', 'cat /etc/fstab'], 'none');
    },
    'disk guard: mdadm write modes are refused in long, short, combined, abbreviated and implicit forms' => function () {
        $why = 'mdadm that changes an array or its members';
        guardEdgeExpect([
            'mdadm --create /dev/md0 --level=1 --raid-devices=2 /dev/sdb /dev/sdc', 'mdadm -C /dev/md0 -l1 -n2 /dev/sdb /dev/sdc', 'mdadm -Cv /dev/md0 -l5 -n3 /dev/sd[bcd]',
            'mdadm --cr /dev/md0 -l1 -n2 /dev/sdb /dev/sdc', 'mdadm --build /dev/md0 -l0 -n2 /dev/sdb /dev/sdc', 'mdadm -G /dev/md0 -n4', 'mdadm --grow /dev/md0 --level=6',
            'mdadm --zero-superblock /dev/sdb', 'mdadm --zero /dev/sdb', 'mdadm --misc --zero-superblock --force /dev/sdb',
            'mdadm /dev/md0 --fail /dev/sdb', 'mdadm /dev/md0 -f /dev/sdb', 'mdadm /dev/md0 -r /dev/sdb', 'mdadm /dev/md0 -fr /dev/sdb', 'mdadm /dev/md0 --remove failed',
            'mdadm /dev/md0 --add /dev/sdd', 'mdadm /dev/md0 -a /dev/sdd', 'mdadm --manage /dev/md0 --add /dev/sdd', 'mdadm --add /dev/md0 /dev/sdd',
            'mdadm /dev/md0 --re-add /dev/sdb', 'mdadm --re-add /dev/md0 /dev/sdb', 'mdadm /dev/md0 --replace /dev/sdb --with /dev/sdd', 'mdadm /dev/md0 --set-faulty /dev/sdb',
            'mdadm /dev/md0 --add-spare /dev/sdd', 'mdadm /dev/md0 --write-mostly /dev/sdb', 'mdadm /dev/md0 -W /dev/sdb',
            'mdadm --assemble --force /dev/md0 /dev/sdb /dev/sdc', 'mdadm -Af /dev/md0 /dev/sdb /dev/sdc', 'mdadm -A -f /dev/md0 /dev/sdb', 'mdadm --assemble --run /dev/md0 /dev/sdb',
            'mdadm -AR /dev/md0 /dev/sdb', 'mdadm --assemble --update=resync /dev/md0 /dev/sdb', 'mdadm -A -U name /dev/md0 /dev/sdb', 'mdadm --assemble --invalid-backup /dev/md0 /dev/sdb',
            'mdadm --create /dev/md0 --assume-clean -l5 -n3 /dev/sdb /dev/sdc /dev/sdd', 'mdadm --create /dev/md0 --write-zeroes -l1 -n2 /dev/sdb /dev/sdc',
            'mdadm --run /dev/md0', 'mdadm -R /dev/md0', 'mdadm --readwrite /dev/md0', 'mdadm -w /dev/md0', 'mdadm --action=repair /dev/md0', 'mdadm --action repair /dev/md0',
            'mdadm --action=check /dev/md0', 'mdadm --action=frozen /dev/md0', 'mdadm --restore=/root/dump /dev/sdb', 'mdadm --incremental /dev/sdb', 'mdadm -I --fail /dev/sdb',
            'mdadm --kill-subarray=0 /dev/md127', 'mdadm --update-subarray=0 --update=name /dev/md127', 'mdadm --auto-detect',
            'sudo mdadm --create /dev/md0 -l1 -n2 /dev/sdb /dev/sdc', '/sbin/mdadm --zero-superblock /dev/sdb', 'sudo /usr/sbin/mdadm /dev/md0 --fail /dev/sdb',
            'mdadm.static --zero-superblock /dev/sdb', 'env LANG=C mdadm --create /dev/md0 -l1 -n2 /dev/sdb /dev/sdc', 'echo ok && mdadm /dev/md0 --add /dev/sdd',
            'm"d"adm --zero-superblock /dev/sdb', "subprocess.run(['mdadm', '--zero-superblock', '/dev/sdb'])",
            'mdadm --bogus-option /dev/md0', 'mdadm --as /dev/md0 /dev/sdb',
        ], 'block', $why);
    },
    'disk guard: mdadm reads, stop, plain assemble, readonly, idle and monitor run unasked' => function () {
        guardEdgeExpect([
            'mdadm --detail /dev/md0', 'mdadm -D /dev/md0', 'sudo mdadm --detail --scan', 'mdadm --detail --brief /dev/md0', 'mdadm -Ds', 'mdadm --detail --export /dev/md0',
            'mdadm --examine /dev/sdb', 'mdadm -E /dev/sdb', 'mdadm --examine --scan', 'mdadm -Es', 'mdadm --examine-badblocks /dev/sdb', 'mdadm --query /dev/sdb', 'mdadm -Q /dev/md0',
            'mdadm -X /dev/sdb', 'mdadm --detail --test /dev/md0', 'mdadm --version', 'mdadm -V', 'mdadm --help', 'mdadm --dump=/root/md-dump /dev/sdb',
            'mdadm --stop /dev/md127', 'mdadm -S /dev/md127', 'mdadm --readonly /dev/md0', 'mdadm --action=idle /dev/md0', 'mdadm --wait /dev/md0',
            'mdadm --assemble /dev/md0 /dev/sdb /dev/sdc', 'mdadm --assemble --scan', 'mdadm -As', 'mdadm --assemble --readonly /dev/md0 /dev/sdb /dev/sdc',
            'mdadm --monitor --scan --oneshot', 'mdadm -F -1 -s', 'mdadm --monitor --scan --test --oneshot',
            'cat /proc/mdstat', 'cat /sys/block/md0/md/sync_action', 'cat /sys/block/md0/md/mismatch_cnt 2>/dev/null', 'grep -r . /sys/block/md0/md/stripe_cache_size',
            'cat /etc/mdadm/mdadm.conf', 'man mdadm', 'apt-get install mdadm', 'systemctl status mdmonitor', 'ls /dev/md*', 'which mdadm',
        ], 'none');
    },
    'disk guard: raw writes to md control files are refused; the mdraid tool is the way' => function () {
        guardEdgeExpect(['echo active > /sys/block/md0/md/array_state', 'echo 32768 >/sys/block/md0/md/stripe_cache_size',
            "file_put_contents('/sys/block/md0/md/sync_action', 'repair');", 'cd /sys/block/md0/md && echo frozen > sync_action'], 'block', 'raw write to an md array control file');
        guardEdgeExpect(['echo repair > /sys/block/md0/md/sync_action'], 'block', 'md repair'); // owned by the drives guard (GuardDrives.php, ADR-0094): refused there, with its own reason
        // the scrub (check) and stopping it (idle) run unasked: the drives guard allows them (ADR-0094)
        guardEdgeExpect(['echo check | sudo tee /sys/block/md0/md/sync_action', "sh -c 'echo idle > /sys/class/block/md1/md/sync_action'", 'printf idle | tee /sys/devices/virtual/block/md2/md/sync_action'], 'none');
    },
    'disk guard: every refusal names the mdraid tool and keeps the refused-outright wording' => function () {
        foreach (['mke2fs /dev/sdb', 'mdadm --zero-superblock /dev/sdb', 'e2fsck -y /dev/md0', 'echo active > /sys/block/md0/md/array_state', 'mdadm --action=repair /dev/md0'] as $cmd) {
            list(, $d) = guardEdgeRun(json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
            check(is_array($d) && $d['action'] === 'block', "$cmd not blocked");
            check(strpos($d['message'], 'mdraid') !== false && strpos($d['message'], 'Refused outright, not asked') !== false, "$cmd: message must name mdraid and say refused outright: " . $d['message']);
        }
    },
    'disk guard: GuardDisk parses mdadm argv on its own (mode, prefix abbreviations, values that are consumed)' => function () {
        require_once dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/GuardDisk.php';
        check(GuardDisk::mdadmWhy(['--detail', '--scan']) === null, 'detail scan must be allowed');
        check(GuardDisk::mdadmWhy(['--config', '--zero-superblock', '--detail', '/dev/md0']) === null, '--config consumes its value: "--zero-superblock" there is a file name');
        check(GuardDisk::mdadmWhy(['-c', '/etc/x.conf', '-As']) === null, 'short -c consumes the next word');
        check(GuardDisk::mdadmWhy(['-c/etc/x.conf', '-As']) === null, 'short -c takes the rest of its word');
        check(GuardDisk::mdadmWhy(['--zero']) !== null, 'a unique prefix of --zero-superblock is --zero-superblock');
        check(GuardDisk::mdadmWhy(['--ass', '/dev/md0']) !== null, 'an ambiguous prefix (assemble, assume-clean) cannot be checked');
        check(GuardDisk::mdadmWhy(['/dev/md0']) === null, 'a device alone is manage mode with nothing to do');
        check(GuardDisk::mdadmWhy(['/dev/md0', '-f', '/dev/sdb']) !== null, '-f in manage mode is --fail');
        check(GuardDisk::mdadmWhy(['--monitor', '-f', '-r', '60']) === null, '-f in monitor mode is --daemonise, -r takes the increment');
        check(GuardDisk::mdadmWhy(['--detail', '-Z5']) !== null, '--array-size belongs to grow and is refused');
    },
    'disk guard (review M-4): the mke2fs siblings are refused: other partitioners, whole-disk erasers, plain writes to a device, other formatters, device removal' => function () {
        guardEdgeExpect(['sfdisk /dev/sdb < layout', 'cfdisk /dev/sdb', 'gdisk /dev/sdb', 'cgdisk /dev/sdb'], 'block', 'partition table change');
        // owned by the drives guard (GuardDrives.php, ADR-0094): refused there, with its own reason
        guardEdgeExpect(['badblocks -wsv /dev/sdb', 'sudo badblocks -w /dev/sdb'], 'block', 'raw badblocks write test');
        guardEdgeExpect(['nvme format /dev/nvme1n1', 'nvme sanitize /dev/nvme1 -a 2', 'sg_format --format /dev/sg2', 'sg_sanitize --crypto /dev/sg2'], 'block', 'drive format or sanitize');
        guardEdgeExpect(['hdparm --security-erase p /dev/sdb', 'hdparm --user-master u --security-erase-enhanced p /dev/sdb'], 'block', 'raw write or erase on a drive');
        guardEdgeExpect(['cat x > /dev/sdb', "sh -c 'cat /dev/zero > /dev/sdb'", 'cp /dev/zero /dev/sdb', 'pv img.raw > /dev/disk/by-id/ata-X', 'cat img | tee /dev/sdb',
            'qemu-img convert -O raw disk.qcow2 /dev/sdb', 'cat img >> /dev/nvme0n1', 'cp img /dev/mapper/vg-lv'], 'block', 'output written onto a disk');
        guardEdgeExpect(['make-bcache -B /dev/sdb', 'bcachefs format /dev/sdb', 'zpool create tank /dev/sdb', 'zpool labelclear -f /dev/sdb'], 'block', 'filesystem format');
        guardEdgeExpect(['echo 1 > /sys/block/sdb/device/delete', 'echo 1 | tee /sys/class/block/sdb/device/delete'], 'block', 'removes a disk');
        guardEdgeExpect(['cd /sys/block/md0/md; echo active > array_state', 'cd /sys/block/md0 && echo frozen > md/sync_action', 'echo 4096 > /sys/dev/block/9:0/md/stripe_cache_size'], 'block', 'raw write to an md array control file');
        guardEdgeExpect(['cd /sys/block/md0 && echo repair > md/sync_action', 'echo repair > /sys/dev/block/9:0/md/sync_action'], 'block', 'md repair'); // owned by the drives guard (GuardDrives.php, ADR-0094): refused there, with its own reason
        guardEdgeExpect(['cd /sys/class/block && echo check > md0/md/sync_action'], 'none'); // a scrub: allowed by the drives guard
        guardEdgeExpect(['badblocks -sv /dev/sdb', 'cp /dev/sdb ~/disk.img', 'cat /dev/sdb > ~/disk.img', 'qemu-img convert -O qcow2 /dev/sdb ~/disk.qcow2', 'nvme list', 'nvme smart-log /dev/nvme0',
            'hdparm -I /dev/sdb', 'zpool status', 'sfdisk -l /dev/sdb', 'gdisk -l /dev/sdb', 'cat /sys/block/sdb/device/model', 'grep . /sys/block/md0/md/sync_action; echo done > ~/log.txt',
            'echo hi 2>/dev/null', 'ls /dev/sdb 2>&1 > ~/out.txt'], 'none');
    },
];
