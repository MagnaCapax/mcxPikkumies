<?php
/**
 * TicketCollect (ADR-0100, on /ticket ADR-0089): what `ticket bundle` reads from this machine for attachment 01. System
 * facts, the process list and the logs. System-wide reads (every process, /var/log, the system journal) follow
 * Detect::rootAccess(): uid 0 runs them directly, passwordless sudo runs them with `sudo -n`, anything else stays inside
 * this account; an automatic ticket and any ticket on a Pulsed Media server pass 'none' whatever the privilege. Logs
 * under HOME must resolve under HOME, end in no symlink, be regular files this account owns, and sit outside dot
 * directories and Pikkumies' own state; as root a file with more than one hard link is refused. Nothing here redacts:
 * the attachments go through TicketDraft::attachment (lib/Redact.php) like every text file a ticket carries.
 */
require_once dirname(__DIR__, 3) . '/lib/Host.php';
require_once dirname(__DIR__, 3) . '/lib/Config.php';

class TicketCollect
{
    const LOG_TAIL = 65536;    // newest bytes of each log
    const PROC_ROWS = 300;
    const PS = ['ps', '-o', 'pid,ppid,user,pcpu,pmem,rss,etime,stat,args', '--sort=-pcpu'];
    const VARLOG_RE = '#^/var/log/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#D';
    /** 'root' (uid 0 with full capabilities), 'sudo' (passwordless) or 'none', from Detect::rootAccess(). */
    public static function privilege(array $root)
    {
        $via = isset($root['via']) ? $root['via'] : 'none';
        return empty($root['available']) ? 'none' : ($via === 'uid0' ? 'root' : ($via === 'sudo' ? 'sudo' : 'none'));
    }

    /** $argv for a system-wide read: as is for root, after `sudo -n` for sudo, null without either. */
    public static function systemWide($priv, array $argv)
    {
        return $priv === 'root' ? $argv : ($priv === 'sudo' ? array_merge(['sudo', '-n'], $argv) : null);
    }

    /** This machine's own non-loopback addresses (no packet sent), the primary one first when known. */
    public static function addresses($primary, $ifaces = null)
    {
        $out = $primary !== null ? [$primary] : [];
        foreach ($ifaces === null ? (function_exists('net_get_interfaces') ? (array) @net_get_interfaces() : []) : $ifaces as $iface) {
            foreach (isset($iface['unicast']) ? (array) $iface['unicast'] : [] as $a) {
                $ip = isset($a['address']) ? strtolower((string) $a['address']) : '';
                if (filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^(127\.|::1$|fe80:)/', $ip)) {
                    $out[] = $ip;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** The system facts, label => value, from the detected profile, the privilege, the addresses, quota and versions. */
    public static function facts(array $profile, $priv, array $addresses, array $quota, array $versions)
    {
        $g = function ($a, $k, $d = '') {
            return is_array($a) && isset($a[$k]) && is_scalar($a[$k]) ? (string) $a[$k] : $d;
        };
        $id = isset($profile['identity']) ? $profile['identity'] : [];
        $os = isset($profile['os']) ? $profile['os'] : [];
        $apps = isset($profile['apps']) && is_array($profile['apps']) ? $profile['apps'] : [];
        $gb = function ($b) {
            return $b === null ? 'unknown' : round($b / 1073741824, 1) . ' GiB';
        };
        return [
            'Hostname' => $g($id, 'fqdn', 'unknown') . ($g($id, 'hostname') !== '' && $g($id, 'hostname') !== $g($id, 'fqdn') ? ' (' . $g($id, 'hostname') . ')' : ''),
            'IP addresses' => $addresses ? implode(', ', $addresses) : 'unknown',
            'Service user' => $g($profile, 'user', 'unknown') . ' (uid ' . $g($profile, 'uid', '?') . ')',
            'Operating system' => trim($g($os, 'id') . ' ' . $g($os, 'version')),
            'Pulsed Media server (PMSS)' => !empty($profile['pmss']) ? 'yes, version ' . ($g($versions, 'pmss') !== '' ? $versions['pmss'] : 'unknown') : 'no',
            'Host family / stack' => $g($profile, 'family', 'unknown') . ' / ' . $g($profile, 'stack', 'unknown'),
            'Privilege' => $priv . ['root' => ' (running as root: system-wide reads run directly)', 'sudo' => ' (passwordless sudo: system-wide reads run with sudo -n)',
                'none' => ' (no root, no passwordless sudo: only this account\'s processes and files)'][$priv],
            'Disk quota' => $quota['quota_limit_bytes'] === null ? 'no quota found' : $gb($quota['quota_used_bytes']) . ' used of ' . $gb($quota['quota_limit_bytes'])
                . (!empty($quota['over_quota']) ? ' (over quota)' : ''),
            'Filesystem free' => $gb($quota['fs_free_bytes']) . ' (the whole filesystem; on a shared server far more than this account may use)',
            'Own running apps' => $apps ? implode(', ', array_map('strval', Config::isList($apps) ? $apps : array_keys(array_filter($apps)))) : 'none detected',
            'Pikkumies / Hermes' => ($g($versions, 'pikkumies') ?: 'unknown') . ' / ' . ($g($versions, 'hermes') ?: 'unknown'),
            'Profile detected at' => $g($profile, 'detectedAt', 'unknown'),
        ];
    }

    /** [scope, text, rows]: every process with root or sudo, else only this account's. */
    public static function processes($priv, $uid, callable $run)
    {
        $all = self::systemWide($priv, array_merge(self::PS, ['-e']));
        $r = $run($all !== null ? $all : array_merge(self::PS, ['-u', (string) (int) $uid]));
        $lines = array_values(array_filter(explode("\n", (string) $r['out']), 'strlen'));
        if ((int) $r['code'] !== 0 || !$lines) {
            return [$all !== null ? 'all users' : 'this account only', 'ps did not run (exit ' . (int) $r['code'] . ')', 0];
        }
        $rows = count($lines) - 1;
        $text = implode("\n", array_slice($lines, 0, self::PROC_ROWS + 1)) . ($rows > self::PROC_ROWS ? "\n… " . ($rows - self::PROC_ROWS) . ' more rows left out' : '');
        return [$all !== null ? 'all users' : 'this account only', $text, $rows];
    }

    /**
     * [[label, text], ...]: the named logs, this host's own log files under HOME, Pikkumies' own logs of today, the user
     * journal, and with root or sudo the system journal and the usual /var/log files. A log that cannot be read says why.
     */
    public static function logs(array $named, $home, $uid, $priv, array $profile, $localDir, callable $run)
    {
        $out = [];
        $home = rtrim((string) realpath((string) $home), '/');
        $paths = array_fill_keys($named, false);
        foreach (Host::logGlobs(isset($profile['family']) ? $profile['family'] : '', isset($profile['stack']) ? $profile['stack'] : '') as $g) {
            foreach ($home !== '' ? (array) glob("$home/$g") : [] as $p) {
                $paths[$p] = true; // listed for this host family: its dot directory (~/.lighttpd) is allowed
            }
        }
        if ($priv !== 'none') {
            foreach (array_filter(['/var/log/syslog', '/var/log/messages', '/var/log/kern.log'], 'file_exists') as $p) {
                $paths[$p] = true;
            }
        }
        foreach ($paths as $p => $listed) {
            $out[] = [(string) $p, self::readLog((string) $p, $home, $uid, $priv, $run, $localDir, $listed)];
        }
        $day = gmdate('Ymd');
        foreach (["$localDir/logs/commands/$day.jsonl" => 'Pikkumies command log (today)', "$localDir/logs/hooks/$day.jsonl" => 'Pikkumies hook log (today)',
            "$localDir/update.log" => 'Pikkumies update log'] as $f => $label) {
            if (is_file($f) && !is_link($f)) {
                $out[] = [$label, self::tail($f)];
            }
        }
        $j = $run(['journalctl', '--user', '-n', '200', '--no-pager']);
        $out[] = ['journalctl --user (last 200 lines)', (int) $j['code'] === 0 ? (string) $j['out'] : 'not available (exit ' . (int) $j['code'] . ')'];
        $sys = self::systemWide($priv, ['journalctl', '-n', '200', '-p', 'warning', '--no-pager']);
        if ($sys !== null) {
            $j = $run($sys);
            $out[] = ['system journal, warnings and worse (last 200 lines)', (int) $j['code'] === 0 ? (string) $j['out'] : 'not available (exit ' . (int) $j['code'] . ')'];
        }
        return $out;
    }

    /**
     * One log's newest part, or why it was not read. /var/log: every directory below /var/log/ on the path must be a real
     * directory owned by root (a service account's own subdirectory is where a planted link or FIFO would sit), and the
     * file a regular file reached through no symlink. Root opens it, then compares the open file (fstat) with the path
     * (lstat): same device and inode, a regular file, or it is refused. Sudo runs `timeout 10 sudo -n dd
     * iflag=nofollow,skip_bytes,nonblock` for the newest bytes, so a link swapped in or a FIFO cannot be followed or hang
     * the bundle. Under HOME: the file must resolve under HOME, end in no symlink, be a regular file this account owns; a
     * name under a dot directory (~/.ssh, ~/.config, ~/.pgpass) is refused unless this host family lists it ($listed),
     * and so is anything in Pikkumies' own local/ but its logs/ and update.log. As root, a file with more than one hard
     * link is refused: it may be another file's second name.
     */
    public static function readLog($path, $home, $uid, $priv, callable $run, $localDir = '', $listed = false)
    {
        $path = (string) $path;
        if (preg_match(self::VARLOG_RE, $path) && strpos($path, '..') === false) {
            if ($priv === 'none') {
                return 'not read: /var/log needs root or passwordless sudo, which this account does not have';
            }
            $at = '';
            $parts = explode('/', ltrim($path, '/'));
            foreach ($parts as $i => $part) {
                $at .= "/$part";
                clearstatcache(true, $at);
                $st = @lstat($at);
                if ($st !== false && ($st['mode'] & 0170000) === 0120000) {
                    return "not read: $at is a symlink";
                }
                if ($i >= 2 && $i < count($parts) - 1 && ($st === false || ($st['mode'] & 0170000) !== 0040000 || (int) $st['uid'] !== 0)) {
                    return "not read: $at is not a directory owned by root";
                }
            }
            $st = @lstat($path);
            if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
                return 'not read: not a regular file, or it cannot be checked without root';
            }
            $skip = max(0, (int) $st['size'] - self::LOG_TAIL);
            if ($priv === 'root') {
                $h = @fopen($path, 'rb');
                $open = $h !== false ? fstat($h) : false;
                clearstatcache(true, $path);
                $now = @lstat($path);
                if ($open === false || $now === false || ($open['mode'] & 0170000) !== 0100000 || $open['dev'] !== $now['dev'] || $open['ino'] !== $now['ino']) {
                    if ($h !== false) {
                        fclose($h);
                    }
                    return 'not read: the file changed while it was opened';
                }
                fseek($h, $skip);
                $data = (string) stream_get_contents($h, self::LOG_TAIL);
                fclose($h);
                return self::fromLineStart($data, $skip > 0);
            }
            $r = $run(['timeout', '10', 'sudo', '-n', 'dd', 'iflag=nofollow,skip_bytes,nonblock', "if=$path", 'bs=' . self::LOG_TAIL, 'count=1', "skip=$skip", 'status=none']);
            return (int) $r['code'] === 0 ? self::fromLineStart((string) $r['out'], $skip > 0) : 'not read: sudo dd failed (exit ' . (int) $r['code'] . ')';
        }
        $real = realpath($path);
        if ($home === '' || $real === false || strpos($real, "$home/") !== 0) {
            return 'not read: only files under your home directory (or /var/log with root or sudo) are attached';
        }
        $local = $localDir !== '' ? realpath($localDir) : false;
        if ($local !== false && strpos($real, "$local/") === 0) {
            $rel = substr($real, strlen($local) + 1);
            if ($rel !== 'update.log' && strpos($rel, 'logs/') !== 0) {
                return 'not read: Pikkumies\' own state and settings are never attached, only its logs';
            }
        } elseif (!$listed && preg_match('#(^|/)\.#', substr($real, strlen($home) + 1)
            . (strpos($path, "$home/") === 0 ? '/' . substr(rtrim($path, '/'), strlen($home) + 1) : ''))) {
            return 'not read: files under a dot directory (~/.ssh, ~/.config, ~/.pgpass and the like) are never attached';
        }
        if (is_link(rtrim($path, '/')) || !is_file($real)) {
            return 'not read: a symlink, or not a regular file';
        }
        $st = @stat($real);
        if ((int) $uid === 0 && is_array($st) && $st['nlink'] > 1) {
            return 'not read: a file with more than one hard link is never read as root';
        }
        if (@fileowner($real) !== (int) $uid) {
            return 'not read: not owned by this account';
        }
        return self::tail($real);
    }

    /** The newest LOG_TAIL bytes of a file, from a line start. */
    public static function tail($file)
    {
        $size = (int) @filesize($file);
        $data = (string) @file_get_contents($file, false, null, max(0, $size - self::LOG_TAIL));
        return self::fromLineStart($data, $size > self::LOG_TAIL);
    }

    public static function fromLineStart($data, $cut)
    {
        $nl = $cut ? strpos($data, "\n") : false;
        return $nl === false ? $data : substr($data, $nl + 1);
    }
}
