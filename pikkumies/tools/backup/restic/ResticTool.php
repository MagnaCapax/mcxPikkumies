<?php
/**
 * ResticTool: the shared backup verbs on restic (tools/backup/README.md, README.md here). Sources
 * are restic 0.11.0 and 0.14.0 (Debian 11 and 12). Prune forgets exactly the snapshot ids the user
 * saw in the plan, never re-running the policy, then runs `restic prune`.
 */
class ResticTool implements BackupTool
{
    private $repo;
    private $store;
    private $script;

    public function __construct(BackupStore $store, $script)
    {
        $this->repo = new ResticRepo($store, $script);
        $this->store = $store;
        $this->script = $script;
    }

    public function bin()
    {
        return $this->repo->bin();
    }

    public function stats()
    {
        $r = ['version' => $this->repo->version(), 'repo' => ToolProc::redact($this->store->repo()), 'logged_in' => false];
        try {
            $this->store->usable($this->script);
        } catch (CliError $e) {
            return $r + ['hint' => $e->hint];
        }
        $r['logged_in'] = true;
        try {
            $snaps = $this->snapshots();
            $r += ['reachable' => true, 'snapshots' => count($snaps), 'latest_at' => $snaps ? max(array_column($snaps, 'time')) : null];
            $locks = $this->repo->run(['list', 'locks', '--no-lock']); // --no-lock: global flag (cmd/restic/global.go:111, 0.11.0)
            $r['locks'] = $locks['code'] === 0 ? count(array_filter(explode("\n", $locks['out']), 'strlen')) : null;
        } catch (CliError $e) {
            $r += ['reachable' => false, 'error' => $e->getMessage()];
        }
        return $r;
    }

    public function verify()
    {
        $r = $this->repo->run(['cat', 'config']);
        if ($r['code'] === 0) {
            return 'ok';
        }
        return stripos($r['err'], 'wrong password') !== false ? 'wrong' : ToolProc::tail($r['err']);
    }

    public function snapshots()
    {
        return array_map(['ResticRepo', 'record'], $this->repo->json(['snapshots', '--json']));
    }

    public function details($id)
    {
        $s = $this->repo->json(['snapshots', '--json', '--', $id]);
        if (!$s) {
            throw new CliError("no snapshot $id", 'ids come from `list`');
        }
        return ResticRepo::record($s[0]) + ['username' => isset($s[0]['username']) ? $s[0]['username'] : null,
            'excludes' => isset($s[0]['excludes']) ? $s[0]['excludes'] : []];
    }

    public function files($id, $path, $max)
    {
        $out = [];
        $r = $this->repo->run(array_merge(['ls', '--json', '--', $id], $path !== null ? [$path] : []), function ($line) use (&$out, $max) {
            $n = json_decode($line, true); // a snapshot line, then one node per line (cmd/restic/cmd_ls.go:72-88, 0.11.0)
            if (is_array($n) && isset($n['struct_type']) && $n['struct_type'] === 'node') {
                $out[] = ['path' => $n['path'], 'type' => $n['type'], 'bytes' => isset($n['size']) ? (int) $n['size'] : null,
                    'modified_at' => isset($n['mtime']) ? ResticRepo::time($n['mtime']) : null];
            }
            return count($out) < $max;
        });
        if (!$r['stopped'] && $r['code'] !== 0) {
            throw new CliError('restic ls failed: ' . ToolProc::tail($r['err']), 'ids come from `list`');
        }
        return ['records' => $out, 'status' => ToolProc::listed(count($out), $max)];
    }

    /** `forget --dry-run --json <policy>`: groups with keep and remove lists (cmd/restic/cmd_forget.go:216-221, 0.11.0). */
    public function prunePlan(array $keep)
    {
        $remove = [];
        $kept = 0;
        foreach ($this->repo->json(array_merge(['forget', '--dry-run', '--json'], ResticRepo::keepFlags($keep))) as $g) {
            $kept += isset($g['keep']) ? count((array) $g['keep']) : 0;
            foreach (isset($g['remove']) ? (array) $g['remove'] : [] as $s) {
                $remove[$s['id']] = ResticRepo::record($s);
            }
        }
        return ['remove' => array_values($remove), 'keep' => $kept];
    }

    public function prune(array $remove)
    {
        $this->repo->must(array_merge(['forget', '--'], array_column($remove, 'snapshot_id')));
        $this->repo->must(['prune']);
        return ['records' => $remove, 'status' => ['space_freed_by' => 'restic prune']];
    }

    /**
     * Damage is only what restic calls damage: "Fatal: repository contains errors" (cmd/restic/cmd_check.go:273 in
     * 0.11.0, :374 in 0.14.0). Any other failure (lock, network, a refused option: all exit 1, main.go:119-128) means
     * the check did not finish. A percentage subset arrived in 0.12.0 (cmd_check.go:57 there; 0.11.0 :56 takes n/m only).
     */
    public function check($readData, $subset)
    {
        $v = $subset !== null && substr($subset, -1) === '%' ? $this->repo->version() : null;
        if ($v !== null && version_compare($v, '0.12.0', '<')) {
            throw new CliError("restic $v takes --read-data-subset only as n/m (a percentage needs restic 0.12.0 or newer);"
                . ' nothing was checked',
                'e.g. --read-data-subset 1/20 reads about 5% of the data');
        }
        $args = $readData ? ['check', '--read-data'] : ($subset !== null ? ['check', '--read-data-subset', $subset] : ['check']);
        $r = $this->repo->run($args, null, true);
        $damage = strpos($r['out'] . $r['err'], 'repository contains errors') !== false;
        return BackupRules::checked($damage, $r['code'] === 0 || $damage, $r, $readData ? 'all' : ($subset !== null ? $subset : 'none'));
    }

    public function backup(array $paths)
    {
        $s = [];
        $r = $this->repo->run(array_merge(['backup', '--json', '--'], $paths), function ($line) use (&$s) {
            $m = json_decode($line, true); // the summary message (internal/ui/json/backup.go:443-456, 0.11.0)
            if (is_array($m) && isset($m['message_type']) && $m['message_type'] === 'summary') {
                $s = $m;
            }
        }, true);
        if ($r['code'] !== 0 && !$s) {
            throw new CliError('restic backup failed: ' . ToolProc::tail($r['err']), 'see the error; `stats` checks the repository');
        }
        $get = function ($k) use ($s) {
            return isset($s[$k]) ? $s[$k] : null;
        };
        // Exit 3: the snapshot was made but some files could not be read (cmd/restic/main.go:123-124, 0.11.0).
        // snapshot_id is the short id on restic 0.14.0 and the full one on 0.19.1 (live runs 2026-10-14): `id` is always short.
        $sid = (string) $get('snapshot_id');
        return ['id' => $sid === '' ? null : substr($sid, 0, 8), 'snapshot_id' => strlen($sid) === 64 ? $sid : null, 'paths' => $paths,
            'files_new' => $get('files_new'), 'files_changed' => $get('files_changed'), 'added_bytes' => $get('data_added'),
            'incomplete' => $r['code'] === 3];
    }

    public function restore($id, $target, $include)
    {
        $this->repo->must(array_merge(['restore', '--target', $target], $include !== null ? ['--include', $include] : [], ['--', $id]),
            true);
        return ['id' => $id, 'note' => 'restic puts the files under their original full path inside the target'];
    }
}
