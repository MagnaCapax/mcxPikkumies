<?php
/**
 * DrivesVerify: the gates before a write pass, the detached launch, and reading a pass back. A pass is
 * `badblocks -nsv` (each block read, written with test patterns, compared and written back) on a standalone
 * rotational disk, or with --fix-unreadable one `badblocks -wv <block> <block>` per block a completed pass could not
 * read. Only badblocks runs as root; every file is written by the account's shell (never `badblocks -o`): stdout
 * (the bad-block list) through `head -c` into pass.bb, so a drive that drops off the bus cannot fill the disk,
 * stderr (progress and summary) into pass.log, the exit code into pass.exit. `flock -n pass.lock` holds the lock
 * for as long as the pass runs: one pass per disk, and "running" survives pid reuse.
 */
class DrivesVerify
{
    const BB_MAX = 1048576;   // bytes of bad-block list before the pass is stopped (a dropped drive lists every block)
    const FIX_MAX = 4096;     // unreadable blocks rewritten one by one at most
    const SCRIPT = 'l=$1 e=$2 b=$3; shift 3; { "$@" 2>"$l"; echo $? >"$e"; } | head -c 1048577 >"$b"';
    const FIX_SCRIPT = 'l=$1 e=$2 b=$3 f=$4; shift 4; { while read -r n; do "$@" "$n" "$n" 2>>"$l"; done <"$f"; echo $? >"$e"; } | head -c 1048577 >"$b"';

    /** The badblocks block size: 4096 above 2 TB, 8192 from 16 TB (its block counter is 32-bit); null = its default. */
    public static function blockSize($bytes)
    {
        $bs = $bytes >= 16e12 ? 8192 : ($bytes > 2e12 ? 4096 : null);
        if ($bytes / ($bs ?: 1024) >= 4294967296) {
            throw new CliError('this disk is too large for one badblocks pass (its block counter is 32-bit)', 'nothing was started');
        }
        return $bs;
    }

    /**
     * Throws CliError for the first gate that fails; returns the role. $d: the disk with its parsed SMART fields. The
     * role is judged from signatures probed as root (blkid -p), not from the udev view smart shows.
     */
    public static function gates(DrivesHost $host, array $disk, array $d, array $opt)
    {
        $virt = $host->virt();
        if ($virt === null || $virt !== 'none') {
            throw new CliError($virt === null ? 'cannot tell whether this machine is a VM (systemd-detect-virt is missing)' : "this is a VM or container ($virt): its disks belong to the host",
                'run drive tests on the machine the disks are in; nothing was started');
        }
        if ($disk['rotational'] !== true || $d['rotational'] === false) {
            throw new CliError('not a spinning disk: a write pass wears flash and proves little', 'for SSD and NVMe use smart and selftest only');
        }
        list($role, $detail) = $host->role($host->probe($disk));
        $refuse = [
            'array-member' => 'it is a live array member ($d). The array itself is the write pass: on a redundant array run its check scrub (echo check > /sys/block/mdN/md/sync_action) or let a rebuild finish; never `repair`, never a targeted write. Single-copy data (RAID0, linear) is copied off first',
            'holder' => 'it is in use ($d): LVM, dm-crypt or multipath sits on it',
            'mounted' => 'it is in use ($d)',
            'swap' => 'it is in use ($d)',
            'array-signature' => 'it $d: it may be the last copy of an array\'s data; find out which array it belongs to (mdadm --examine) before anything writes to it',
        ];
        if (isset($refuse[$role])) {
            throw new CliError('refused: ' . str_replace('$d', $detail, $refuse[$role]), 'nothing was started; this refusal is the safety rule (tools/hardware/DOCTRINE.md)');
        }
        if ($role === 'data' && empty($opt['with-data'])) {
            throw new CliError("this disk holds data ($detail): an interrupted pass can lose the block under test, and anything that still writes"
                . ' to the disk during the pass (a VM, an export, a program using the raw device) loses those writes',
                'stop whatever uses it and copy anything you need off it first (a lone disk is the only copy), then add --with-data; the safety check decides');
        }
        if (!empty($opt['fix-unreadable']) && empty($opt['with-data'])) {
            throw new CliError('--fix-unreadable writes over the unreadable blocks', 'add --with-data; the safety check decides');
        }
        $busy = $host->mdBusy();
        if ($busy !== '') {
            throw new CliError("an md array is busy ($busy)", 'wait until it finishes; nothing was started');
        }
        return $role;
    }

    /** Refuses when a process has the disk or a partition open (checked as root just before launch). */
    public static function notInUse(DrivesHost $host, array $disk)
    {
        if ($host->inUse($disk)) {
            throw new CliError("a process has {$disk['device']} or one of its partitions open (a VM, an export, a program using the raw device)",
                'a write pass would race its writes and lose them; stop it first (fuser -v as root shows which). Nothing was started');
        }
    }

    /** Starts the pass in the background; returns [pid, start time]. $root: the badblocks argv up to the device. */
    public static function launch($dir, array $root, $fixList = null)
    {
        foreach (['flock', 'sh', 'head', 'nohup'] as $p) {
            ToolProc::need($p, 'coreutils and util-linux provide it; no pass is started without it');
        }
        foreach (['pass.log', 'pass.bb', 'pass.exit'] as $f) {
            if (is_file("$dir/$f")) {
                @rename("$dir/$f", "$dir/$f.prev"); // the previous pass's files are kept one generation
            }
        }
        $args = ["$dir/pass.log", "$dir/pass.exit", "$dir/pass.bb"];
        $argv = array_merge(['flock', '-n', "$dir/pass.lock", 'sh', '-c', $fixList === null ? self::SCRIPT : self::FIX_SCRIPT, 'sh'],
            $args, $fixList === null ? [] : [$fixList], $root);
        $out = [];
        exec('nohup ' . ToolProc::line($argv) . ' >/dev/null 2>&1 & echo $!', $out);
        $pid = isset($out[0]) ? (int) $out[0] : 0;
        usleep(200000);
        return [$pid, $pid > 0 ? OwnProcs::started($pid) : null];
    }

    /** True while the pass holds its lock. */
    public static function running($dir)
    {
        $h = @fopen("$dir/pass.lock", 'c');
        if (!$h) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return !$free;
    }

    /**
     * What the pass files say: progress (percent) and r/w/c from the last progress line; completed, summed r/w/c from
     * every "Pass completed" line; bb_bytes, bb_over; exit (null while running or when it never wrote one); last_line.
     */
    public static function read($dir)
    {
        $log = (string) @file_get_contents("$dir/pass.log", false, null, max(0, (int) @filesize("$dir/pass.log") - 262144));
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\x08\r\n]+/', $log)), 'strlen'));
        $p = ['progress' => null, 'completed' => false, 'r' => 0, 'w' => 0, 'c' => 0, 'bad' => 0, 'last_line' => $lines ? end($lines) : 'no output'];
        $done = [0, 0, 0];
        foreach ($lines as $l) {
            if (preg_match('/^([\d.]+)% done.*\((\d+)\/(\d+)\/(\d+) errors\)/', $l, $m)) {
                $p['progress'] = round((float) $m[1] / 100, 4);
                list($p['r'], $p['w'], $p['c']) = [(int) $m[2], (int) $m[3], (int) $m[4]];
            } elseif (preg_match('/^Pass completed, (\d+) bad blocks found\.(?: \((\d+)\/(\d+)\/(\d+) errors\))?/', $l, $m)) {
                $p['completed'] = true;
                $p['bad'] += (int) $m[1];
                $triple = isset($m[4]) ? [(int) $m[2], (int) $m[3], (int) $m[4]] : [(int) $m[1], 0, 0]; // no triple: count as unreadable
                $done = [$done[0] + $triple[0], $done[1] + $triple[1], $done[2] + $triple[2]];
            }
        }
        if ($p['completed']) {
            list($p['r'], $p['w'], $p['c']) = $done;
        }
        clearstatcache();
        $p['bb_bytes'] = is_file("$dir/pass.bb") ? (int) filesize("$dir/pass.bb") : 0;
        $p['bb_over'] = $p['bb_bytes'] > self::BB_MAX;
        $exit = @file_get_contents("$dir/pass.exit");
        $p['exit'] = is_string($exit) && is_numeric(trim($exit)) ? (int) trim($exit) : null;
        return $p;
    }

    /** The block numbers of a completed pass's list, for --fix-unreadable; throws when there are none or too many. */
    public static function unreadable($file)
    {
        $blocks = [];
        foreach ((array) @file($file, FILE_IGNORE_NEW_LINES) as $l) {
            if (ctype_digit(trim((string) $l))) {
                $blocks[] = trim($l);
            }
        }
        if (!$blocks || count($blocks) > self::FIX_MAX) {
            throw new CliError($blocks ? 'more than ' . self::FIX_MAX . ' unreadable blocks: a block-by-block rewrite is not the way for this drive' : 'the last completed pass left no unreadable blocks to rewrite',
                'the drive stays HOLD; nothing was started');
        }
        return array_values(array_unique($blocks));
    }
}
