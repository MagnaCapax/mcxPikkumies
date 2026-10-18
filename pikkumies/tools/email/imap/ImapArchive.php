<?php
/**
 * ImapArchive: copies folders into Maildirs under --to, incrementally, never deleting on the server (ADR-0053). Each
 * folder goes to <to>/<name>-<uidvalidity>/, so a changed UIDVALIDITY starts a new directory from uid 0 and never
 * overwrites. <name> comes from the whole folder path (dirName), so Work/2023 and Old/2023 never share a directory.
 * The last uid done is kept in local/state/email-archive/ and saved as it goes, so a stopped run resumes.
 * It stops before the account would have less than 10% of its disk or file quota free (HermesInstall::UNATTENDED_RESERVE),
 * and does at most BATCH messages per call. Each run writes one summary line to local/logs/email/.
 */
class ImapArchive
{
    const MAX = 52428800; // 50 MiB: larger messages are skipped and listed
    const BATCH = 2000;

    public static function run(MailImap $imap, array $login, array $folders, $to, $home, $stateDir)
    {
        $root = MailPath::mailRoot($to, $home, 'archive');
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

    /**
     * The directory name of a folder, from its whole path: separators become dots, then MailPath::safeName; when that
     * changed the name, 8 hex of the folder's sha1 are added, so two folders never map to one directory.
     */
    public static function dirName($folder)
    {
        $safe = MailPath::safeName(str_replace(['/', '\\'], '.', $folder), 0);
        return $safe === $folder ? $safe : $safe . '-' . substr(sha1($folder), 0, 8);
    }

    private static function folder(MailImap $imap, array $login, $folder, $root, $stateDir, array $q, $files, $fsTotal, array &$done)
    {
        $uv = $imap->status($folder)['uidvalidity'];
        if ($uv === null) {
            throw new CliError("the server gave no UIDVALIDITY for $folder; nothing archived", 'archive this folder with a mail client');
        }
        $stateFile = rtrim($stateDir, '/') . '/email-archive/' . sha1($login['host'] . "\0" . $login['user'] . "\0" . $folder . "\0" . $root) . '.json';
        $state = MailUnit::readState($stateFile);
        $dir = $root . '/' . self::dirName($folder) . '-' . $uv;
        // Resume only into the same directory. An archive made before folder names used the whole path (only its last
        // part) may share its directory with another folder, where messages were skipped as already there: such a
        // folder starts again from uid 0 in its new directory; the old one is left as it is (`previous_dir`).
        $sameUv = isset($state['uidvalidity'], $state['dir']) && (int) $state['uidvalidity'] === $uv;
        $same = $sameUv && isset($state['last_uid']) && $state['dir'] === $dir;
        $last = $same ? (int) $state['last_uid'] : 0;
        $previous = $sameUv && !$same && is_string($state['dir']) && is_dir($state['dir']) ? $state['dir'] : null;
        Maildir::ensure($dir);
        $todo = array_values(array_filter($imap->search($folder, 'ALL'), function ($u) use ($last) {
            return $u > $last;
        }));
        $rec = ['folder' => $folder, 'dir' => $dir, 'uidvalidity' => $uv, 'archived' => 0, 'bytes' => 0, 'skipped_too_large' => [], 'left' => count($todo)]
            + ($previous !== null ? ['previous_dir' => $previous] : []);
        foreach (array_chunk($todo, 200) as $chunk) {
            $meta = $imap->meta($folder, $chunk);
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
                    // Set back to unseen right after its own read (a read that fails half way may still have set \Seen),
                    // not after the chunk: the flag was read when the chunk started, and the user may open the message
                    // elsewhere meanwhile. Nothing to set back when it was already in the Maildir and not read.
                    $unseen = in_array('\\Seen', $meta[$uid]['flags'], true) ? [] : [$uid];
                    $read = false;
                    try {
                        $bytes = Maildir::deliver($dir, "U$uid.V$uv.pikkumies", function ($sink) use ($imap, $folder, $uid, $uv, &$read) {
                            $read = true;
                            return $imap->fetch($folder, $uid, null, ['max' => self::MAX, 'sink' => $sink, 'uidvalidity' => $uv]);
                        });
                    } catch (Throwable $e) {
                        throw $imap->restoreAfterFailure($folder, $read ? $unseen : [], $e);
                    }
                    $imap->restoreUnseen($folder, $read ? $unseen : []);
                    $done['bytes'] += $bytes;
                    $done['count'] += $bytes > 0 ? 1 : 0;
                    $rec['archived'] += $bytes > 0 ? 1 : 0;
                    $rec['bytes'] += $bytes;
                }
                $last = $uid;
                $rec['left']--;
            }
            MailUnit::writeState($stateFile, ['uidvalidity' => $uv, 'last_uid' => $last, 'dir' => $dir]);
            if ($done['stopped'] !== null || $done['more']) {
                break;
            }
        }
        $rec['last_uid'] = $last;
        return $rec;
    }
}
