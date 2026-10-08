<?php
/**
 * Update: fast-forward the checkout to origin/main. Never stashes, never merges, never resets:
 * if tracked files were edited, it stops and lists them. local/ and the lesson files in memory/ are
 * gitignored, so user state is never touched.
 */
class Update
{
    const CRON_TAG = '# pikkumies-update';

    /** @var string|null test seam: a checkout other than this one */
    public static $root = null;
    /** @var string test seam: the crontab command */
    public static $crontab = 'crontab';

    public static function repoRoot()
    {
        return self::$root !== null ? self::$root : dirname(Config::root());
    }

    public static function git(array $args, &$code = null)
    {
        $cmd = 'git -C ' . escapeshellarg(self::repoRoot());
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $out = [];
        exec($cmd . ' 2>&1', $out, $code);
        return $out;
    }

    /**
     * The daily update line. Minute and hour come from the uid, so the users of one machine do
     * not all fetch at the same moment. It runs bin/pikkumies by its `env php` line, not a versioned PHP
     * binary that a distribution upgrade may remove. The log keeps only the last run. '' when a path has a % or a
     * line break, which would break the line.
     */
    public static function cronLine($uid)
    {
        $line = sprintf('%d %d * * * %s update > %s 2>&1 %s', $uid % 60, intdiv($uid, 60) % 24,
            escapeshellarg(Config::root() . '/bin/pikkumies'), escapeshellarg(Config::localDir() . '/update.log'), self::CRON_TAG);
        return strpbrk($line, "%\n\r") === false ? $line : '';
    }

    /** Crontab text without our line, plus $line when it is not ''. Every other line is kept as it was. */
    public static function cronMerge($current, $line)
    {
        $kept = '';
        foreach (preg_split('/(?<=\n)/', (string) $current, -1, PREG_SPLIT_NO_EMPTY) as $l) {
            if (strpos($l, self::CRON_TAG) === false) {
                $kept .= $l;
            }
        }
        if ($line === '') {
            return $kept;
        }
        return $kept . ($kept !== '' && substr($kept, -1) !== "\n" ? "\n" : '') . $line . "\n";
    }

    /**
     * Put the daily update line in the user's crontab, or take it out when autoUpdate.disabled.
     * Nothing is written unless `crontab -l` worked or said there is no crontab yet. Returns a status line.
     */
    public static function schedule(array $config, $uid)
    {
        list($code, $current, $err) = self::exec([self::$crontab, '-l']);
        if ($code !== 0) {
            if (stripos($err . $current, 'no crontab') === false) {
                return 'not changed (crontab -l failed: ' . trim($err) . ')';
            }
            $current = '';
        }
        $off = !empty($config['autoUpdate']['disabled']);
        $new = self::cronMerge($current, $off ? '' : self::cronLine($uid));
        if ($new !== $current) {
            $tmp = Config::localDir() . '/crontab.new';
            @mkdir(Config::localDir(), 0700, true);
            if (file_put_contents($tmp, $new) !== strlen($new)) {
                return 'not changed (could not write ' . $tmp . ')';
            }
            list($code, , $err) = self::exec([self::$crontab, $tmp]);
            @unlink($tmp);
            if ($code !== 0) {
                return 'not changed (crontab failed: ' . trim($err) . ')';
            }
        }
        return $off ? 'off (autoUpdate.disabled)' : 'daily, from your crontab (log: local/update.log)';
    }

    /** Run a command without a shell; returns [exit code, stdout, stderr]. */
    public static function exec(array $argv)
    {
        $p = proc_open(implode(' ', array_map('escapeshellarg', $argv)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [1, '', 'could not start ' . $argv[0]];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), (string) $out, (string) $err];
    }

    /** Returns [ok, message]. */
    public static function run()
    {
        $dirty = self::git(['status', '--porcelain', '--untracked-files=no'], $code);
        if ($code !== 0) {
            return [false, "not a git checkout:\n" . implode("\n", $dirty)];
        }
        if ($dirty) {
            return [false, "tracked files were changed locally; update stopped so nothing is overwritten:\n" . implode("\n", $dirty) . "\nMove your changes to local/ (or a local skill) and run update again."];
        }
        $before = trim(implode('', self::git(['rev-parse', '--short', 'HEAD'])));
        // No --depth: on a shallow clone a depth-1 fetch grafts the new tip as a parentless root,
        // so the fast-forward sees unrelated histories. A plain fetch stops at the existing shallow
        // boundary and downloads only the new commits.
        $fetch = self::git(['fetch', 'origin', 'main'], $code);
        if ($code !== 0) {
            return [false, "fetch failed:\n" . implode("\n", $fetch)];
        }
        $merge = self::git(['merge', '--ff-only', 'FETCH_HEAD'], $code);
        if ($code !== 0) {
            return [false, "cannot fast-forward (history diverged); nothing changed:\n" . implode("\n", $merge)];
        }
        $after = trim(implode('', self::git(['rev-parse', '--short', 'HEAD'])));
        return [true, $before === $after ? "already up to date ($after)" : "updated $before -> $after"];
    }
}
