<?php
/**
 * MdRun: the one door of the mdraid tool to programs and files (ADR-0095). Every program runs through ToolProc with an
 * argv (each word escapeshellarg'd, no shell string built from text); every /dev path in that argv must be /dev/mdN
 * or an entry of the live /dev/disk/by-id list read at that moment, so a stale or typed sdX name never reaches mdadm.
 * mdadm never gets the options this tool must not use (assume-clean, write-zeroes, grow, build, update, a repair).
 * Root writes go only to fixed root-owned places; never into the account's ~/pikkumies.
 * Root comes from Detect::rootAccess (ADR-0004), the one check Pikkumies has: via uid0 every program runs directly and
 * files are read and written in PHP; via sudo every program runs as `sudo -n <program>`, files are written by copying a
 * private temp file with `sudo -n cp`, and disk samples are read with `sudo -n dd`, so this PHP itself never runs as root;
 * via none a privileged verb is refused with the reason. The agent always runs `php mdraid.php ...`, never `sudo php`.
 * Test seams: $exec (a callable(argv) returning ['code','out','err']), $access ('uid0', 'sudo' or 'none') and the
 * PIKKUMIES_MDRAID_ROOT prefix for every file path; with that prefix set no program runs unless $exec is set, so a test
 * tree never meets a real disk.
 */
require_once dirname(__DIR__, 3) . '/lib/Detect.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MdRun
{
    const PROGRAMS = ['mdadm', 'blkid', 'wipefs', 'lsblk', 'mkfs.ext4', 'update-initramfs', 'systemctl', 'udevadm', 'uname', 'cp', 'mkdir', 'dd', 'cat', 'find'];
    const SBIN = ['/usr/local/sbin', '/usr/sbin', '/sbin', '/usr/local/bin', '/usr/bin', '/bin']; // a user's PATH often lacks sbin, where mdadm lives
    const MDADM_NEVER = '/^--(assume-clean|write-zeroes|grow|build|update|kill-subarray|update-subarray|restore|action=(repair|frozen))\b|^-[A-Za-z]*[GBU]/';
    const WRITE_PREFIXES = ['/etc/mdadm/', '/etc/systemd/system/pikkumies-mdraid-', '/var/lib/pikkumies-mdraid/'];
    const SYSFS_WRITE = '#^/sys/block/md[0-9]+/(md/(sync_action|stripe_cache_size)|queue/read_ahead_kb)$#';
    const STATE_DIR = '/var/lib/pikkumies-mdraid';
    const LOG = '/var/lib/pikkumies-mdraid/actions.log';

    /** Test seam: callable(array $argv): ['code' => int, 'out' => string, 'err' => string]. */
    public static $exec = null;
    /** Test seam: 'uid0', 'sudo' or 'none' instead of Detect::rootAccess. */
    public static $access = null;
    /** Detect::rootAccess of this process, asked once (it may probe sudo -n -k -l). */
    private static $detected = null;
    /** Every argv run in this process, for tests and for the error record of a failed step. */
    public static $log = [];

    public static function root()
    {
        $r = getenv('PIKKUMIES_MDRAID_ROOT');
        return is_string($r) && $r !== '' ? rtrim($r, '/') : '';
    }

    public static function path($p)
    {
        return self::root() . $p;
    }

    /** How root is reached: 'uid0' (run directly), 'sudo' (sudo -n) or 'none' (Detect::rootAccess, ADR-0004). */
    public static function access()
    {
        if (self::$access !== null) {
            return self::$access;
        }
        if (self::$detected === null) {
            self::$detected = Detect::rootAccess(function_exists('posix_geteuid') ? posix_geteuid() : -1);
        }
        return self::$detected['available'] ? self::$detected['via'] : 'none';
    }

    public static function needRoot($verb)
    {
        if (self::access() === 'none') {
            $uid0 = self::$access === null && function_exists('posix_geteuid') && posix_geteuid() === 0;
            throw new CliError("$verb reads or changes block devices and needs root, and root is not available here: "
                . ($uid0 ? 'this runs as uid 0 but without full capabilities (a container or a user namespace)' : 'this is not uid 0 and passwordless sudo is not available (sudo -n -k -l failed, or the account is not in the sudo, wheel or admin group)')
                . '; nothing was done', 'tell the owner; status still works without root');
        }
    }

    /** File contents under the root prefix, or null; a file only root can read (crypttab) is read with sudo -n cat. */
    public static function read($p)
    {
        $full = self::path($p);
        $t = @file_get_contents($full);
        if ($t === false && file_exists($full) && !is_readable($full) && self::access() === 'sudo') {
            $r = self::run(['cat', $full]);
            return $r['code'] === 0 ? $r['out'] : null;
        }
        return $t === false ? null : $t;
    }

    /** $len bytes of a disk at $offset (false when unreadable): fopen as uid 0, sudo -n dd otherwise. Never printed. */
    public static function readAt($p, $offset, $len)
    {
        if (self::access() === 'sudo') {
            $r = self::run(['dd', 'if=' . self::path($p), 'bs=' . (int) $len, 'count=1', 'skip=' . (int) $offset, 'iflag=skip_bytes', 'status=none']);
            return $r['code'] === 0 ? $r['out'] : false;
        }
        $h = @fopen(self::path($p), 'rb');
        $buf = $h !== false && fseek($h, (int) $offset) === 0 ? fread($h, (int) $len) : false;
        if ($h !== false) {
            fclose($h);
        }
        return $buf;
    }

    /** The whole disks and partitions by their /dev/disk/by-id names: [by-id basename => kernel name]. Read live each call. */
    public static function byId()
    {
        $dir = self::path('/dev/disk/by-id');
        $out = [];
        foreach (self::ls('/dev/disk/by-id') as $e) {
            if (is_link("$dir/$e")) {
                $out[$e] = PathName::base((string) readlink("$dir/$e"));
            }
        }
        ksort($out);
        return $out;
    }

    /** The entries of a directory under the root prefix, without dot-entries; [] when it cannot be read. */
    public static function ls($p)
    {
        $e = @scandir(self::path($p));
        return $e === false ? [] : array_values(array_filter($e, function ($n) {
            return $n !== '' && $n[0] !== '.';
        }));
    }

    /** Runs a program: ['code', 'out', 'err'] (127 when it is not installed). Refuses a device path that is not live, and options mdadm must never get here. */
    public static function run(array $argv)
    {
        $argv = array_map('strval', $argv);
        if (!in_array($argv[0], self::PROGRAMS, true)) {
            throw new CliError('mdraid will not run ' . $argv[0] . '; nothing was done', 'this is a bug in the tool');
        }
        $live = self::byId();
        foreach (array_slice($argv, 1) as $a) {
            $dev = strpos($a, 'if=') === 0 ? substr($a, 3) : $a; // dd if=<disk>
            if (strpos($dev, '/dev/') === 0 && strpos($dev, '*') === false && !preg_match('#^/dev/md[0-9]+$#', $dev) // a find pattern is no device
                && !(strpos($dev, '/dev/disk/by-id/') === 0 && isset($live[substr($dev, 16)]))) {
                throw new CliError("$a is not /dev/mdN or a live /dev/disk/by-id entry; nothing was run", 'run disks again and name the disk by its by-id name or serial');
            }
            if ($argv[0] === 'mdadm' && preg_match(self::MDADM_NEVER, $a)) {
                throw new CliError("mdraid never runs mdadm $a; nothing was run", 'this is a bug in the tool');
            }
        }
        $sudo = $argv[0] !== 'uname' && self::access() === 'sudo';
        self::$log[] = $sudo ? array_merge(['sudo', '-n'], $argv) : $argv;
        if (self::$exec !== null) {
            $argv = $sudo ? array_merge(['sudo', '-n'], $argv) : $argv;
            $r = call_user_func(self::$exec, $argv);
            return ['code' => (int) $r['code'], 'out' => (string) $r['out'], 'err' => isset($r['err']) ? (string) $r['err'] : ''];
        }
        if (self::root() !== '') {
            throw new CliError('PIKKUMIES_MDRAID_ROOT is set (a test tree), so no program runs; nothing was done', 'unset it');
        }
        $bin = self::bin($argv[0]);
        if ($bin === null) { // reported by must() for a step; a read verb shows what it could not read
            return ['code' => 127, 'out' => '', 'err' => $argv[0] . ' is not installed (not in PATH); install mdadm, util-linux, e2fsprogs, initramfs-tools or systemd'];
        }
        $argv[0] = $bin;
        if ($sudo) {
            $s = self::bin('sudo');
            if ($s === null) {
                return ['code' => 127, 'out' => '', 'err' => 'sudo is not installed'];
            }
            $argv = array_merge([$s, '-n'], $argv);
        }
        $r = ToolProc::run($argv, ['LC_ALL' => 'C']);
        return ['code' => (int) $r['code'], 'out' => (string) $r['out'], 'err' => (string) $r['err']];
    }

    /** run() that throws when the program fails; $what names the step for the user. Logged to actions.log unless $audit is false. */
    public static function must(array $argv, $what, $audit = true)
    {
        $r = self::run($argv);
        if ($audit) {
            self::audit(implode(' ', $argv), $r['code']);
        }
        if ($r['code'] !== 0) {
            throw new CliError("$what failed (exit {$r['code']}): " . trim(substr($r['err'] !== '' ? $r['err'] : $r['out'], 0, 600)), 'nothing after this step was done; run status to see the exact state');
        }
        return $r;
    }

    public static function have($program)
    {
        return self::$exec !== null || self::bin($program) !== null;
    }

    /** Absolute path of a program in PATH or the sbin directories, or null. */
    public static function bin($name)
    {
        foreach (array_merge(explode(':', (string) getenv('PATH')), self::SBIN) as $dir) {
            if (substr($dir, 0, 1) === '/' && is_file("$dir/$name") && is_executable("$dir/$name")) {
                return "$dir/$name";
            }
        }
        return null;
    }

    /** One line per change, with its exit code, to /var/lib/pikkumies-mdraid/actions.log (0600); it survives the run. */
    public static function audit($what, $code)
    {
        $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . self::access() . ' ' . str_replace("\n", ' ', $what) . ' exit=' . (int) $code . "\n";
        self::write(self::LOG, $line, true, false);
    }

    /** Writes (or appends to) a fixed root-owned file; anything else is refused. Every write but the log's own is logged. */
    public static function write($p, $text, $append = false, $audit = true)
    {
        $ok = (bool) preg_match(self::SYSFS_WRITE, $p);
        foreach (self::WRITE_PREFIXES as $prefix) {
            $ok = $ok || (strpos($p, $prefix) === 0 && strpos($p, '..') === false);
        }
        if (!$ok) {
            throw new CliError("mdraid does not write $p; nothing was written", 'this is a bug in the tool');
        }
        $full = self::path($p);
        $private = $p === self::LOG;
        if (self::access() === 'sudo') {
            self::sudoWrite($p, $full, $append ? (string) self::read($p) . $text : $text, $private);
        } else {
            if (!is_dir(dirname($full)) && !@mkdir(dirname($full), 0700, true)) {
                throw new CliError('could not create ' . dirname($p), 'root must be able to write there');
            }
            if (@file_put_contents($full, $text, $append ? FILE_APPEND : 0) === false) {
                throw new CliError("could not write $p", 'root must be able to write there');
            }
            if ($private) {
                @chmod($full, 0600);
            }
        }
        if ($audit) {
            self::audit("write $p", 0);
        }
    }

    /** Through sudo: the whole new content goes into a private temp file (0600), then sudo -n cp onto the target. */
    private static function sudoWrite($p, $full, $text, $private)
    {
        if (!is_dir(dirname($full))) {
            self::must(['mkdir', '-p', '-m', '0700', dirname($full)], 'creating ' . dirname($p), false);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pkmdw-');
        if ($tmp === false || !@chmod($tmp, 0600) || @file_put_contents($tmp, $text) === false) {
            throw new CliError("could not stage the new content of $p in a temp file; nothing was written", 'check the temp directory');
        }
        try {
            self::must($private ? ['cp', '--preserve=mode', $tmp, $full] : ['cp', $tmp, $full], "writing $p", false); // the log keeps the temp file's 0600
        } finally {
            @unlink($tmp);
        }
    }

    /** Saves a record (an --examine, a copy of mdadm.conf) under /var/lib/pikkumies-mdraid, 0700; returns its path. */
    public static function keep($name, $text)
    {
        $p = self::STATE_DIR . '/' . gmdate('Ymd\THis\Z') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.txt';
        self::write($p, $text);
        @chmod(self::path(self::STATE_DIR), 0700);
        return $p;
    }
}
