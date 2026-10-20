<?php
/**
 * BackupStatus: whether this account's backups work, in a cache the hooks read without running anything (ADR-0099).
 * The backup-safety unit (tools/backup/backup-safety/) gathers the facts of each method (restic, borg, backup remote,
 * other tools found in crontabs or config files) and judge() turns them into one state:
 * - working: a method whose latest backup is fresh (48 h, or twice its schedule), verified within 7 days, not empty
 *   and within 50 % of the size of the one before;
 * - broken: a method that failed, is unreachable, found damage, has no backup yet, an empty one or a size jump;
 * - stale: a method whose latest backup is older than fresh; unknown: found but not checkable (another tool, or not
 *   verified in 7 days), and also when there is no cache yet; none: no method at all.
 * The cache is local/state/backup-status.json (0600). A session start refreshes it in the background when it is older
 * than a day (refreshInBackground, one at a time, 120 s at most, its output kept in local/logs/backup-status/last.log).
 */
class BackupStatus
{
    const FRESH = 172800;
    const VERIFY = 604800;
    const REFRESH = 86400;
    const DEVIATION = 0.5;
    const STATES = ['working', 'stale', 'broken', 'none', 'unknown'];
    /** Programs whose name on a cron line counts as a backup method Pikkumies cannot check itself. */
    const THIRD_PARTY = ['restic', 'borg', 'borgmatic', 'rsync', 'rclone', 'duplicity', 'rsnapshot', 'kopia', 'duplicacy'];
    /** Config files of backup tools ('~' is the home); found = a method Pikkumies cannot check itself. */
    const CONFIG_FILES = ['/etc/rsnapshot.conf', '/etc/borgmatic/config.yaml', '~/.config/borgmatic/config.yaml', '/etc/borgmatic.d',
        '~/.config/borgmatic.d', '~/.duplicacy/preferences'];
    /** The documentation sources (config/documentationSources.md) whose addresses the suggestion gives the user. */
    const LINKS = ['storage_box' => 'pm-storage-boxes', 'e2e_guide' => 'pm-zero-knowledge-backups'];

    /** @var callable|null test seam: fn(string $shellLine) instead of exec() in refreshInBackground() */
    public static $spawn = null;

    public static function file($localDir)
    {
        return rtrim($localDir, '/') . '/state/backup-status.json';
    }

    /** The cache, or null when there is none that this account wrote (the state is then unknown). */
    public static function read($localDir)
    {
        $f = self::file($localDir);
        $st = @lstat($f);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== $uid || $st['size'] > 1048576) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) && isset($d['state'], $d['checked_at']) && in_array($d['state'], self::STATES, true) ? $d : null;
    }

    public static function state($cache)
    {
        return is_array($cache) ? (string) $cache['state'] : 'unknown';
    }

    public static function write($localDir, array $status)
    {
        $f = self::file($localDir);
        if (!is_dir(dirname($f)) && !@mkdir(dirname($f), 0700, true)) {
            return false;
        }
        $tmp = $f . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $f)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** One method's facts plus its verdict: state, fresh, working, why. */
    public static function judgeMethod(array $m, $now)
    {
        $get = function ($k) use ($m) {
            return isset($m[$k]) ? $m[$k] : null;
        };
        $latest = $get('latest_at');
        $bytes = $get('latest_bytes');
        $prev = $get('prev_bytes');
        $fresh = $latest !== null && $now - (int) $latest <= max(self::FRESH, 2 * (int) $get('interval'));
        $verified = $get('verified_at') !== null && $now - (int) $get('verified_at') <= self::VERIFY && $get('verify_ok') !== false;
        $jump = $bytes !== null && $prev !== null && (int) $prev > 0 && abs((int) $bytes - (int) $prev) > self::DEVIATION * (int) $prev;
        if ($get('kind') === 'thirdparty') {
            list($state, $why) = ['unknown', 'found in ' . $get('where') . '; Pikkumies cannot check it, so check it yourself'];
        } elseif ($get('error') !== null) {
            list($state, $why) = ['broken', (string) $get('error')];
        } elseif ($get('verify_ok') === false) {
            list($state, $why) = ['broken', 'the last check found damage'];
        } elseif ($latest === null) {
            list($state, $why) = ['broken', 'set up, but no backup was made yet'];
        } elseif ($bytes !== null && (int) $bytes === 0) {
            list($state, $why) = ['broken', 'the latest backup is empty'];
        } elseif ($jump) {
            list($state, $why) = ['broken', 'the latest backup is ' . self::size($bytes) . ', the one before ' . self::size($prev) . ' (over 50 % apart)'];
        } elseif (!$fresh) {
            list($state, $why) = ['stale', 'the latest backup is ' . self::age($now - (int) $latest) . ' old'];
        } elseif (!$verified) {
            list($state, $why) = ['unknown', 'not verified in the last 7 days'];
        } else {
            list($state, $why) = ['working', 'latest ' . self::age($now - (int) $latest) . ' ago, verified ' . self::age($now - (int) $get('verified_at')) . ' ago'];
        }
        return ['state' => $state, 'fresh' => $fresh, 'working' => $state === 'working', 'why' => $why] + $m;
    }

    /** The whole status: working when any method works, else the worst found (broken, stale, unknown), none without one. */
    public static function judge(array $methods, $now)
    {
        $judged = [];
        foreach ($methods as $m) {
            $judged[] = self::judgeMethod($m, $now);
        }
        $states = array_column($judged, 'state');
        $state = 'none';
        foreach (['working', 'broken', 'stale', 'unknown'] as $s) {
            if (in_array($s, $states, true)) {
                $state = $s;
                break;
            }
        }
        return ['state' => $state, 'working' => $state === 'working', 'fresh' => in_array(true, array_column($judged, 'fresh'), true),
            'checked_at' => (int) $now, 'methods' => $judged];
    }

    /** Methods from crontab text: lines (not comments, not Pikkumies' own tagged lines) that run a known backup program. */
    public static function thirdParty($text, $source)
    {
        $out = [];
        $re = '/(^|[\s\/;&|(`"\'])(' . implode('|', self::THIRD_PARTY) . ')(?=[\s;&|)"\']|$)/';
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || strpos($t, '# pikkumies-') !== false || !preg_match($re, $t, $m)) {
                continue;
            }
            $out[] = ['kind' => 'thirdparty', 'where' => "$source ({$m[2]})", 'program' => $m[2]];
        }
        return $out;
    }

    /** Methods from known config files that exist ($home replaces ~). */
    public static function configFiles($home, array $files = null)
    {
        $out = [];
        foreach ($files !== null ? $files : self::CONFIG_FILES as $f) {
            $p = strpos($f, '~/') === 0 ? rtrim($home, '/') . substr($f, 1) : $f;
            if (($home !== '' || $f[0] !== '~') && @file_exists($p)) {
                $out[] = ['kind' => 'thirdparty', 'where' => $p];
            }
        }
        return $out;
    }

    /** The $n newest backups of every method, newest first: kind, where, name, time, bytes. */
    public static function latest($cache, $n)
    {
        $rows = [];
        foreach (is_array($cache) && isset($cache['methods']) ? (array) $cache['methods'] : [] as $m) {
            foreach (is_array($m) && isset($m['backups']) ? (array) $m['backups'] : [] as $b) {
                if (is_array($b) && isset($b['time']) && is_numeric($b['time'])) { // a hook reads this: a bad row is skipped, never a warning
                    $rows[] = ['kind' => isset($m['kind']) ? (string) $m['kind'] : '?', 'where' => isset($m['where']) ? $m['where'] : null,
                        'name' => isset($b['name']) ? (string) $b['name'] : '?', 'time' => (int) $b['time'],
                        'bytes' => isset($b['bytes']) && is_numeric($b['bytes']) ? (int) $b['bytes'] : null];
                }
            }
        }
        usort($rows, function ($a, $b) {
            return (int) $b['time'] - (int) $a['time'];
        });
        return array_slice($rows, 0, max(0, (int) $n));
    }

    /**
     * The custody line while backup remote is set up and its passphrase not confirmed saved, else ''. Shown to the user only:
     * never part of the verdict, because the key stays on this server through an rm and the archives still restore (ADR-0099).
     */
    public static function custody($cache)
    {
        foreach (is_array($cache) && isset($cache['methods']) ? (array) $cache['methods'] : [] as $m) {
            if (is_array($m) && isset($m['kind']) && $m['kind'] === 'remote' && empty($m['passphrase_saved_by_user'])) {
                return 'backup passphrase not confirmed saved: if this server is lost, the archives cannot be decrypted; run `php '
                    . Config::shown(Config::root() . '/tools/backup/remote/remote.php') . ' passphrase` in your own terminal';
            }
        }
        return '';
    }

    /** A markdown table of latest() rows for the user, or one line saying there is none; $custody (a line) follows it. */
    public static function table(array $rows, $now, $custody = '')
    {
        if (!$rows) {
            return 'No backups found on record (backup-safety `status --refresh` checks again).' . ($custody !== '' ? "\n$custody" : '');
        }
        $out = "| # | made (UTC) | age | size | where | name |\n|---|---|---|---|---|---|\n";
        foreach (array_values($rows) as $i => $r) {
            $out .= sprintf("| %d | %s | %s | %s | %s %s | %s |\n", $i + 1, gmdate('Y-m-d H:i', (int) $r['time']), self::age($now - (int) $r['time']),
                $r['bytes'] !== null ? self::size($r['bytes']) : '?', $r['kind'], self::cell($r['where']), self::cell($r['name']));
        }
        return rtrim($out) . ($custody !== '' ? "\n$custody" : '');
    }

    private static function cell($s)
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], Cli::cleanString((string) $s));
    }

    public static function age($s)
    {
        $s = max(0, (int) $s);
        return $s < 3600 ? (int) ($s / 60) . ' min' : ($s < 172800 ? (int) ($s / 3600) . ' h' : (int) ($s / 86400) . ' days');
    }

    public static function size($b)
    {
        $b = (float) $b;
        foreach (['B', 'KiB', 'MiB', 'GiB'] as $u) {
            if ($b < 1024) {
                return ($u === 'B' ? (string) (int) $b : sprintf('%.1f', $b)) . " $u";
            }
            $b /= 1024;
        }
        return sprintf('%.1f TiB', $b);
    }

    /** True when the cache is missing or older than REFRESH. */
    public static function due($cache, $now)
    {
        return !is_array($cache) || $now - (int) $cache['checked_at'] > self::REFRESH;
    }

    /** Starts `status --refresh` of $script detached: one at a time (flock), at most 120 s, output kept in a log, never /dev/null. */
    public static function refreshInBackground($localDir, $script)
    {
        $logDir = rtrim($localDir, '/') . '/logs/backup-status';
        if (!is_dir($logDir) && !@mkdir($logDir, 0700, true)) {
            return false;
        }
        $line = 'nohup flock -n ' . escapeshellarg(rtrim($localDir, '/') . '/state/backup-status.lock') . ' timeout 120 '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' status --refresh > ' . escapeshellarg("$logDir/last.log") . ' 2>&1 &';
        if (self::$spawn !== null) {
            call_user_func(self::$spawn, $line);
        } else {
            exec($line);
        }
        return true;
    }

    /** ['storage_box' => url|null, 'e2e_guide' => url|null] from the documentation sources, by name, on any host. */
    public static function links()
    {
        require_once __DIR__ . '/DocSources.php';
        $out = [];
        try {
            $all = DocSources::load();
        } catch (Throwable $e) {
            $all = [];
        }
        foreach (self::LINKS as $k => $name) {
            $s = isset($all[$name]) ? $all[$name] : null;
            $on = $s !== null && (isset($s['status']) ? $s['status'] : 'on') === 'on' && !DocSources::problems($s);
            $out[$k] = $on ? $s['url'] : null;
        }
        return $out;
    }

    /** One line for the user: set up real backups, with the storage box and guide addresses when the sources have them. */
    public static function suggestion()
    {
        $l = self::links();
        return 'Suggest to the user that they set up backups in case something happens: a storage box is cheap and zero-knowledge'
            . ' end-to-end encrypted backups to it are quick to set up' . ($l['storage_box'] !== null ? " ({$l['storage_box']}" . ($l['e2e_guide'] !== null
            ? ", guide {$l['e2e_guide']}" : '') . ')' : '') . '; the backup-safety skill says how.';
    }
}
