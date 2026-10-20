<?php
/**
 * MdState: what the arrays are now (ADR-0095). Parses /proc/mdstat, /sys/block/mdN/md/*, mdadm --examine and
 * --detail --export, and mdadm.conf; the N-1 verdict compares the members' superblocks (Events, Update Time, Role).
 * Pure parsers take text, so tests feed fixtures; the readers go through MdRun.
 */
class MdState
{
    /** /proc/mdstat -> [mdN => ['name','active','level','members' => [[kname, slot, flag]], 'devices','present','map','action','progress']]. */
    public static function parseMdstat($text)
    {
        $out = [];
        $cur = null;
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^(md[0-9]+)\s*:\s*(active|inactive)(?:\s+\((?:auto-)?read-only\))?\s*(.*)$/', $line, $m)) {
                $words = preg_split('/\s+/', trim($m[3]), -1, PREG_SPLIT_NO_EMPTY);
                $level = $words && preg_match('/^(raid[0-9]+|linear|multipath|faulty)$/', $words[0]) ? array_shift($words) : '';
                $members = [];
                foreach ($words as $w) {
                    if (preg_match('/^([a-z0-9_.-]+)\[([0-9]+)\](\((F|S|W|R|J)\))*$/', $w, $mm)) {
                        preg_match_all('/\(([FSWRJ])\)/', $w, $flags);
                        $members[] = ['kname' => $mm[1], 'slot' => (int) $mm[2], 'flags' => implode('', $flags[1])];
                    }
                }
                $cur = $m[1];
                $out[$cur] = ['name' => $cur, 'active' => $m[2] === 'active', 'readonly' => strpos($line, 'read-only') !== false,
                    'level' => $level, 'members' => $members, 'devices' => null, 'present' => null, 'map' => '', 'action' => 'idle', 'progress' => null];
                continue;
            }
            if ($cur === null) {
                continue;
            }
            if (preg_match('/\[([0-9]+)\/([0-9]+)\]\s+\[([U_]+)\]/', $line, $m)) {
                $out[$cur]['devices'] = (int) $m[1];
                $out[$cur]['present'] = (int) $m[2];
                $out[$cur]['map'] = $m[3];
            }
            if (preg_match('/\b(recovery|resync|reshape|check|repair)\s*=\s*([0-9.]+)%/', $line, $m)) {
                $out[$cur]['action'] = $m[1];
                $out[$cur]['progress'] = (float) $m[2];
            } elseif (preg_match('/\b(resync|recovery)\s*=\s*(DELAYED|PENDING)/', $line, $m)) {
                $out[$cur]['action'] = $m[1] . '-' . strtolower($m[2]);
            }
            if (trim($line) === '') {
                $cur = null;
            }
        }
        return $out;
    }

    /** "Key : Value" lines of mdadm --examine or --detail -> [key => value]. */
    public static function parseColon($text)
    {
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('#^\s*([A-Za-z][A-Za-z /-]*?)\s+:\s+(.*)$#', $line, $m)) {
                $out[$m[1]] = trim($m[2]);
            }
        }
        return $out;
    }

    /** KEY=value lines (--export, blkid -o export) -> [KEY => value]. */
    public static function parseExport($text)
    {
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    /** One member's superblock from mdadm --examine: uuid, name, level, devices, events, updated, role (int|null), state. */
    public static function examine($text)
    {
        $e = self::parseColon($text);
        $role = isset($e['Device Role']) && preg_match('/Active device ([0-9]+)/', $e['Device Role'], $m) ? (int) $m[1] : null;
        return [
            'uuid' => isset($e['Array UUID']) ? $e['Array UUID'] : (isset($e['UUID']) ? $e['UUID'] : ''),
            'name' => isset($e['Name']) ? preg_replace('/\s+\(.*$/', '', $e['Name']) : '',
            'level' => isset($e['Raid Level']) ? $e['Raid Level'] : '',
            'devices' => isset($e['Raid Devices']) ? (int) $e['Raid Devices'] : 0,
            'events' => isset($e['Events']) ? (int) $e['Events'] : -1,
            'updated' => isset($e['Update Time']) ? $e['Update Time'] : '',
            'role' => $role,
            'spare' => isset($e['Device Role']) && stripos($e['Device Role'], 'spare') !== false,
            'bitmap' => isset($e['Internal Bitmap']) || (isset($e['Bitmap']) && $e['Bitmap'] !== ''),
            'chunk_kib' => isset($e['Chunk Size']) ? (int) $e['Chunk Size'] : 0,
            'array_state' => isset($e['Array State']) ? $e['Array State'] : '',
        ];
    }

    /** How many members a level may lose and still run. */
    public static function maxDegraded($level, $devices)
    {
        $level = strtolower(preg_replace('/^raid/', '', (string) $level));
        $map = ['1' => max(0, $devices - 1), '4' => 1, '5' => 1, '6' => 2, '10' => 1];
        return isset($map[$level]) ? $map[$level] : 0;
    }

    /**
     * The N-1 verdict over the members that carry this array's superblock ([by-id => examine()]):
     * consistent (every member at the newest Events), n-1 (exactly one behind and the rest can run the array),
     * stop (two or more behind, too few to run, or two members claiming one role). Spares are left out.
     */
    public static function verdict(array $members, $level, $devices)
    {
        $active = array_filter($members, function ($m) {
            return !$m['spare'] && $m['events'] >= 0;
        });
        if (!$active) {
            return ['verdict' => 'stop', 'why' => 'no member superblock was readable', 'events' => null, 'group' => [], 'stale' => []];
        }
        $newest = max(array_column($active, 'events'));
        $group = array_keys(array_filter($active, function ($m) use ($newest) {
            return $m['events'] === $newest;
        }));
        $stale = array_values(array_diff(array_keys($active), $group));
        $roles = array_filter(array_map(function ($k) use ($active) {
            return $active[$k]['role'];
        }, $group), 'is_int');
        $updated = array_unique(array_map(function ($k) use ($active) {
            return $active[$k]['updated'];
        }, $group));
        $need = $devices - self::maxDegraded($level, $devices);
        $base = ['events' => $newest, 'group' => $group, 'stale' => $stale, 'update_time_differs' => count($updated) > 1, 'needs' => $need];
        if (count($roles) !== count(array_unique($roles))) {
            return ['verdict' => 'stop', 'why' => 'two members at the newest Events claim the same role'] + $base;
        }
        if (count($stale) >= 2) {
            return ['verdict' => 'stop', 'why' => count($stale) . ' members are behind: assembling would mix states; keep the disks untouched'] + $base;
        }
        if (count($group) < $need) {
            return ['verdict' => 'stop', 'why' => count($group) . " members agree, the array needs $need to run"] + $base;
        }
        if ($stale) {
            return ['verdict' => 'n-1', 'why' => "one member is behind ({$active[$stale[0]]['events']} vs $newest Events): leave it out, re-add it after"] + $base;
        }
        return ['verdict' => 'consistent', 'why' => count($group) < $devices ? count($group) . " of $devices members, all at the newest Events" : 'every member at the newest Events'] + $base;
    }

    /** mdadm.conf ARRAY lines -> [['device','uuid','name','line']]; MAILADDR as 'mailaddr'. */
    public static function parseConf($text)
    {
        $arrays = [];
        $mail = '';
        foreach (explode("\n", (string) $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (preg_match('/^ARRAY\s+(\S+)(.*)$/', $line, $m)) {
                preg_match('/\bUUID=([0-9a-fA-F:]+)/', $m[2], $u);
                preg_match('/\bname=(\S+)/', $m[2], $n);
                $arrays[] = ['device' => $m[1], 'uuid' => isset($u[1]) ? strtolower($u[1]) : '', 'name' => isset($n[1]) ? $n[1] : '', 'line' => $line];
            } elseif (preg_match('/^MAILADDR\s+(\S+)/', $line, $m)) {
                $mail = $m[1];
            }
        }
        return ['arrays' => $arrays, 'mailaddr' => $mail];
    }

    public static function conf()
    {
        return self::parseConf(MdRun::read('/etc/mdadm/mdadm.conf') . "\n" . MdRun::read('/etc/mdadm.conf')); // Debian path, then the Red Hat one
    }

    public static function arrays()
    {
        return self::parseMdstat((string) MdRun::read('/proc/mdstat'));
    }

    /** One value of /sys/block/mdN/md/<file> (queue/... and md/... are taken from /sys/block/mdN/), trimmed; null when unreadable. */
    public static function sys($md, $file)
    {
        $t = MdRun::read("/sys/block/$md/" . (preg_match('#^(queue|md)/#', $file) ? $file : "md/$file"));
        return $t === null ? null : trim($t);
    }

    /** The members of a running array from sysfs: [kname => ['slot' => int|null, 'state' => '...']]. */
    public static function sysMembers($md)
    {
        $out = [];
        foreach (MdRun::ls("/sys/block/$md/md") as $e) {
            if (strpos($e, 'dev-') === 0) {
                $slot = self::sys($md, "$e/slot");
                $out[substr($e, 4)] = ['slot' => $slot !== null && ctype_digit($slot) ? (int) $slot : null, 'state' => (string) self::sys($md, "$e/state")];
            }
        }
        return $out;
    }

    /** The md name: md plus digits, or a CliError. */
    public static function name($md)
    {
        $md = preg_replace('#^/dev/#', '', (string) $md);
        if (!preg_match('/^md[0-9]+$/', $md)) {
            throw new CliError("array must be named mdN (md0, md1, ...), got '$md'; nothing was done", 'run status to list the arrays');
        }
        return $md;
    }
}
