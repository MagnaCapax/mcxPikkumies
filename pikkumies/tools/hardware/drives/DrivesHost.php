<?php
/**
 * DrivesHost: the machine around the drives. Which disks exist (lsblk, as the account: the udev view, no disk woken),
 * what each one is used for (its array role, checked first because it decides how a drive may be written: md member,
 * device-mapper holder, mounted, swap, an array or volume signature, other data, blank), whether an md array is busy
 * resyncing or checking, whether this is a VM, and running smartctl as root. $seam: proc, sys, root, virt (tests).
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class DrivesHost
{
    /** Signatures of a device that belongs to an array or volume (assembled or not): never written by hand. */
    const MEMBER_FS = ['linux_raid_member', 'LVM2_member', 'zfs_member', 'bcache', 'ceph_bluestore', 'drbd', 'isw_raid_member', 'ddf_raid_member', 'promise_fasttrack_raid_member', 'silicon_medley_raid_member', 'nvidia_raid_member', 'adaptec_raid_member', 'hpt45x_raid_member', 'jmicron_raid_member', 'via_raid_member', 'lsi_mega_raid_member'];

    private $proc;
    private $sys;
    private $root;
    private $virt;

    public function __construct(array $seam = [])
    {
        $this->proc = isset($seam['proc']) ? $seam['proc'] : '/proc';
        $this->sys = isset($seam['sys']) ? $seam['sys'] : '/sys';
        $this->root = isset($seam['root']) ? $seam['root'] : null;
        $this->virt = isset($seam['virt']) ? $seam['virt'] : null;
    }

    /**
     * ['available' => bool, 'via' => uid0|sudo|none]: the live check Detect::rootAccess() makes for this process (real
     * uid 0 with full capabilities, or passwordless sudo), never the cached host.json, which is only a hint (ADR-0004);
     * none when the user turned root off (root.disabled).
     */
    public function root()
    {
        if ($this->root === null) {
            $config = Config::load();
            $euid = function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));
            $this->root = !empty($config['root']['disabled']) ? ['available' => false, 'via' => 'none'] : Detect::rootAccess($euid);
        }
        return $this->root;
    }

    /** The disks lsblk lists (type disk), each with its partitions' signatures and mount points. */
    public function disks()
    {
        ToolProc::need('lsblk', 'util-linux provides it');
        $r = ToolProc::run(['lsblk', '-J', '-b', '-o', 'NAME,SERIAL,WWN,MODEL,SIZE,ROTA,TYPE,TRAN,FSTYPE,PTTYPE,MOUNTPOINT']);
        $j = json_decode($r['out'], true);
        if ($r['code'] !== 0 || !is_array($j) || !isset($j['blockdevices'])) {
            throw new CliError('lsblk could not list the disks', trim($r['err']) ?: 'run lsblk yourself to see why');
        }
        $out = [];
        foreach ($j['blockdevices'] as $b) {
            if (($b['type'] ?? '') !== 'disk' || preg_match('/^(zram|ram|loop|nbd)/', (string) $b['name'])) {
                continue;
            }
            $parts = [];
            foreach ($b['children'] ?? [] as $c) {
                $parts[] = ['name' => (string) $c['name'], 'fstype' => $c['fstype'] ?? null, 'mounted' => !empty($c['mountpoint'])];
            }
            $out[] = ['device' => '/dev/' . $b['name'], 'name' => (string) $b['name'], 'serial' => self::str($b['serial'] ?? null),
                'wwn' => self::str($b['wwn'] ?? null), 'model' => self::str($b['model'] ?? null),
                'bytes' => is_numeric($b['size'] ?? null) ? (int) $b['size'] : null,
                'rotational' => isset($b['rota']) ? in_array($b['rota'], [true, 1, '1'], true) : null,
                'tran' => self::str($b['tran'] ?? null), 'fstype' => self::str($b['fstype'] ?? null), 'pttype' => self::str($b['pttype'] ?? null),
                'mounted' => !empty($b['mountpoint']), 'parts' => $parts];
        }
        return $out;
    }

    /**
     * [role, detail]. Checked in this order, worst first: array-member (named in /proc/mdstat or held by md), holder
     * (device mapper: LVM, dm-crypt, multipath), mounted, swap, array-signature (a member signature on a device no
     * running array holds), data (another filesystem or a partition table), blank.
     */
    public function role(array $disk)
    {
        $names = array_merge([$disk['name']], array_column($disk['parts'], 'name'));
        $mdstat = (string) @file_get_contents("$this->proc/mdstat");
        foreach ($names as $n) {
            if (preg_match('/^(md\S*)\s*:.*\b' . preg_quote($n, '/') . '\[\d+\]/m', $mdstat, $m)) {
                return ['array-member', "$n is in $m[1]"];
            }
        }
        foreach ($names as $n) {
            $dir = $n === $disk['name'] ? "$this->sys/block/$n/holders" : "$this->sys/block/{$disk['name']}/$n/holders";
            foreach ((array) @scandir($dir) as $h) {
                if ($h !== '.' && $h !== '..' && $h !== false) {
                    return [strncmp($h, 'md', 2) === 0 ? 'array-member' : 'holder', "$n is held by $h"];
                }
            }
        }
        $mounts = (string) @file_get_contents("$this->proc/self/mounts");
        $swaps = (string) @file_get_contents("$this->proc/swaps");
        foreach ($names as $n) {
            if (preg_match('#^/dev/' . preg_quote($n, '#') . '\s#m', $mounts)) {
                return ['mounted', "/dev/$n is mounted"];
            }
            if (preg_match('#^/dev/' . preg_quote($n, '#') . '\s#m', $swaps)) {
                return ['swap', "/dev/$n is swap"];
            }
        }
        if ($disk['mounted'] || in_array(true, array_column($disk['parts'], 'mounted'), true)) {
            return ['mounted', 'lsblk shows a mount point'];
        }
        $types = array_values(array_filter(array_merge([$disk['fstype']], array_column($disk['parts'], 'fstype'))));
        $member = array_values(array_intersect($types, self::MEMBER_FS));
        if ($member) {
            return ['array-signature', "carries a $member[0] signature but no running array holds it"];
        }
        if ($types || $disk['pttype'] || $disk['parts']) {
            return ['data', $types ? 'signature: ' . implode(', ', array_unique($types)) : 'partition table: ' . ($disk['pttype'] ?: 'partitions')];
        }
        return ['blank', 'no signature, no partition table'];
    }

    /**
     * $disk with its signatures probed as root by `blkid -p` (a low-level read of the device, not the udev cache that
     * lsblk shows): for verify, where a member udev knows nothing about must still be seen. Not used by smart, because
     * probing reads the disk and would wake a sleeping one. A failed or ambivalent probe refuses.
     */
    public function probe(array $disk)
    {
        ToolProc::need('blkid', 'util-linux provides it; no write pass starts without a signature probe');
        $types = function ($dev) {
            $r = ToolProc::run($this->asRoot(['blkid', '-p', '-o', 'export', $dev]));
            if ($r['code'] === 2) {
                return [null, null]; // nothing found
            }
            if ($r['code'] !== 0) {
                throw new CliError("blkid could not probe $dev (exit {$r['code']}" . ($r['code'] === 8 ? ', conflicting signatures' : '') . ')',
                    'nothing was started; look at the device with blkid -p yourself');
            }
            preg_match('/^TYPE=(.*)$/m', $r['out'], $t);
            preg_match('/^PTTYPE=(.*)$/m', $r['out'], $p);
            return [isset($t[1]) ? trim($t[1]) : null, isset($p[1]) ? trim($p[1]) : null];
        };
        list($disk['fstype'], $disk['pttype']) = $types($disk['device']);
        foreach ($disk['parts'] as $i => $part) {
            list($disk['parts'][$i]['fstype']) = $types('/dev/' . $part['name']);
        }
        return $disk;
    }

    /** True when a process (a VM, an export, a database) has the disk or one of its partitions open: `fuser -s`, as root. */
    public function inUse(array $disk)
    {
        ToolProc::need('fuser', 'psmisc provides it (apt install psmisc); no write pass starts without checking who has the disk open');
        $devs = array_merge([$disk['device']], array_map(function ($p) {
            return '/dev/' . $p['name'];
        }, $disk['parts']));
        return ToolProc::run($this->asRoot(array_merge(['fuser', '-s'], $devs)))['code'] === 0;
    }

    /** "md0 check" while an md array resyncs, recovers, checks or reshapes; '' when none does. */
    public function mdBusy()
    {
        $md = '';
        foreach (explode("\n", (string) @file_get_contents("$this->proc/mdstat")) as $line) {
            if (preg_match('/^(md\S*)\s*:/', $line, $m)) {
                $md = $m[1];
            } elseif ($md !== '' && preg_match('/\b(resync|recovery|check|reshape)\s*=/', $line, $m)) {
                return "$md $m[1]";
            }
        }
        return '';
    }

    /** systemd-detect-virt's answer ('none' on bare metal), or null when it cannot tell. */
    public function virt()
    {
        if ($this->virt === null) {
            $r = ToolProc::which('systemd-detect-virt') === null ? ['out' => ''] : ToolProc::run(['systemd-detect-virt']);
            $this->virt = trim($r['out']) !== '' ? trim($r['out']) : false;
        }
        return $this->virt === false ? null : $this->virt;
    }

    /** $argv run as root: directly as uid 0, through passwordless sudo (-n: never a password prompt), else refused. */
    public function asRoot(array $argv)
    {
        $root = $this->root();
        if (!empty($root['available']) && $root['via'] === 'uid0') {
            return $argv; // already root: no sudo
        }
        if (!empty($root['available']) && $root['via'] === 'sudo') {
            return array_merge(['sudo', '-n'], $argv);
        }
        throw new CliError(PathName::base((string) $argv[0]) . ' needs root, and root is not available to this agent (not uid 0, no passwordless sudo, or root.disabled)',
            'check root.available with system-status; nothing was run');
    }

    /** `smartctl -j -x -n standby <dev>` decoded, or ['error' => text] when there is no document. */
    public function smart($device, array $extra = ['-x', '-n', 'standby'])
    {
        ToolProc::need('smartctl', 'install smartmontools (apt install smartmontools)');
        $r = ToolProc::run($this->asRoot(array_merge(['smartctl', '-j'], $extra, [$device])));
        $j = json_decode($r['out'], true);
        return is_array($j) ? $j : ['error' => trim($r['err']) ?: 'smartctl gave no JSON (exit ' . $r['code'] . ')'];
    }

    private static function str($v)
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }
}
