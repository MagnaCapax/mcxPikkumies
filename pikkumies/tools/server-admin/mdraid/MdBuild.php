<?php
/**
 * MdBuild: create and tune (ADR-0095). create re-runs the disk preflight in this process, saves each disk's
 * --examine, wipes only the disks named in --destroy, then mdadm --create (metadata 1.2, internal bitmap on redundant
 * levels), mkfs.ext4 -n then mkfs with stride and stripe_width, this array's own ARRAY line appended to mdadm.conf,
 * update-initramfs -u, and reads it all back. A failed step stops and says what was done before it.
 * tune writes one oneshot systemd unit with the profile's values, applies them now and reads them back.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MdBuild
{
    const UNIT = '/etc/systemd/system/pikkumies-mdraid-tune.service';

    public static function create(array $opt)
    {
        MdRun::needRoot('create');
        $level = MdPlan::level(self::opt($opt, 'level', 'name the level: --level 1, 5, 10 or 0'));
        $profile = MdPlan::profile(self::opt($opt, 'profile', 'name a profile: --profile big-file, typical or small-file'));
        if ($level === 'raid0' && empty($opt['no-redundancy'])) {
            throw new CliError('RAID0 has no redundancy: one failed disk loses every file on the array; nothing was done', 'only if the user wants that, run it again with --no-redundancy');
        }
        $whole = MdDisks::whole();
        $serials = MdDisks::serials();
        $ks = [];
        foreach (array_filter(array_map('trim', explode(',', self::opt($opt, 'disks', 'name the disks: --disks <by-id or serial>,<...>'))), 'strlen') as $g) {
            $k = MdDisks::resolve($g, $whole, $serials);
            if (in_array($k, $ks, true)) {
                throw new CliError("$g is named twice; nothing was done", 'name each disk once');
            }
            $ks[] = $k;
        }
        $n = count($ks);
        if ($n < MdPlan::MIN_DISKS[$level] || ($level === 'raid10' && $n % 2 !== 0)) {
            throw new CliError("$level needs " . ($level === 'raid10' ? 'an even number of disks, at least 4' : 'at least ' . MdPlan::MIN_DISKS[$level] . ' disks') . ", got $n; nothing was done", 'run disks to pick more');
        }
        foreach (['mkfs.ext4', 'update-initramfs', 'systemctl'] as $p) {
            if (!MdRun::have($p)) {
                throw new CliError("$p is not installed; nothing was done", 'create needs e2fsprogs, initramfs-tools and systemd (Debian or Ubuntu); status works anywhere');
            }
        }
        $disks = MdOps::checkNew($ks, MdOps::destroyList($opt));
        list($usable, $lost, $share) = MdPlan::sizes(array_column($disks, 'size_bytes'));
        if ($share > MdPlan::SIZE_LOSS && empty($opt['accept-size-loss'])) {
            throw new CliError(sprintf('the disks differ in size: every member is cut to the smallest, losing %.1f GiB (%.1f%%); nothing was done', $lost / 1073741824, $share * 100),
                'pick disks of one size, or run it again with --accept-size-loss if the user accepts that loss');
        }
        $md = isset($opt['array']) && is_string($opt['array']) ? MdState::name($opt['array']) : self::freeName();
        self::nameFree($md);
        $geo = MdPlan::geometry($level, $n, $profile);
        $paths = array_map(function ($d) {
            return '/dev/disk/by-id/' . $d['disk'];
        }, array_values($disks));
        $create = array_merge(['mdadm', '--create', "/dev/$md", '--metadata=1.2', "--level=$level", "--raid-devices=$n"],
            $geo['chunk_kib'] === null ? [] : ['--chunk=' . $geo['chunk_kib']], $geo['bitmap'] ? ['--bitmap=internal'] : [], ['--run'], $paths);
        $mkfs = array_merge(['mkfs.ext4'], $geo['mkfs_options'], ["/dev/$md"]);
        $plan = ['array' => $md, 'geometry' => $geo, 'usable_bytes_per_member' => $usable, 'size_loss_bytes' => $lost, 'profile' => $opt['profile'], 'profile_label' => $profile['label'],
            'disks' => array_map(function ($d) {
                return ['disk' => $d['disk'], 'serial' => $d['serial'], 'size_bytes' => $d['size_bytes'], 'verdict' => $d['verdict'], 'destroyed' => $d['verdict'] === 'holds-data' ? MdOps::evidence($d) : null];
            }, array_values($disks)),
            'commands' => [ToolProc::line($create), ToolProc::line($mkfs), 'append this array\'s mdadm --detail --brief line to /etc/mdadm/mdadm.conf', 'update-initramfs -u'],
            'warning' => in_array($level, $profile['levels'], true) ? null : "$level is not what this profile suggests (" . implode(', ', $profile['levels']) . ')'];
        if ($level === 'raid5') {
            list($cache) = MdPlan::stripeCache($profile, $n, MdPlan::memTotal(), self::pinnedElsewhere(''));
            $plan['tune_after'] = ['stripe_cache_size' => $cache, 'pinned_mib' => round(MdPlan::pinned($cache, $n) / 1048576)];
        }
        if (!empty($opt['plan'])) {
            return ['records' => [$plan], 'status' => ['plan' => true]];
        }
        $done = [];
        try {
            $snap = MdOps::snapshot("create-$md", $paths);
            $done[] = 'saved each disk\'s --examine to ' . $snap['snapshot'];
            foreach ($disks as $d) {
                $done = array_merge($done, MdOps::wipe($d));
            }
            MdRun::must($create, 'mdadm --create');
            $done[] = "created /dev/$md";
            MdRun::must(array_merge(['mkfs.ext4', '-n'], array_slice($mkfs, 1)), 'mkfs.ext4 -n (the dry run)');
            MdRun::must($mkfs, 'mkfs.ext4');
            $done[] = "made ext4 on /dev/$md";
            $line = trim(MdRun::must(['mdadm', '--detail', '--brief', "/dev/$md"], 'mdadm --detail --brief')['out']);
            self::appendConf($md, $line);
            $done[] = 'added its ARRAY line to /etc/mdadm/mdadm.conf';
            MdRun::must(['update-initramfs', '-u'], 'update-initramfs -u');
            $done[] = 'updated the initramfs';
        } catch (CliError $e) {
            throw new CliError($e->getMessage() . '. Done before it: ' . ($done ? implode('; ', $done) : 'nothing'), $done ? 'do not run create again (the disks are now members); run status and continue by hand from the step that failed only after reading it' : $e->hint);
        }
        $d = MdState::parseExport(MdRun::run(['mdadm', '--detail', '--export', "/dev/$md"])['out']);
        $fs = MdState::parseExport(MdRun::run(['blkid', '-p', '-o', 'export', "/dev/$md"])['out']);
        $inConf = isset($d['MD_UUID']) && strpos(strtolower((string) MdRun::read('/etc/mdadm/mdadm.conf')), strtolower($d['MD_UUID'])) !== false;
        $verified = isset($d['MD_LEVEL'], $fs['TYPE']) && $d['MD_LEVEL'] === $level && (int) $d['MD_DEVICES'] === $n && $fs['TYPE'] === 'ext4' && $inConf;
        return ['records' => [$plan, ['array' => $md, 'uuid' => isset($d['MD_UUID']) ? $d['MD_UUID'] : null, 'done' => $done,
            'fstab_line' => 'UUID=' . (isset($fs['UUID']) ? $fs['UUID'] : '?') . ' <mount point> ext4 ' . $profile['mount'] . ',nofail 0 2',
            'next' => "tune --profile {$opt['profile']} --array $md; the initial resync runs in the background; reboot once before data goes on and check status",
            'verified' => $verified]], 'status' => ['verified' => $verified]];
    }

    public static function tune(array $opt)
    {
        MdRun::needRoot('tune');
        $name = self::opt($opt, 'profile', 'name a profile: --profile big-file, typical or small-file');
        $profile = MdPlan::profile($name);
        if (!MdRun::have('systemctl') || !is_dir(MdRun::path('/run/systemd/system'))) {
            throw new CliError('systemd is not running here; nothing was done', 'tune writes a systemd unit so the values come back after a reboot');
        }
        $all = array_keys(array_filter(MdState::arrays(), function ($a) {
            return $a['active'];
        }));
        $targets = isset($opt['array']) && is_string($opt['array']) ? [MdState::name($opt['array'])] : $all;
        if (!$targets || array_diff($targets, $all)) {
            throw new CliError('no running array to tune' . ($targets ? ': ' . implode(', ', array_diff($targets, $all)) . ' is not running' : '') . '; nothing was done', 'run status');
        }
        $values = [];
        $pinned = self::pinnedElsewhere($targets);
        foreach ($targets as $md) {
            $level = (string) MdState::sys($md, 'level');
            $n = (int) MdState::sys($md, 'raid_disks');
            $v = [];
            if (in_array($level, ['raid4', 'raid5', 'raid6'], true)) {
                list($cache, $why) = MdPlan::stripeCache($profile, $n, MdPlan::memTotal(), $pinned);
                $pinned += MdPlan::pinned($cache, $n);
                $v['md/stripe_cache_size'] = [$cache, $why . sprintf(', pins %.0f MiB', MdPlan::pinned($cache, $n) / 1048576)];
            }
            if ($profile['read_ahead_kb'] !== null) {
                $v['queue/read_ahead_kb'] = [$profile['read_ahead_kb'], 'profile value'];
            }
            $values[$md] = $v;
        }
        $unit = self::unitText($values, $name);
        if (!empty($opt['plan'])) {
            return ['records' => [['values' => $values, 'unit' => self::UNIT, 'unit_text' => $unit, 'label' => $profile['label']]], 'status' => ['plan' => true]];
        }
        $old = MdRun::read(self::UNIT);
        $kept = $old === null ? null : MdRun::keep('tune-unit-before', $old);
        MdRun::write(self::UNIT, $unit);
        MdRun::must(['systemctl', 'daemon-reload'], 'systemctl daemon-reload');
        MdRun::must(['systemctl', 'enable', PathName::base(self::UNIT)], 'systemctl enable');
        $records = [];
        $verified = true;
        foreach ($values as $md => $v) {
            foreach ($v as $file => $val) {
                MdRun::write("/sys/block/$md/$file", $val[0] . "\n");
                $now = (int) MdState::sys($md, $file);
                $verified = $verified && $now === (int) $val[0];
                $records[] = ['array' => $md, 'setting' => PathName::base($file), 'value' => $now, 'want' => $val[0], 'why' => $val[1]];
            }
        }
        $records[] = ['unit' => self::UNIT, 'previous_unit_saved' => $kept, 'profile' => $name, 'label' => $profile['label'],
            'measure' => 'measure before data goes on; a value here is a default, not a measured optimum', 'verified' => $verified];
        return ['records' => $records, 'status' => ['verified' => $verified]];
    }

    /** The unit: this run's arrays, plus the lines of arrays it does not touch from the unit already there. */
    private static function unitText(array $values, $profile)
    {
        $lines = [];
        foreach (explode("\n", (string) MdRun::read(self::UNIT)) as $l) {
            if ((preg_match('#^\# (md[0-9]+) [a-z-]+$#', $l, $m) || preg_match("#^ExecStart=/bin/sh -c 'echo [0-9]+ > /sys/block/(md[0-9]+)/(md/stripe_cache_size|queue/read_ahead_kb)'$#", $l, $m))
                && !isset($values[$m[1]])) { // only lines of this tool's exact shape are kept
                $lines[] = $l;
            }
        }
        foreach ($values as $md => $v) {
            $lines[] = "# $md $profile";
            foreach ($v as $file => $val) {
                $lines[] = "ExecStart=/bin/sh -c 'echo " . (int) $val[0] . " > /sys/block/$md/$file'";
            }
        }
        return "[Unit]\nDescription=md RAID tunables written by the Pikkumies mdraid tool (tune --profile rewrites this file)\nAfter=local-fs.target\n\n"
            . "[Service]\nType=oneshot\nRemainAfterExit=yes\n" . implode("\n", $lines) . "\n\n[Install]\nWantedBy=multi-user.target\n";
    }

    /** Bytes the stripe caches of running parity arrays other than $skip pin now. */
    private static function pinnedElsewhere($skip)
    {
        $total = 0;
        foreach (MdState::arrays() as $md => $a) {
            $cache = MdState::sys($md, 'stripe_cache_size');
            if ($a['active'] && !in_array($md, (array) $skip, true) && $cache !== null && ctype_digit($cache)) {
                $total += MdPlan::pinned((int) $cache, (int) MdState::sys($md, 'raid_disks'));
            }
        }
        return $total;
    }

    private static function freeName()
    {
        for ($i = 0; $i < 128; $i++) {
            try {
                self::nameFree("md$i");
                return "md$i";
            } catch (CliError $e) {
                continue;
            }
        }
        throw new CliError('no free md number; nothing was done', 'name one with --array mdN');
    }

    /** mdN must be free: not in /proc/mdstat and not in an ARRAY line of mdadm.conf. */
    private static function nameFree($md)
    {
        $num = substr($md, 2);
        $taken = isset(MdState::arrays()[$md]);
        foreach (MdState::conf()['arrays'] as $a) {
            $taken = $taken || in_array($a['device'], ["/dev/$md", "/dev/md/$num"], true) || preg_match('/(^|:)' . preg_quote($num, '/') . '$/', $a['name']);
        }
        if ($taken) {
            throw new CliError("$md is taken (running, or named in mdadm.conf); nothing was done", 'leave out --array to take the first free number, or name another');
        }
    }

    /** Appends only this array's line; refused when its UUID or name is already there (a --scan would also copy foreign arrays). */
    private static function appendConf($md, $line)
    {
        $new = MdState::parseConf($line)['arrays'];
        if (count($new) !== 1 || $new[0]['uuid'] === '') {
            throw new CliError("mdadm --detail --brief /dev/$md did not print one ARRAY line with a UUID", 'run status');
        }
        $text = (string) MdRun::read('/etc/mdadm/mdadm.conf');
        foreach (MdState::parseConf($text)['arrays'] as $a) {
            if ($a['uuid'] === $new[0]['uuid'] || ($a['name'] !== '' && $a['name'] === $new[0]['name'])) {
                throw new CliError("mdadm.conf already has a line for this array ({$a['line']})", 'run status to compare it with the running array');
            }
        }
        if ($text !== '') {
            MdRun::keep('mdadm.conf-before', $text);
        }
        MdRun::write('/etc/mdadm/mdadm.conf', ($text !== '' && substr($text, -1) !== "\n" ? "\n" : '') . $new[0]['line'] . "\n", true);
    }

    private static function opt(array $opt, $key, $hint)
    {
        if (!isset($opt[$key]) || !is_string($opt[$key]) || $opt[$key] === '') {
            throw new CliError("--$key is missing; nothing was done", $hint);
        }
        return $opt[$key];
    }
}
