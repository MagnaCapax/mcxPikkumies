<?php
/**
 * Detect: read-only probe of the machine Pikkumies runs on.
 *
 * Returns a plain array (the "profile"). No network calls (rDNS is a bounded resolver query, not
 * web access, ADR-0021; `ip route get` never sends a packet, verified in /tmp/gld15q/limits.md), no
 * writes outside cached()'s own cache file, no LLM. Root is reported from what the OS says, never
 * from configuration (ADR-0004).
 */
require_once __DIR__ . '/Host.php';
require_once __DIR__ . '/Quota.php';
require_once __DIR__ . '/Cgroup.php';
require_once __DIR__ . '/Config.php';

class Detect
{
    /** Known user-level apps: profile key => process names that indicate it. */
    const KNOWN_APPS = [
        'rtorrent'    => ['rtorrent', 'rtorrent main', 'rtorrent-main'],  // main-thread names of 0.9 and 0.16
        'qbittorrent' => ['qbittorrent-nox', 'qbittorrent'],
        'deluge'      => ['deluged'],
        'transmission'=> ['transmission-daemon'],
        'jellyfin'    => ['jellyfin'],
        'plex'        => ['Plex Media Server', 'Plex Media Serv'],
        'rclone'      => ['rclone'],
        'docker'      => ['dockerd', 'containerd'],
        'nginx'       => ['nginx'],
        'lighttpd'    => ['lighttpd'],
    ];

    /** Cache file schema: bump when the profile shape changes so an old cache cannot be read as the new one. */
    const SCHEMA = 1;

    /**
     * Account size: "small" at or under 1 core / 1024 MiB, "large" at or over 4 cores AND 8192 MiB,
     * "medium" between (and whenever a limit could not be determined at all: unknown never guesses a
     * size either way).
     */
    const TIER_THRESHOLDS = ['small' => ['cpu' => 1.0, 'memMb' => 1024], 'large' => ['cpu' => 4.0, 'memMb' => 8192]];

    /** Test seam: replaces the probe cached() runs, so cache/invalidation tests never touch the real machine. */
    public static $runOverride = null;

    /** Test seam: replaces primaryIp() inside identity(), so the rDNS path can be exercised, and "non-generic family never attempts it" can be proven, without a real route lookup. */
    public static $primaryIpOverride = null;

    public static function run()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));
        $home = (string) getenv('HOME');
        $pmss = is_dir('/etc/seedbox');
        $family = Host::family();
        $stack = Host::stack();
        $tenancy = self::sharedTenancy($pmss);
        $root = self::rootAccess($uid);
        $root['suPresent'] = self::suPresent();
        $limits = self::limits($family, $home, $tenancy);
        return [
            'detectedAt' => gmdate('c'),
            'os'         => self::osRelease(),
            'user'       => self::userName($uid),
            'uid'        => $uid,
            'pmss'       => $pmss,
            'family'     => $family,
            'stack'      => $stack,
            'identity'   => self::identity($family),
            'tenancy'    => ['shared' => $tenancy],
            'root'       => $root,
            'apps'       => self::runningApps($uid),
            'resources'  => self::resources(),
            'limits'     => $limits,
            'tier'       => self::tier($limits['cpuQuota']['value'], $limits['memMb']['value']),
        ];
    }

    /**
     * run(), but only once per machine+account: local/host.json is reused while its schema and
     * fingerprint (hostname, /etc/machine-id, uid) match, so a PMSS userTransfer that moves this home
     * to another server re-detects instead of keeping the old server's profile. $refresh forces a
     * fresh probe (`pikkumies detect --refresh`). A probe that throws is never cached: the caller
     * gets an in-memory profile for this run only, and the next call probes again. local/config.php's
     * 'host' key, when set, overrides detected values on every read (merged last, after the cache).
     * $file/$logFile are test seams; production callers take the defaults.
     */
    public static function cached($refresh = false, $file = null, $logFile = null)
    {
        $file = $file !== null ? $file : Config::localDir() . '/host.json';
        $logFile = $logFile !== null ? $logFile : Config::localDir() . '/update.log';
        $config = Config::load();
        if (!$refresh) {
            $cached = self::readCache($file);
            if ($cached !== null) {
                return self::applyOverride($cached, $config);
            }
        }
        $t0 = microtime(true);
        try {
            $profile = self::$runOverride !== null ? call_user_func(self::$runOverride) : self::run();
            $profile['schema'] = self::SCHEMA;
            $profile['fingerprint'] = self::fingerprint($profile['uid']);
        } catch (Throwable $e) {
            // A probe failure must never block a start; it is also never written to the cache, so the
            // next call probes again instead of persisting a half-detected machine.
            // Every key callers read, with safe defaults, so a failed probe never breaks the JSONL output.
            $fallback = ['detectedAt' => gmdate('c'), 'error' => $e->getMessage(), 'pmss' => false, 'family' => 'generic',
                'stack' => 'none', 'tenancy' => ['shared' => true], 'root' => ['available' => false, 'via' => 'none', 'capEff' => null, 'suPresent' => false],
                'apps' => [], 'limits' => [], 'tier' => 'medium'];
            return self::applyOverride($fallback, $config);
        }
        self::writeCache($file, $profile);
        self::logUpdate($logFile, $profile, (microtime(true) - $t0) * 1000);
        return self::applyOverride($profile, $config);
    }

    /** hostname|/etc/machine-id|uid: changes when this home moves to another machine or another account reads it. */
    public static function fingerprint($uid, $machineIdFile = '/etc/machine-id')
    {
        $hostname = (string) @gethostname();
        $machineId = trim((string) @file_get_contents($machineIdFile));
        return $hostname . '|' . $machineId . '|' . $uid;
    }

    private static function readCache($file)
    {
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['schema'], $data['fingerprint'], $data['uid'])) {
            return null;
        }
        if ($data['schema'] !== self::SCHEMA || $data['fingerprint'] !== self::fingerprint($data['uid'])) {
            return null;
        }
        return $data;
    }

    /** Write through a temp file, 0600, then rename (ADR-0019 privacy; same pattern as SecretFile/HermesConfig). */
    private static function writeCache($file, array $profile)
    {
        @mkdir(dirname($file), 0700, true);
        $tmp = $file . '.tmp-' . getmypid();
        $json = json_encode($profile, JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $json) === strlen($json) && @chmod($tmp, 0600) && @rename($tmp, $file)) {
            return;
        }
        @unlink($tmp);
    }

    /** One JSONL line per detection run (ADR-0016): what it decided and where each limit came from, never the full profile. */
    private static function logUpdate($file, array $profile, $ms)
    {
        @mkdir(dirname($file), 0700, true);
        $line = json_encode([
            'ts'      => gmdate('c'),
            'event'   => 'detect',
            'family'  => $profile['family'],
            'stack'   => $profile['stack'],
            'tier'    => $profile['tier'],
            'sources' => [
                'cpuQuota' => $profile['limits']['cpuQuota']['source'],
                'memMb'    => $profile['limits']['memMb']['source'],
                'disk'     => $profile['limits']['disk']['source'],
            ],
            'ms' => round($ms, 1),
        ], JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** local/config.php's 'host' key, merged over the detected/cached profile last, so a human override always wins. Public: a pure function over its two arguments, so cached()'s override step is testable without touching the real local/config.php. */
    public static function applyOverride(array $profile, array $config)
    {
        if (empty($config['host']) || !is_array($config['host'])) {
            return $profile;
        }
        return Config::merge($profile, $config['host']);
    }

    /**
     * {hostname, fqdn, rdns}. rdns is attempted only while $family is still 'generic' (a known family
     * already answers "where am I"; a resolver round trip would add nothing). The primary IP comes
     * from the kernel's own route cache (`ip route get`, never a packet on the wire), and the PTR
     * lookup is a bounded subprocess (`timeout 3 getent hosts`), never PHP's unguarded
     * `gethostbyaddr()` (verified unbounded in /tmp/gld15q/limits.md). Any failure at any step skips
     * rdns silently; it stays null.
     */
    public static function identity($family)
    {
        $hostname = (string) @gethostname();
        $fqdn = $hostname;
        if (strpos($hostname, '.') === false) {
            $full = trim((string) @shell_exec('timeout 3 hostname -f 2>/dev/null')); // broken DNS must not hang a start
            $fqdn = $full !== '' ? $full : $hostname;
        }
        $id = ['hostname' => $hostname, 'fqdn' => $fqdn, 'rdns' => null];
        if ($family !== 'generic') {
            return $id;
        }
        $ip = self::$primaryIpOverride !== null ? call_user_func(self::$primaryIpOverride) : self::primaryIp();
        if ($ip === null) {
            return $id;
        }
        $out = [];
        $code = 1;
        exec('timeout 3 getent hosts ' . escapeshellarg($ip) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && $out) {
            $fields = preg_split('/\s+/', trim($out[0]));
            if (isset($fields[1]) && $fields[1] !== '') {
                $id['rdns'] = strtolower($fields[1]);
            }
        }
        return $id;
    }

    /** This host's outbound IP from the kernel routing table only (`ip route get`: a cache lookup, no packet sent), or null. */
    public static function primaryIp()
    {
        $out = [];
        $code = 1;
        exec('ip -4 route get 1.1.1.1 2>/dev/null', $out, $code);
        if ($code !== 0 || !$out) {
            return null;
        }
        foreach ($out as $line) {
            if (preg_match('/\bsrc\s+(\d{1,3}(?:\.\d{1,3}){3})\b/', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * true when this account shares the machine with other accounts: always true on pmss, else when
     * /etc/passwd lists more than one human account (uid >= 1000, a real home directory). Only that
     * boolean is returned; the account count itself never leaves this function (privacy).
     */
    public static function sharedTenancy($pmss, $passwd = '/etc/passwd')
    {
        if ($pmss) {
            return true;
        }
        $lines = @file($passwd, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }
        $humans = 0;
        foreach ($lines as $line) {
            $f = explode(':', $line);
            if (count($f) >= 7 && is_numeric($f[2]) && (int) $f[2] >= 1000 && $f[5] !== '' && is_dir($f[5])) {
                $humans++;
                if ($humans > 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /** True when an `su` binary exists on PATH. Only checked for existence (is_executable); never executed. */
    public static function suPresent()
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("$dir/su") && is_executable("$dir/su")) {
                return true;
            }
        }
        return false;
    }

    /**
     * {cpuQuota, cpuset, memMb, disk}, each {value, source}. PMSS reads its own
     * `$HOME/bin/userShowLimits --json` (the account's canonical, already-built limits tool,
     * /tmp/pmd15q/pmss.md); every other host reads its own cgroup (Cgroup.php). cpuset (affinity, a
     * different number from the CFS quota) always comes from the cgroup/`nproc` reader: PMSS's tool
     * does not expose it. disk always reuses the existing Quota::fields(), the one quota reader this
     * project has. On a dedicated (not shared) account, a limit that still cannot be determined
     * (truly unlimited, or the cgroup/tool gave nothing) is reported as the host's own total,
     * labelled source=host; on a shared account an undetermined limit stays null, because "the whole
     * host" would be a wrong answer to give a tenant.
     */
    public static function limits($family, $home, $shared)
    {
        $cpuQuota = ['value' => null, 'source' => null];
        $memMb = ['value' => null, 'source' => null];
        if ($family === 'pmss') {
            $parsed = self::userShowLimits($home);
            if ($parsed !== null) {
                $cpuQuota = $parsed['cpuQuota'];
                $memMb = $parsed['memMb'];
            }
        }
        $format = Cgroup::format() ?: 'cgroup';
        if ($cpuQuota['value'] === null) {
            $cores = Cgroup::cpuQuota();
            if ($cores !== null) {
                $cpuQuota = ['value' => round($cores, 2), 'source' => $format];
            }
        }
        if ($memMb['value'] === null) {
            $bytes = Cgroup::memoryMax();
            if ($bytes !== null) {
                $memMb = ['value' => round($bytes / 1048576, 1), 'source' => $format];
            }
        }
        $affinity = Cgroup::cpusetCount();
        $cpuset = $affinity !== null
            ? ['value' => $affinity, 'source' => $format]
            : self::hostCpusetFallback();
        if (!$shared) {
            if ($cpuQuota['value'] === null && $cpuset['value'] !== null) {
                $cpuQuota = ['value' => (float) $cpuset['value'], 'source' => 'host'];
            }
            if ($memMb['value'] === null) {
                $kb = self::memTotalKb();
                if ($kb > 0) {
                    $memMb = ['value' => round($kb / 1024, 1), 'source' => 'host'];
                }
            }
        }
        $quota = Quota::fields($home);
        $diskSource = $quota['quota_source'] !== null ? $quota['quota_source'] : ($quota['fs_free_bytes'] !== null ? 'df' : null);
        return [
            'cpuQuota' => $cpuQuota,
            'cpuset'   => $cpuset,
            'memMb'    => $memMb,
            'disk'     => ['value' => $quota, 'source' => $diskSource],
        ];
    }

    /**
     * The limit with the highest used/limit fraction first (so the agent answers "can we increase
     * performance" with the real bottleneck, not whichever key happens to be declared first). Limits
     * with no known fraction keep the table order (disk, memMb, cpuQuota, cpuset) after the ranked
     * ones. $memoryCurrentBytes is this moment's own cgroup memory.current/usage_in_bytes (the
     * caller's live read, e.g. Cgroup::memoryCurrent()) or null when unknown; a pure function over
     * its own arguments, so it needs no live system read to test.
     */
    public static function leadingLimit(array $limits, $memoryCurrentBytes)
    {
        $fractions = [];
        $disk = $limits['disk']['value'];
        if (is_array($disk) && $disk['quota_limit_bytes'] > 0 && $disk['quota_used_bytes'] !== null) {
            $fractions['disk'] = $disk['quota_used_bytes'] / $disk['quota_limit_bytes'];
        }
        $memLimitMb = $limits['memMb']['value'];
        if ($memLimitMb && $memoryCurrentBytes !== null) {
            $fractions['memMb'] = $memoryCurrentBytes / ($memLimitMb * 1048576);
        }
        arsort($fractions);
        $order = array_keys($fractions);
        foreach (['disk', 'memMb', 'cpuQuota', 'cpuset'] as $k) {
            if (!in_array($k, $order, true)) {
                $order[] = $k;
            }
        }
        $out = [];
        foreach ($order as $k) {
            $out[$k] = $limits[$k];
        }
        return $out;
    }

    /**
     * small | medium | large from the account's own cpu cores / memory MiB. Knowing only one of the
     * two still decides small/large off it alone; knowing NEITHER is the only case that defaults to
     * medium rather than guessing. small is checked before large, so a tiny-CPU/huge-RAM (or the
     * reverse) outlier reads as small, the more conservative of the two.
     */
    public static function tier($cpuCores, $memMb)
    {
        if ($cpuCores === null && $memMb === null) {
            return 'medium';
        }
        $t = self::TIER_THRESHOLDS;
        if (($cpuCores !== null && $cpuCores <= $t['small']['cpu']) || ($memMb !== null && $memMb <= $t['small']['memMb'])) {
            return 'small';
        }
        if (($cpuCores === null || $cpuCores >= $t['large']['cpu']) && ($memMb === null || $memMb >= $t['large']['memMb'])) {
            return 'large';
        }
        return 'medium';
    }

    /**
     * $HOME/bin/userShowLimits --json (absolute path, 10 s timeout, fixed argv — never the probe's
     * own output interpolated into a shell string). null on any failure (binary missing, times out,
     * bad JSON, or the cpu/memory sections are unavailable): the caller falls back to the cgroup
     * reader. Field names verified by direct read of etc/skel/bin/userShowLimits this session
     * (/tmp/pmd15q/pmss.md): cpu section carries a 'CPU quota enforced' metric (percent of one core,
     * source 'cgroup'); memory section carries a 'memory' metric whose LIMIT (not value, which is
     * current usage) is the enforced ceiling in bytes.
     */
    public static function userShowLimits($home, $bin = null)
    {
        $bin = $bin !== null ? $bin : $home . '/bin/userShowLimits';
        if (!is_file($bin) || !is_executable($bin)) {
            return null;
        }
        $out = [];
        $code = 1;
        exec('timeout 10 ' . escapeshellarg($bin) . ' --json 2>/dev/null', $out, $code);
        if ($code !== 0 || !$out) {
            return null;
        }
        $data = json_decode(implode("\n", $out), true);
        if (!is_array($data) || !isset($data['sections']) || !is_array($data['sections'])) {
            return null;
        }
        $cpu = self::userShowLimitsMetric($data['sections'], 'cpu', 'CPU quota enforced', 'value');
        $mem = self::userShowLimitsMetric($data['sections'], 'memory', 'memory', 'limit');
        return [
            'cpuQuota' => $cpu !== null && is_numeric($cpu) ? ['value' => round(((float) $cpu) / 100, 2), 'source' => 'userShowLimits'] : ['value' => null, 'source' => null],
            'memMb'    => $mem !== null && is_numeric($mem) ? ['value' => round(((float) $mem) / 1048576, 1), 'source' => 'userShowLimits'] : ['value' => null, 'source' => null],
        ];
    }

    private static function userShowLimitsMetric(array $sections, $section, $metricName, $field)
    {
        if (empty($sections[$section]['available']) || empty($sections[$section]['metrics'])) {
            return null;
        }
        foreach ($sections[$section]['metrics'] as $m) {
            if (isset($m['metric']) && $m['metric'] === $metricName && isset($m[$field]) && $m[$field] !== null) {
                return $m[$field];
            }
        }
        return null;
    }

    private static function hostCpusetFallback()
    {
        $n = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        return $n > 0 ? ['value' => $n, 'source' => 'nproc'] : ['value' => null, 'source' => null];
    }

    private static function memTotalKb()
    {
        $mem = @file_get_contents('/proc/meminfo');
        return $mem !== false && preg_match('/^MemTotal:\s*(\d+)/m', $mem, $m) ? (int) $m[1] : 0;
    }

    /**
     * Root = real uid 0 (identity uid_map, so not a user-namespace fake) with CAP_SYS_ADMIN,
     * or passwordless sudo. sudo is only probed for sudo/wheel members, because probing as a
     * non-member can mail "not in sudoers" to the admin.
     */
    public static function rootAccess($uid)
    {
        if ($uid === 0) {
            $capEff = self::capEff();
            $real = self::uidMapIsIdentity() && $capEff !== null && self::capsAreFull($capEff);
            return ['available' => $real, 'via' => $real ? 'uid0' : 'none', 'capEff' => $capEff];
        }
        if (!self::inSudoGroup()) {
            return ['available' => false, 'via' => 'none', 'capEff' => null];
        }
        $out = [];
        $code = 1;
        // -k ignores a cached sudo ticket, so only truly passwordless sudo counts.
        exec('sudo -n -k -l >/dev/null 2>&1', $out, $code);
        return ['available' => $code === 0, 'via' => $code === 0 ? 'sudo' : 'none', 'capEff' => null];
    }

    public static function uidMapIsIdentity($map = null)
    {
        $map = $map === null ? @file_get_contents('/proc/self/uid_map') : $map;
        if ($map === false || $map === null) {
            return false;
        }
        return (bool) preg_match('/^\s*0\s+0\s+4294967295\s*$/', trim($map));
    }

    public static function inSudoGroup()
    {
        $groups = preg_split('/\s+/', trim((string) shell_exec('id -Gn 2>/dev/null')));
        return count(array_intersect($groups, ['sudo', 'wheel', 'admin'])) > 0;
    }

    public static function capEff()
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status !== false && preg_match('/^CapEff:\s*([0-9a-f]+)/mi', $status, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Full set = CAP_SYS_ADMIN (bit 21) present; a stripped root lacks it. */
    public static function capsAreFull($capEffHex)
    {
        $value = hexdec(substr($capEffHex, -8));
        return ($value & (1 << 21)) !== 0;
    }

    public static function osRelease()
    {
        $ini = @parse_ini_file('/etc/os-release');
        if (!is_array($ini)) {
            return ['id' => php_uname('s'), 'version' => php_uname('r')];
        }
        return ['id' => isset($ini['ID']) ? $ini['ID'] : 'unknown', 'version' => isset($ini['VERSION_ID']) ? $ini['VERSION_ID'] : ''];
    }

    public static function userName($uid)
    {
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($uid);
            if (is_array($pw)) {
                return $pw['name'];
            }
        }
        return (string) getenv('USER');
    }

    /** Apps running under OUR uid only (privacy: never inspect other users). */
    public static function runningApps($uid)
    {
        $names = [];
        foreach ((array) glob('/proc/[0-9]*') as $dir) {
            if (@fileowner($dir) !== $uid) {
                continue;
            }
            $comm = @file_get_contents($dir . '/comm');
            if ($comm !== false) {
                $names[trim($comm)] = true;
            }
        }
        $found = [];
        foreach (self::KNOWN_APPS as $app => $procs) {
            foreach ($procs as $p) {
                if (isset($names[$p]) || isset($names[substr($p, 0, 15)])) {
                    $found[] = $app;
                    break;
                }
            }
        }
        return $found;
    }

    public static function resources()
    {
        $memTotalKb = 0;
        $mem = @file_get_contents('/proc/meminfo');
        if ($mem !== false && preg_match('/^MemTotal:\s*(\d+)/m', $mem, $m)) {
            $memTotalKb = (int) $m[1];
        }
        $home = (string) getenv('HOME');
        return [
            'cpus'       => (int) trim((string) @shell_exec('nproc 2>/dev/null')),
            'memTotalMb' => (int) round($memTotalKb / 1024),
            'disk'       => Quota::fields($home), // quota first: the filesystem's free space is the whole array on shared servers
            'homeTotalGb' => $home !== '' && is_dir($home) ? round(disk_total_space($home) / 1073741824, 1) : null,
        ];
    }
}
