<?php
/**
 * BorgPrune: removing archives for the borg unit (tools/backup/README.md, README.md here). The plan is borg's own
 * `prune --dry-run --list`; the removal deletes exactly the archives the plan named (`borg delete :: <names>`,
 * docs/usage/delete.rst.inc:9 in 1.1.16 and 1.2.4), then runs `borg compact` on borg 1.2 or newer: borg 1.2 frees
 * repository space only on compact (compact.rst.inc:56-60, 1.2.4), and borg 1.1.16 has no compact command (no
 * docs/usage/compact.rst.inc at that tag).
 */
class BorgPrune
{
    private $repo;

    public function __construct(BorgRepo $repo)
    {
        $this->repo = $repo;
    }

    public function plan(array $keep)
    {
        $r = $this->repo->run(array_merge(['prune', '--dry-run', '--list'], self::keepFlags($keep)));
        if ($r['code'] !== 0) {
            throw new CliError('borg prune --dry-run failed: ' . ToolText::tail($r['err']), 'see the error; `stats` checks the repository');
        }
        return self::wouldPrune($r['err']);
    }

    public function remove(array $remove)
    {
        $this->repo->must(array_merge(['delete', '::'], array_column($remove, 'id')));
        $v = $this->repo->version();
        $compact = $v !== null && version_compare($v, '1.2', '>=');
        if ($compact) {
            $this->repo->must(['compact']);
        }
        return ['records' => $remove, 'status' => ['compacted' => $compact] + ($compact ? []
            : ['note' => "borg $v has no compact command (it arrived in 1.2)"])];
    }

    /** The shared keep options as borg prune flags (docs/usage/prune.rst.inc, 1.1.16 and 1.2.4). */
    public static function keepFlags(array $keep)
    {
        $flags = [];
        foreach ($keep as $k => $v) {
            $flags[] = "--$k";
            $flags[] = $v;
        }
        return $flags;
    }

    /**
     * "Would prune:" lines of `prune --dry-run --list` (stderr): the archive as name, time and
     * [id] (archiver.py:1597-1616 in 1.2.4, :1660 in 1.1.16; helpers/parseformat.py:287-292
     * format_archive; helpers/time.py:92-96, "%a, %Y-%m-%d %H:%M:%S").
     */
    public static function wouldPrune($stderr)
    {
        $out = [];
        $kept = 0;
        foreach (explode("\n", (string) $stderr) as $line) {
            $line = rtrim($line);
            if (strpos($line, 'Keeping') === 0) {
                $kept++;
            } elseif (preg_match('/^Would prune:\s+(.+?)\s+\w{3}, (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) \[([0-9a-f]{64})\]$/', $line, $m)) {
                $out[] = ['id' => $m[1], 'time' => BorgRepo::utc($m[2]), 'host' => null, 'paths' => [], 'tags' => [],
                    'snapshot_id' => $m[3]];
            }
        }
        return ['remove' => $out, 'keep' => $kept];
    }
}
