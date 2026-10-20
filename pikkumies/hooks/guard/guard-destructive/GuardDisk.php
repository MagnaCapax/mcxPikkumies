<?php
/**
 * The raw disk and md RAID rule of the guard (ADR-0095), loaded by guard-destructive.php. Refused outright, never
 * asked: the mdraid tool (tools/server-admin/mdraid/) is the one way to build, recover or tune an array, because it
 * reads the live device state just before it writes and a raw command cannot.
 * - mdadm read word by word (mdadm(8) and ReadMe.c, v4.4): create, build, grow, incremental, zero-superblock,
 *   assume-clean, write-zeroes, every manage operation (add, re-add, fail, remove, replace, ...), assemble or misc
 *   --force/--run/--update, --readwrite, --action other than idle; an unknown or ambiguous option is refused too.
 *   The mode comes from argv the way mdadm finds it (a device first, or a first manage option, is manage mode), so
 *   -f is --fail there, --force in assemble and misc, --daemonise in monitor.
 * - other disk destroyers: the formatters the mkfs rule misses (mke2fs, mkntfs, ..., make-bcache, bcachefs format,
 *   zpool create/labelclear), cryptsetup luksFormat/erase, pvcreate; partitioners the fdisk rule misses (sfdisk,
 *   cfdisk, gdisk, cgdisk; their list and dump options run); a write to a disk's /device/delete.
 *   Raw drive writes and drive tooling (badblocks -w, hdparm, blkdiscard, mkswap on a device, nvme and sg format or
 *   sanitize, output written onto a device, md repair by a sync_action write) are GuardDrives.php's (ADR-0094), checked
 *   before this file: one owner per command, so each refusal names the right tool.
 * - filesystem repair without -n: e2fsck, fsck, fsck.*, dosfsck, xfs_repair.
 * - a write into an md array's control files (/sys/block/mdN/, /sys/dev/block/9:N/), judged per part of the command;
 *   also a cd into the block sysfs tree followed by a write to an md attribute by a relative name. The scrub values the
 *   drives guard allows (check and idle written to sync_action, ADR-0094) run.
 * A safety prompt, not security (AGENTS.md): a command name built in a variable or in code is out of its sight.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class GuardDisk
{
    const TOOL = '. The mdraid tool does this after checking the live device state (server-admin/mdraid: status, disks, create, add, replace, re-add, assemble, check, idle, tune); run it with php, not sudo: it uses root itself, directly as uid 0 or through sudo -n';
    /** Long options: name => [argument (0 none, 1 required, 2 optional), class]. Classes other than ok refuse or set the mode. */
    const LONG = [
        'manage' => [0, 'manage'], 'misc' => [0, 'misc'], 'assemble' => [0, 'A'], 'build' => [0, 'B'], 'create' => [0, 'C'], 'follow' => [0, 'F'],
        'monitor' => [0, 'F'], 'grow' => [0, 'G'], 'incremental' => [0, 'I'], 'detail' => [0, 'ok'], 'examine' => [0, 'ok'], 'query' => [0, 'ok'],
        'examine-bitmap' => [0, 'ok'], 'detail-platform' => [0, 'ok'], 'examine-badblocks' => [0, 'ok'], 'offroot' => [0, 'ok'], 'dump' => [1, 'ok'],
        'help' => [0, 'ok'], 'help-options' => [0, 'ok'], 'version' => [0, 'ok'], 'verbose' => [0, 'ok'], 'quiet' => [0, 'ok'], 'chunk' => [1, 'ok'],
        'rounding' => [1, 'ok'], 'level' => [1, 'ok'], 'parity' => [1, 'ok'], 'layout' => [1, 'ok'], 'raid-disks' => [1, 'ok'], 'raid-devices' => [1, 'ok'],
        'spare-disks' => [1, 'ok'], 'spare-devices' => [1, 'ok'], 'size' => [1, 'ok'], 'auto' => [1, 'ok'], 'metadata' => [1, 'ok'], 'bitmap' => [1, 'ok'],
        'bitmap-chunk' => [1, 'ok'], 'write-behind' => [2, 'ok'], 'homehost' => [1, 'ok'], 'data-offset' => [1, 'ok'], 'nodes' => [1, 'ok'],
        'home-cluster' => [1, 'ok'], 'write-journal' => [1, 'ok'], 'consistency-policy' => [1, 'ok'], 'uuid' => [1, 'ok'], 'super-minor' => [1, 'ok'],
        'name' => [1, 'ok'], 'config' => [1, 'ok'], 'scan' => [0, 'ok'], 'freeze-reshape' => [0, 'ok'], 'stop' => [0, 'ok'], 'readonly' => [0, 'ok'],
        'no-degraded' => [0, 'ok'], 'wait' => [0, 'ok'], 'wait-clean' => [0, 'ok'], 'brief' => [0, 'ok'], 'no-devices' => [0, 'ok'], 'export' => [0, 'ok'],
        'sparc2.2' => [0, 'ok'], 'test' => [0, 'ok'], 'prefer' => [1, 'ok'], 'mail' => [1, 'ok'], 'program' => [1, 'ok'], 'alert' => [1, 'ok'],
        'increment' => [1, 'ok'], 'delay' => [1, 'ok'], 'daemonise' => [0, 'ok'], 'daemonize' => [0, 'ok'], 'oneshot' => [0, 'ok'], 'pid-file' => [1, 'ok'],
        'syslog' => [0, 'ok'], 'no-sharing' => [0, 'ok'], 'backup-file' => [1, 'ok'], 'path' => [1, 'ok'], 'action' => [1, 'action'],
        'add' => [0, 'op'], 'add-spare' => [0, 'op'], 'add-journal' => [0, 'op'], 're-add' => [0, 'op'], 'remove' => [0, 'op'], 'fail' => [0, 'op'],
        'set-faulty' => [0, 'op'], 'replace' => [0, 'op'], 'with' => [0, 'op'], 'write-mostly' => [0, 'op'], 'failfast' => [0, 'op'],
        'nofailfast' => [0, 'op'], 'cluster-confirm' => [0, 'op'], 'zero-superblock' => [0, 'deny'], 'assume-clean' => [0, 'deny'],
        'write-zeroes' => [0, 'deny'], 'force' => [0, 'deny'], 'run' => [0, 'deny'], 'update' => [1, 'deny'], 'readwrite' => [0, 'deny'],
        'invalid-backup' => [0, 'deny'], 'array-size' => [1, 'deny'], 'continue' => [0, 'deny'], 'rebuild-map' => [0, 'deny'],
        'kill-subarray' => [1, 'deny'], 'update-subarray' => [1, 'deny'], 'restore' => [1, 'deny'], 'udev-rules' => [2, 'deny'], 'auto-detect' => [0, 'deny'],
    ];
    /** Short options that take a value in every mdadm variant (ReadMe.c short_opts "...Z:...c:i:l:p:m:n:x:u:c:d:z:U:N:...e:k:"). */
    const SHORT_ARG = 'Zcilpmnxudz' . 'UNek';
    const SHORT = ['A' => 'assemble', 'B' => 'build', 'C' => 'create', 'D' => 'detail', 'E' => 'examine', 'F' => 'monitor', 'G' => 'grow',
        'I' => 'incremental', 'Q' => 'query', 'h' => 'help', 'V' => 'version', 'X' => 'examine-bitmap', 'Y' => 'export', 'Z' => 'array-size',
        'v' => 'verbose', 'q' => 'quiet', 'b' => 'brief', 'c' => 'config', 'i' => 'pid-file', 'l' => 'level', 'p' => 'layout', 'm' => 'mail',
        'n' => 'raid-devices', 'x' => 'spare-devices', 'u' => 'uuid', 'd' => 'delay', 'z' => 'size', 'U' => 'update', 'N' => 'name', 'e' => 'metadata',
        'k' => 'consistency-policy', 's' => 'scan', 'a' => 'add', 'R' => 'run', 'S' => 'stop', 'o' => 'readonly', 'w' => 'readwrite', '1' => 'oneshot',
        't' => 'test', 'y' => 'syslog'];
    const PREFIX = '/^(sudo|doas|env|nice|ionice|timeout|nohup|stdbuf|command|exec|time|chrt|taskset|xargs)$/';
    const MD_SYSFS = '#/sys/(block|class/block|devices/virtual/block)/md[0-9]+(/|\b)|/sys/dev/block/9:#'; // 9 is md's block major
    /** check or idle written to an md sync_action (a scrub, or stopping one): the drives guard allows these (ADR-0094). */
    const SCRUB = '#\b(echo|printf)\s+(-\S+\s+)*["\']?(check|idle)["\']?\s*(>|\|\s*(sudo\s+)?tee(\s+-a)?)\s*["\']?(/sys/(block|class/block|devices/virtual/block)/md[0-9]+/+md/+|(md[0-9]+/+)?md/+)?sync_action\b["\']?#';
    /** A write in a command: a redirect (not 2> or >&, not to /dev/null), tee, of=, or a write call in code. */
    const WRITE = '#(?<![0-9&>])>(?!&)(?!>?\s*/dev/null)|\btee\b|\bof=|file_put_contents|fwrite|write_text|\.write\(|\bopen\([^)]*,\s*[wa]#';
    const MD_ATTR = '#\b(sync_action|array_state|stripe_cache_size|bitmap_set_bits|sync_min|sync_max|suspend_lo|suspend_hi|safe_mode_delay|sync_speed_min|sync_speed_max|consistency_policy|sync_force_parallel|new_dev|resync_start|reshape_position|chunk_size|raid_disks|component_size|layout|level)\b#';

    /** The reason to refuse, or null. $texts: every form of the command (guardTexts). */
    public static function check(array $texts)
    {
        foreach ($texts as $text) {
            $text = (string) preg_replace(self::SCRUB, ':', $text); // a scrub write is not this guard's to refuse
            $why = self::simple($text);
            foreach (preg_split('/\|\||&&|[;&|\n`]|\$\(/', $text) as $segment) {
                $why = $why !== null ? $why : self::writes($segment);
                $why = $why !== null ? $why : self::segment($segment);
            }
            if ($why !== null) {
                return $why . self::TOOL;
            }
        }
        return null;
    }

    private static function simple($text)
    {
        $rules = [
            '/\b(mke2fs|mkntfs|mkdosfs|mkexfatfs|mkreiserfs|mkudffs)\b/' => 'filesystem format',
            '/\bcryptsetup\b[^;&|\n]*\b(luksFormat|erase|luksErase)\b/' => 'encrypted volume format or key erase',
            '/\bpvcreate\b/' => 'LVM physical volume format',
            '/\b(make-bcache|bcachefs\s+format|zpool\s+(create|labelclear))\b/' => 'filesystem format',
            '/\b(sfdisk|cfdisk|gdisk|cgdisk)\b(?![^;&|\n]*\s(-l|--list|-d|--dump|-V|--verify|--version|--help)\b)/' => 'partition table change',
        ];
        foreach ($rules as $re => $why) {
            if (preg_match($re, $text) !== 0) { // a regex engine error (false) refuses too
                return $why;
            }
        }
        // A cd into the block sysfs tree, then a write to an md attribute by a relative name (md/sync_action, array_state).
        if (preg_match('#\bcd\s+["\']?/sys/(block|class/block|devices/virtual/block|dev/block)\b#', $text) !== 0 && preg_match(self::MD_ATTR, $text) !== 0 && preg_match(self::WRITE, $text) !== 0) {
            return 'raw write to an md array control file';
        }
        return null;
    }

    /** Writes inside one part of a command: to an md control file or to a disk's delete file. */
    private static function writes($segment)
    {
        $write = preg_match(self::WRITE, $segment) !== 0;
        if ($write && preg_match(self::MD_SYSFS, $segment) !== 0) {
            return 'raw write to an md array control file';
        }
        if ($write && preg_match('#/device/delete\b#', $segment) !== 0) {
            return 'removes a disk from the system';
        }
        return null;
    }

    private static function segment($segment)
    {
        $words = array_values(array_filter(preg_split('/[\s,\[\]()"\'{}]+/', $segment), 'strlen'));
        $first = true;
        foreach ($words as $i => $w) {
            $name = PathName::base($w);
            $rest = array_slice($words, $i + 1);
            if ($name === 'mdadm' || $name === 'mdadm.static') {
                $why = self::mdadmWhy($rest);
                if ($why !== null) {
                    return 'mdadm that changes an array or its members (' . $why . ')';
                }
            } elseif (preg_match('/^(e2fsck|fsck(\.[a-z0-9]+)?|dosfsck|xfs_repair)$/', $name)
                && ($first || preg_grep('#^/dev/#', $rest)) && !self::repairReadOnly($name, $rest)) {
                return 'filesystem repair without -n';
            }
            $first = $first && (preg_match(self::PREFIX, $name) || $w[0] === '-' || ctype_digit($w) || strpos($w, '=') !== false);
        }
        return null;
    }

    /** e2fsck, fsck and dosfsck with -n (and none of the options that write anyway), fsck -N, xfs_repair -n without -L. */
    private static function repairReadOnly($name, array $args)
    {
        $flags = implode('', array_map(function ($a) {
            return preg_match('/^-[A-Za-z]+$/', $a) ? substr($a, 1) : '';
        }, $args));
        if ($name === 'xfs_repair') {
            return strpos($flags, 'n') !== false && strpos($flags, 'L') === false;
        }
        return ($name === 'fsck' && in_array('-N', $args, true)) || (strpos($flags, 'n') !== false && strcspn($flags, 'ypacLlED') === strlen($flags));
    }

    /** Why this mdadm argv changes something, or null when it only reads, stops, assembles plainly or monitors. */
    public static function mdadmWhy(array $argv)
    {
        $mode = null;
        for ($i = 0; $i < count($argv); $i++) {
            $a = (string) $argv[$i];
            if ($a === '--') {
                break;
            }
            if ($a === '' || $a[0] !== '-' || $a === '-') {
                $mode = $mode !== null ? $mode : 'manage';
                continue;
            }
            $opts = [];
            if (substr($a, 0, 2) === '--') {
                $eq = strpos($a, '=');
                $long = self::long($eq === false ? substr($a, 2) : substr($a, 2, $eq - 2)); // PHP 7.3 reads a null length as 0
                if ($long === null) {
                    return 'an option it cannot check: ' . $a;
                }
                $value = $eq !== false ? substr($a, $eq + 1) : (self::LONG[$long][0] === 1 && isset($argv[$i + 1]) ? $argv[++$i] : null);
                $opts[] = [$long, $value];
            } else {
                for ($j = 1; $j < strlen($a); $j++) {
                    $c = $a[$j];
                    if ($mode === 'F' && ($c === 'f' || $c === 'r')) { // monitor: -f daemonise, -r <increment>
                        $i += $c === 'r' && $j === strlen($a) - 1 ? 1 : 0;
                        break;
                    }
                    $name = $c === 'f' ? ($mode === 'manage' || $mode === 'I' || $mode === null ? 'fail' : 'force')
                        : ($c === 'r' ? 'remove' : ($c === 'W' ? ($mode === 'manage' ? 'write-mostly' : 'wait') : (isset(self::SHORT[$c]) ? self::SHORT[$c] : null)));
                    if ($name === null) {
                        return 'an option it cannot check: -' . $c;
                    }
                    $value = null;
                    if (strpos(self::SHORT_ARG, $c) !== false) {
                        $value = $j < strlen($a) - 1 ? substr($a, $j + 1) : (isset($argv[$i + 1]) ? $argv[++$i] : '');
                        $j = strlen($a);
                    }
                    $opts[] = [$name, $value];
                }
            }
            foreach ($opts as $o) {
                list($name, $value) = $o;
                $class = self::LONG[$name][1];
                if ($mode === null) {
                    $mode = in_array($class, ['A', 'B', 'C', 'F', 'G', 'I', 'manage', 'misc'], true) ? $class
                        : (in_array($name, ['add', 're-add', 'add-spare', 'fail', 'remove', 'replace'], true) ? 'manage' : 'misc');
                }
                if (in_array($class, ['B', 'C', 'G', 'I', 'op', 'deny'], true) || ($class === 'action' && $value !== 'idle')) {
                    return '--' . $name . ($value !== null && $class === 'action' ? '=' . $value : '');
                }
            }
        }
        return null;
    }

    /** The long option getopt_long would pick for $name: exact, else a unique prefix (or several that mean the same); null otherwise. */
    private static function long($name)
    {
        if (isset(self::LONG[$name])) {
            return $name;
        }
        $hits = $name === '' ? [] : array_filter(array_keys(self::LONG), function ($k) use ($name) {
            return strpos($k, $name) === 0;
        });
        $same = array_unique(array_map(function ($k) {
            return json_encode(self::LONG[$k]);
        }, $hits));
        return count($same) === 1 ? reset($hits) : null;
    }
}
