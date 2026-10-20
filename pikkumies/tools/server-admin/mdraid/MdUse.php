<?php
/**
 * MdUse: whether something on this machine still uses a disk (ADR-0095, security review M-2 and M-3). Read once per
 * check into a context, then asked per disk:
 * - mounted or swap (/proc/mounts, /proc/swaps), held by md or dm (sysfs holders), part of a mounted btrfs
 *   (/sys/fs/btrfs/<fs>/devices), backing a loop device (/sys/block/loopN/loop/backing_file);
 * - open by a process (every /proc/<pid>/fd link to the disk or a partition: read in PHP as uid 0, through
 *   `sudo -n find /proc ...` otherwise), which covers a VM, an nbd export or a running dd or badblocks;
 * - its md superblock (whole disk or a partition) belongs to an array in /proc/mdstat, running or inactive, or to an
 *   array listed in mdadm.conf, or other disks still carry that array and are not all being destroyed in this same
 *   command (they could still assemble it: destroying one member takes away a way back).
 * A claim the kernel holds without a process (an imported ZFS pool) is caught by the exclusive-open probe before a wipe.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MdUse
{
    /** Everything the per-disk check needs, read now. */
    public static function context()
    {
        $mdstat = MdState::arrays();
        $live = [];
        foreach ($mdstat as $md => $a) {
            if ($a['active']) {
                $d = MdState::parseExport(MdRun::run(['mdadm', '--detail', '--export', "/dev/$md"])['out']);
                if (isset($d['MD_UUID'])) {
                    $live[strtolower($d['MD_UUID'])] = $md;
                }
            }
            foreach ($a['members'] as $m) { // an inactive array names no uuid: its members' superblocks do
                $p = MdDisks::idPath($m['kname']);
                $u = $p === null ? '' : strtolower(MdState::examine(MdRun::run(['mdadm', '--examine', $p])['out'])['uuid']);
                if ($u !== '') {
                    $live[$u] = $md;
                }
            }
        }
        $sets = [];
        $scan = (string) MdRun::run(['mdadm', '--examine', '--scan', '--verbose'])['out'];
        foreach (preg_split('/\n(?=ARRAY\s)/', $scan) as $block) {
            if (preg_match('/\bUUID=([0-9a-fA-F:]+)/', $block, $u) && preg_match('/(?:^|\s)devices=(\S+)/', $block, $dv)) {
                $sets[strtolower($u[1])] = array_map(['PathName', 'base'], explode(',', $dv[1]));
            }
        }
        $parent = [];
        foreach (MdDisks::whole() as $k => $id) {
            $parent[$k] = $k;
            foreach (MdDisks::partitions($k) as $p) {
                $parent[$p] = $k;
            }
        }
        return ['serials' => MdDisks::serials(), 'mounted' => MdDisks::mounted(), 'conf' => MdState::conf(), 'mdstat' => $mdstat, 'parent' => $parent,
            'live' => $live, 'sets' => $sets, 'open' => self::openFiles(), 'btrfs' => self::btrfs(), 'loops' => self::loops()];
    }

    /** Why a disk (kname $k with partitions $parts) is in use, from everything but its superblock. */
    public static function holders($k, array $parts, array $ctx)
    {
        $use = [];
        foreach (array_merge([$k], $parts) as $n) {
            if (isset($ctx['mounted'][$n])) {
                $use[] = "$n is " . $ctx['mounted'][$n];
            }
            foreach (MdRun::ls($n === $k ? "/sys/block/$k/holders" : "/sys/block/$k/$n/holders") as $h) {
                $inactive = isset($ctx['mdstat'][$h]) && !$ctx['mdstat'][$h]['active'];
                $use[] = "$n is held by $h" . ($inactive ? ' (an inactive array assembled from this disk: stop that array first with mdadm --stop /dev/' . $h . ')' : '');
            }
            if (isset($ctx['open'][$n])) {
                $use[] = "$n is open by process " . implode(', ', array_slice($ctx['open'][$n], 0, 5));
            }
            if (in_array($n, $ctx['btrfs'], true)) {
                $use[] = "$n is a device of a mounted btrfs";
            }
            if (isset($ctx['loops'][$n])) {
                $use[] = "$n is the backing file of {$ctx['loops'][$n]}";
            }
        }
        return $use;
    }

    /** Why a disk's md superblocks ($uuids, whole disk and partitions) keep it in use; $destroy: knames being destroyed in this command. */
    public static function superblocks($k, array $parts, array $uuids, array $ctx, array $destroy)
    {
        $use = [];
        $own = array_merge([$k], $parts);
        foreach (array_unique(array_filter($uuids, 'strlen')) as $u) {
            if (isset($ctx['live'][$u])) {
                $use[] = "its md superblock belongs to {$ctx['live'][$u]}, which is in /proc/mdstat";
            }
            foreach ($ctx['conf']['arrays'] as $a) {
                if ($a['uuid'] === $u) {
                    $use[] = MdDisks::CONF_HIT . "{$a['device']}, listed in mdadm.conf";
                }
            }
            $whole = function ($dev) use ($ctx) {
                return isset($ctx['parent'][$dev]) ? $ctx['parent'][$dev] : $dev;
            };
            $others = array_values(array_filter(isset($ctx['sets'][$u]) ? $ctx['sets'][$u] : [], function ($dev) use ($own, $destroy, $whole) {
                return !in_array($dev, $own, true) && !in_array($whole($dev), $destroy, true);
            }));
            if ($others && !isset($ctx['live'][$u])) {
                $names = array_map(function ($dev) use ($ctx, $whole) {
                    $w = $whole($dev);
                    return isset($ctx['serials'][$w]) && $ctx['serials'][$w]['serial'] !== '' ? 'serial ' . $ctx['serials'][$w]['serial'] : $dev;
                }, $others);
                $use[] = count($others) . ' other disk(s) still carry the same array (' . implode(', ', $names) . ') and could assemble it; destroy all of them in one command, or assemble it first';
            }
        }
        return $use;
    }

    /** [kname => [pid, ...]] for every process fd that points at a device node. */
    private static function openFiles()
    {
        $links = [];
        if (MdRun::access() === 'sudo') {
            $r = MdRun::run(['find', MdRun::path('/proc'), '-mindepth', '3', '-maxdepth', '3', '-path', MdRun::path('/proc') . '/[0-9]*/fd/*', '-lname', '/dev/*', '-printf', "%p %l\n"]);
            foreach (explode("\n", $r['out']) as $line) {
                if (preg_match('#/proc/([0-9]+)/fd/[0-9]+ (/dev/\S+)$#', trim($line), $m)) {
                    $links[] = [(int) $m[1], $m[2]];
                }
            }
        } else {
            foreach ((array) glob(MdRun::path('/proc') . '/[0-9]*/fd/*', GLOB_NOSORT) as $fd) {
                $t = @readlink($fd);
                if ($t !== false && strpos($t, '/dev/') === 0 && preg_match('#/proc/([0-9]+)/fd/#', $fd, $m)) {
                    $links[] = [(int) $m[1], $t];
                }
            }
        }
        $out = [];
        foreach ($links as $l) {
            if ($l[0] !== getmypid()) {
                $link = @readlink(MdRun::path($l[1]));
                $out[PathName::base($link !== false ? $link : $l[1])][] = $l[0];
            }
        }
        return $out;
    }

    private static function btrfs()
    {
        $out = [];
        foreach (MdRun::ls('/sys/fs/btrfs') as $fs) {
            $out = array_merge($out, MdRun::ls("/sys/fs/btrfs/$fs/devices"));
        }
        return $out;
    }

    private static function loops()
    {
        $out = [];
        foreach (MdRun::ls('/sys/block') as $b) {
            $file = trim((string) MdRun::read("/sys/block/$b/loop/backing_file"));
            if (strpos($b, 'loop') === 0 && strpos($file, '/dev/') === 0) {
                $link = @readlink(MdRun::path($file));
                $out[PathName::base($link !== false ? $link : $file)] = $b;
            }
        }
        return $out;
    }
}
