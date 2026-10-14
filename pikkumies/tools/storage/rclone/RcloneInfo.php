<?php
/**
 * RcloneInfo: the read verbs list, show and stats (README.md here). Remote settings are never
 * read or printed: `list` uses `rclone listremotes --long` (name, type, description; rclone 1.69.1
 * cmd/listremotes/listremotes.go:224), and the config file is named by path only.
 */
class RcloneInfo
{
    public static function remotes(RcloneCmd $c)
    {
        $c->ready();
        $r = $c->must(['listremotes'], ['--long']);
        $out = [];
        foreach (explode("\n", $r['out']) as $line) {
            if (preg_match('/^(\S.*?):\s+(\S+)\s*(.*)$/', rtrim($line), $m)) {
                $out[] = ['remote' => $m[1] . ':', 'type' => $m[2], 'description' => $m[3]];
            }
        }
        return ['records' => $out, 'status' => ['config' => $c->configPath(), 'config_encrypted' => false]];
    }

    /**
     * One directory level (`lsf --max-depth 1`, read line by line up to ToolProc::want, so --offset and --limit 0 page
     * like every list). lsf prints times in rclone's local zone without saying which, so rclone runs with TZ=UTC0 (Go
     * reads TZ; seen on rclone 1.75.0) and `modified` is ISO 8601 UTC.
     */
    public static function show(RcloneCmd $c, array $args, array $opt)
    {
        if (count($args) !== 1) {
            throw new CliError('show needs one place', 'show remote:path, or a full local path');
        }
        $c->ready();
        $where = $c->place($args[0], true);
        $want = ToolProc::want($opt);
        $rows = [];
        $flags = ['--max-depth', '1', '--format', 'tsp', '--separator', "\t"];
        $r = $c->run(['lsf'], $flags, [$where], function ($line) use (&$rows, $want) {
            $f = explode("\t", $line, 3); // time, size, path: the path last because separators in it are not escaped (lsf docs)
            if (count($f) === 3) {
                $dir = substr($f[2], -1) === '/';
                $rows[] = ['name' => $dir ? substr($f[2], 0, -1) : $f[2], 'is_dir' => $dir, 'bytes' => (int) $f[1] < 0 ? null : (int) $f[1],
                    'modified' => preg_match('/^(\d{4}-\d\d-\d\d) (\d\d:\d\d:\d\d)$/', $f[0], $m) ? "{$m[1]}T{$m[2]}Z" : $f[0]];
            }
            return count($rows) < $want;
        }, null, ['TZ' => 'UTC0']);
        if (!$r['stopped'] && $r['code'] !== 0) {
            throw new CliError("rclone lsf failed (exit {$r['code']}): " . ToolProc::tail($r['err']), 'remote names come from `list`');
        }
        $status = ['place' => $where] + ToolProc::listed(count($rows), $want);
        if (!empty($opt['size'])) {
            $size = json_decode($c->must(['size'], ['--json'], [$where])['out'], true); // a full listing: API calls and time on big remotes
            $status['files'] = isset($size['count']) ? (int) $size['count'] : null;
            $status['total_bytes'] = isset($size['bytes']) ? (int) $size['bytes'] : null;
            if (!RcloneCmd::isLocal($where)) {
                $about = $c->run(['about'], ['--json'], [substr($where, 0, strpos($where, ':') + 1)]);
                $a = $about['code'] === 0 ? json_decode($about['out'], true) : null; // not every backend supports about
                foreach (['total', 'used', 'free'] as $k) {
                    $status["remote_{$k}_bytes"] = is_array($a) && isset($a[$k]) ? (int) $a[$k] : null;
                }
            }
        }
        return ['records' => $rows, 'status' => $status];
    }

    public static function stats(RcloneCmd $c, $family, $mountinfo, $procRoot)
    {
        $c->bin();
        $config = $c->configPath();
        $r = ['version' => $c->version(), 'config' => $config,
            'config_exists' => $config !== null && is_file($config), 'config_encrypted' => $c->encrypted()];
        if ($family === 'pmss') {
            // The panel toggle writes ~/.rcloneEnable; the watchdog runs `rclone rcd` on 127.0.0.1:<~/.rclonePort>
            // (PMSS scripts/cron/checkRcloneInstances.php:13-21, etc/skel/www/scriptsInc.php:1122).
            $port = (int) trim((string) @file_get_contents($c->home . '/.rclonePort'));
            $r['webui'] = ['enabled' => is_file($c->home . '/.rcloneEnable'), 'port' => $port > 0 ? $port : null,
                'running' => $port > 0 ? Host::ownsListener($port, $c->uid) === 'own' : false];
        }
        $r['mounts'] = array_map(function ($m) {
            return ['mountpoint' => $m['mountpoint'], 'fstype' => $m['fstype'], 'source' => $m['source']];
        }, HomePath::fuseMounts($mountinfo, $c->uid));
        $r['running'] = self::processes($procRoot, $c->uid);
        return [$r];
    }

    /** This account's rclone processes: pid and subcommand only (the rest of a command line can hold secrets). */
    private static function processes($procRoot, $uid)
    {
        $out = [];
        foreach ((array) glob($procRoot . '/[0-9]*', GLOB_ONLYDIR) as $dir) {
            if (@fileowner($dir) !== $uid || trim((string) @file_get_contents("$dir/comm")) !== 'rclone') {
                continue;
            }
            $words = array_values(array_filter(explode("\0", (string) @file_get_contents("$dir/cmdline")), function ($w) {
                return $w !== '' && $w[0] !== '-';
            }));
            $out[] = ['pid' => (int) basename($dir), 'command' => isset($words[1]) ? preg_replace('/[^a-z0-9\/_-]/i', '', $words[1]) : ''];
        }
        return $out;
    }
}
