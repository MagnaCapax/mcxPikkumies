<?php
/**
 * ArrDelete: `show --files` and `remove [--with-data --expect]` for the library apps (tools/arr/README.md
 * "Deleting data"). With deleteFiles the app deletes the item's WHOLE folder, extras included, through
 * its recycle bin when one is set (Sonarr and Radarr MediaFileDeletionService.cs:91-127,
 * RecycleBinProvider.cs:40-67), after the API call returns (HandleAsync). It silently deletes nothing
 * when the folder equals or contains another item's folder (same file, lines 106-116). So the tool
 * refuses those up front, recomputes the plan at delete time, and checks the folders afterwards (ArrRemove).
 */
class ArrDelete
{
    const MAX_FILES = 100000;

    /** Files under each target's folder (symlinks are listed, never followed) and the totals. */
    public static function plan(array $app, array $all, array $targets)
    {
        $files = [];
        $refused = [];
        $per = [];
        foreach ($targets as $t) {
            $path = rtrim((string) $t['path'], '/');
            $per[$t['id']] = ['path' => $path, 'freed' => 0, 'on_disk' => $path !== '' && @is_dir($path) && !is_link($path)];
            foreach ($all as $o) {
                $op = rtrim((string) $o['path'], '/');
                if ((int) $o['id'] !== (int) $t['id'] && $op !== '' && ($op === $path || strpos($op . '/', $path . '/') === 0)) {
                    $refused[$t['id']] = "its folder $path " . ($op === $path ? 'is also' : 'contains')
                        . " the folder of {$o['id']} ({$o['title']}); the app would delete nothing";
                }
            }
            if (!$per[$t['id']]['on_disk']) {
                continue;
            }
            $dir = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::SELF_FIRST) as $f) {
                $st = @lstat((string) $f);
                if ($st === false || $f->isDir() && !$f->isLink()) {
                    continue;
                }
                if (count($files) >= self::MAX_FILES) {
                    throw new CliError('more than ' . self::MAX_FILES . " files under $path; not planning a delete that large",
                        'delete it in the app\'s own UI, or in smaller folders');
                }
                $link = $f->isLink();
                $files[] = ['item' => (int) $t['id'], 'file' => (string) $f, 'bytes' => $link ? 0 : (int) $st['size'],
                    'links' => (int) $st['nlink'], 'symlink' => $link];
                // another hard link keeps the space in use
                $per[$t['id']]['freed'] += (!$link && (int) $st['nlink'] === 1) ? (int) $st['size'] : 0;
            }
        }
        $bytes = array_sum(array_column($files, 'bytes'));
        return ['files' => $files, 'bytes' => $bytes, 'freed' => array_sum(array_column($per, 'freed')), 'per' => $per,
            'refused' => $refused, 'expect' => self::label($bytes) . ':' . $bytes];
    }

    public static function summary(array $plan)
    {
        return ['files' => count($plan['files']), 'bytes' => $plan['bytes'], 'freed_bytes' => $plan['freed'], 'expect' => $plan['expect'],
            'refused' => $plan['refused']];
    }

    /** Deletes after the plan is rebuilt and matches what the user saw (--expect); otherwise refuses with nothing changed. */
    public static function execute(ArrClient $c, array $app, array $all, array $targets, $expect)
    {
        $plan = self::plan($app, $all, $targets);
        $missing = array_keys(array_filter($plan['per'], function ($p) {
            return !$p['on_disk'];
        }));
        if ($plan['refused'] || $missing) {
            $why = array_values($plan['refused']);
            foreach ($missing as $id) {
                $why[] = "$id: its folder {$plan['per'][$id]['path']} is not there";
            }
            throw new CliError('nothing deleted: ' . implode('; ', $why),
                'remove those items without --with-data, or fix the folders first');
        }
        if ($expect === '') {
            throw new CliError('nothing deleted: --expect is missing',
                'run `show <ids> --files --limit 0` first, show the user every file, then add --expect ' . $plan['expect']);
        }
        if (!preg_match('/^(?:[0-9.,]+\s*[a-z]*:)?([0-9]+)$/i', trim($expect), $m)) {
            throw new CliError("nothing deleted: --expect $expect is not the value `show --files` printed ({$plan['expect']})",
                'give it as printed: size and exact bytes');
        }
        if ((string) (int) $m[1] !== (string) $plan['bytes']) {
            throw new CliError("nothing deleted: the folders now total {$plan['expect']}, not $expect",
                'run `show <ids> --files --limit 0` again and show the user the new list');
        }
        $cfg = $c->call('GET', 'config/mediamanagement');
        $bin = isset($cfg['recycleBin']) && is_string($cfg['recycleBin']) ? rtrim($cfg['recycleBin'], '/') : '';
        $sent = ArrRemove::send($c, $app, $targets, ['deleteFiles' => true] + $app['deleteBools']);
        $wait = (int) getenv('PIKKUMIES_ARR_WAIT');
        $deadline = microtime(true) + ($wait > 0 && $wait < ArrCommand::WAIT ? $wait : ArrCommand::WAIT);
        do { // the app deletes after it answers: wait for the folders of the accepted items
            clearstatcache();
            $left = array_filter($plan['per'], function ($p, $id) use ($sent) {
                return isset($sent[$id]) && $sent[$id] === '' && file_exists($p['path']);
            }, ARRAY_FILTER_USE_BOTH);
        } while ($left && microtime(true) < $deadline && usleep(250000) === null);
        return ArrRemove::finish(ArrRemove::report($c, $app, $targets, $plan, $bin, 'deleted', $sent));
    }

    /**
     * The size as the user sees it, without spaces: 41.2GiB. `expect` is <label>:<exact bytes> and only the exact bytes
     * count, the same rule as torrent-clients/lib/DeletePlan.php mismatch(), so a change hidden by rounding refuses.
     */
    public static function label($bytes)
    {
        return str_replace(' ', '', CliHuman::bytes($bytes));
    }
}
