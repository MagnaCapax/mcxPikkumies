<?php
/**
 * ArrRemove: sends `remove` (with or without --with-data) one item at a time and reports every item.
 * The calls stop at the first failure, so nothing more is removed from an app that is failing: the
 * items before it are checked on disk and in the library as usual, the failed one is `failed`, the rest
 * `not_sent`. A failure ends the call with ok:false naming what happened to every item, as
 * torrent-clients/lib/DeleteRun.php finish() does, so a half-done removal is never reported as done.
 */
class ArrRemove
{
    /** DELETE each target in order; [id => '' when the app accepted it, or the error], up to the first error. */
    public static function send(ArrClient $c, array $app, array $targets, array $query)
    {
        $sent = [];
        foreach ($targets as $t) {
            $id = (int) $t['id'];
            try {
                $gone = "$id was no longer in the library when the removal was sent";
                $c->call('DELETE', "{$app['item']}/$id", $query, null, ['missing' => $gone]);
                $sent[$id] = '';
            } catch (CliError $e) {
                $sent[$id] = $e->getMessage();
                break;
            }
        }
        return $sent;
    }

    /**
     * One record per target. $how 'deleted' (--with-data): `disposal` is where its folder went (deleted,
     * recycled, refused); 'kept': kept. A call that failed is `failed`, one never sent `not_sent`.
     */
    public static function report(ArrClient $c, array $app, array $targets, array $plan, $bin, $how, array $sent)
    {
        try {
            $still = array_map('intval', array_column(Arr::all($c, $app), 'id'));
            $unread = '';
        } catch (CliError $e) {
            $still = [];
            $unread = 'could not read the library afterwards: ' . $e->getMessage();
        }
        $out = [];
        $freed = 0;
        foreach ($targets as $t) {
            $id = (int) $t['id'];
            $p = isset($plan['per'][$id]) ? $plan['per'][$id] : ['path' => (string) $t['path'], 'freed' => 0];
            $r = ['id' => $id, 'title' => (string) $t['title'], 'path' => $p['path'], 'disposal' => 'kept',
                'freed_bytes' => 0, 'errors' => []];
            $in = in_array($id, $still, true);
            clearstatcache();
            if (!isset($sent[$id])) {
                $r['disposal'] = 'not_sent';
                $r['errors'][] = 'not sent: an earlier removal in this call failed';
            } elseif ($sent[$id] !== '') {
                $r['disposal'] = 'failed';
                $r['errors'][] = $sent[$id];
                if ($unread === '' && !$in) {
                    $r['errors'][] = 'it is no longer in the library: the app may have removed it anyway'
                        . ($how === 'deleted' && !file_exists($p['path']) ? ', and its folder is gone' : '');
                }
            } elseif ($how === 'deleted') {
                if (file_exists($p['path'])) {
                    $r['disposal'] = 'refused';
                    $r['errors'][] = 'the folder is still there: the app refused, or is still deleting (see its log, System, Logs)';
                } elseif ($bin !== '' && is_dir($bin . '/' . basename($p['path']))) {
                    $r['disposal'] = 'recycled';
                    $r['recycle_bin'] = $bin . '/' . basename($p['path']);
                } else {
                    $r['disposal'] = 'deleted';
                    $r['freed_bytes'] = $p['freed'];
                }
            }
            if (isset($sent[$id]) && $sent[$id] === '') {
                if ($unread !== '') {
                    $r['errors'][] = $unread;
                } elseif ($in) {
                    $r['errors'][] = 'still in the library after the removal';
                }
            }
            $r['verified'] = !$r['errors'];
            $freed += $r['freed_bytes'];
            $out[] = $r;
        }
        $status = ['freed_bytes' => $freed];
        if ($freed === 0) {
            $status['note'] = $how === 'deleted'
                ? 'no disk space was freed (moved to the recycle bin, refused, or kept alive by other hard links)'
                : 'files kept, disk not freed';
        }
        return ['records' => $out, 'status' => $status];
    }

    /** $result unchanged when every call was accepted; otherwise ok:false that says what happened to each item. */
    public static function finish(array $result)
    {
        $failed = array_filter($result['records'], function ($r) {
            return in_array($r['disposal'], ['failed', 'not_sent'], true);
        });
        if (!$failed) {
            return $result;
        }
        $each = array_map(function ($r) {
            return "{$r['id']} ({$r['title']}): {$r['disposal']}" . (isset($r['recycle_bin']) ? " to {$r['recycle_bin']}" : '')
                . ($r['errors'] ? ', ' . implode('; ', array_slice($r['errors'], 0, 2)) : '');
        }, $result['records']);
        $freed = $result['status']['freed_bytes'];
        throw new CliError('not done as asked: ' . implode(' | ', $each) . "; freed $freed bytes (" . ArrDelete::label($freed) . ')',
            'tell the user exactly this, item by item; `list` shows what is still in the library'
                . ' and `show <id> --files` what is still on disk');
    }
}
