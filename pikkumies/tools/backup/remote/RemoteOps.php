<?php
/**
 * RemoteOps: the smaller verbs of `backup remote` (ADR-0049): list and restore through the receiver, stats from the
 * local records, schedule through Update's tagged crontab line, and passphrase, which shows the gpg passphrase only on
 * the user's own terminal (the delete guard also blocks the agent from running it).
 */
class RemoteOps
{
    const TAG_NAME = 'backup-remote';

    /** The archives on the backup machine, newest first. */
    public static function listing(RemoteDest $dest)
    {
        $d = $dest->load();
        list($code, $out, $err) = $dest->call($d, ['list']);
        if ($code !== 0) {
            throw new CliError('the backup machine did not answer: ' . RemoteDest::sshTail($err), 'check the network; `stats` shows the destination');
        }
        $recs = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^(\S+) (\d+)$/', trim($line), $m) && preg_match(RemoteDest::NAME_RE, $m[1], $n)) {
                $recs[] = ['name' => $m[1], 'mode' => $n[2], 'time' => strtotime($n[1]), 'bytes' => (int) $m[2]];
            }
        }
        rsort($recs);
        return $recs;
    }

    /** get <name> | gpg -d | pigz -d | tar -x into a new or empty directory inside the home, never over existing files. */
    public static function restore(RemoteDest $dest, array $args, array $opt, $home, $mountinfo)
    {
        $name = isset($args[0]) ? (string) $args[0] : '';
        if (!preg_match(RemoteDest::NAME_RE, $name)) {
            throw new CliError('name an archive as `list` prints it', 'e.g. restore 20261018T030000Z.meta.tgz.gpg --target ~/restore-1018');
        }
        $d = $dest->load();
        foreach (['tar', 'pigz', 'gpg'] as $bin) {
            ToolProc::need($bin, "install $bin");
        }
        $pass = $dest->passFile(false);
        list($target, $created) = HomePath::newTarget(isset($opt['target']) ? $opt['target'] : '', $home, HomePath::fuseMounts($mountinfo));
        $out = (string) tempnam(sys_get_temp_dir(), 'pk-remote-out');
        $r = RemotePipe::run([$dest->ssh($d, ['get', $name]), ['gpg', '--batch', '--pinentry-mode', 'loopback', '--no-symkey-cache',
            '--passphrase-file', $pass, '--decrypt'], ['pigz', '-d', '-c'], ['tar', '-xf', '-', '-C', $target]], '/dev/null', $out, 0);
        @unlink($out);
        $n = count((array) @scandir($target)) - 2;
        if ($r['codes'] !== [0, 0, 0, 0]) {
            if ($created && $n === 0) {
                @rmdir($target);
            }
            $stage = ['download', 'gpg', 'pigz', 'tar'][(int) key(array_filter($r['codes']))];
            throw new CliError("the restore failed at $stage: " . RemoteDest::sshTail(implode("\n", array_filter($r['err']))),
                $n > 0 ? "$target holds what arrived before the failure; check it before using it" : 'nothing was restored');
        }
        return ['records' => [['name' => $name, 'target' => $target, 'entries' => $n, 'bytes' => $r['bytes']]], 'status' => ['verified' => $n > 0]];
    }

    /** Local facts only: the destination, the last run, the schedule, whether the user saved the passphrase. */
    public static function stats(RemoteDest $dest, $keptDir)
    {
        $d = $dest->load();
        $s = $dest->state();
        $file = "$keptDir/" . self::TAG_NAME . '.cron';
        $line = is_file($file) ? trim((string) SecretFile::read($file, 'run schedule again')) : '';
        return [['host' => $d['host'], 'user' => $d['user'], 'port' => (int) $d['port'], 'label' => $d['label'],
            'keep_days' => (int) $d['keep_days'], 'host_key' => isset($d['host_key']) ? $d['host_key'] : null,
            'schedule' => $line !== '' ? $line : null, 'last_run' => isset($s['last_run']) ? $s['last_run'] : null,
            'passphrase_saved_by_user' => !empty($s['passphrase_saved_by_user'])]];
    }

    /** --daily HH:MM --mode meta|full [--allow-large], or --off. */
    public static function schedule(RemoteDest $dest, array $opt, $script, $localDir)
    {
        if (!empty($opt['off'])) {
            return [['schedule' => Update::keepLine(self::TAG_NAME, '', '', 'off')]];
        }
        $at = isset($opt['daily']) ? (string) $opt['daily'] : '';
        $mode = isset($opt['mode']) ? (string) $opt['mode'] : '';
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $at, $t) || !in_array($mode, ['meta', 'full'], true)) {
            throw new CliError('schedule takes --daily HH:MM --mode meta|full, or --off', 'e.g. schedule --daily 04:30 --mode meta');
        }
        $d = $dest->load();
        // The PHP that runs this now, not `php` from cron's short PATH (as HermesConfig does for its cron line).
        $line = sprintf('%d %d * * * %s %s run --mode %s%s > %s 2>&1 # pikkumies-%s', (int) $t[2], (int) $t[1], escapeshellarg(PHP_BINARY),
            escapeshellarg($script), $mode,
            empty($opt['allow-large']) ? '' : ' --allow-large', escapeshellarg($localDir . '/backup-remote.log'), self::TAG_NAME);
        $msg = Update::keepLine(self::TAG_NAME, $line, "daily at $at server time ($mode) to {$d['host']}", 'off');
        if (strpos($msg, 'not changed') === 0) {
            throw new CliError("crontab $msg", 'check `crontab -l`');
        }
        return ['records' => [['schedule' => $msg, 'line' => $line]], 'status' => ['verified' => true]];
    }

    /** Shows the passphrase, or records that the user saved it; both only at a terminal (never through the agent). */
    public static function passphrase(RemoteDest $dest, array $opt)
    {
        $tty = function ($s) {
            return function_exists('stream_isatty') ? @stream_isatty($s) : (function_exists('posix_isatty') && @posix_isatty($s));
        };
        if (!$tty(STDIN) || !$tty(STDOUT)) {
            throw new CliError('this verb shows the passphrase only on your own terminal; nothing shown',
                'the user runs it in their own terminal, never through the agent (the model provider would get it)');
        }
        $f = $dest->passFile(false);
        if (!empty($opt['confirm-saved'])) {
            $dest->save('state.json', ['passphrase_saved_by_user' => true] + $dest->state());
            return ['records' => [['passphrase_saved_by_user' => true]], 'status' => ['verified' => true]];
        }
        fwrite(STDOUT, 'gpg passphrase of your backups: ' . trim((string) SecretFile::read($f, 'run setup')) . "\nKeep it away from this server and"
            . " the backup machine. Then run this verb again with --confirm-saved.\n");
        return [['shown' => true]];
    }
}
