<?php
/**
 * MdRecover: the recovery and scrub verbs (ADR-0095), each re-checking the live state just before it writes and
 * saving every member's --examine first. The ladder they follow is doctrine/SOP/recover-a-degraded-or-failed-array.md:
 * re-add the same disk first, add or replace second; assemble read-only from the consistent N-1 group; two or more
 * members behind stops; a scrub never runs on a degraded array; nothing here repairs a filesystem.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MdRecover
{
    /** re-add mdN <member>: an active, degraded array whose slot for that member is vacant (or held by it, marked faulty). */
    public static function readd($md, $given)
    {
        MdRun::needRoot('re-add');
        $info = MdOps::info($md);
        if (!$info['active'] || in_array($info['array_state'], ['inactive', 'clear', ''], true)) {
            throw new CliError("{$info['md']} is not running; nothing was done", 're-add needs a running array; for a stopped or failed array use assemble');
        }
        if ($info['degraded'] < 1) {
            throw new CliError("{$info['md']} is not degraded; nothing was done", 'every slot is filled: nothing to re-add');
        }
        self::notFailed($info);
        $k = MdDisks::resolve($given, MdDisks::whole(), MdDisks::serials());
        $path = MdDisks::idPath($k);
        self::notHeld($k, $info['md']);
        $sb = MdState::examine(MdRun::run(['mdadm', '--examine', $path])['out']);
        if ($sb['uuid'] === '' || strtolower($sb['uuid']) !== $info['uuid']) {
            throw new CliError("$given does not carry {$info['md']}'s superblock; nothing was done", 'a new or wiped disk goes in with add');
        }
        $faulty = isset($info['members'][$k]) && strpos($info['members'][$k]['state'], 'faulty') !== false;
        if (isset($info['members'][$k]) && !$faulty) {
            throw new CliError("$given is already an active member of {$info['md']}; nothing was done", 'run status');
        }
        $taken = array_filter($info['members'], function ($m, $kn) use ($sb, $k) {
            return $kn !== $k && $m['slot'] === $sb['role'] && strpos($m['state'], 'faulty') === false;
        }, ARRAY_FILTER_USE_BOTH);
        if ($sb['role'] === null || $taken) {
            throw new CliError("the slot $given held (" . ($sb['role'] === null ? 'none recorded' : 'role ' . $sb['role']) . ") is not vacant in {$info['md']}; nothing was done",
                're-add only returns a member to its own empty slot; a disk whose slot was filled goes in with add after the user agrees to wipe it');
        }
        $kernel = MdOps::kernel($info['level'], $info['raid_disks'], $info['bitmap']);
        if ($kernel['risk'] && $kernel['kernel'] !== 'fixed') {
            throw new CliError("re-add refused: {$info['md']} is parity RAID with an internal bitmap and a data-disk count that is not a power of two, and the running kernel ({$kernel['release']}) is "
                . ($kernel['kernel'] === 'affected' ? 'one that can skip writes in the bitmap' : 'not known to carry the bitmap rounding fix') . '; nothing was done',
                'boot a kernel with "md/raid5: round bitmap stripes with sector division" (6.6.157+, 6.12.110+, 6.18.52+, 7.2.6+, 7.3+), or add the disk as new (a full rebuild) after the user agrees to wipe it');
        }
        $snap = MdOps::snapshot("re-add-{$info['md']}", self::paths($info, [$path]));
        $done = [];
        if ($faulty) {
            MdRun::must(['mdadm', "/dev/{$info['md']}", '--remove', $path], "removing the faulty $path");
            $done[] = 'removed the faulty entry first';
        }
        MdRun::must(['mdadm', "/dev/{$info['md']}", '--re-add', $path], "re-add of $path");
        $after = MdOps::info($info['md']);
        $verified = isset($after['members'][$k]);
        return ['records' => [$snap, ['array' => $info['md'], 'member' => PathName::base($path), 'done' => array_merge($done, ['re-added']),
            'sync_action' => $after['sync_action'], 'degraded' => $after['degraded'], 'verified' => $verified]], 'status' => ['verified' => $verified]];
    }

    /** add mdN <disk>: a new or wiped disk into a degraded array. */
    public static function add($md, $given, array $destroy)
    {
        MdRun::needRoot('add');
        $info = MdOps::info($md);
        if (!$info['active'] || $info['degraded'] < 1) {
            throw new CliError("{$info['md']} is " . ($info['active'] ? 'not degraded' : 'not running') . '; nothing was done',
                $info['active'] ? 'to swap a working member use replace' : 'assemble it first');
        }
        self::notFailed($info);
        $k = MdDisks::resolve($given, MdDisks::whole(), MdDisks::serials());
        $d = MdOps::checkNew([$k], $destroy, $info['uuid'])[$k];
        if ($info['component_kib'] > 0 && $d['size_bytes'] < $info['component_kib'] * 1024) {
            throw new CliError("$given is smaller than the array's members need; nothing was done", 'pick a disk at least as large as the others');
        }
        $path = '/dev/disk/by-id/' . $d['disk'];
        $snap = MdOps::snapshot("add-{$info['md']}", self::paths($info, [$path]));
        $wiped = MdOps::wipe($d);
        MdRun::must(['mdadm', "/dev/{$info['md']}", '--add', $path], "add of $path");
        $after = MdOps::info($info['md']);
        $verified = isset($after['members'][$k]);
        return ['records' => [$snap, ['array' => $info['md'], 'added' => $d['disk'], 'serial' => $d['serial'], 'wiped' => $wiped,
            'sync_action' => $after['sync_action'], 'verified' => $verified]], 'status' => ['verified' => $verified]];
    }

    /** replace mdN <old> <new>: --replace --with keeps redundancy while the copy runs; a faulty old member is removed, then the new one added. */
    public static function replace($md, $oldGiven, $newGiven, array $destroy)
    {
        MdRun::needRoot('replace');
        $info = MdOps::info($md);
        if (!$info['active'] || MdState::maxDegraded($info['level'], $info['raid_disks']) < 1) {
            throw new CliError("{$info['md']} is not a running redundant array; nothing was done", 'replace needs RAID1, 5 or 10, running');
        }
        self::notFailed($info);
        list($ko, $old) = MdOps::member($info, $oldGiven);
        $faulty = strpos($info['members'][$ko]['state'], 'faulty') !== false;
        if ($info['degraded'] > 0 && !$faulty) {
            throw new CliError("{$info['md']} is degraded; nothing was done", 'fill the empty slot first with re-add or add; replacing a working member now would leave no redundancy');
        }
        $kn = MdDisks::resolve($newGiven, MdDisks::whole(), MdDisks::serials());
        $d = MdOps::checkNew([$kn], $destroy, $info['uuid'])[$kn];
        if ($info['component_kib'] > 0 && $d['size_bytes'] < $info['component_kib'] * 1024) {
            throw new CliError("$newGiven is smaller than the array's members need; nothing was done", 'pick a disk at least as large as the others');
        }
        $new = '/dev/disk/by-id/' . $d['disk'];
        $snap = MdOps::snapshot("replace-{$info['md']}", self::paths($info, [$new]));
        $wiped = MdOps::wipe($d);
        if ($faulty) {
            MdRun::must(['mdadm', "/dev/{$info['md']}", '--remove', $old], "removing the faulty $old");
            MdRun::must(['mdadm', "/dev/{$info['md']}", '--add', $new], "add of $new");
        } else {
            MdRun::must(['mdadm', "/dev/{$info['md']}", '--add', $new], "add of $new as a spare");
            MdRun::must(['mdadm', "/dev/{$info['md']}", '--replace', $old, '--with', $new], "replace of $old with $new");
        }
        $after = MdOps::info($info['md']);
        $verified = isset($after['members'][$kn]);
        return ['records' => [$snap, ['array' => $info['md'], 'old' => PathName::base($old), 'new' => $d['disk'], 'old_was_faulty' => $faulty, 'wiped' => $wiped,
            'sync_action' => $after['sync_action'], 'note' => $faulty ? 'rebuild onto the new disk' : 'the old member stays until the copy ends, then it is marked faulty; remove it after with replace or leave it',
            'verified' => $verified]], 'status' => ['verified' => $verified]];
    }

    /** assemble mdN [--writable]: read-only from the consistent N-1 group; --writable switches a read-only running array to read-write. */
    public static function assemble($md, $writable)
    {
        MdRun::needRoot('assemble');
        $md = MdState::name($md);
        $mdstat = MdState::arrays();
        if (isset($mdstat[$md]) && $mdstat[$md]['active']) {
            $state = (string) MdState::sys($md, 'array_state');
            if (!$writable) {
                throw new CliError("$md is already running ($state); nothing was done", 'run status; after looking at the data read-only, assemble ' . $md . ' --writable');
            }
            if (!in_array($state, ['readonly', 'read-auto'], true)) {
                throw new CliError("$md is already writable ($state); nothing was done", 'run status');
            }
            $snap = MdOps::snapshot("writable-$md", self::paths(MdOps::info($md), []));
            MdRun::must(['mdadm', '--readwrite', "/dev/$md"], "switching $md to read-write");
            $after = (string) MdState::sys($md, 'array_state');
            $verified = !in_array($after, ['readonly', 'read-auto'], true);
            return ['records' => [$snap, ['array' => $md, 'array_state' => $after, 'verified' => $verified]], 'status' => ['verified' => $verified]];
        }
        if ($writable) {
            throw new CliError("$md is not running; nothing was done", 'assemble it read-only first, mount it read-only and look, then run assemble ' . $md . ' --writable');
        }
        $uuid = self::confUuid($md, isset($mdstat[$md]) ? $mdstat[$md] : null);
        $members = [];
        foreach (MdDisks::whole() as $k => $id) {
            $e = MdState::examine(MdRun::run(['mdadm', '--examine', "/dev/disk/by-id/$id"])['out']);
            if ($e['uuid'] !== '' && strtolower($e['uuid']) === $uuid) {
                $members["/dev/disk/by-id/$id"] = $e;
            }
        }
        if (!$members) {
            throw new CliError("no disk carries the superblock of $md ($uuid); nothing was done", 'run disks; arrays on partitions are outside this tool');
        }
        $first = reset($members);
        $level = 'raid' . preg_replace('/^raid/', '', $first['level']);
        $v = MdState::verdict($members, $level, $first['devices']);
        if ($v['verdict'] === 'stop') {
            throw new CliError("assemble refused: {$v['why']}; nothing was done",
                'keep the disks untouched and tell the owner; this needs Pulsed Media support or a data recovery specialist, not another command');
        }
        foreach (array_keys($members) as $p) {
            self::notHeld(PathName::base((string) readlink(MdRun::path($p))), $md);
        }
        $snap = MdOps::snapshot("assemble-$md", array_keys($members));
        $done = [];
        if (isset($mdstat[$md])) { // an inactive array holds its members: stopping it loses nothing
            MdRun::must(['mdadm', '--stop', "/dev/$md"], "stopping the inactive $md");
            $done[] = "stopped the inactive $md";
        }
        $argv = array_merge(['mdadm', '--assemble', '--readonly', "/dev/$md"], count($v['group']) < $first['devices'] ? ['--run'] : [], $v['group']);
        $r = MdRun::run($argv);
        MdRun::audit(implode(' ', $argv), $r['code']);
        if ($r['code'] !== 0) { // every member given is in the consistent group, so --force only marks a dirty degraded array clean
            $done[] = 'plain assemble failed: ' . trim(substr($r['err'], 0, 300));
            MdRun::must(array_merge(['mdadm', '--assemble', '--readonly', '--force', "/dev/$md"], array_slice($argv, 4)), "assemble of $md with --force (members: the consistent group only)");
            $done[] = 'assembled with --force (only members at the newest Events)';
        } else {
            $done[] = 'assembled read-only';
        }
        $state = (string) MdState::sys($md, 'array_state');
        $verified = in_array($state, ['readonly', 'read-auto', 'clean', 'active'], true);
        return ['records' => [$snap, ['array' => $md, 'verdict' => $v['verdict'], 'why' => $v['why'], 'used' => array_map(['PathName', 'base'], $v['group']),
            'left_out' => array_map(['PathName', 'base'], $v['stale']), 'done' => $done, 'array_state' => $state,
            'next' => 'mount it read-only and look at the data before assemble ' . $md . ' --writable' . ($v['stale'] ? '; then re-add the member left out' : ''),
            'verified' => $verified]], 'status' => ['verified' => $verified]];
    }

    /** check mdN (start a scrub) or idle mdN (stop a check or resync). */
    public static function action($md, $what)
    {
        MdRun::needRoot($what);
        $info = MdOps::info($md);
        if ($what === 'check') {
            if (!$info['active'] || $info['degraded'] > 0 || MdState::maxDegraded($info['level'], $info['raid_disks']) < 1) {
                throw new CliError("check refused: {$info['md']} is " . (!$info['active'] ? 'not running' : ($info['degraded'] > 0 ? 'degraded (a scrub on a degraded array can wedge writes)' : 'not redundant')) . '; nothing was done',
                    $info['degraded'] > 0 ? 'fill the empty slot first (re-add or add)' : 'nothing to check');
            }
            if ($info['sync_action'] !== 'idle') {
                throw new CliError("{$info['md']} is busy ({$info['sync_action']}); nothing was done", 'wait for it to finish; status shows the progress');
            }
        }
        MdRun::write("/sys/block/{$info['md']}/md/sync_action", "$what\n");
        $now = (string) MdState::sys($info['md'], 'sync_action');
        $verified = $what === 'idle' ? $now !== 'check' && $now !== 'repair' : $now === 'check';
        return ['records' => [['array' => $info['md'], 'sync_action' => $now, 'note' => $what === 'idle' ? 'a resync or recovery the kernel needs may start again by itself' : 'progress in status', 'verified' => $verified]],
            'status' => ['verified' => $verified]];
    }

    /** A failed array (more members missing than its level can lose) is refused: a re-add or add there can turn a member into a spare. */
    private static function notFailed(array $info)
    {
        if ($info['degraded'] > MdState::maxDegraded($info['level'], $info['raid_disks'])) {
            throw new CliError("{$info['md']} has failed: {$info['degraded']} members are missing and {$info['level']} can lose " . MdState::maxDegraded($info['level'], $info['raid_disks']) . '; nothing was done',
                'do not re-add or add here; follow the recovery SOP: stop, compare the members with status, and assemble from the consistent group');
        }
    }

    /** A disk held by another md or dm device (or mounted, or swap) is refused: stop that array first. */
    private static function notHeld($k, $md)
    {
        $holders = array_values(array_filter(MdRun::ls("/sys/block/$k/holders"), function ($h) use ($md) {
            return $h !== $md;
        }));
        $mounted = MdDisks::mounted();
        if ($holders || isset($mounted[$k])) {
            throw new CliError("$k is " . ($holders ? 'held by ' . implode(', ', $holders) : $mounted[$k]) . '; nothing was done',
                $holders ? 'if that is an inactive array assembled from this disk by itself, stop it first: mdadm --stop /dev/' . $holders[0] : 'a disk in use is never taken');
        }
    }

    /** The UUID of mdN from mdadm.conf, else from its members as /proc/mdstat lists them (an inactive array). */
    private static function confUuid($md, $inactive)
    {
        foreach (MdState::conf()['arrays'] as $a) {
            if (in_array($a['device'], ["/dev/$md", '/dev/md/' . substr($md, 2)], true) && $a['uuid'] !== '') {
                return $a['uuid'];
            }
        }
        foreach ($inactive !== null ? $inactive['members'] : [] as $m) {
            $p = MdDisks::idPath($m['kname']);
            $u = $p === null ? '' : MdState::examine(MdRun::run(['mdadm', '--examine', $p])['out'])['uuid'];
            if ($u !== '') {
                return strtolower($u);
            }
        }
        throw new CliError("$md is neither in mdadm.conf nor listed inactive in /proc/mdstat; nothing was done", 'run status; name an array by the mdN in its ARRAY line');
    }

    /** The by-id paths of the current members plus $extra, for the snapshot. */
    private static function paths(array $info, array $extra)
    {
        $out = [];
        foreach (array_keys($info['members']) as $k) {
            $p = MdDisks::idPath($k);
            if ($p !== null) {
                $out[] = $p;
            }
        }
        return array_values(array_unique(array_merge($out, $extra)));
    }
}
