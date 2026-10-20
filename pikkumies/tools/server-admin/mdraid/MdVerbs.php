<?php
/**
 * MdVerbs: the read verbs and the table mdraid.php hands to Cli::run (ADR-0095). status works without root (from
 * /proc/mdstat and sysfs); member superblocks, the N-1 verdict and disks need root, which MdRun reaches itself.
 */
require_once __DIR__ . '/MdRun.php';
require_once __DIR__ . '/MdState.php';
require_once __DIR__ . '/MdDisks.php';
require_once __DIR__ . '/MdUse.php';
require_once __DIR__ . '/MdPlan.php';
require_once __DIR__ . '/MdOps.php';
require_once __DIR__ . '/MdRecover.php';
require_once __DIR__ . '/MdBuild.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MdVerbs
{
    const FLAGS = ['plan', 'no-redundancy', 'accept-size-loss', 'writable'];
    const OPTIONS = ['array', 'level', 'disks', 'profile', 'destroy'];

    public static function table()
    {
        $one = function ($args, $n, $usage) {
            if (count($args) !== $n) {
                throw new CliError("expected $usage; nothing was done", 'see USAGE.md');
            }
            return $args;
        };
        return [
            'status' => function ($args, $opt) {
                return self::status($opt);
            },
            'disks' => function () {
                MdRun::needRoot('disks');
                return array_values(array_map(function ($d) {
                    unset($d['md']);
                    return $d;
                }, MdDisks::all()));
            },
            'create' => function ($args, $opt) {
                return MdBuild::create($opt);
            },
            'add' => function ($args, $opt) use ($one) {
                list($md, $disk) = $one($args, 2, 'add mdN <disk>');
                return MdRecover::add($md, $disk, MdOps::destroyList($opt));
            },
            'replace' => function ($args, $opt) use ($one) {
                list($md, $old, $new) = $one($args, 3, 'replace mdN <old member> <new disk>');
                return MdRecover::replace($md, $old, $new, MdOps::destroyList($opt));
            },
            're-add' => function ($args) use ($one) {
                list($md, $member) = $one($args, 2, 're-add mdN <member>');
                return MdRecover::readd($md, $member);
            },
            'assemble' => function ($args, $opt) use ($one) {
                list($md) = $one($args, 1, 'assemble mdN');
                return MdRecover::assemble($md, !empty($opt['writable']));
            },
            'check' => function ($args) use ($one) {
                list($md) = $one($args, 1, 'check mdN');
                return MdRecover::action($md, 'check');
            },
            'idle' => function ($args) use ($one) {
                list($md) = $one($args, 1, 'idle mdN');
                return MdRecover::action($md, 'idle');
            },
            'tune' => function ($args, $opt) {
                return MdBuild::tune($opt);
            },
        ];
    }

    /** Every array (or --array mdN): state, members, superblocks and the N-1 verdict, mdadm.conf, the kernel line, tunables; then monitoring. */
    public static function status(array $opt)
    {
        $root = MdRun::access() !== 'none'; // uid 0 directly, or passwordless sudo (Detect::rootAccess)
        $arrays = MdState::arrays();
        if (isset($opt['array']) && is_string($opt['array'])) {
            $md = MdState::name($opt['array']);
            if (!isset($arrays[$md])) {
                throw new CliError("$md is not in /proc/mdstat", 'run status without --array to list the arrays');
            }
            $arrays = [$md => $arrays[$md]];
        }
        $conf = MdState::conf();
        $profile = isset($opt['profile']) && is_string($opt['profile']) ? MdPlan::profile($opt['profile']) : null;
        $sb = $root ? self::superblocks() : [];
        $serials = MdDisks::serials(); // lsblk reads the udev database: no root needed
        $records = [];
        $running = [];
        foreach ($arrays as $md => $a) {
            $info = $a['active'] ? MdOps::info($md) : null;
            $uuid = $info !== null ? $info['uuid'] : '';
            $members = [];
            foreach ($a['members'] as $m) {
                $sys = $info !== null && isset($info['members'][$m['kname']]) ? $info['members'][$m['kname']] : ['slot' => $m['slot'], 'state' => ''];
                $id = MdDisks::idPath($m['kname']);
                $members[] = ['disk' => $id === null ? null : PathName::base($id), 'serial' => isset($serials[$m['kname']]) ? $serials[$m['kname']]['serial'] : null,
                    'kernel_name_now' => $m['kname'], 'slot' => $sys['slot'], 'state' => $sys['state'] !== '' ? $sys['state'] : $m['flags']];
                if ($uuid === '' && $id !== null && isset($sb['by_path'][$id])) {
                    $uuid = strtolower($sb['by_path'][$id]['uuid']);
                }
            }
            $r = ['array' => $md, 'active' => $a['active'], 'readonly' => $a['readonly'], 'level' => $info !== null ? $info['level'] : $a['level'],
                'array_state' => $info !== null ? $info['array_state'] : 'inactive', 'sync_action' => $a['action'], 'progress_pct' => $a['progress'],
                'devices' => $a['devices'], 'present' => $a['present'], 'map' => $a['map'], 'degraded' => $info !== null ? $info['degraded'] : null,
                'bitmap' => $info !== null ? $info['bitmap'] : null, 'uuid' => $uuid, 'members' => $members];
            $lines = array_values(array_filter($conf['arrays'], function ($c) use ($uuid) {
                return $uuid !== '' && $c['uuid'] === $uuid;
            }));
            $r['mdadm_conf'] = $uuid === '' ? 'unknown (run as root)' : (count($lines) === 1 ? 'listed' : (count($lines) === 0 ? 'missing: after a reboot it may come back under another number' : count($lines) . ' lines: duplicate lines can stop the boot'));
            if ($root && $uuid !== '' && isset($sb['by_uuid'][$uuid])) {
                $members = $sb['by_uuid'][$uuid];
                $first = reset($members);
                $v = MdState::verdict($members, 'raid' . preg_replace('/^raid/', '', $first['level']), $first['devices']);
                $r['superblocks'] = array_map(function ($p, $e) {
                    return ['disk' => PathName::base($p), 'events' => $e['events'], 'updated' => $e['updated'], 'role' => $e['spare'] ? 'spare' : $e['role']];
                }, array_keys($members), $members);
                $r['verdict'] = ['verdict' => $v['verdict'], 'why' => $v['why'], 'stale' => array_map(['PathName', 'base'], $v['stale']), 'update_time_differs' => !empty($v['update_time_differs'])];
            } elseif (!$root) {
                $r['verdict'] = 'run status as root to compare the member superblocks';
            }
            if ($info !== null) {
                $k = MdOps::kernel($info['level'], $info['raid_disks'], $info['bitmap']);
                if ($k['risk']) {
                    $r['kernel_line'] = $k['kernel'] === 'fixed' ? "kernel {$k['release']} carries the raid5 bitmap rounding fix"
                        : "kernel {$k['release']} is " . ($k['kernel'] === 'affected' ? 'affected by' : 'not known to carry the fix for') . ' the raid5 bitmap rounding bug; re-add is refused until a fixed kernel runs';
                }
                $r['tunables'] = self::tunables($md, $info, $profile);
                $running[] = $uuid; // '' when it could not be read (not root)
            }
            $records[] = $r;
        }
        $records[] = ['root' => MdRun::access(), 'note' => ['uid0' => 'running as root: programs run directly', 'sudo' => 'programs run through sudo -n', 'none' => 'no root here: superblocks and disks are not read'][MdRun::access()]];
        $stale = in_array('', $running, true) || isset($opt['array']) ? null : array_column(array_values(array_filter($conf['arrays'], function ($c) use ($running) {
            return $c['uuid'] !== '' && !in_array($c['uuid'], $running, true);
        })), 'line');
        $records[] = ['monitoring' => true, 'mdmonitor' => MdRun::have('systemctl') ? trim(MdRun::run(['systemctl', 'is-active', 'mdmonitor'])['out']) : 'unknown (no systemctl)',
            'mailaddr' => $conf['mailaddr'] !== '' ? $conf['mailaddr'] : null, 'mail_agent' => is_file(MdRun::path('/usr/sbin/sendmail')),
            'conf_lines_not_running' => $stale === null ? 'not compared (run as root, without --array)' : $stale,
            'note' => $conf['mailaddr'] === '' || !is_file(MdRun::path('/usr/sbin/sendmail')) ? 'a failed disk sends no mail here: mdmonitor needs MAILADDR and a mail agent' : null];
        return $records;
    }

    /** Current tunables; with a profile, what it wants beside them. */
    private static function tunables($md, array $info, $profile)
    {
        $t = ['stripe_cache_size' => MdState::sys($md, 'stripe_cache_size'), 'read_ahead_kb' => MdState::sys($md, 'queue/read_ahead_kb'),
            'sync_speed_min' => MdState::sys($md, 'sync_speed_min'), 'sync_speed_max' => MdState::sys($md, 'sync_speed_max')];
        if ($t['stripe_cache_size'] !== null && ctype_digit($t['stripe_cache_size'])) {
            $t['stripe_cache_pinned_mib'] = round(MdPlan::pinned((int) $t['stripe_cache_size'], $info['raid_disks']) / 1048576);
        }
        if ($profile !== null) {
            $t['profile_wants'] = ['stripe_cache_size' => $t['stripe_cache_size'] === null ? null : $profile['stripe_cache'], 'read_ahead_kb' => $profile['read_ahead_kb'] === null ? 'kernel default' : $profile['read_ahead_kb']];
        }
        return $t;
    }

    /** The md superblock of every whole disk: ['by_path' => [path => examine], 'by_uuid' => [uuid => [path => examine]]]. */
    private static function superblocks()
    {
        $out = ['by_path' => [], 'by_uuid' => []];
        foreach (MdDisks::whole() as $id) {
            $p = "/dev/disk/by-id/$id";
            $e = MdState::examine(MdRun::run(['mdadm', '--examine', $p])['out']);
            if ($e['uuid'] !== '') {
                $out['by_path'][$p] = $e;
                $out['by_uuid'][strtolower($e['uuid'])][$p] = $e;
            }
        }
        return $out;
    }
}
