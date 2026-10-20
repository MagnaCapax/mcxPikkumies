<?php
/**
 * BackupSnap: `snap <path>... | --package <name>`, a plain copy of exactly the files about to change (ADR-0099). It goes
 * to ~/backups/<UTC time>/ (relative path inside the home kept; a path outside the home under its full path), or to
 * /backups/<UTC time>/ for a path outside the home when this account manages the whole system (Detect::rootAccess: uid 0
 * runs cp itself, passwordless sudo runs it through `sudo -n`, neither copies into ~/backups as far as this account can
 * read). Directories 0700, files keep their mode for the owner only. The whole copy is capped at min(2 GiB, half the free
 * quota or disk); over the cap nothing is copied. Each snap is logged to local/logs/backup-snap/YYYYMMDD.jsonl and its
 * sources recorded in local/state/backup-snap/recent.json, which the delete guard reads (a snap of a target lets its
 * delete through). A copy on the same disk survives a bad edit or delete, not a lost disk: it is not a backup.
 */
class BackupSnap
{
    const CAP = 2147483648;
    const PACKAGE_RE = '/^[a-z0-9][a-z0-9+.-]{0,99}(:[a-z0-9]+)?$/';

    /** @var array|null test seam: Detect::rootAccess()'s answer */
    public static $root = null;
    /** @var callable|null test seam: fn(argv) => ['code', 'out', 'err'] instead of ToolProc::run */
    public static $run = null;
    /** @var string|null test seam: the system-wide directory instead of /backups */
    public static $systemDir = null;
    /** @var int|null test seam: free bytes where the copy goes */
    public static $free = null;

    public static function rootAccess()
    {
        return self::$root !== null ? self::$root : Detect::rootAccess(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
    }

    public static function run(array $args, array $opt, $home, $localDir, $now)
    {
        $root = self::rootAccess();
        $via = !empty($root['available']) ? (string) $root['via'] : 'none';
        $pkg = isset($opt['package']) ? (string) $opt['package'] : '';
        if ($pkg !== '') {
            if (!preg_match(self::PACKAGE_RE, $pkg)) {
                throw new CliError("not a package name: $pkg", 'the name dpkg -l shows, e.g. nginx');
            }
            if ($via === 'none') {
                throw new CliError('copying a package\'s settings needs root or passwordless sudo, and this account has neither; nothing copied',
                    'name the files instead: snap <path>...');
            }
            $args = array_merge($args, self::conffiles($pkg, $via));
        }
        if (!$args) {
            throw new CliError('snap needs at least one path, or --package <name>', 'e.g. snap ~/.config/app/settings.conf');
        }
        $system = self::$systemDir !== null ? self::$systemDir : '/backups';
        $stamp = gmdate('Ymd\THis\Z', $now);
        $plan = [];
        foreach (array_unique($args) as $p) {
            $real = realpath(($p !== '' && $p[0] === '/') ? $p : getcwd() . '/' . $p);
            if ($real === false) {
                throw new CliError("$p does not exist; nothing copied", 'name existing files or folders');
            }
            $inside = HomePath::inside($real, $home);
            // As uid 0 every copy goes to the system folder: root never makes or writes a folder inside a home (ADR-0099).
            $base = $via === 'uid0' || (!$inside && $via !== 'none') ? $system : rtrim($home, '/') . '/backups';
            if (HomePath::inside($real, $base) || HomePath::inside($base, $real)) {
                throw new CliError("$p holds or is inside $base, where the copies go; nothing copied", 'name the files inside it instead');
            }
            $rel = $inside && $base !== $system && $real !== rtrim($home, '/') ? substr($real, strlen(rtrim($home, '/')) + 1) : ltrim($real, '/');
            $plan[] = ['path' => $p, 'real' => $real, 'base' => $base, 'to' => "$base/$stamp/$rel", 'sudo' => $base === $system && $via === 'sudo'];
        }
        $total = 0;
        foreach ($plan as $i => $c) {
            $plan[$i]['bytes'] = self::measure($c['real'], $c['sudo']);
            $total += $plan[$i]['bytes'];
        }
        $free = self::$free !== null ? self::$free : self::freeBytes($plan[0]['base'], $home);
        $cap = $free === null ? self::CAP : min(self::CAP, intdiv(max(0, $free), 2));
        if ($total > $cap) {
            throw new CliError('nothing copied: these files hold ' . BackupStatus::size($total) . ', over the cap of ' . BackupStatus::size($cap)
                . ' (2 GiB, or half the free space)', 'copy less, or set up real backups first: ' . BackupStatus::suggestion());
        }
        $records = [];
        foreach ($plan as $c) {
            // The base and the stamp folder are 0700 before anything is copied in, so no other account can reach a copy.
            self::privateDir($c['base'], $c['sudo']);
            self::privateDir("{$c['base']}/$stamp", $c['sudo']);
            self::exec(['mkdir', '-p', '--', dirname($c['to'])], $c['sudo'], 'cannot create ' . dirname($c['to']));
            self::exec(['cp', '-a', '--reflink=auto', '--', $c['real'], $c['to']], $c['sudo'], "cannot copy {$c['path']}");
            self::exec(['chmod', '-R', 'go-rwx', '--', "{$c['base']}/$stamp"], $c['sudo'], "cannot make {$c['to']} owner-only");
            $copied = self::measure($c['to'], $c['sudo']);
            $records[] = ['path' => $c['path'], 'to' => $c['to'], 'bytes' => $copied, 'verified' => $copied === $c['bytes']];
        }
        $dests = array_values(array_unique(array_map(function ($c) use ($stamp) {
            return "{$c['base']}/$stamp";
        }, $plan)));
        // Only a copy that read back whole covers its target for the delete guard; what arrived is counted again against the cap,
        // because the source may have grown between the measure and cp (ADR-0099).
        $copiedTotal = array_sum(array_column($records, 'bytes'));
        self::record($localDir, $now, array_values(array_filter($plan, function ($c) use ($records) {
            foreach ($records as $r) {
                if ($r['path'] === $c['path']) {
                    return $r['verified'];
                }
            }
            return false;
        })), $dests, $copiedTotal, $via, $pkg);
        return ['records' => $records, 'status' => ['to' => $dests, 'total_bytes' => $copiedTotal, 'verified' => !in_array(false, array_column($records, 'verified'), true),
            'over_cap' => $copiedTotal > $cap,
            'note' => 'a copy on this disk survives a bad change, not a lost disk; tell the user where it is so they can remove it once the change is confirmed']];
    }

    /** The package's config files that exist (dpkg-query Conffiles), read with root or sudo. */
    private static function conffiles($pkg, $via)
    {
        $r = self::exec(['dpkg-query', '-W', '--showformat=${Conffiles}\n', '--', $pkg], $via === 'sudo', "dpkg-query does not know $pkg");
        $out = [];
        foreach (explode("\n", $r['out']) as $line) {
            if (preg_match('#^\s+(/\S+)\s+[0-9a-f]{32}(\s|$)#', $line, $m) && strpos($line, ' obsolete') === false && file_exists($m[1])) {
                $out[] = $m[1];
            }
        }
        if (!$out) {
            throw new CliError("$pkg has no config files on this machine; nothing copied", 'name the files to keep instead: snap <path>...');
        }
        return $out;
    }

    /** $dir as a real 0700 folder of its owner (root through sudo, else this account): never a symlink, never someone else's. */
    private static function privateDir($dir, $sudo)
    {
        clearstatcache(true, $dir);
        $st = @lstat($dir);
        $owner = $sudo ? 0 : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        if ($st !== false && (($st['mode'] & 0170000) !== 0040000 || (int) $st['uid'] !== $owner)) {
            throw new CliError("$dir is not a folder of uid $owner (a symlink, a file or another account's); nothing copied",
                'move it aside, or copy by hand to a folder only its owner can open');
        }
        if ($st === false) {
            self::exec(['mkdir', '-m', '0700', '--', $dir], $sudo, "cannot create $dir");
        }
        self::exec(['chmod', '0700', '--', $dir], $sudo, "cannot set $dir to 0700");
    }

    /** Bytes of the regular files under $path (an absolute path): the same figure for the original and the copy. */
    private static function measure($path, $sudo)
    {
        $r = self::exec(['find', $path, '-type', 'f', '-printf', '%s\n'], $sudo, "cannot measure $path");
        return (int) array_sum(array_map('intval', array_filter(explode("\n", $r['out']), 'strlen')));
    }

    private static function freeBytes($base, $home)
    {
        if (HomePath::inside($base, $home)) {
            $q = Quota::fields($home);
            if ($q['quota_free_bytes'] !== null) {
                return $q['quota_free_bytes'];
            }
        }
        $f = @disk_free_space(is_dir($base) ? $base : dirname($base));
        return $f === false ? null : (int) $f;
    }

    /** Runs $argv (through `sudo -n` when $sudo); a failure throws $what with the program's error. */
    private static function exec(array $argv, $sudo, $what)
    {
        $argv = $sudo ? array_merge(['sudo', '-n', '--'], $argv) : $argv;
        $r = self::$run !== null ? call_user_func(self::$run, $argv) : ToolProc::run($argv);
        if ((int) $r['code'] !== 0) {
            throw new CliError("$what: " . ToolText::tail($r['err']), 'nothing was removed; check the path and the permissions');
        }
        return $r;
    }

    /** The log line and the recent-snap record the delete guard reads. */
    private static function record($localDir, $now, array $plan, array $dests, $total, $via, $pkg)
    {
        $logDir = "$localDir/logs/backup-snap";
        if (is_dir($logDir) || @mkdir($logDir, 0700, true)) {
            $f = "$logDir/" . gmdate('Ymd', $now) . '.jsonl';
            @file_put_contents($f, json_encode(['ts' => gmdate('c', $now), 'to' => $dests, 'sources' => array_column($plan, 'real'), 'bytes' => $total,
                'via' => $via, 'package' => $pkg !== '' ? $pkg : null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND);
            @chmod($f, 0600);
        }
        $recent = BackupHold::recent($localDir, $now);
        foreach ($plan as $c) {
            $recent[$c['real']] = $now;
        }
        if ($pkg !== '') {
            $recent["package:$pkg"] = $now;
        }
        BackupHold::saveRecent($localDir, $recent);
    }
}
