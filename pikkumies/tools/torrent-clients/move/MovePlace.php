<?php
/**
 * MovePlace: rsync writes a torrent's files into the move's own staging folder, `<target>/.pikkumies-move-<move>-<n>/`,
 * and this puts each one in place without overwriting anything that is not this move's own:
 * - a new place: `link()` (fails when the name exists, so a file that appeared meanwhile is never replaced), then the
 *   staged name is removed;
 * - an existing place is refused, unless this move put it there before (its device and inode are in the move's
 *   `placed` record): then, after a recheck found bad data, the staged copy replaces it with rename();
 * - every folder on the way must be a real folder under the target (no symlink), so nothing lands outside it.
 * Files already placed are left out of the next copy's list; the staging folder is removed once it is empty.
 */
class MovePlace
{
    public static function staging($dir, $move, $n)
    {
        return rtrim($dir, '/') . "/.pikkumies-move-$move-$n";
    }

    /** The files still to copy: not yet placed, or every file on a copy after bad data ($all). */
    public static function todo(array $files, array $placed, $all)
    {
        return array_values(array_filter($files, function ($f) use ($placed, $all) {
            return $all || !isset($placed[$f[0]]);
        }));
    }

    /** Bytes still to copy: each file's size minus what the staging folder already holds of it. */
    public static function remaining($stage, array $files)
    {
        $n = 0;
        foreach ($files as $f) {
            $p = "$stage/{$f[0]}";
            $n += $f[1] - (is_file($p) && !is_link($p) ? min((int) filesize($p), $f[1]) : 0);
        }
        return $n;
    }

    /** Refuses when a target file of the torrent is already here (and is not this move's own). */
    public static function free($dir, array $files, array $placed = [])
    {
        foreach ($files as $f) {
            $t = "$dir/{$f[0]}";
            if ((file_exists($t) || is_link($t)) && !(isset($placed[$f[0]]) && self::same($t, $placed[$f[0]]))) {
                throw new CliError("nothing copied: {$f[0]} already exists in $dir; nothing was overwritten",
                    'move or rename that file first, or plan the move into another folder with --dir');
            }
        }
    }

    /** Puts the staged $files in place; $placed gains [device, inode] of each as it goes, so a stop keeps what was placed. */
    public static function place($dir, $stage, array $files, array &$placed)
    {
        $realDir = realpath($dir);
        if ($realDir === false || is_link($stage)) {
            throw new CliError("cannot place files in $dir", 'run the move again');
        }
        foreach ($files as $f) {
            $rel = $f[0];
            $s = "$stage/$rel";
            $t = "$dir/$rel";
            $parent = dirname($t);
            if (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new CliError("cannot create the folder of $rel", 'check the free space and the permissions here');
            }
            $sub = dirname($rel) === '.' ? '' : '/' . dirname($rel);
            if (realpath($parent) !== $realDir . $sub) {
                throw new CliError("not placed: a folder on the way to $rel is a symlink; nothing was overwritten", 'remove the symlink, then run the move again');
            }
            if (!is_file($s) || is_link($s) || realpath(dirname($s)) !== realpath($stage) . $sub) {
                if (isset($placed[$rel]) && self::same($t, $placed[$rel])) {
                    continue; // put in place by an earlier run
                }
                throw new CliError("not placed: $rel is missing from the copy", 'run the move again: it copies the missing files');
            }
            if (file_exists($t) || is_link($t)) {
                if (!isset($placed[$rel]) || !self::same($t, $placed[$rel]) || !@rename($s, $t)) {
                    throw new CliError("not placed: $rel already exists in $dir and is not this move's copy; nothing was overwritten",
                        'move or rename that file first, then run the move again');
                }
            } elseif (!@link($s, $t) || !@unlink($s)) {
                throw new CliError("not placed: $rel could not be linked into $dir (it may have appeared meanwhile); nothing was overwritten", 'run the move again');
            }
            clearstatcache(true, $t);
            $st = stat($t);
            $placed[$rel] = [$st['dev'], $st['ino']];
        }
        self::sweep($stage);
        return $placed;
    }

    private static function same($path, array $id)
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        return $st !== false && !is_link($path) && [$st['dev'], $st['ino']] === array_map('intval', $id);
    }

    /** Removes the staging folder's empty folders, deepest first; anything left (a cut file) stays for the next run. */
    private static function sweep($stage)
    {
        if (!is_dir($stage) || is_link($stage)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $p) {
            if ($p->isDir() && !$p->isLink()) {
                @rmdir($p->getPathname());
            }
        }
        @rmdir($stage);
    }
}
