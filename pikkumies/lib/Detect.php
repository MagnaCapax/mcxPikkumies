<?php
/**
 * Detect: read-only probe of the machine Pikkumies runs on.
 *
 * Returns a plain array (the "profile"). No network calls, no writes, no LLM.
 * Root is reported from what the OS says, never from configuration.
 */
class Detect
{
    /** Known user-level apps: profile key => process names that indicate it. */
    const KNOWN_APPS = [
        'rtorrent'    => ['rtorrent'],
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

    public static function run()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));
        return [
            'detectedAt' => gmdate('c'),
            'os'         => self::osRelease(),
            'user'       => self::userName($uid),
            'uid'        => $uid,
            'pmss'       => is_dir('/etc/seedbox'),
            'root'       => self::rootAccess($uid),
            'apps'       => self::runningApps($uid),
            'resources'  => self::resources(),
        ];
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
            'homeFreeGb' => $home !== '' && is_dir($home) ? (int) round(disk_free_space($home) / 1073741824) : null,
        ];
    }
}
