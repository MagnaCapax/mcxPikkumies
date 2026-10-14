<?php
/**
 * BorgTool: the shared backup verbs on borg (tools/backup/README.md, README.md here). Prune deletes
 * exactly the archives the plan named (`borg delete :: <names>`, docs/usage/delete.rst.inc:9 in
 * 1.1.16 and 1.2.4), then runs `borg compact` on borg 1.2 or newer: borg 1.2 frees repository
 * space only on compact (compact.rst.inc:56-60, 1.2.4), and borg 1.1.16 has no compact command
 * (no docs/usage/compact.rst.inc at that tag).
 */
class BorgTool implements BackupTool
{
    private $repo;
    private $store;
    private $script;

    public function __construct(BackupStore $store, $script)
    {
        $this->repo = new BorgRepo($store, $script);
        $this->store = $store;
        $this->script = $script;
    }

    public function bin()
    {
        return $this->repo->bin();
    }

    public function stats()
    {
        $v = $this->repo->version();
        $r = ['version' => $v, 'compact_supported' => $v !== null && version_compare($v, '1.2', '>='),
            'repo' => ToolProc::redact($this->store->repo()),
            'borgmatic_configs' => BorgRepo::borgmaticConfigs(), 'logged_in' => false];
        try {
            $this->store->usable($this->script);
        } catch (CliError $e) {
            return $r + ['hint' => $e->hint];
        }
        $r['logged_in'] = true;
        try {
            $a = $this->snapshots();
            $r += ['reachable' => true, 'archives' => count($a), 'latest_at' => $a ? max(array_column($a, 'time')) : null];
        } catch (CliError $e) {
            $r += ['reachable' => false, 'error' => $e->getMessage()];
        }
        return $r;
    }

    public function verify()
    {
        $r = $this->repo->run(['info', '--json']);
        if ($r['code'] === 0) {
            return 'ok';
        }
        return preg_match('/passphrase.*(incorrect|wrong)/i', $r['err']) ? 'wrong' : ToolProc::tail($r['err']);
    }

    public function snapshots()
    {
        $d = $this->repo->json(['list', '--json']);
        return array_map(['BorgRepo', 'record'], isset($d['archives']) ? (array) $d['archives'] : []);
    }

    public function details($id)
    {
        $d = $this->repo->json(['info', '--json', '::' . $id]);
        if (empty($d['archives'][0])) {
            throw new CliError("no archive $id", 'names come from `list`');
        }
        $a = $d['archives'][0];
        unset($a['command_line']); // the command line that made the archive can hold anything the user typed
        return BorgRepo::record($a) + ['end' => isset($a['end']) ? $a['end'] : null,
            'duration' => isset($a['duration']) ? $a['duration'] : null,
            'stats' => isset($a['stats']) ? $a['stats'] : null, 'username' => isset($a['username']) ? $a['username'] : null];
    }

    public function files($id, $path, $max)
    {
        $out = [];
        $args = array_merge(['list', '--json-lines', '::' . $id], BorgRepo::stored($path));
        $r = $this->repo->run($args, function ($line) use (&$out, $max) {
            $f = json_decode($line, true); // one item per line: type, path, size, mtime (docs/internals/frontends.rst, 1.2.4)
            if (is_array($f) && isset($f['path'])) {
                $out[] = ['path' => '/' . $f['path'], 'type' => isset($f['type']) ? $f['type'] : null,
                    'bytes' => isset($f['size']) ? (int) $f['size'] : null,
                    'modified_at' => isset($f['mtime']) ? (int) strtotime((string) preg_replace('/\.\d+$/', '', $f['mtime'])) : null];
            }
            return count($out) <= $max;
        });
        if (!$r['stopped'] && $r['code'] !== 0) {
            throw new CliError('borg list failed: ' . ToolProc::tail($r['err']), 'names come from `list`');
        }
        return ['records' => array_slice($out, 0, $max), 'status' => ['listed_all' => count($out) <= $max]];
    }

    public function prunePlan(array $keep)
    {
        $r = $this->repo->run(array_merge(['prune', '--dry-run', '--list'], BorgRepo::keepFlags($keep)));
        if ($r['code'] !== 0) {
            throw new CliError('borg prune --dry-run failed: ' . ToolProc::tail($r['err']), 'see the error; `stats` checks the repository');
        }
        return BorgRepo::wouldPrune($r['err']);
    }

    public function prune(array $remove)
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

    /**
     * Damage is rc 1: do_check returns EXIT_WARNING only when the repository or archive check fails
     * (src/borg/archiver.py:343-351, 1.2.4 and 1.1.16). rc 2 is an error before the end, such as a lock,
     * a lost connection or a refused key (docs/usage/general/return-codes.rst.inc), so the check did not finish.
     */
    public function check($readData, $subset)
    {
        if ($subset !== null) {
            throw new CliError('borg has no sampled data check',
                'check without options reads the metadata; --read-data reads all data (borg check --verify-data)');
        }
        $r = $this->repo->run($readData ? ['check', '--verify-data'] : ['check'], null, null, true);
        return BackupRules::checked($r['code'] === 1, in_array($r['code'], [0, 1], true), $r, $readData ? 'all' : 'none')
            + ['memory_note' => 'borg check loads the repository index into the memory of the account that serves the repository;'
                . ' on a 500 MB storage box a big repository can run out of memory'];
    }

    public function backup(array $paths)
    {
        $r = $this->repo->run(array_merge(['create', '--json', '::{hostname}-{now}', '--'], $paths), null, null, true);
        $d = json_decode($r['out'], true); // {"archive": {"name", "id", "stats": {...}}, ...} (docs/internals/frontends.rst, 1.2.4)
        if ($r['code'] !== 0 && !isset($d['archive'])) {
            throw new CliError('borg create failed: ' . ToolProc::tail($r['err']), 'see the error; `stats` checks the repository');
        }
        $a = isset($d['archive']) ? $d['archive'] : [];
        // rc 1 = warning (docs/usage/general/return-codes.rst.inc, 1.2.4)
        return ['id' => isset($a['name']) ? $a['name'] : null, 'paths' => $paths, 'stats' => isset($a['stats']) ? $a['stats'] : null,
            'warnings' => $r['code'] === 1];
    }

    public function restore($id, $target, $include)
    {
        // extract writes into the working directory (extract.rst.inc:120-121); stored paths are relative (helpers/fs.py:159-162, 1.2.4).
        $this->repo->must(array_merge(['extract', '::' . $id], BorgRepo::stored($include)), $target, true);
        return ['id' => $id, 'note' => 'borg puts the files under their stored path (without the leading /) inside the target'];
    }
}
