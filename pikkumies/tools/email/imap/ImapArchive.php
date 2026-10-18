<?php
/**
 * ImapArchive: copies folders into Maildirs under --to, incrementally, never deleting on the server (ADR-0053). Each
 * folder goes to <to>/<folder>-<uidvalidity>/, so a changed UIDVALIDITY starts a new directory from uid 0 and never
 * overwrites. The last uid done is kept in local/state/email-archive/ and saved as it goes, so a stopped run resumes.
 * It stops before the account would have less than 10% of its disk or file quota free (HermesInstall::UNATTENDED_RESERVE),
 * and does at most BATCH messages per call. Each run writes one summary line to local/logs/email/.
 */
class ImapArchive
{
    const MAX = 52428800; // 50 MiB: larger messages are skipped and listed
    const BATCH = 2000;

    public static function run(MailImap $imap, array $login, array $folders, $to, $home, $stateDir)
    {
        $root = self::root($to, $home);
        $q = Quota::fields($home);
        $files = Quota::files($home);
        $fsTotal = @disk_total_space($home);
        $done = ['bytes' => 0, 'count' => 0, 'stopped' => null, 'more' => false];
        $start = microtime(true);
        $records = [];
        foreach ($folders as $folder) {
            if ($done['stopped'] !== null || $done['more']) {
                break;
            }
            $records[] = self::folder($imap, $login, $folder, $root, $stateDir, $q, $files, $fsTotal, $done);
        }
        MailUnit::log($stateDir, ['verb' => 'archive', 'folders' => count($records), 'messages' => $done['count'], 'bytes' => $done['bytes'],
            'seconds' => round(microtime(true) - $start, 1), 'stopped' => $done['stopped'] !== null]);
        return ['records' => $records, 'status' => array_filter(['dir' => $root, 'stopped' => $done['stopped'], 'more' => $done['more'] ?: null],
            function ($v) {
                return $v !== null;
            })];
    }

    private static function folder(MailImap $imap, array $login, $folder, $root, $stateDir, array $q, $files, $fsTotal, array &$done)
    {
        $uv = $imap->status($folder)['uidvalidity'];
        if ($uv === null) {
            throw new CliError("the server gave no UIDVALIDITY for $folder; nothing archived", 'archive this folder with a mail client');
        }
        $stateFile = rtrim($stateDir, '/') . '/email-archive/' . sha1($login['host'] . "\0" . $login['user'] . "\0" . $folder . "\0" . $root) . '.json';
        $state = MailUnit::readState($stateFile);
        $last = isset($state['uidvalidity'], $state['last_uid']) && (int) $state['uidvalidity'] === $uv ? (int) $state['last_uid'] : 0;
        $dir = Maildir::ensure($root . '/' . MailPath::safeName($folder, 0) . '-' . $uv);
        $todo = array_values(array_filter($imap->search($folder, 'ALL'), function ($u) use ($last) {
            return $u > $last;
        }));
        $rec = ['folder' => $folder, 'dir' => $dir, 'uidvalidity' => $uv, 'archived' => 0, 'bytes' => 0, 'skipped_too_large' => [], 'left' => count($todo)];
        foreach (array_chunk($todo, 200) as $chunk) {
            $meta = $imap->meta($folder, $chunk);
            $unseen = [];
            foreach ($chunk as $uid) {
                if ($done['count'] >= self::BATCH) {
                    $done['more'] = true;
                    break;
                }
                $size = isset($meta[$uid]) ? (int) $meta[$uid]['size'] : 0;
                if (isset($meta[$uid]) && $size > self::MAX) {
                    $rec['skipped_too_large'][] = $uid;
                } elseif (isset($meta[$uid])) {
                    $room = Maildir::roomProblem($q, $files, $done['bytes'], $done['count'], $size, HermesInstall::UNATTENDED_RESERVE, $fsTotal);
                    if ($room !== '') {
                        $done['stopped'] = "$room; the archive keeps 10% free and stopped. Free space, then run it again to resume.";
                        break;
                    }
                    $bytes = Maildir::deliver($dir, "U$uid.V$uv.pikkumies", function ($sink) use ($imap, $folder, $uid, $uv) {
                        return $imap->fetch($folder, $uid, null, ['max' => self::MAX, 'sink' => $sink, 'uidvalidity' => $uv]);
                    });
                    $done['bytes'] += $bytes;
                    $done['count'] += $bytes > 0 ? 1 : 0;
                    $rec['archived'] += $bytes > 0 ? 1 : 0;
                    $rec['bytes'] += $bytes;
                    if (!in_array('\\Seen', $meta[$uid]['flags'], true)) {
                        $unseen[] = $uid;
                    }
                }
                $last = $uid;
                $rec['left']--;
            }
            if ($unseen) {
                $imap->store($folder, $unseen, '-', '\\Seen');
            }
            MailUnit::writeState($stateFile, ['uidvalidity' => $uv, 'last_uid' => $last, 'dir' => $dir]);
            if ($done['stopped'] !== null || $done['more']) {
                break;
            }
        }
        $rec['last_uid'] = $last;
        return $rec;
    }

    /** --to: a full path inside the home, outside dot-directories, ~/www, ~/bin and Pikkumies; created when missing. */
    private static function root($to, $home)
    {
        if (!is_string($to) || $to === '' || $to[0] !== '/') {
            throw new CliError('archive needs --to <full path of a folder for the Maildirs>', 'e.g. --to ' . $home . '/mail-archive');
        }
        $to = rtrim($to, '/');
        $parent = realpath(dirname($to));
        if ($parent === false || is_link($to) || preg_match('#(^|/)\.\.?(/|$)#', $to)) {
            throw new CliError("$to is not a usable folder (missing parent, a symlink, or . or .. in it)", 'e.g. --to ' . $home . '/mail-archive');
        }
        $real = MailPath::fence($parent . '/' . basename($to), $home, 'the archive folder');
        if (!is_dir($real) && !@mkdir($real, 0700)) {
            throw new CliError("cannot create $real", 'check the parent directory and the quota (storage stats)');
        }
        return $real;
    }
}
