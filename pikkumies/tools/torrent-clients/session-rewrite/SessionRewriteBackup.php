<?php
/**
 * SessionRewriteBackup: tars the whole session directory before `rewrite --apply` touches anything (this
 * repo's own rule: no destructive write without a backup first). Resolves the REAL directory first (a
 * symlinked session dir -- e.g. rtorrent's `~/session -> /elsewhere` -- must back up what rewrite is about
 * to modify, not the ~100-byte link itself: tar on a symlink archives only the link, security review N1),
 * tars it at low priority with a time limit (lib/ToolProc.php's gentle(), the storage/backup convention),
 * then verifies the archive by LISTING it and checking every file rewrite is about to touch is actually in
 * it with the right size -- never just "the .tar.gz is bigger than 0 bytes", which a backup of a bare
 * symlink also satisfies. Keeps only the newest BACKUP_KEEP backups per client (review N6: unbounded
 * backups are a slow-growing use of the account's own quota).
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class SessionRewriteBackup
{
    const BACKUP_KEEP = 10;
    const TAR_TIMEOUT = 300;

    /**
     * $localDir: Config::localDir() in production; a test fixture's own local/ dir in tests (session-
     * rewrite.php's $sessionRewriteLocalDir seam). $expect: [file's basename => its current byte size],
     * every file `rewrite` is about to change, checked against the archive listing before it is trusted.
     */
    public static function make($client, $dir, $localDir, array $expect)
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new CliError("nothing was changed: $dir is not a real, existing directory", 'check the session directory and try again');
        }
        $root = rtrim($localDir, '/') . '/state/backups';
        if (!is_dir($root) && !@mkdir($root, 0700, true)) {
            throw new CliError('nothing was changed: cannot create ' . Config::shown($root), 'check the permissions of ' . Config::shown($localDir));
        }
        $tar = ToolProc::need('tar', 'tar is part of every normal install (coreutils); this tool will not rewrite session files without a backup');
        $file = $root . '/session-rewrite-' . $client . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.tar.gz';
        // realpath() already resolved every symlink in $dir, so dirname/basename of $real are the REAL
        // parent and the REAL leaf name -- never the original (possibly symlinked) $dir. "--" (review D3)
        // stops a leaf name starting with "-" (a dash-leading session.path.set value) from being read as
        // another tar option.
        $r = ToolProc::run(ToolProc::gentle([$tar, '-czf', $file, '-C', dirname($real), '--', PathName::base($real)], self::TAR_TIMEOUT));
        clearstatcache(true, $file);
        if ($r['code'] !== 0 || !is_file($file)) {
            throw new CliError('nothing was changed: the backup of ' . Config::shown($real) . " could not be made (tar exit code {$r['code']})",
                'check free space and permissions; rewrite refuses to touch session files without a working backup first');
        }
        self::verify($tar, $file, PathName::base($real), $expect);
        self::prune($root, $client);
        return $file;
    }

    /**
     * Lists the archive back (tar -tzv) and checks every $expect[name] => bytes is actually in it, at that
     * size, under $base/. A backup that only holds a symlink (or that is otherwise short of what rewrite is
     * about to change) is deleted and refused here, before --apply ever reads it as "safe to proceed"
     * (review N1: the old check, size(archive) > 0, is satisfied by a 141-byte archive of a bare link).
     */
    private static function verify($tar, $file, $base, array $expect)
    {
        $r = ToolProc::run([$tar, '-tzv', '-f', $file]);
        if ($r['code'] !== 0) {
            @unlink($file);
            throw new CliError("nothing was changed: the backup at $file could not be listed back (tar exit code {$r['code']})",
                'check free space and permissions; rewrite refuses to rely on a backup it cannot verify');
        }
        $sizes = [];
        foreach (explode("\n", $r['out']) as $line) {
            // GNU/BSD tar -tzv: "perms user/group size date time name" (checked against a real tar run).
            if (preg_match('#^\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.+)$#', rtrim($line), $m)) {
                $sizes[$m[2]] = (int) $m[1];
            }
        }
        $missing = [];
        foreach ($expect as $name => $bytes) {
            $key = "$base/$name";
            if (!isset($sizes[$key]) || $sizes[$key] !== $bytes) {
                $missing[] = $name;
            }
        }
        if ($missing) {
            @unlink($file);
            throw new CliError('nothing was changed: the backup does not actually hold ' . self::quoteList($missing)
                . ' at the size rewrite is about to change -- a symlinked session directory backs up only the link; refusing to rewrite without a verified backup',
                'check whether the session directory is really a folder (not a symlink) and that there is enough free space, then retry');
        }
    }

    /** $names (file names from the session directory) for an error message: quoted, capped at 5 with a trailing count. */
    private static function quoteList(array $names)
    {
        $shown = array_map(function ($s) {
            return '`' . Cli::cleanString((string) $s) . '`';
        }, array_slice($names, 0, 5));
        return implode(', ', $shown) . (count($names) > 5 ? ' (+' . (count($names) - 5) . ' more)' : '');
    }

    /** Keeps only the newest BACKUP_KEEP backups for $client; oldest deleted first (review N6: bounded, not pruned, use of the account's own quota). */
    private static function prune($root, $client)
    {
        $files = (array) glob($root . '/session-rewrite-' . $client . '-*.tar.gz');
        sort($files); // the Ymd-His timestamp in the name sorts oldest first
        foreach (array_slice($files, 0, max(0, count($files) - self::BACKUP_KEEP)) as $old) {
            @unlink($old);
        }
    }
}
