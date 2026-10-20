<?php
/**
 * TransmissionRepath: `repath <id>... --to <dir>` moves a torrent's RECORD to data that is already at
 * <dir> -- it never moves bytes itself. Refuses unless every one of the torrent's files already exists
 * at <dir> with the exact size `files()` reports.
 *
 * `torrent-set-location` with `move: false` repoints Transmission's own idea of where the files are
 * without touching anything on disk ("if true, move from previous location; otherwise, search
 * 'location' for files", rpc-spec.md on the 4.0.6 tag, section 3.6); `torrent-verify` (section 3.1)
 * then hash-checks the new location, so a mismatch this check missed surfaces as missing/incomplete
 * data in `list` afterwards, never as a silent "done".
 */
class TransmissionRepath
{
    public static function run(TransmissionClient $c, array $targets, $to)
    {
        if ($to === null || $to === '') {
            throw new CliError('repath needs --to <dir>', 'repath <id>... --to <existing dir inside your home>');
        }
        $dir = Torrents::dir($to, (string) getenv('HOME'));
        foreach ($targets as $t) {
            $missing = [];
            $oldRoot = rtrim((string) $t['path'], '/');
            foreach ($c->files($t['native']) as $f) {
                $base = self::relative($f[0], $oldRoot);
                $new = $dir . '/' . $base;
                clearstatcache(true, $new);
                $st = @stat($new);
                if ($st === false) {
                    $missing[] = "$base: missing at $new";
                } elseif ((int) $st['size'] !== (int) $f[1]) {
                    $missing[] = "$base: " . (int) $st['size'] . ' bytes at ' . $new . ', expected ' . (int) $f[1];
                }
            }
            if ($missing) {
                throw new CliError("nothing repathed: {$t['id']} ({$t['name']}): " . implode('; ', array_slice($missing, 0, 5)),
                    'copy or link every file into --to first, with the same names and sizes, then run repath again');
            }
        }
        $natives = Torrents::natives($targets);
        $c->rpc()->call('torrent-set-location', ['ids' => $natives, 'location' => $dir, 'move' => false]);
        $c->rpc()->call('torrent-verify', ['ids' => $natives]);
        $now = [];
        foreach ($c->torrents() as $r) {
            $now[$r['native']] = $r;
        }
        $records = [];
        $ok = true;
        foreach ($targets as $t) {
            $r = isset($now[$t['native']]) ? $now[$t['native']] : null;
            $moved = $r !== null && rtrim((string) $r['path'], '/') === $dir;
            $ok = $ok && $moved;
            $records[] = ['id' => $t['id'], 'name' => $t['name'], 'path' => $r !== null ? $r['path'] : $t['path'],
                'state' => $r !== null ? $r['state'] : 'gone', 'verified' => $moved];
        }
        return ['records' => $records, 'status' => ['verified' => $ok,
            'note' => 'torrent-verify was requested in the background; a mismatch now shows up as missing/incomplete data in `list`, not as an error here']];
    }

    /** $old relative to $oldRoot (the torrent's own download dir, before the move); falls back to the
     * bare file name on the defensive branch where $old is not under $oldRoot at all. */
    private static function relative($old, $oldRoot)
    {
        return $oldRoot !== '' && strncmp($old, $oldRoot . '/', strlen($oldRoot) + 1) === 0
            ? substr($old, strlen($oldRoot) + 1) : basename($old);
    }
}
