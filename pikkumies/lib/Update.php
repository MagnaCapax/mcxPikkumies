<?php
/**
 * Update: fast-forward the checkout to the newest release, or to a branch of origin when the channel
 * says so (UpdateChannel, ADR-0057). Never stashes, never merges, never resets:
 * if tracked files were edited, it stops and lists them. local/ and the lesson files in memory/ are
 * gitignored, so user state is never touched.
 */
require_once __DIR__ . '/PathName.php';

class Update
{
    const CRON_TAG = '# pikkumies-update';
    const CRON_TICK_TAG = '# pikkumies-cron-tick';

    /** @var string|null test seam: a checkout other than this one */
    public static $root = null;
    /** @var string test seam: the crontab command */
    public static $crontab = 'crontab';
    /** @var string|null test seam: where keepLine() records the lines ensureScheduled() restores */
    public static $keptDir = null;

    public static function repoRoot()
    {
        return self::$root !== null ? self::$root : dirname(Config::root());
    }

    public static function git(array $args, &$code = null)
    {
        // The daily cron runs this: a hook or fsmonitor written into .git/config must not run (as HermesInstall::git).
        $cmd = 'git -c core.fsmonitor=false -c core.hooksPath=/dev/null -C ' . escapeshellarg(self::repoRoot());
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

    /** Crontab text without any line tagged $tag, plus $line when it is not ''. Every other line is kept as it was. */
    public static function cronMerge($current, $line, $tag)
    {
        $kept = '';
        foreach (preg_split('/(?<=\n)/', (string) $current, -1, PREG_SPLIT_NO_EMPTY) as $l) {
            if (strpos($l, $tag) === false) {
                $kept .= $l;
            }
        }
        if ($line === '') {
            return $kept;
        }
        return $kept . ($kept !== '' && substr($kept, -1) !== "\n" ? "\n" : '') . $line . "\n";
    }

    /**
     * One crontab read/merge/write cycle for one tagged line, shared by the daily auto-update line
     * and the per-minute cron-tick line (ADR-0025), and by keepLine() for a tool's own line (ADR-0049).
     * Nothing is written unless `crontab -l` worked or said there is no crontab yet. Returns a status line.
     */
    public static function installTaggedLine($tag, $line, $onMsg, $offMsg)
    {
        list($code, $current, $err) = self::exec([self::$crontab, '-l']);
        if ($code !== 0) {
            if (stripos($err . $current, 'no crontab') === false) {
                return 'not changed (crontab -l failed: ' . trim($err) . ')';
            }
            $current = '';
        }
        $new = self::cronMerge($current, $line, $tag);
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
        return $line === '' ? $offMsg : $onMsg;
    }

    /**
     * Put the daily update line in the user's crontab, or take it out when autoUpdate.disabled.
     * Nothing is written unless `crontab -l` worked or said there is no crontab yet. Returns a status line.
     */
    public static function schedule(array $config, $uid)
    {
        $off = !empty($config['autoUpdate']['disabled']);
        return self::installTaggedLine(self::CRON_TAG, $off ? '' : self::cronLine($uid),
            'daily, from your crontab (log: ' . Config::shown(Config::localDir() . '/update.log') . ')', 'off (autoUpdate.disabled)');
    }

    /**
     * The per-minute tick line. Output is discarded by the line itself: `cron-tick` logs its own
     * state changes to local/update.log (CronTick::logIfChanged), and without a redirect cron would
     * otherwise mail the account on every run that printed anything. '' when the path has a % or a
     * line break (see cronLine()).
     */
    public static function cronTickLine()
    {
        $line = sprintf('* * * * * %s cron-tick >/dev/null 2>&1 %s', escapeshellarg(Config::root() . '/bin/pikkumies'), self::CRON_TICK_TAG);
        return strpbrk($line, "%\n\r") === false ? $line : ''; // same guard as cronLine(): a % or line break would break the line
    }

    /**
     * Put the per-minute cron-tick line in the user's crontab, or take it out when
     * cron.disabled — and never install it when it would only ever refuse: root available but no
     * `setpriv` to run it under (CronTick::refuses, ADR-0025). That refusal is logged once instead,
     * so it stays visible without a per-minute line that can never succeed. Returns a status line.
     */
    public static function scheduleCronTick(array $config, $rootAvailable, $logFile = null)
    {
        $off = !empty($config['cron']['disabled']);
        if (!$off && CronTick::refuses($rootAvailable)) {
            CronTick::logRefusalOnce($logFile !== null ? $logFile : Config::localDir() . '/update.log');
            return 'not installed (root available, no setpriv; see ' . Config::shown(Config::localDir() . '/update.log') . ')';
        }
        return self::installTaggedLine(self::CRON_TICK_TAG, $off ? '' : self::cronTickLine(),
            'every minute, from your crontab (log: ' . Config::shown(Config::localDir() . '/update.log') . ')', 'off (cron.disabled)');
    }

    /**
     * Put the two tagged lines back when they are missing: an account moved to another server arrives
     * without its crontab (PMSS userTransfer does not carry it), and the daily line is what runs
     * `update`, so nothing else would ever restore them. Reads the crontab once and calls schedule()
     * / scheduleCronTick() only for a line that is absent while wanted or present while switched off.
     * Returns the status lines of what changed, [] when the crontab is unchanged or unreadable.
     */
    public static function ensureScheduled(array $config, $uid, $rootAvailable, $logFile = null)
    {
        $before = self::readCrontab();
        if ($before === null) {
            return [];
        }
        $msgs = [];
        $updateWanted = empty($config['autoUpdate']['disabled']) && self::cronLine($uid) !== '';
        if ((strpos($before, self::CRON_TAG) !== false) !== $updateWanted) {
            $msgs[] = 'auto-update ' . self::schedule($config, $uid);
        }
        $tickWanted = empty($config['cron']['disabled']) && !CronTick::refuses($rootAvailable) && self::cronTickLine() !== '';
        if ((strpos($before, self::CRON_TICK_TAG) !== false) !== $tickWanted) {
            $msgs[] = 'cron-tick ' . self::scheduleCronTick($config, $rootAvailable, $logFile);
        }
        foreach (self::keptLines() as $tag => $line) {
            if (strpos($before, $tag) === false) {
                $msgs[] = substr($tag, 2) . ' ' . self::installTaggedLine($tag, $line, 'restored', 'off');
            }
        }
        return self::readCrontab() === $before ? [] : $msgs;
    }

    /**
     * A tool's own tagged line (`backup remote schedule`, ADR-0049), tag `# pikkumies-<name>`: installed now and recorded
     * in local/secrets/cron/<name>.cron, so ensureScheduled() puts it back after an account move; $line '' removes both.
     * The record sits under local/secrets, which the delete guard keeps the agent from writing. Returns a status line.
     */
    public static function keepLine($name, $line, $onMsg, $offMsg)
    {
        $tag = '# pikkumies-' . $name;
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $name) || ($line !== '' && (strpbrk($line, "%\n\r") !== false
            || substr($line, -strlen($tag)) !== $tag))) {
            return 'not changed (a line must be one line without %, ending with ' . $tag . ')';
        }
        $msg = self::installTaggedLine($tag, $line, $onMsg, $offMsg);
        $file = self::keptDir() . "/$name.cron";
        if (strpos($msg, 'not changed') === 0) {
            return $msg;
        }
        if ($line === '') {
            @unlink($file);
        } elseif (!@is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true) || @file_put_contents($file, $line . "\n") === false) {
            return $msg . ' (but not recorded for restoring after an account move: ' . dirname($file) . ' is not writable)';
        }
        @chmod($file, 0600);
        return $msg;
    }

    /** [tag => line] of every line keepLine() recorded; a record that is not this account's regular file is skipped. */
    private static function keptLines()
    {
        $out = [];
        foreach ((array) glob(self::keptDir() . '/*.cron') as $file) {
            $tag = '# pikkumies-' . PathName::base($file, '.cron');
            try {
                $line = trim((string) SecretFile::read($file, 'run the schedule verb again'));
            } catch (Exception $e) {
                continue;
            }
            if ($line !== '' && strpbrk($line, "%\n\r") === false && substr($line, -strlen($tag)) === $tag) {
                $out[$tag] = $line;
            }
        }
        return $out;
    }

    private static function keptDir()
    {
        return self::$keptDir !== null ? self::$keptDir : Config::localDir() . '/secrets/cron';
    }

    /** The current crontab text, '' when there is none yet, null when `crontab -l` failed otherwise. */
    private static function readCrontab()
    {
        list($code, $current, $err) = self::exec([self::$crontab, '-l']);
        if ($code === 0) {
            return $current;
        }
        return stripos($err . $current, 'no crontab') !== false ? '' : null;
    }

    /** Run a command through /bin/sh with every argument escaped; returns [exit code, stdout, stderr]. */
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

    /**
     * Returns [ok, message]. $channel is `release` (the newest date tag, fast-forward only; main until a
     * release exists) or `git/<branch>` (ADR-0057); $used is set to what was followed.
     */
    public static function run($channel = 'release', &$used = null)
    {
        $used = is_string($channel) ? $channel : json_encode($channel);
        if (!Config::validChannel($channel)) {
            return [false, 'update.channel is not valid (' . json_encode($channel) . '): use release or git/<branch>; nothing changed'];
        }
        $dirty = self::git(['status', '--porcelain', '--untracked-files=no'], $code);
        if ($code !== 0) {
            return [false, "not a git checkout:\n" . implode("\n", $dirty)];
        }
        if ($dirty) {
            return [false, "tracked files were changed locally; update stopped so nothing is overwritten:\n" . implode("\n", $dirty) . "\nMove your changes to local/ (or a local skill) and run update again."];
        }
        $before = trim(implode('', self::git(['rev-parse', '--short', 'HEAD'])));
        $branch = substr($channel, 4);
        if ($channel === 'release') {
            $tag = UpdateChannel::newest($out);
            if ($tag === null) {
                return [false, "fetch failed:\n" . implode("\n", $out)];
            }
            if ($tag !== '') {
                $used = "release $tag";
                return UpdateChannel::toRelease($tag, $before);
            }
            $used = 'release (none published yet: following main)';
            $branch = 'main';
        }
        // No --depth: on a shallow clone a depth-1 fetch grafts the new tip as a parentless root,
        // so the fast-forward sees unrelated histories. A plain fetch stops at the existing shallow
        // boundary and downloads only the new commits.
        // refs/heads/ explicitly: only a real branch, never a tag or another ref such as a pull-request head.
        $fetch = self::git(['fetch', 'origin', 'refs/heads/' . $branch], $code);
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
