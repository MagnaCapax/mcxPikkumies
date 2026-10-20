<?php
/**
 * MdDisks: the disks a build or recovery may use, and why not (ADR-0095). A disk is named by its /dev/disk/by-id
 * entry or its serial, never by sdX. Verdict per disk:
 * - in-use (refused, no flag clears it): it or a partition is mounted, swap, in fstab or crypttab, held by md or dm,
 *   open by a process, a btrfs or loop device, or its superblock belongs to a live array or one other disks could
 *   still assemble (MdUse);
 * - holds-data (needs its serial in --destroy): partitions, any signature (blkid -p, wipefs -n), an md superblock,
 *   or any non-zero byte in the sampled reads;
 * - blank: none of that.
 * No signature is not empty: the sampler reads fixed offsets and reports counts of non-zero bytes, never the bytes.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MdDisks
{
    const SAMPLE = 65536;
    const FRACTIONS = [0.01, 0.05, 0.1, 0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 0.99];
    const FIXED = [0, 4096, 1048576, 134217728, 135266304];
    const CONF_HIT = 'its md superblock names ';

    /** Whole disks by by-id name, one name per disk (ata-/nvme-/scsi- before wwn-/eui-): [kname => by-id basename]. */
    public static function whole()
    {
        $out = [];
        foreach (MdRun::byId() as $id => $k) {
            if (preg_match('/-part[0-9]+$/', $id) || !is_dir(MdRun::path("/sys/block/$k")) || preg_match('/^(md|dm-|loop|sr|zram|ram)/', $k)) {
                continue;
            }
            $rank = preg_match('/^(wwn|eui|nvme-eui|nvme-nvme)\b|^dm-|^md-/', $id) ? 1 : 0;
            if (!isset($out[$k]) || $rank < $out[$k][1]) {
                $out[$k] = [$id, $rank];
            }
        }
        return array_map(function ($v) {
            return $v[0];
        }, $out);
    }

    /** Serial and size from lsblk; size falls back to /sys/block/<k>/size. */
    public static function serials()
    {
        $r = MdRun::run(['lsblk', '-J', '-d', '-b', '-o', 'NAME,SERIAL,SIZE,MODEL,ROTA']);
        $j = json_decode($r['out'], true);
        $out = [];
        foreach (isset($j['blockdevices']) ? $j['blockdevices'] : [] as $d) {
            $out[(string) $d['name']] = ['serial' => trim((string) (isset($d['serial']) ? $d['serial'] : '')), 'model' => trim((string) (isset($d['model']) ? $d['model'] : '')),
                'rotational' => !empty($d['rota']) && $d['rota'] !== '0'];
        }
        return $out;
    }

    /** A name the user gave (by-id basename, /dev/disk/by-id/ path or serial) -> kname; sdX and anything else is refused. */
    public static function resolve($given, array $whole, array $serials)
    {
        $id = preg_replace('#^/dev/disk/by-id/#', '', (string) $given);
        $bySerial = [];
        foreach ($whole as $k => $name) {
            if ($name === $id) {
                return $k;
            }
            if (isset($serials[$k]) && $serials[$k]['serial'] !== '' && $serials[$k]['serial'] === $given) {
                $bySerial[] = $k;
            }
        }
        if (count($bySerial) > 1) { // some USB bridges report one serial for every disk behind them
            throw new CliError("serial '$given' belongs to " . count($bySerial) . ' disks; nothing was done', 'name the disk by its /dev/disk/by-id name instead (run disks)');
        }
        if ($bySerial) {
            return $bySerial[0];
        }
        $byId = MdRun::byId();
        if (isset($byId[$id]) && isset($whole[$byId[$id]]) && !preg_match('#^/dev/#', (string) $given)) {
            return $byId[$id]; // another by-id name of the same whole disk
        }
        if (preg_match('#^(/dev/)?(sd[a-z]+|nvme[0-9]+n[0-9]+|vd[a-z]+|hd[a-z]+|xvd[a-z]+)$#', (string) $given)) {
            throw new CliError("'$given' is a kernel name, which can change at the next boot; nothing was done", 'name the disk by its /dev/disk/by-id name or its serial (run disks)');
        }
        throw new CliError("no whole disk named '$given' (by-id name or serial); nothing was done", 'run disks to list them; a partition is not accepted');
    }

    /** Partitions of a whole disk (kernel names). */
    public static function partitions($k)
    {
        $out = [];
        foreach (MdRun::ls("/sys/block/$k") as $e) {
            if (strpos($e, $k) === 0 && $e !== $k && is_file(MdRun::path("/sys/block/$k/$e/partition"))) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** The by-id path of a kernel name (whole disk or partition), or null. */
    public static function idPath($k)
    {
        foreach (MdRun::byId() as $id => $kn) {
            if ($kn === $k) {
                return "/dev/disk/by-id/$id";
            }
        }
        return null;
    }

    /** Mounted and swap sources as kernel names: [kname => where]. */
    public static function mounted()
    {
        $out = [];
        foreach (explode("\n", (string) MdRun::read('/proc/mounts')) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) >= 2 && strpos($f[0], '/dev/') === 0) {
                $out[self::kname($f[0])] = 'mounted at ' . $f[1];
            }
        }
        foreach (array_slice(explode("\n", (string) MdRun::read('/proc/swaps')), 1) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if ($f[0] !== '' && strpos($f[0], '/dev/') === 0) {
                $out[self::kname($f[0])] = 'swap';
            }
        }
        return $out;
    }

    private static function kname($dev)
    {
        $link = @readlink(MdRun::path($dev));
        return PathName::base($link !== false ? $link : $dev);
    }

    /** Everything the build and recovery verbs need to know about one disk, and its verdict. */
    /** $ctx: MdUse::context(); $destroy: knames this command destroys (an old array is free only when all its disks are among them). */
    public static function inspect($k, $id, array $ctx, array $destroy = [])
    {
        $serials = $ctx['serials'];
        $parts = self::partitions($k);
        $use = MdUse::holders($k, $parts, $ctx);
        $sig = [];
        $ids = [];
        foreach (array_merge([$k], $parts) as $n) {
            $p = self::idPath($n);
            if ($p === null) {
                continue;
            }
            $b = MdState::parseExport(MdRun::run(['blkid', '-p', '-o', 'export', $p])['out']);
            foreach (['TYPE', 'PTTYPE'] as $t) {
                if (isset($b[$t])) {
                    $sig[] = "$n:" . $b[$t];
                }
            }
            foreach (['UUID', 'UUID_SUB', 'PARTUUID', 'LABEL', 'PARTLABEL'] as $t) {
                if (isset($b[$t]) && $b[$t] !== '') {
                    $ids[] = [$t, $b[$t], $n];
                }
            }
            foreach (explode("\n", trim(MdRun::run(['wipefs', '-n', '-i', '-p', '-O', 'OFFSET,TYPE', $p])['out'])) as $line) {
                if (preg_match('/^(0x[0-9a-f]+),(\S+)$/i', trim($line), $m)) {
                    $sig[] = "$n:" . $m[2];
                }
            }
        }
        $use = array_merge($use, self::references($k, $parts, $ids));
        $md = MdState::examine(MdRun::run(['mdadm', '--examine', "/dev/disk/by-id/$id"])['out']);
        $uuids = [strtolower($md['uuid'])];
        foreach ($parts as $n) { // an older array may live on partitions
            $p = self::idPath($n);
            $uuids[] = $p === null ? '' : strtolower(MdState::examine(MdRun::run(['mdadm', '--examine', $p])['out'])['uuid']);
        }
        $use = array_merge($use, MdUse::superblocks($k, $parts, $uuids, $ctx, $destroy));
        $sample = self::sample("/dev/disk/by-id/$id", self::size($k));
        $data = $parts || $sig || $md['uuid'] !== '' || $sample['nonzero_samples'] > 0;
        return [
            'disk' => $id, 'serial' => isset($serials[$k]) ? $serials[$k]['serial'] : '', 'model' => isset($serials[$k]) ? $serials[$k]['model'] : '',
            'size_bytes' => self::size($k), 'kernel_name_now' => $k, 'partitions' => $parts, 'signatures' => array_values(array_unique($sig)),
            'md_superblock' => $md['uuid'] === '' ? null : ['uuid' => $md['uuid'], 'name' => $md['name'], 'level' => $md['level'], 'events' => $md['events']],
            'in_use' => $use, 'samples' => $sample, 'verdict' => $use ? 'in-use' : ($data ? 'holds-data' : 'blank'), 'md' => $md,
        ];
    }

    /** fstab and crypttab lines that name this disk or a partition (by path, by-id, UUID, PARTUUID, LABEL). */
    private static function references($k, array $parts, array $ids)
    {
        $out = [];
        foreach (['/etc/fstab', '/etc/crypttab'] as $file) {
            foreach (explode("\n", (string) MdRun::read($file)) as $line) {
                $f = preg_split('/\s+/', trim(preg_replace('/#.*$/', '', $line)));
                $spec = $file === '/etc/crypttab' ? (isset($f[1]) ? $f[1] : '') : $f[0];
                if ($spec === '') {
                    continue;
                }
                $hit = strpos($spec, '/dev/') === 0 && in_array(self::kname($spec), array_merge([$k], $parts), true);
                foreach ($ids as $i) {
                    $hit = $hit || $spec === $i[0] . '=' . $i[1] || $spec === $i[0] . '="' . $i[1] . '"' || PathName::base($spec) === $i[1];
                }
                if ($hit) {
                    $out[] = "named in $file ($spec)";
                }
            }
        }
        return $out;
    }

    public static function size($k)
    {
        return (int) trim((string) MdRun::read("/sys/block/$k/size")) * 512;
    }

    /** Reads SAMPLE bytes at fixed offsets; returns counts only: samples, nonzero_samples, nonzero_bytes, unreadable. */
    public static function sample($path, $size)
    {
        $offsets = self::FIXED;
        foreach (self::FRACTIONS as $f) {
            $offsets[] = (int) floor($size * $f / 4096) * 4096;
        }
        $offsets[] = max(0, $size - 1048576);
        $offsets = array_values(array_unique(array_filter($offsets, function ($o) use ($size) {
            return $o < $size;
        })));
        $r = ['samples' => 0, 'nonzero_samples' => 0, 'nonzero_bytes' => 0, 'unreadable' => 0];
        foreach ($offsets as $o) {
            $buf = MdRun::readAt($path, $o, self::SAMPLE);
            if ($buf === false || $buf === '') {
                $r['unreadable']++;
                continue;
            }
            $r['samples']++;
            $nz = strlen($buf) - substr_count($buf, "\0");
            $r['nonzero_samples'] += $nz > 0 ? 1 : 0;
            $r['nonzero_bytes'] += $nz;
        }
        if ($r['unreadable'] > 0) {
            $r['nonzero_samples'] += $r['unreadable']; // what could not be read is never counted as empty
        }
        return $r;
    }

    /** Every whole disk inspected: [kname => inspect()]. */
    public static function all()
    {
        $ctx = MdUse::context();
        $out = [];
        foreach (self::whole() as $k => $id) {
            $out[$k] = self::inspect($k, $id, $ctx);
        }
        return $out;
    }
}
