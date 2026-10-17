<?php
/**
 * Cgroup: this process's OWN effective CPU and memory ceiling, read straight from cgroupfs — no
 * subprocess, no privilege needed, nothing withheld (ADR-0023). a live host check, this
 * session: cgroup interface files are world-readable kernel paths for the account's own slice, and
 * every ancestor cgroup enforces its own limit independently, so a parent slice's quota binds even
 * when the leaf scope shows "max"/unlimited. Every reader here walks from the leaf (found via
 * /proc/self/cgroup) up to the mount root and takes the tightest (minimum) numeric value seen at
 * any level. nproc/cpuinfo are never used for the quota: verified (same session) that they track
 * cpuset/affinity only and never see the CFS bandwidth quota a `--cpus`-style limit sets.
 */
class Cgroup
{
    /**
     * v1's "no limit" is a huge sentinel (historically LLONG_MAX rounded to a page boundary, not a
     * clean literal like v2's "max"); anything at or above this is unset, never a real byte limit.
     */
    const V1_UNLIMITED_FLOOR = 4611686018427387904; // 2^62

    /** cgroupv2 | cgroupv1 | null (self-cgroup file unreadable/empty): which format this host uses. */
    public static function format($selfCgroup = '/proc/self/cgroup')
    {
        $content = @file_get_contents($selfCgroup);
        if ($content === false) {
            return null;
        }
        foreach (preg_split('/\r?\n/', trim($content)) as $line) {
            $parts = explode(':', $line, 3);
            if (count($parts) === 3) {
                return $parts[1] === '' ? 'cgroupv2' : 'cgroupv1';
            }
        }
        return null;
    }

    /** Effective CPU budget in cores (fractional), or null when no ancestor sets one (unlimited). */
    public static function cpuQuota($base = '/sys/fs/cgroup', $selfCgroup = '/proc/self/cgroup')
    {
        $min = null;
        foreach (self::ancestorPaths($base, $selfCgroup, 'cpu') as $p) {
            $cores = is_file("$p/cpu.max") ? self::v2Cores("$p/cpu.max") : (is_file("$p/cpu.cfs_quota_us") ? self::v1Cores($p) : null);
            if ($cores !== null) {
                $min = $min === null ? $cores : min($min, $cores);
            }
        }
        return $min;
    }

    /** Effective memory ceiling in bytes, or null when no ancestor sets one (unlimited). */
    public static function memoryMax($base = '/sys/fs/cgroup', $selfCgroup = '/proc/self/cgroup')
    {
        return self::minOverAncestors($base, $selfCgroup, 'memory', [self::class, 'memoryLimitAt'])[0];
    }

    /**
     * What is used against the binding limits (ADR-0034: launch preflight), read where each limit is set, not at the
     * leaf: memoryCurrent() is this process's own scope, which on a per-user slice says nothing about the account's
     * other processes (the torrent client, containers). Returns memory_max, memory_current, memory_path (the ancestor
     * with the lowest memory limit, the topmost on a tie), pids_max, pids_current, pids_path (the same for pids, its
     * own hierarchy on cgroup v1). A value is null when unlimited or unreadable; a path is null when no level sets
     * a limit.
     */
    public static function usageAtLimit($base = '/sys/fs/cgroup', $selfCgroup = '/proc/self/cgroup')
    {
        list($memMax, $memPath) = self::minOverAncestors($base, $selfCgroup, 'memory', [self::class, 'memoryLimitAt']);
        list($pidsMax, $pidsPath) = self::minOverAncestors($base, $selfCgroup, 'pids', [self::class, 'pidsLimitAt']);
        return [
            'memory_max' => $memMax,
            'memory_current' => $memPath === null ? null : self::numberIn($memPath, ['memory.current', 'memory.usage_in_bytes']),
            'memory_path' => $memPath,
            'pids_max' => $pidsMax === null ? null : (int) $pidsMax,
            'pids_current' => $pidsPath === null ? null : self::intOrNull(self::numberIn($pidsPath, ['pids.current'])),
            'pids_path' => $pidsPath,
        ];
    }

    /** [lowest limit over the ancestors of $controller, the directory holding it], [null, null] when none sets one. */
    private static function minOverAncestors($base, $selfCgroup, $controller, callable $limitAt)
    {
        $min = null;
        $at = null;
        foreach (self::ancestorPaths($base, $selfCgroup, $controller) as $p) {
            $v = call_user_func($limitAt, $p);
            if ($v !== null && ($min === null || $v < $min)) {
                $min = $v;
                $at = $p;
            }
        }
        return [$min, $at];
    }

    /** The memory limit set at directory $p (v2 memory.max, v1 memory.limit_in_bytes), or null for none/unset. */
    private static function memoryLimitAt($p)
    {
        if (is_file("$p/memory.max")) {
            $raw = trim((string) @file_get_contents("$p/memory.max"));
            return $raw !== '' && $raw !== 'max' && is_numeric($raw) ? (float) $raw : null;
        }
        if (is_file("$p/memory.limit_in_bytes")) {
            $raw = trim((string) @file_get_contents("$p/memory.limit_in_bytes"));
            return $raw !== '' && is_numeric($raw) && (float) $raw < self::V1_UNLIMITED_FLOOR ? (float) $raw : null;
        }
        return null;
    }

    /** The pids limit set at directory $p (pids.max, the same file on v1 and v2), or null for "max"/unset. */
    private static function pidsLimitAt($p)
    {
        $raw = is_file("$p/pids.max") ? trim((string) @file_get_contents("$p/pids.max")) : '';
        return $raw !== '' && ctype_digit($raw) ? (float) $raw : null;
    }

    /** The number in the first of $files that exists in $dir, or null. */
    private static function numberIn($dir, array $files)
    {
        foreach ($files as $file) {
            if (is_file("$dir/$file")) {
                $raw = trim((string) @file_get_contents("$dir/$file"));
                return is_numeric($raw) ? (float) $raw : null;
            }
        }
        return null;
    }

    private static function intOrNull($v)
    {
        return $v === null ? null : (int) $v;
    }

    /** This process's own current memory usage in bytes (leaf cgroup only; a usage counter, not a limit), or null. */
    public static function memoryCurrent($base = '/sys/fs/cgroup', $selfCgroup = '/proc/self/cgroup')
    {
        $paths = self::ancestorPaths($base, $selfCgroup, 'memory');
        $leaf = end($paths);
        if ($leaf === false) {
            return null;
        }
        foreach (['memory.current' => true, 'memory.usage_in_bytes' => true] as $file => $_) {
            if (is_file("$leaf/$file")) {
                $raw = trim((string) @file_get_contents("$leaf/$file"));
                return is_numeric($raw) ? (float) $raw : null;
            }
        }
        return null;
    }

    /** Effective CPU affinity count from cpuset.cpus.effective (v2) / cpuset.cpus (v1), or null (the caller falls back). */
    public static function cpusetCount($base = '/sys/fs/cgroup', $selfCgroup = '/proc/self/cgroup')
    {
        foreach (self::ancestorPaths($base, $selfCgroup, 'cpuset') as $p) {
            foreach (['cpuset.cpus.effective', 'cpuset.cpus'] as $file) {
                if (is_file("$p/$file")) {
                    $n = self::rangeListCount(trim((string) @file_get_contents("$p/$file")));
                    if ($n !== null) {
                        return $n;
                    }
                }
            }
        }
        return null;
    }

    /** "0-1,3,5-7" -> 5 cores; '' or malformed -> null. */
    public static function rangeListCount($s)
    {
        if ($s === '') {
            return null;
        }
        $n = 0;
        foreach (explode(',', $s) as $part) {
            if (strpos($part, '-') !== false) {
                $bounds = explode('-', $part, 2);
                if (count($bounds) !== 2 || !is_numeric($bounds[0]) || !is_numeric($bounds[1]) || (int) $bounds[1] < (int) $bounds[0]) {
                    return null;
                }
                $n += (int) $bounds[1] - (int) $bounds[0] + 1;
            } elseif (is_numeric($part)) {
                $n++;
            } else {
                return null;
            }
        }
        return $n;
    }

    private static function v2Cores($file)
    {
        $raw = trim((string) @file_get_contents($file));
        if ($raw === '' || strncmp($raw, 'max', 3) === 0) {
            return null; // literal "max" (optionally "max <period>"): unlimited at this level
        }
        $parts = preg_split('/\s+/', $raw);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1]) || (float) $parts[1] <= 0) {
            return null;
        }
        return (float) $parts[0] / (float) $parts[1];
    }

    private static function v1Cores($dir)
    {
        $quota = trim((string) @file_get_contents("$dir/cpu.cfs_quota_us"));
        $period = is_file("$dir/cpu.cfs_period_us") ? trim((string) @file_get_contents("$dir/cpu.cfs_period_us")) : '';
        if (!is_numeric($quota) || (float) $quota < 0 || !is_numeric($period) || (float) $period <= 0) {
            return null; // -1 means unlimited at this level
        }
        return (float) $quota / (float) $period;
    }

    /**
     * Every ancestor cgroup directory from the mount root down to the leaf (read from
     * $selfCgroup's line for $controller), so the caller can take a minimum over all of them. v2 is
     * one unified hierarchy: every controller's line has an empty 2nd field and the same path.
     * v1/hybrid: the per-controller line's 2nd field names the controller(s) at that mount, and the
     * controller's own cgroupfs root is $base/$controller (the stock Debian/systemd layout this was
     * verified against on a live host). Returns [] when $selfCgroup cannot be read or has
     * no line for $controller.
     */
    private static function ancestorPaths($base, $selfCgroup, $controller)
    {
        $content = @file_get_contents($selfCgroup);
        if ($content === false) {
            return [];
        }
        $leaf = null;
        $root = null;
        foreach (preg_split('/\r?\n/', trim($content)) as $line) {
            $parts = explode(':', $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            if ($parts[1] === '') { // v2 unified hierarchy: one line, applies to every controller
                $leaf = $parts[2];
                $root = rtrim($base, '/');
                break;
            }
            if (in_array($controller, explode(',', $parts[1]), true)) { // v1/hybrid: controller-specific line
                $leaf = $parts[2];
                $root = rtrim($base, '/') . '/' . $parts[1]; // the mount dirname is the line's own controller list, e.g. "cpu,cpuacct"
                break;
            }
        }
        if ($leaf === null) {
            return [];
        }
        $segments = array_values(array_filter(explode('/', $leaf), 'strlen'));
        $paths = [$root];
        $cur = $root;
        foreach ($segments as $seg) {
            $cur .= '/' . $seg;
            $paths[] = $cur;
        }
        return $paths;
    }
}
