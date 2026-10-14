<?php
/**
 * RclonePlan: what a copy, sync or move between two places would do. On rclone 1.66 or newer the plan is
 * `rclone sync --dry-run --combined -`: sync itself decides, with the rule the real sync and move use
 * (both go through NeedTransfer, fs/sync/sync.go:379, 1.69.1), so the counts the user approves are the
 * counts that run. `--combined` lines (rclone_sync.md "Logger Flags", 1.69.1): "= path" identical, "- path"
 * only in the destination, "+ path" only in the source, "* path" different, "! path" error; directories
 * are not listed (cmd/sync/sync.go:44-49). `--retries 1`, because a retried run repeats lines (same
 * section). sync deletes the "-" files and replaces the "*" files; move removes every source file
 * ("=", "+", "*") and replaces the "*" files. rclone before 1.66 has no `sync --combined` (absent from
 * rclone_sync.md at v1.65.2): there the preview is `rclone check` (size and hash, rclone_check.md),
 * which is not the rule sync uses, so it gives no `expect` value and sync and move refuse.
 * The plan is read at nice 19, idle I/O and with a time limit; a plan cut short is never used.
 */
class RclonePlan
{
    const SAMPLES = 200;     // paths kept per kind for the records; the counts cover everything
    const SECONDS = 3600;    // time limit of one plan
    const SAME_RULE = '1.66';

    public static function exact(RcloneCmd $c)
    {
        return $c->atLeast(self::SAME_RULE);
    }

    /** The plan for $src -> $dst; with $oneWay files only in the destination are left out. */
    public static function build(RcloneCmd $c, $src, $dst, $oneWay = false)
    {
        $exact = self::exact($c);
        $kinds = ['+' => 'copy', '*' => 'replace', '-' => 'extra_on_dst', '=' => 'same', '!' => 'error'];
        $p = ['copy' => 0, 'replace' => 0, 'extra_on_dst' => 0, 'same' => 0, 'error' => 0, 'samples' => [],
            'copy_bytes' => RcloneCmd::isLocal($src) ? 0 : null, 'exact' => $exact];
        $read = function ($line) use (&$p, $kinds, $src, $oneWay) {
            if (strlen($line) < 3 || $line[1] !== ' ' || !isset($kinds[$line[0]]) || ($oneWay && $line[0] === '-')) {
                return true;
            }
            $kind = $kinds[$line[0]];
            $path = substr($line, 2);
            $p[$kind]++;
            if ($kind !== 'same' && count($p['samples']) < self::SAMPLES * 4) {
                $p['samples'][] = ['change' => $kind, 'path' => $path];
            }
            if ($p['copy_bytes'] !== null && ($kind === 'copy' || $kind === 'replace')) {
                $size = @filesize(is_dir($src) ? rtrim($src, '/') . '/' . $path : $src);
                $p['copy_bytes'] += $size === false ? 0 : $size;
            }
            return true;
        };
        $r = $exact ? $c->run(['sync'], ['--dry-run', '--retries', '1', '--combined', '-'], [$src, $dst], $read, self::SECONDS)
            : $c->run(['check'], array_merge(['--combined', '-'], $oneWay ? ['--one-way'] : []), [$src, $dst], $read, self::SECONDS);
        $seen = $p['copy'] + $p['replace'] + $p['extra_on_dst'] + $p['same'] + $p['error'];
        // A dry-run sync exits 0 when it would succeed; check exits 1 when it finds differences (rclone_check.md).
        $p['complete'] = $r['code'] === 0 || (!$exact && $r['code'] === 1 && $seen > 0 && $p['error'] === 0);
        if ($r['code'] === 124) {
            throw new CliError('the rclone plan took longer than ' . self::SECONDS . ' s and was stopped; nothing changed',
                'compare smaller folders');
        }
        if (!$p['complete'] && $p['error'] === 0) {
            throw new CliError("rclone could not compare the two places (exit {$r['code']}): " . ToolProc::tail($r['err']),
                'check both places exist: remotes come from `list`, local paths must exist');
        }
        $ok = $exact && $p['complete'];
        $p['expect_sync'] = $ok && !$oneWay ? $p['extra_on_dst'] + $p['replace'] : null;
        $p['expect_move'] = $ok ? $p['same'] + $p['copy'] + $p['replace'] : null;
        return $p;
    }

    /** Records (deletions first) and the status fields of a plan. */
    public static function show(array $p, $oneWay, $version)
    {
        $order = ['extra_on_dst' => 0, 'replace' => 1, 'error' => 2, 'copy' => 3];
        $records = $p['samples'];
        usort($records, function ($a, $b) use ($order) {
            return $order[$a['change']] - $order[$b['change']];
        });
        $status = ['to_copy' => $p['copy'], 'to_replace' => $p['replace'], 'extra_on_dst' => $oneWay ? null : $p['extra_on_dst'],
            'identical' => $p['same'], 'errors' => $p['error'], 'copy_bytes' => $p['copy_bytes'],
            'compared_by' => $p['exact'] ? 'size and modification time, as sync and move decide' : 'size and hash (rclone check)',
            'expect_sync' => $p['expect_sync'], 'expect_move' => $p['expect_move']];
        if (!$p['exact']) {
            $status['note'] = "rclone $version is older than " . self::SAME_RULE . ': it cannot preview sync or move with their own rule,'
                . ' so they refuse; copy works';
        } elseif (!$p['complete']) {
            $status['note'] = 'rclone could not read some files, so the plan is incomplete and has no --expect value';
        } elseif ($oneWay) {
            $status['note'] = 'one-way: files only on the destination were not looked for, so there is no sync value';
        }
        return ['records' => $records, 'status' => $status];
    }

    /** Rebuilds the plan and refuses unless the count the user saw still holds. */
    public static function confirm(RcloneCmd $c, $src, $dst, $expect, $verb)
    {
        if (!self::exact($c)) {
            throw new CliError("nothing changed: rclone {$c->version()} cannot preview $verb with the rule $verb uses (rclone "
                . self::SAME_RULE . ' or newer can)', 'copy works with this rclone; for sync and move rclone needs an update');
        }
        $p = self::build($c, $src, $dst);
        if ($p['error'] > 0 || !$p['complete']) {
            throw new CliError("nothing changed: rclone could not read {$p['error']} files, so the plan is not reliable",
                'run `check` to see them');
        }
        $n = $verb === 'sync' ? $p['expect_sync'] : $p['expect_move'];
        $what = $verb === 'sync' ? 'delete or replace' : 'remove from the source';
        if (!is_string($expect) || $expect === '') {
            throw new CliError("nothing changed: --expect is missing ($verb would $what $n files)",
                "run `check <src> <dst>` first, show the user the list, then add --expect $n");
        }
        if (!preg_match('/^\d+$/', $expect) || (int) $expect !== $n) {
            throw new CliError("nothing changed: $verb would now $what $n files, not $expect",
                'run `check` again and show the user the new list');
        }
        return $p;
    }
}
