<?php
/**
 * Shared by the mdraid tests (ADR-0095): a throwaway machine under PIKKUMIES_MDRAID_ROOT (by-id links, sparse disk
 * files, /sys/block, /proc/mdstat, mdadm.conf) and a fake for every program the tool runs (MdRun::$exec), which keeps
 * the arrays' state so a write verb's read-back sees what it did. No test touches a real disk: with the root prefix set
 * MdRun refuses to start any real program.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/server-admin/mdraid/MdVerbs.php';

if (!class_exists('MdFix')) {
    class MdFix
    {
        const GIB = 1073741824;
        public $root;
        public $disks = [];    // kname => id, serial, size, examine (array|null), sig (blkid TYPE|null), parts (kname list)
        public $arrays = [];   // md => level, devices, uuid, members (kname => slot), faulty (kname list), active, readonly, action, progress, bitmap, fs
        public $release = '6.1.0-52-amd64';
        public $procVersion = 'Linux version 6.1.0-52-amd64 (builder@example.org) #1 SMP PREEMPT_DYNAMIC Debian 6.1.176-1';
        public $fail = [];     // argv prefix (space-joined) => [code, err]
        public $calls = [];   // every argv as the tool ran it (with sudo -n when it used sudo)
        public $busy = [];    // knames another holder has open exclusively (an exclusive open fails)
        public $onExec = null; // callable(argv) run before each fake program, to change the machine mid-verb

        public function __construct()
        {
            $this->root = testTempDir('pk-mdraid');
            foreach (['dev/disk/by-id', 'sys/block', 'proc', 'etc/mdadm', 'run/systemd/system', 'usr/sbin'] as $d) {
                mkdir("$this->root/$d", 0700, true);
            }
            file_put_contents("$this->root/proc/meminfo", "MemTotal:       67108864 kB\n");
            foreach (['proc/mounts', 'proc/swaps', 'etc/fstab', 'etc/crypttab'] as $f) {
                file_put_contents("$this->root/$f", $f === 'proc/swaps' ? "Filename Type Size Used Priority\n" : '');
            }
            file_put_contents("$this->root/etc/mdadm/mdadm.conf", "HOMEHOST <system>\nMAILADDR root\n");
        }

        /** Runs $fn with the fixture as the machine (fake programs; $access: uid0, sudo or none); always restores. */
        public function run(callable $fn, $access = 'uid0')
        {
            file_put_contents("$this->root/proc/version", $this->procVersion . "\n");
            MdRun::$exec = [$this, 'exec'];
            MdRun::$access = $access;
            MdRun::$log = [];
            try {
                return testWithEnv(['PIKKUMIES_MDRAID_ROOT' => $this->root], $fn);
            } finally {
                MdRun::$exec = null;
                MdRun::$access = null;
            }
        }

        public function clean()
        {
            testRmTree($this->root);
        }

        /** A whole disk: by-id link, a sparse file of $gib GiB, /sys/block entry. $data: [offset => bytes] written into it. */
        public function disk($k, $serial, $gib = 8, array $data = [], $sig = null, array $parts = [])
        {
            $id = "ata-TESTDISK_$serial";
            symlink("../../$k", "$this->root/dev/disk/by-id/$id");
            $size = (int) ($gib * self::GIB);
            $h = fopen("$this->root/dev/$k", 'c+');
            ftruncate($h, $size);
            foreach ($data as $off => $bytes) {
                fseek($h, $off);
                fwrite($h, $bytes);
            }
            fclose($h);
            mkdir("$this->root/sys/block/$k/holders", 0700, true);
            file_put_contents("$this->root/sys/block/$k/size", (string) ($size / 512));
            foreach ($parts as $i => $p) {
                mkdir("$this->root/sys/block/$k/$p/holders", 0700, true);
                file_put_contents("$this->root/sys/block/$k/$p/partition", (string) ($i + 1));
                symlink("../../$p", "$this->root/dev/disk/by-id/$id-part" . ($i + 1));
                touch("$this->root/dev/$p");
            }
            $this->disks[$k] = ['id' => $id, 'serial' => $serial, 'size' => $size, 'examine' => null, 'sig' => $sig, 'parts' => $parts];
            return "/dev/disk/by-id/$id";
        }

        /** An array: members [kname => slot]; every member gets a superblock at $events unless $events gives [kname => n]. */
        public function addArray($md, $level, $devices, array $members, $events = 100, array $opts = [])
        {
            $uuid = isset($opts['uuid']) ? $opts['uuid'] : substr(sha1($md), 0, 8) . ':' . substr(sha1($md), 8, 8) . ':' . substr(sha1($md), 16, 8) . ':' . substr(sha1($md), 24, 8);
            $this->arrays[$md] = array_merge(['level' => $level, 'devices' => $devices, 'uuid' => $uuid, 'members' => $members, 'faulty' => [],
                'active' => true, 'readonly' => false, 'action' => 'idle', 'progress' => null, 'bitmap' => $level !== 'raid0', 'fs' => false], $opts);
            foreach ($members as $k => $slot) {
                $this->disks[$k]['examine'] = ['uuid' => $uuid, 'md' => $md, 'level' => $level, 'devices' => $devices, 'role' => $slot,
                    'events' => is_array($events) ? $events[$k] : $events];
            }
            $this->sync();
            return $uuid;
        }

        public function conf($line)
        {
            file_put_contents("$this->root/etc/mdadm/mdadm.conf", $line . "\n", FILE_APPEND);
        }

        /** Writes /proc/mdstat, /sys/block/mdN and the members' holders from $this->arrays. */
        public function sync()
        {
            $text = "Personalities : [raid1] [raid6] [raid5] [raid4] [raid10]\n";
            foreach ($this->disks as $k => $d) {
                foreach ((array) glob("$this->root/sys/block/$k/holders/*") as $h) {
                    rmdir($h);
                }
            }
            foreach ((array) glob("$this->root/sys/block/md*") as $dir) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
            foreach ($this->arrays as $md => $a) {
                $words = [];
                foreach ($a['members'] as $k => $slot) {
                    $words[] = "{$k}[$slot]" . (in_array($k, $a['faulty'], true) ? '(F)' : '') . (!$a['active'] ? '(S)' : '');
                    mkdir("$this->root/sys/block/$k/holders/$md");
                }
                if (!$a['active']) {
                    $text .= "$md : inactive " . implode(' ', $words) . "\n      0 blocks super 1.2\n\n";
                    continue;
                }
                $map = '';
                $up = 0;
                for ($s = 0; $s < $a['devices']; $s++) {
                    $k = array_search($s, $a['members'], true);
                    $ok = $k !== false && !in_array($k, $a['faulty'], true);
                    $map .= $ok ? 'U' : '_';
                    $up += $ok ? 1 : 0;
                }
                $text .= "$md : active" . ($a['readonly'] ? ' (read-only)' : '') . " {$a['level']} " . implode(' ', $words) . "\n";
                $text .= "      1000 blocks super 1.2 [{$a['devices']}/$up] [$map]\n";
                if ($a['action'] !== 'idle') {
                    $text .= "      [==>....]  {$a['action']} = {$a['progress']}% (1/10) finish=1min speed=1K/sec\n";
                }
                $text .= "\n";
                $sys = "$this->root/sys/block/$md/md";
                mkdir("$sys/bitmap", 0700, true);
                mkdir("$this->root/sys/block/$md/queue", 0700, true);
                $files = ['level' => $a['level'], 'raid_disks' => $a['devices'], 'degraded' => $a['devices'] - $up, 'array_state' => $a['readonly'] ? 'readonly' : 'clean',
                    'sync_action' => $a['action'] === 'idle' ? 'idle' : $a['action'], 'component_size' => 8 * 1048576 - 131072, 'bitmap/location' => $a['bitmap'] ? '+8' : 'none',
                    'sync_speed_min' => '1000 (system)', 'sync_speed_max' => '200000 (system)'];
                if (in_array($a['level'], ['raid4', 'raid5', 'raid6'], true)) {
                    $files['stripe_cache_size'] = isset($a['stripe_cache']) ? $a['stripe_cache'] : 256;
                }
                foreach ($files as $f => $v) {
                    file_put_contents("$sys/$f", "$v\n");
                }
                file_put_contents("$this->root/sys/block/$md/queue/read_ahead_kb", "128\n");
                foreach ($a['members'] as $k => $slot) {
                    mkdir("$sys/dev-$k");
                    file_put_contents("$sys/dev-$k/slot", in_array($k, $a['faulty'], true) ? "none\n" : "$slot\n");
                    file_put_contents("$sys/dev-$k/state", in_array($k, $a['faulty'], true) ? "faulty\n" : "in_sync\n");
                }
            }
            file_put_contents("$this->root/proc/mdstat", $text . "unused devices: <none>\n");
        }

        private function kOf($path)
        {
            $l = @readlink("$this->root$path");
            return $l === false ? null : basename($l);
        }

        private function examineText(array $e)
        {
            return "          Magic : a92b4efc\n        Version : 1.2\n     Array UUID : {$e['uuid']}\n           Name : host:" . substr($e['md'], 2) . "  (local to host host)\n"
                . "     Raid Level : {$e['level']}\n   Raid Devices : {$e['devices']}\n    Update Time : Tue Oct 20 10:00:00 2026\n         Events : {$e['events']}\n"
                . '    Device Role : ' . ($e['role'] === 'spare' ? 'spare' : "Active device {$e['role']}") . "\nInternal Bitmap : 8 sectors from superblock\n";
        }

        /** The fake for every program MdRun runs. */
        public function exec(array $argv)
        {
            $this->calls[] = $argv;
            if ($argv[0] === 'sudo') {
                check($argv[1] === '-n', 'sudo must run with -n (never a password prompt): ' . implode(' ', $argv));
                $argv = array_slice($argv, 2);
            }
            if ($this->onExec !== null) {
                call_user_func($this->onExec, $argv);
            }
            $line = implode(' ', $argv);
            foreach ($this->fail as $prefix => $r) {
                if (strpos($line, $prefix) === 0) {
                    return ['code' => $r[0], 'out' => '', 'err' => $r[1]];
                }
            }
            $last = end($argv);
            switch ($argv[0]) {
                case 'uname':
                    return ['code' => 0, 'out' => "$this->release\n"];
                case 'lsblk':
                    $devs = [];
                    foreach ($this->disks as $k => $d) {
                        $devs[] = ['name' => $k, 'serial' => $d['serial'], 'size' => $d['size'], 'model' => 'TESTDISK', 'rota' => true];
                    }
                    return ['code' => 0, 'out' => json_encode(['blockdevices' => $devs])];
                case 'blkid':
                    if (preg_match('#^/dev/(md[0-9]+)$#', $last, $m)) {
                        return isset($this->arrays[$m[1]]) && $this->arrays[$m[1]]['fs'] ? ['code' => 0, 'out' => "UUID=fs-$m[1]\nTYPE=ext4\n"] : ['code' => 2, 'out' => ''];
                    }
                    $k = $this->kOf($last);
                    return isset($this->disks[$k]) && $this->disks[$k]['sig'] !== null ? ['code' => 0, 'out' => "TYPE={$this->disks[$k]['sig']}\n"] : ['code' => 2, 'out' => ''];
                case 'wipefs':
                    $k = $this->kOf($last);
                    if (in_array('-a', $argv, true) && in_array($k, $this->busy, true)) {
                        return ['code' => 1, 'out' => '', 'err' => "wipefs: error: $last: probing initialization failed: Device or resource busy"];
                    }
                    if (in_array('-a', $argv, true) && in_array('-n', $argv, true)) {
                        return ['code' => 0, 'out' => ''];
                    }
                    if (in_array('-a', $argv, true)) {
                        if (isset($this->disks[$k])) {
                            $this->disks[$k]['sig'] = null;
                        }
                        return ['code' => 0, 'out' => ''];
                    }
                    return ['code' => 0, 'out' => isset($this->disks[$k]) && $this->disks[$k]['sig'] !== null ? "0x438,{$this->disks[$k]['sig']}\n" : ''];
                case 'mkfs.ext4':
                    if (!in_array('-n', $argv, true)) {
                        $this->arrays[substr($last, 5)]['fs'] = true;
                    }
                    return ['code' => 0, 'out' => ''];
                case 'cp': // the sudo write path: a private temp file copied onto the target
                    $src = $argv[count($argv) - 2];
                    $ok = copy($src, $last);
                    if ($ok && in_array('--preserve=mode', $argv, true)) {
                        chmod($last, fileperms($src) & 0777);
                    }
                    return ['code' => $ok ? 0 : 1, 'out' => ''];
                case 'find': // the sudo path of the open-file scan: find /proc ... -printf '%p %l\n'
                    $out = '';
                    foreach ((array) glob("$this->root/proc/[0-9]*/fd/*") as $fd) {
                        $out .= substr($fd, strlen($this->root)) . ' ' . readlink($fd) . "\n";
                    }
                    return ['code' => 0, 'out' => $out];
                case 'mkdir':
                    return ['code' => is_dir($last) || mkdir($last, 0700, true) ? 0 : 1, 'out' => ''];
                case 'cat':
                    return ['code' => 0, 'out' => (string) file_get_contents($last)];
                case 'dd': // the sudo read path for disk samples
                    $o = [];
                    foreach (array_slice($argv, 1) as $a) {
                        list($k, $v) = explode('=', $a, 2);
                        $o[$k] = $v;
                    }
                    $h = @fopen($o['if'], 'rb');
                    if ($h === false) {
                        return ['code' => 1, 'out' => '', 'err' => 'dd: cannot open'];
                    }
                    fseek($h, (int) $o['skip']);
                    $buf = (string) fread($h, (int) $o['bs']);
                    fclose($h);
                    return ['code' => 0, 'out' => $buf];
                case 'update-initramfs':
                    return ['code' => 0, 'out' => ''];
                case 'systemctl':
                    return ['code' => 0, 'out' => $argv[1] === 'is-active' ? "active\n" : ''];
                case 'mdadm':
                    return $this->mdadm($argv);
            }
            return ['code' => 1, 'out' => '', 'err' => 'fake: unknown program'];
        }

        private function mdadm(array $argv)
        {
            $last = end($argv);
            if ($argv[1] === '--examine' && in_array('--scan', $argv, true)) {
                $sets = [];
                foreach ($this->disks as $k => $d) {
                    if ($d['examine'] !== null) {
                        $sets[$d['examine']['uuid']][] = $k;
                    }
                }
                $out = '';
                foreach ($sets as $uuid => $ks) {
                    $e = $this->disks[$ks[0]]['examine'];
                    $out .= "ARRAY /dev/md/" . substr($e['md'], 2) . " level={$e['level']} metadata=1.2 num-devices={$e['devices']} UUID=$uuid name=host:" . substr($e['md'], 2) . "\n   devices=/dev/" . implode(',/dev/', $ks) . "\n";
                }
                return ['code' => 0, 'out' => $out];
            }
            if ($argv[1] === '--examine') {
                $k = $this->kOf($last);
                return isset($this->disks[$k]) && $this->disks[$k]['examine'] !== null ? ['code' => 0, 'out' => $this->examineText($this->disks[$k]['examine'])]
                    : ['code' => 1, 'out' => '', 'err' => "mdadm: No md superblock detected on $last."];
            }
            if ($argv[1] === '--detail') {
                $md = substr($last, 5);
                if (!isset($this->arrays[$md]) || !$this->arrays[$md]['active']) {
                    return ['code' => 1, 'out' => ''];
                }
                $a = $this->arrays[$md];
                return ['code' => 0, 'out' => in_array('--export', $argv, true)
                    ? "MD_LEVEL={$a['level']}\nMD_DEVICES={$a['devices']}\nMD_UUID={$a['uuid']}\nMD_NAME=host:" . substr($md, 2) . "\n"
                    : "ARRAY /dev/$md metadata=1.2 name=host:" . substr($md, 2) . " UUID={$a['uuid']}\n"];
            }
            if ($argv[1] === '--zero-superblock') {
                $this->disks[$this->kOf($last)]['examine'] = null;
                return ['code' => 0, 'out' => ''];
            }
            if ($argv[1] === '--stop') {
                unset($this->arrays[substr($last, 5)]);
                $this->sync();
                return ['code' => 0, 'out' => ''];
            }
            if ($argv[1] === '--readwrite') {
                $this->arrays[substr($last, 5)]['readonly'] = false;
                $this->sync();
                return ['code' => 0, 'out' => ''];
            }
            if ($argv[1] === '--create') {
                $md = substr($argv[2], 5);
                preg_match('/--level=(\S+)/', implode(' ', $argv), $lv);
                $members = [];
                foreach (preg_grep('#^/dev/disk/by-id/#', $argv) as $p) {
                    $members[$this->kOf($p)] = count($members);
                }
                $this->addArray($md, $lv[1], count($members), $members, 1, ['action' => 'resync', 'progress' => 0.1]);
                return ['code' => 0, 'out' => ''];
            }
            if ($argv[1] === '--assemble') {
                $md = substr(preg_grep('#^/dev/md[0-9]+$#', $argv) ? array_values(preg_grep('#^/dev/md[0-9]+$#', $argv))[0] : '', 5);
                $members = [];
                $first = null;
                foreach (preg_grep('#^/dev/disk/by-id/#', $argv) as $p) {
                    $k = $this->kOf($p);
                    $members[$k] = $this->disks[$k]['examine']['role'];
                    $first = $this->disks[$k]['examine'];
                }
                $this->arrays[$md] = ['level' => $first['level'], 'devices' => $first['devices'], 'uuid' => $first['uuid'], 'members' => $members, 'faulty' => [],
                    'active' => true, 'readonly' => in_array('--readonly', $argv, true), 'action' => 'idle', 'progress' => null, 'bitmap' => true, 'fs' => true];
                $this->sync();
                return ['code' => 0, 'out' => ''];
            }
            // manage mode: mdadm /dev/mdN --op dev [--op dev ...]
            $md = substr($argv[1], 5);
            for ($i = 2; $i < count($argv); $i += 2) {
                $k = $this->kOf($argv[$i + 1]);
                $a = &$this->arrays[$md];
                if ($argv[$i] === '--remove') {
                    unset($a['members'][$k]);
                    $a['faulty'] = array_values(array_diff($a['faulty'], [$k]));
                } elseif ($argv[$i] === '--re-add') {
                    $a['members'][$k] = $this->disks[$k]['examine']['role'];
                    $a['action'] = 'recovery';
                    $a['progress'] = 1.0;
                } elseif ($argv[$i] === '--add') {
                    $free = 0;
                    while (in_array($free, $a['members'], true)) {
                        $free++;
                    }
                    $a['members'][$k] = $free;
                    $this->disks[$k]['examine'] = ['uuid' => $a['uuid'], 'md' => $md, 'level' => $a['level'], 'devices' => $a['devices'], 'role' => $free, 'events' => 1];
                } elseif ($argv[$i] === '--replace') {
                    $i += 2; // --with <new> follows; the new disk is already a spare in this fake
                }
                unset($a);
            }
            $this->sync();
            return ['code' => 0, 'out' => ''];
        }

        /** The argv lists the tool ran that change something (for "nothing was done" checks). */
        public function writes()
        {
            return array_values(array_filter($this->calls, function ($a) {
                $l = implode(' ', $a);
                return !preg_match('/^(sudo -n )?(uname|lsblk|blkid|systemctl is-active|wipefs -n|wipefs -a -n|mdadm --examine|mdadm --detail|dd |cat |find )/', $l);
            }));
        }
    }

    /** Runs $fn and returns the CliError message it throws, or fails when it does not throw. */
    function mdRefusal(callable $fn)
    {
        try {
            $fn();
        } catch (CliError $e) {
            return $e->getMessage();
        }
        throw new Exception('expected a refusal, got none');
    }
}
