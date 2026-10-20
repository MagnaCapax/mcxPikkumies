<?php
/**
 * MoveCopy: the destination pulls one torrent's files with rsync over the service's ssh key (MoveRunner::ssh).
 * - The remote side gets one path, `<folder>/`: the torrent's folder on the source, relative to its home and inside one
 *   of its data roots (its serve refuses `.`, dot-folders and anything outside them; probe() asks before a plan is kept).
 *   The file names go over the protocol from a NUL-separated list on stdin (`--files-from=- --from0`), each relative to
 *   that folder, so no file name is ever in an argv and no `-s` is needed (rrsync refuses it, man rrsync).
 * - `-rt --partial`: files and times only, never the source's symlinks, permissions or device files (`-a` would bring
 *   them); `--partial` keeps a cut file for the next run; never `--delete`, `--remove-source-files` or `-s`; `--timeout`
 *   ends a stalled connection; `-c` on the run after a recheck found bad data. rsync writes only into the move's own
 *   staging folder (MovePlace), never over a file that is already here.
 * - The target folder lies inside a data root here (MoveRoots).
 */
class MoveCopy
{
    const IO_TIMEOUT = 300;
    const MARGIN = 1.05; // free space wanted: the data plus 5 %

    /** What serve's tokenizer passes (lib/Services.php TOKEN_RE): the source folder is the one path word the remote sees. */
    const TOKEN = '/^[A-Za-z0-9._:\/@%+=,~-]{1,512}\z/';

    /**
     * The rsync argv; $ssh: [words up to the host, host spec] from MoveRunner::ssh; $folder: the torrent's folder on the
     * source, relative to its home, inside one of its data roots (serve refuses `.` and anything outside them).
     */
    public static function argv(array $ssh, $folder, $dest, $checksum)
    {
        list($words, $host) = $ssh;
        foreach ($words as $w) {
            if (strpbrk((string) $w, "'\"\\ \n\t") !== false) { // rsync -e splits on spaces and honours quotes, never backslashes
                throw new CliError('the ssh command for the service holds a quote, a space or a backslash', 'move the service key to a path without them');
            }
        }
        if (!preg_match('/^([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+$/', $host)) {
            throw new CliError("the service host $host is not a plain user@host", 'check the service with `services show`');
        }
        return array_merge(['rsync', '-rt', '--partial', '--files-from=-', '--from0', '--timeout=' . self::IO_TIMEOUT],
            $checksum ? ['-c'] : [], ['-e', implode(' ', $words), $host . ':' . self::folder($folder) . '/', rtrim($dest, '/') . '/']);
    }

    /** The source folder as the remote sees it: relative, no dot part, only characters serve passes; refused otherwise. */
    public static function folder($folder)
    {
        $folder = (string) $folder;
        if ($folder === '' || $folder[0] === '/' || preg_match('#(^|/)\.#', $folder) || !preg_match(self::TOKEN, $folder)) {
            throw new CliError("nothing planned: the source folder $folder cannot be pulled (the home itself, a dot-folder, or a space or other character the source's serve refuses)",
                'move the torrent into a data folder on the source whose name has only letters, digits and . _ - (e.g. ~/data)');
        }
        return $folder;
    }

    /** The NUL-separated list for stdin: each path relative to the source folder (rsync sanitizes it below that folder). */
    public static function listing(array $files)
    {
        $out = '';
        foreach ($files as $f) {
            $out .= $f[0] . "\0";
        }
        return $out;
    }

    /**
     * Asks the source's serve whether $folder may be pulled, before anything is planned: the same pull with an empty
     * list into an empty folder, so it copies nothing. A refusal names the source's data roots setting.
     */
    public static function probe(MoveRunner $run, $nick, $folder, $tmp)
    {
        $dir = "$tmp/move-probe-" . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0700, true)) {
            throw new CliError("cannot create $dir", 'check the permissions of ~/pikkumies/local/state');
        }
        file_put_contents("$dir.list", '');
        try {
            $r = $run->rsync(self::argv($run->ssh($nick), $folder, $dir, false), "$dir.list");
        } finally {
            @unlink("$dir.list");
            @rmdir($dir);
        }
        if ($r['code'] !== 0) {
            throw new CliError("nothing planned: $nick refuses to send $folder (rsync exit {$r['code']}" . ($r['err'] !== '' ? ': ' . substr($r['err'], -200) : '') . ')',
                "on $nick, the folder must be inside a data root: ~/data, rTorrent's download folder, or a folder added to services.serveRoots there");
        }
    }

    /** Files missing here or of another size than the .torrent says (empty when all are in place). */
    public static function check($dir, array $files)
    {
        clearstatcache();
        $bad = [];
        foreach ($files as $f) {
            $p = "$dir/{$f[0]}";
            if (is_link($p) || !is_file($p) || filesize($p) !== $f[1]) {
                $bad[] = $f[0];
            }
        }
        return $bad;
    }

    /** Free space for $need bytes: the quota's free bytes, else the filesystem's. [ok, free or null]. */
    public static function room($home, $dir, $need)
    {
        $q = Quota::fields($home);
        $free = $q['quota_free_bytes'] !== null ? $q['quota_free_bytes'] : (is_dir($dir) ? @disk_free_space($dir) : $q['fs_free_bytes']);
        $free = $free === false || $free === null ? null : (int) $free;
        return [$free === null || $free >= (int) ceil($need * self::MARGIN), $free];
    }

    public static function makeDir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check the free space and the permissions of the folder');
        }
    }
}
