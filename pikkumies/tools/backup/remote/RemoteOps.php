<?php
/**
 * RemoteOps: the smaller verbs of `backup remote` (ADR-0049): list and restore through the receiver, stats from the
 * local records, schedule through Update's tagged crontab line, and passphrase, which shows the gpg passphrase only on
 * the user's own terminal (the delete guard also blocks the agent from running it).
 */
class RemoteOps
{
    const TAG_NAME = 'backup-remote';
    const CHECK_FULL_MAX = 10737418240; // 10 GiB: a bigger full archive is not downloaded just to check it

    /** @var callable|null test seam: fn($stream) => bool, whether it is the user's own terminal */
    public static $tty = null;
    /** @var callable|null test seam: fn($label) => the line typed with echo off, instead of SecretFile::prompt */
    public static $readHidden = null;

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
        $out = $dest->tmp('pk-remote-out');
        $r = RemotePipe::run([$dest->ssh($d, ['get', $name]), self::decryptArgv($pass), ['pigz', '-d', '-c'], ['tar', '-xf', '-', '-C', $target]], '/dev/null', $out, 0);
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
        if (!self::decrypted($r['err'][1])) {
            // tar has unpacked it already; it stays for the user to look at, never as their backup.
            throw new CliError("the archive was not encrypted with your passphrase, so what was unpacked into $target is not your backup",
                'do not use those files: someone with access to the backup machine may have put them there; check with its owner');
        }
        return ['records' => [['name' => $name, 'target' => $target, 'entries' => $n, 'bytes' => $r['bytes']]], 'status' => ['verified' => $n > 0]];
    }

    /**
     * gpg reading an archive. --status-fd 2 puts its machine-readable status lines into the stderr Pikkumies keeps: plain
     * `--decrypt` also prints an unencrypted OpenPGP message and exits 0 (gpg 2.4.7, tried 2026-10-20), so only a
     * DECRYPTION_OKAY line proves the archive was encrypted with this passphrase (ADR-0099).
     */
    public static function decryptArgv($pass)
    {
        return ['gpg', '--batch', '--pinentry-mode', 'loopback', '--no-symkey-cache', '--status-fd', '2', '--passphrase-file', $pass, '--decrypt'];
    }

    public static function decrypted($gpgErr)
    {
        return strpos((string) $gpgErr, '[GNUPG:] DECRYPTION_OKAY') !== false;
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
            'passphrase_saved_by_user' => $dest->passphraseConfirmed(), 'last_check' => isset($s['last_check']) ? $s['last_check'] : null]];
    }

    /**
     * Whether the newest archive can be read back: the newest meta archive, else the newest full one of at most CHECK_FULL_MAX,
     * streamed get | gpg -d | pigz -d | tar -t | wc -l, so nothing of it is written here and only the number of entries is
     * kept. Every stage's exit code counts; a failure keeps the stderr tails. The result is state.json's last_check, which
     * backup-safety reads as the remote's verification (ADR-0099). No archive small enough: checked false, not a failure.
     */
    public static function check(RemoteDest $dest, $fullMax = self::CHECK_FULL_MAX)
    {
        $rows = self::listing($dest);
        $pick = null;
        foreach ($rows as $r) {
            if ($r['mode'] === 'meta') {
                $pick = $r;
                break;
            }
        }
        foreach ($pick === null ? $rows : [] as $r) {
            if ($r['bytes'] <= $fullMax) {
                $pick = $r;
                break;
            }
        }
        if ($pick === null) {
            $rec = ['checked' => false, 'verify_ok' => null, 'why' => $rows ? 'only full archives over 10 GiB; checked on restore only' : 'no archive yet'];
            $dest->save('state.json', ['last_check' => $rec + ['at' => time()]] + $dest->state());
            return ['records' => [$rec], 'status' => ['verified' => false]];
        }
        $d = $dest->load();
        foreach (['tar', 'pigz', 'gpg', 'wc'] as $bin) {
            ToolProc::need($bin, "install $bin");
        }
        $out = $dest->tmp('pk-remote-out');
        $r = RemotePipe::run([$dest->ssh($d, ['get', $pick['name']]), self::decryptArgv($dest->passFile(false)), ['pigz', '-d', '-c'], ['tar', '-tf', '-'],
            ['wc', '-l']], '/dev/null', $out);
        $count = trim((string) @file_get_contents($out, false, null, 0, 64));
        @unlink($out);
        $plain = $r['codes'][1] === 0 && !self::decrypted($r['err'][1]);
        $ok = $r['codes'] === [0, 0, 0, 0, 0] && !$plain && preg_match('/^\d+$/', $count) && (int) $count > 0;
        $rec = ['checked' => true, 'name' => $pick['name'], 'verify_ok' => (bool) $ok, 'entries' => $ok ? (int) $count : null,
            'verified_at' => time()];
        if (!$ok) {
            $stage = $r['codes'] === [0, 0, 0, 0, 0] ? 'tar (no entries)' : ['download', 'gpg', 'pigz', 'tar', 'wc'][(int) key(array_filter($r['codes']))];
            $rec['error'] = $plain ? 'failed at gpg: not encrypted with your passphrase (an unencrypted archive is not your backup)'
                : "failed at $stage: " . RemoteDest::sshTail(implode("\n", array_filter($r['err'])));
        }
        $dest->save('state.json', ['last_check' => $rec] + $dest->state());
        return ['records' => [$rec], 'status' => ['verified' => (bool) $ok]];
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
        $line = self::cronLine((int) $t[1], (int) $t[2], $script, $mode, !empty($opt['allow-large']), $localDir);
        $msg = Update::keepLine(self::TAG_NAME, $line, "daily at $at server time ($mode) to {$d['host']}", 'off');
        if (strpos($msg, 'not changed') === 0) {
            throw new CliError("crontab $msg", 'check `crontab -l`');
        }
        return ['records' => [['schedule' => $msg, 'line' => $line]], 'status' => ['verified' => true]];
    }

    /**
     * The schedule line: run by the PHP that runs this now, not `php` from cron's short PATH (as HermesConfig does for its
     * cron line), its output and errors kept in local/backup-remote.log, never /dev/null (ADR-0099).
     */
    public static function cronLine($hour, $minute, $script, $mode, $allowLarge, $localDir)
    {
        return sprintf('%d %d * * * %s %s run --mode %s%s > %s 2>&1 # pikkumies-%s', $minute, $hour, escapeshellarg(PHP_BINARY),
            escapeshellarg($script), $mode, $allowLarge ? ' --allow-large' : '', escapeshellarg($localDir . '/backup-remote.log'), self::TAG_NAME);
    }

    /**
     * Shows the passphrase, or records that the user saved it; both only at a terminal (never through the agent). --confirm-saved
     * asks the user to type their saved copy back with echo off and compares it here, so `passphrase_saved_by_user` means a
     * copy that works, not a promise; the typed text is never stored, printed or logged (ADR-0099).
     */
    public static function passphrase(RemoteDest $dest, array $opt)
    {
        $tty = self::$tty !== null ? self::$tty : function ($s) {
            return function_exists('stream_isatty') ? @stream_isatty($s) : (function_exists('posix_isatty') && @posix_isatty($s));
        };
        if (!$tty(STDIN) || !$tty(STDOUT)) {
            throw new CliError('this verb shows the passphrase only on your own terminal; nothing shown',
                'the user runs it in their own terminal, never through the agent (the model provider would get it)');
        }
        $f = $dest->passFile(false);
        if (!empty($opt['confirm-saved'])) {
            $label = 'Type the passphrase from your saved copy (not shown): ';
            $typed = self::$readHidden !== null ? (string) call_user_func(self::$readHidden, $label)
                : SecretFile::prompt($label, 'this verb reads from your own terminal only; nothing recorded', 'run it in your own terminal');
            $real = trim((string) SecretFile::read($f, 'run setup'));
            if ($real === '' || !hash_equals($real, (string) preg_replace('/\s+/', '', $typed))) { // typed in its groups of 4, or not
                throw new CliError('that is not the passphrase of your backups; nothing recorded', 'run `passphrase` to see it again, save it'
                    . ' away from this server and the backup machine, then run `passphrase --confirm-saved` again');
            }
            $dest->save('state.json', ['passphrase_saved_by_user' => true, 'passphrase_sha256' => (string) hash_file('sha256', $f)] + $dest->state());
            return ['records' => [['passphrase_saved_by_user' => true]], 'status' => ['verified' => true]];
        }
        // In groups of 4, so it can be copied by hand without losing a character; the confirm ignores the spaces.
        fwrite(STDOUT, 'gpg passphrase of your backups: ' . implode(' ', str_split(trim((string) SecretFile::read($f, 'run setup')), 4)) . "\n"
            . "Save it in a password manager or on paper, not on this server and not on the backup machine.\n"
            . "Then run this verb again with --confirm-saved.\n");
        return [['shown' => true]];
    }
}
