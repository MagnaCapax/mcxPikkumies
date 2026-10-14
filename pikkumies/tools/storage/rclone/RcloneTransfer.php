<?php
/**
 * RcloneTransfer: the verbs that change files: copy, sync, move (README.md here, "Changing files").
 * copy never deletes and, without --replace, never overwrites (rclone --ignore-existing, rclone 1.69.1
 * docs.md). sync and move rebuild the plan and need the --expect count the user saw. sync runs with
 * --max-delete (docs.md "--max-delete=N": a fatal error stops rclone) and --combined -, and is stopped
 * as soon as it reports one delete or replace more than planned: no rclone flag caps replacements, and
 * the "*" line is written when sync decides, before the upload (equal(), fs/operations/operations.go:239-277).
 * Uploads go to a .partial name renamed at the end, so a stopped upload leaves the old file (docs.md
 * "--inplace", backends with partial uploads). move has no --combined in 1.69.1 (cmd/sync/sync.go:31,
 * "TODO: add same flags to move and copy"), so only the fresh plan bounds it. Transfers run at nice 19
 * and idle I/O, without a time limit. Each verb reads the result back (`verified`).
 */
class RcloneTransfer
{
    public static function copy(RcloneCmd $c, array $args, array $opt)
    {
        list($src, $dst) = self::places($c, $args, 'copy');
        $replace = !empty($opt['replace']);
        self::transfer($c, 'copy', $replace ? [] : ['--ignore-existing'], $src, $dst);
        $after = RclonePlan::build($c, $src, $dst, true);
        $r = ['src' => $src, 'dst' => $dst, 'replace' => $replace, 'not_copied' => $after['copy'], 'differs_on_dst' => $after['replace']];
        $status = ['verified' => $after['copy'] === 0 && $after['error'] === 0 && ($replace ? $after['replace'] === 0 : true)];
        if (!$replace && $after['replace'] > 0) {
            $status['note'] = "{$after['replace']} files already on the destination differ and were left as they are; --replace"
                . ' overwrites them (the user is asked first)';
        }
        return ['records' => [$r], 'status' => $status];
    }

    public static function sync(RcloneCmd $c, array $args, array $opt)
    {
        list($src, $dst) = self::places($c, $args, 'sync');
        $p = RclonePlan::confirm($c, $src, $dst, isset($opt['expect']) ? $opt['expect'] : '', 'sync');
        $done = ['-' => 0, '*' => 0];
        $limit = ['-' => $p['extra_on_dst'], '*' => $p['replace']];
        $flags = ['--max-delete', (string) $p['extra_on_dst'], '--retries', '1', '--combined', '-'];
        $r = self::transfer($c, 'sync', $flags, $src, $dst, function ($line) use (&$done, $limit) {
            $k = substr($line, 0, 2);
            if ($k === '- ' || $k === '* ') {
                return ++$done[$k[0]] <= $limit[$k[0]];
            }
            return true;
        });
        if ($r['stopped']) {
            throw new CliError("rclone sync was stopped: it was about to delete or replace more files than the {$p['expect_sync']}"
                . ' the user approved; the files before that point were already synced',
                'run `check` again and show the user the new list');
        }
        $a = RclonePlan::build($c, $src, $dst);
        $rec = ['src' => $src, 'dst' => $dst, 'deleted' => $done['-'], 'replaced' => $done['*'], 'copied' => $p['copy']];
        return ['records' => [$rec], 'status' => ['verified' => $a['copy'] + $a['replace'] + $a['extra_on_dst'] + $a['error'] === 0]];
    }

    public static function move(RcloneCmd $c, array $args, array $opt)
    {
        list($src, $dst) = self::places($c, $args, 'move');
        $p = RclonePlan::confirm($c, $src, $dst, isset($opt['expect']) ? $opt['expect'] : '', 'move');
        self::transfer($c, 'move', [], $src, $dst);
        $r = $c->run(['size'], ['--json'], [$src]);
        $left = json_decode($r['out'], true);
        // Exit 3 / 4: the source directory or file is gone (rclone docs.md "List of exit codes").
        $count = in_array($r['code'], [3, 4], true) ? 0 : ($r['code'] === 0 && isset($left['count']) ? (int) $left['count'] : null);
        $rec = ['src' => $src, 'dst' => $dst, 'moved' => $p['expect_move'], 'replaced_on_dst' => $p['replace'], 'left_in_src' => $count];
        return ['records' => [$rec], 'status' => ['verified' => $count === 0]];
    }

    /** `unmount <path>`: fusermount -u on a FUSE mount this account owns; never kills a process. */
    public static function unmount(RcloneCmd $c, array $args, $mountinfo)
    {
        $path = isset($args[0]) ? rtrim((string) $args[0], '/') : '';
        if ($path === '' || $path[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new CliError('unmount needs the full path of a mount', '`stats` lists this account\'s mounts');
        }
        $real = realpath($path) ?: $path; // a mount whose program died cannot be resolved (ENOTCONN)
        $match = function ($m) use ($real, $path) {
            return $m['mountpoint'] === $real || $m['mountpoint'] === $path;
        };
        $mine = array_filter(HomePath::fuseMounts($mountinfo, $c->uid), $match);
        if (!$mine || !HomePath::inside($real, $c->home)) {
            throw new CliError("$path is not a FUSE mount of this account inside the home; nothing was done",
                '`stats` lists this account\'s mounts');
        }
        $fm = ToolProc::which('fusermount3')
            ?: ToolProc::need('fusermount', 'fuse3 provides fusermount3; on Pulsed Media a missing one is a support ticket');
        $r = ToolProc::run([$fm, '-u', reset($mine)['mountpoint']]);
        if ($r['code'] !== 0) {
            throw new CliError('fusermount failed: ' . ToolText::tail($r['err']),
                'a program still using files there must stop first (never kill rclone: unwritten cache files would be lost)');
        }
        $still = array_filter(HomePath::fuseMounts($mountinfo, $c->uid), $match);
        return ['records' => [['mountpoint' => $real, 'unmounted' => !$still]], 'status' => ['verified' => !$still]];
    }

    private static function places(RcloneCmd $c, array $args, $verb)
    {
        if (count($args) !== 2) {
            throw new CliError("$verb needs exactly a source and a destination", "$verb <src> <dst>: remote:path or a full local path");
        }
        $c->ready();
        return [RclonePlace::of($args[0], true, $c->home), RclonePlace::of($args[1], false, $c->home)];
    }

    private static function transfer(RcloneCmd $c, $verb, array $flags, $src, $dst, $onLine = null)
    {
        $r = $c->run([$verb], $flags, [$src, $dst], $onLine, 0);
        if ($r['code'] !== 0 && !$r['stopped']) {
            throw new CliError("rclone $verb stopped (exit {$r['code']}): " . ToolText::tail($r['err']),
                'part of it may have run: run `check` to see where things stand');
        }
        return $r;
    }
}
