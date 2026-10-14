<?php
/**
 * ResticPrune: removing snapshots for the restic unit (tools/backup/README.md, README.md here). The plan is restic's own
 * `forget --dry-run --json`; the removal forgets exactly the snapshot ids the user saw in the plan, never re-running the
 * policy, then runs `restic prune`.
 */
class ResticPrune
{
    private $repo;

    public function __construct(ResticRepo $repo)
    {
        $this->repo = $repo;
    }

    /** `forget --dry-run --json <policy>`: groups with keep and remove lists (cmd/restic/cmd_forget.go:216-221, 0.11.0). */
    public function plan(array $keep)
    {
        $remove = [];
        $kept = 0;
        foreach ($this->repo->json(array_merge(['forget', '--dry-run', '--json'], self::keepFlags($keep))) as $g) {
            $kept += isset($g['keep']) ? count((array) $g['keep']) : 0;
            foreach (isset($g['remove']) ? (array) $g['remove'] : [] as $s) {
                $remove[$s['id']] = ResticRepo::record($s);
            }
        }
        return ['remove' => array_values($remove), 'keep' => $kept];
    }

    public function remove(array $remove)
    {
        $this->repo->must(array_merge(['forget', '--'], array_column($remove, 'snapshot_id')));
        $this->repo->must(['prune']);
        return ['records' => $remove, 'status' => ['space_freed_by' => 'restic prune']];
    }

    /** The shared keep options as restic flags. restic durations know y, m, d, h (internal/restic/duration.go, 0.11.0). */
    public static function keepFlags(array $keep)
    {
        $flags = [];
        foreach ($keep as $k => $v) {
            if ($k === 'keep-within') {
                $n = (int) $v;
                $unit = substr($v, -1);
                $v = $unit === 'w' ? (7 * $n) . 'd' : ($unit === 'H' ? "{$n}h" : $v);
            }
            $flags[] = "--$k";
            $flags[] = $v;
        }
        return $flags;
    }
}
