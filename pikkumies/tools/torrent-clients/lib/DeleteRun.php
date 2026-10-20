<?php
/**
 * DeleteRun: `remove --with-data` after the plan (DeletePlan) is rebuilt and matches what the user
 * saw, then the result read back from the disk: freed_bytes counts only files that are gone now, once
 * per inode (DeleteFreed), a torrent whose planned files are still there is `partial`, and any record
 * with errors ends the call with ok:false (finish), so a half-done delete is never reported as done.
 */
class DeleteRun
{
    const POLLS = 25; // qBittorrent and Deluge delete in the background: up to 5 s (25 x 0.2 s) for the files to go

    /** Deletes, or refuses with nothing changed. */
    public static function execute(TorrentClient $c, array $all, array $targets, $expect)
    {
        $plan = DeletePlan::build($c, $all, $targets);
        if ($plan['shared']) {
            $why = [];
            foreach ($plan['shared'] as $id => $others) {
                $why[] = "$id shares files with " . implode(', ', array_map(function ($o, $n) {
                    return "$o ($n)";
                }, array_keys($others), $others)) . ' in this client';
            }
            throw new CliError('nothing deleted: ' . implode('; ', $why), 'remove those torrents in the same call, or remove without --with-data');
        }
        if ($plan['unlisted']) {
            throw new CliError('nothing deleted: ' . DeletePlan::unlistedText($plan),
                'try again in a minute (a torrent still fetching its metadata or being moved may not list its files);'
                . ' or remove without --with-data');
        }
        if ($expect === '') {
            throw new CliError('nothing deleted: --expect is missing', 'run `show <ids> --files` first, say the files in your message, then add --expect ' . $plan['expect']);
        }
        $why = DeletePlan::mismatch($expect, $plan);
        if ($why !== '') {
            throw new CliError("nothing deleted: $why", 'run `show <ids> --files` again and say the new list in your message; a torrent that is still downloading changes size, pause it first');
        }
        return self::finish(self::outcome($plan, $c->remove(Torrents::natives($targets), true)));
    }

    /** The client's records with freed_bytes from the disk; `deleted` with planned files left becomes `partial`. */
    public static function outcome(array $plan, array $done)
    {
        $byTorrent = [];
        $skipped = 0;
        foreach ($plan['files'] as $f) {
            if (isset($f['skip'])) {
                $skipped++;
            } elseif ($f['on_disk']) {
                $byTorrent[$f['torrent']][] = $f;
            }
        }
        for ($i = 0; $i < self::POLLS && self::waiting($done, $byTorrent); $i++) {
            usleep(200000);
        }
        $why = [];
        $deleted = [];
        foreach ($done as $n => $r) {
            $files = isset($byTorrent[$r['id']]) ? $byTorrent[$r['id']] : [];
            $left = self::left($files);
            $disposal = isset($r['disposal']) ? $r['disposal'] : 'kept';
            if ($left && in_array($disposal, ['deleted', 'partial'], true)) {
                $done[$n]['disposal'] = 'partial';
                if (empty($r['errors'])) {
                    $done[$n]['errors'] = [count($left) . ' planned file(s) still on disk, e.g. ' . $left[0]];
                    if (isset($r['verified'])) {
                        $done[$n]['verified'] = false;
                    }
                }
            }
            if (in_array($disposal, ['deleted', 'partial'], true)) {
                $deleted[] = $r['id'];
            }
            $why[$disposal === 'trashed' ? 'trash' : 'none'] = true;
        }
        // Counted per inode from what is gone now: a hard link deleted with its twin in the batch frees it too.
        $perTorrent = DeleteFreed::byTorrent(isset($plan['inodes']) ? $plan['inodes'] : [], $deleted, function ($path) {
            clearstatcache(true, $path);
            return @lstat($path) === false;
        });
        foreach ($done as $n => $r) {
            $done[$n]['freed_bytes'] = isset($perTorrent[$r['id']]) ? $perTorrent[$r['id']] : 0;
        }
        $freed = array_sum($perTorrent);
        $status = ['freed_bytes' => $freed];
        $notes = [];
        if ($skipped) {
            $status['skipped_files'] = $skipped;
            $notes[] = "$skipped file(s) were left on disk on purpose (`skip` in the preview)";
        }
        if ($freed === 0) {
            $inodes = isset($plan['inodes']) ? $plan['inodes'] : [];
            $notes[] = 'no disk space was freed' . (isset($why['trash'])
                ? ': the client moved the files to its trash; space comes back when the trash is emptied'
                : (DeleteFreed::linkedElsewhere($inodes) ? ': the files have other hard links, which keep the space in use' : ''));
        }
        if ($notes) {
            $status['note'] = implode('; ', $notes);
        }
        return ['records' => array_values($done), 'status' => $status];
    }

    /** $result unchanged when no record has errors; otherwise a CliError (ok:false) that says what happened to each torrent. */
    public static function finish(array $result)
    {
        $bad = [];
        $good = 0;
        foreach ($result['records'] as $r) {
            if (empty($r['errors'])) {
                $good++;
                continue;
            }
            $bad[] = "{$r['id']} ({$r['name']}): " . (isset($r['disposal']) ? $r['disposal'] . ', ' : '') . implode('; ', array_slice((array) $r['errors'], 0, 3));
        }
        if (!$bad) {
            return $result;
        }
        $freed = isset($result['status']['freed_bytes']) ? $result['status']['freed_bytes'] : 0;
        throw new CliError('not done as asked: ' . implode(' | ', array_slice($bad, 0, 5)) . ($good ? "; $good other(s) done" : '') . "; freed $freed bytes (" . DeletePlan::label($freed) . ')',
            'tell the user exactly this; `list` shows what is still in the client and `show <id> --files` what is still on disk');
    }

    /** True while a torrent the client reports as deleted still has planned files on disk. */
    private static function waiting(array $done, array $byTorrent)
    {
        foreach ($done as $r) {
            if (isset($r['disposal']) && $r['disposal'] === 'deleted' && isset($byTorrent[$r['id']]) && self::left($byTorrent[$r['id']])) {
                return true;
            }
        }
        return false;
    }

    /** The paths of $files that are still on disk. */
    private static function left(array $files)
    {
        $out = [];
        foreach ($files as $f) {
            clearstatcache(true, $f['file']);
            if (@lstat($f['file']) !== false) {
                $out[] = $f['file'];
            }
        }
        return $out;
    }
}
