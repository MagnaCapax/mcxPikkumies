<?php
/**
 * MdOps: the steps the write verbs share (ADR-0095): one array's live state, the --examine snapshot taken before any
 * write, the disk check for a new member (in-use refused; holds-data only with its serial in --destroy), the wipe of a
 * --destroy disk, and the kernel line.
 */
class MdOps
{
    /** One array's live state from /proc/mdstat, sysfs and mdadm --detail --export; a CliError when it does not exist. */
    public static function info($md)
    {
        $md = MdState::name($md);
        $mdstat = MdState::arrays();
        if (!isset($mdstat[$md])) {
            throw new CliError("$md is not in /proc/mdstat; nothing was done", 'run status to list the arrays');
        }
        $d = $mdstat[$md]['active'] ? MdState::parseExport(MdRun::run(['mdadm', '--detail', '--export', "/dev/$md"])['out']) : [];
        $bitmap = MdState::sys($md, 'bitmap/location');
        return [
            'md' => $md, 'active' => $mdstat[$md]['active'], 'mdstat' => $mdstat[$md],
            'level' => (string) (MdState::sys($md, 'level') ?: $mdstat[$md]['level']),
            'raid_disks' => (int) MdState::sys($md, 'raid_disks'), 'degraded' => (int) MdState::sys($md, 'degraded'),
            'array_state' => (string) MdState::sys($md, 'array_state'), 'sync_action' => (string) MdState::sys($md, 'sync_action'),
            'bitmap' => $bitmap !== null && $bitmap !== '' && $bitmap !== 'none', 'component_kib' => (int) MdState::sys($md, 'component_size'),
            'members' => MdState::sysMembers($md), 'uuid' => isset($d['MD_UUID']) ? strtolower($d['MD_UUID']) : '', 'name' => isset($d['MD_NAME']) ? $d['MD_NAME'] : '',
        ];
    }

    /** Saves mdadm --examine of every path to /var/lib/pikkumies-mdraid and returns the record (ADR-0095: before any write). */
    public static function snapshot($label, array $paths)
    {
        $text = '';
        foreach ($paths as $p) {
            $text .= "### mdadm --examine $p\n" . MdRun::run(['mdadm', '--examine', $p])['out'] . "\n";
        }
        return ['snapshot' => MdRun::keep($label, $text), 'members' => count($paths)];
    }

    /** --destroy serials as a list. */
    public static function destroyList(array $opt)
    {
        return isset($opt['destroy']) && is_string($opt['destroy']) ? array_values(array_filter(array_map('trim', explode(',', $opt['destroy'])), 'strlen')) : [];
    }

    /**
     * Checks new member disks: in-use is refused outright; holds-data needs its serial in $destroy, and the refusal
     * names what was found; every --destroy serial must be one of these disks. $array: the uuid of the array a disk
     * is joining (a disk that was its member is pointed to re-add). Returns the inspect() records.
     */
    public static function checkNew(array $ks, array $destroy, $arrayUuid = '')
    {
        $ctx = MdUse::context();
        $whole = MdDisks::whole();
        $set = array_values(array_filter($ks, function ($k) use ($ctx, $destroy) {
            return isset($ctx['serials'][$k]) && $ctx['serials'][$k]['serial'] !== '' && in_array($ctx['serials'][$k]['serial'], $destroy, true);
        }));
        $out = [];
        foreach ($ks as $k) {
            $d = MdDisks::inspect($k, $whole[$k], $ctx, $set) + ['destroy_set' => $set];
            if ($arrayUuid !== '' && $d['md_superblock'] !== null && strtolower($d['md_superblock']['uuid']) === $arrayUuid) {
                throw new CliError("{$d['disk']} was a member of this array (its superblock names it): use re-add for it; nothing was done", 'use re-add for a former member; add is for a new or wiped disk');
            }
            if ($d['verdict'] === 'in-use') {
                throw new CliError("{$d['disk']} is in use: " . implode('; ', $d['in_use']) . '; nothing was done', 'a disk in use is never taken; pick another disk');
            }
            if ($d['verdict'] === 'holds-data' && ($d['serial'] === '' || !in_array($d['serial'], $destroy, true))) {
                throw new CliError("{$d['disk']} (serial {$d['serial']}) holds data: " . self::evidence($d) . '; nothing was done',
                    "tell the user in one line what is on it; only if they want it destroyed, run the same command with --destroy {$d['serial']}");
            }
            $out[$k] = $d;
        }
        $known = array_column($out, 'serial');
        foreach ($destroy as $s) {
            if (!in_array($s, $known, true)) {
                throw new CliError("--destroy names serial $s, which is not one of the selected disks; nothing was done", 'name only serials of the disks this command uses');
            }
        }
        return $out;
    }

    /** What a holds-data disk carries, in one line: signatures, the array its superblock names, the share of non-zero samples. */
    public static function evidence(array $d)
    {
        $s = $d['samples'];
        $bits = [];
        if ($d['partitions']) {
            $bits[] = count($d['partitions']) . ' partition(s)';
        }
        if ($d['signatures']) {
            $bits[] = 'signatures ' . implode(', ', $d['signatures']);
        }
        if ($d['md_superblock'] !== null) {
            $bits[] = 'an md superblock of array ' . ($d['md_superblock']['name'] !== '' ? $d['md_superblock']['name'] : $d['md_superblock']['uuid']);
        }
        $bits[] = $s['nonzero_samples'] . ' of ' . ($s['samples'] + $s['unreadable']) . ' sampled reads not all zero';
        return implode(', ', $bits);
    }

    /**
     * Just before anything is written to a selected disk: the in-use check runs again (a holder may have appeared since
     * the first check), and a disk that holds data is wiped only after an exclusive open of it succeeded (wipefs -a -n
     * opens O_RDWR|O_EXCL and writes nothing), then signatures of each partition, an md superblock, the disk's own.
     */
    public static function wipe(array $d)
    {
        $k = $d['kernel_name_now'];
        $now = MdDisks::inspect($k, $d['disk'], MdUse::context(), isset($d['destroy_set']) ? $d['destroy_set'] : []);
        if ($now['verdict'] === 'in-use') {
            throw new CliError("{$d['disk']} became in use since it was checked: " . implode('; ', $now['in_use']) . '; it was left untouched', 'run disks again');
        }
        if ($d['verdict'] !== 'holds-data' && $now['verdict'] !== 'holds-data') {
            return [];
        }
        if ($d['verdict'] !== 'holds-data') {
            throw new CliError("{$d['disk']} now holds data, which the first check did not see; it was left untouched", 'run the command again so the refusal names what is on it');
        }
        $path = '/dev/disk/by-id/' . $d['disk'];
        $probe = MdRun::run(['wipefs', '-a', '-n', $path]);
        if ($probe['code'] !== 0) {
            throw new CliError("{$d['disk']} cannot be opened exclusively (the kernel or another program holds it: " . trim(substr($probe['err'], 0, 200)) . '); it was left untouched', 'find what holds it (a ZFS pool, a VM, a mapper device) and release it first');
        }
        $done = [];
        foreach ($d['partitions'] as $n) {
            $p = MdDisks::idPath($n);
            if ($p !== null) {
                MdRun::must(['wipefs', '-a', $p], "wipefs of $p");
                $done[] = "wipefs -a $p";
            }
        }
        if ($d['md_superblock'] !== null) {
            MdRun::must(['mdadm', '--zero-superblock', $path], "zeroing the md superblock of $path");
            $done[] = "mdadm --zero-superblock $path";
        }
        MdRun::must(['wipefs', '-a', $path], "wipefs of $path");
        $done[] = "wipefs -a $path";
        return $done;
    }

    /** The kernel line for an array: [risk (bool), kernel verdict, release]. */
    public static function kernel($level, $devices, $bitmap)
    {
        $release = trim(MdRun::run(['uname', '-r'])['out']);
        $verdict = MdPlan::kernelBitmap($release, MdRun::read('/proc/version') . "\n" . MdRun::read('/proc/version_signature'));
        return ['risk' => MdPlan::bitmapRisk($level, $devices, $bitmap), 'kernel' => $verdict, 'release' => $release];
    }

    /** The member of $info named by the user (by-id, serial), as [kname, by-id path]; a CliError when it is not one. */
    public static function member(array $info, $given)
    {
        $k = MdDisks::resolve($given, MdDisks::whole(), MdDisks::serials());
        if (!isset($info['members'][$k])) {
            throw new CliError("$given is not a member of {$info['md']}; nothing was done", 'run status --array ' . $info['md'] . ' to see its members');
        }
        return [$k, MdDisks::idPath($k)];
    }
}
