<?php
/**
 * BorgTool: the shared backup verbs on borg (tools/backup/README.md, README.md here). BorgRepo runs borg and reads
 * its output, BorgPrune plans and removes archives.
 */
class BorgTool implements BackupTool
{
    private $repo;
    private $prune;
    private $store;
    private $script;

    public function __construct(BackupStore $store, $script)
    {
        $this->repo = new BorgRepo($store, $script);
        $this->prune = new BorgPrune($this->repo);
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
            'repo' => ToolText::redact($this->store->repo()),
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
        return preg_match('/passphrase.*(incorrect|wrong)/i', $r['err']) ? 'wrong' : ToolText::tail($r['err']);
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
        return BorgRepo::record($a) + ['end' => isset($a['end']) ? BorgRepo::utc($a['end']) : null,
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
                $out[] = BorgRepo::item($f);
            }
            return count($out) < $max;
        });
        if (!$r['stopped'] && $r['code'] !== 0) {
            throw new CliError('borg list failed: ' . ToolText::tail($r['err']), 'names come from `list`');
        }
        $links = array_filter(array_column($out, 'hardlink'));
        return ['records' => $out, 'status' => ToolList::listed(count($out), $max) + ($links ? ['hardlink_note' => 'bytes is null'
            . ' for the second name of a hard link: its data is the file named in link_to, counted once there'] : [])];
    }

    public function prunePlan(array $keep)
    {
        return $this->prune->plan($keep);
    }

    public function prune(array $remove)
    {
        return $this->prune->remove($remove);
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

    /** The archive is <host>-<UTC time to the millisecond>Z: borg's `{hostname}-{now}` is to the second, so a second run in
     * the same second failed with "Archive ... already exists" (smoke run 2026-10-14); one job per account runs at a time. */
    public function backup(array $paths)
    {
        $t = microtime(true);
        $host = preg_replace('/[^A-Za-z0-9_-]/', '', explode('.', (string) gethostname())[0]) ?: 'backup';
        $name = $host . '-' . gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000));
        $r = $this->repo->run(array_merge(['create', '--json', '::' . $name, '--'], $paths), null, null, true);
        $d = json_decode($r['out'], true); // {"archive": {"name", "id", "stats": {...}}, ...} (docs/internals/frontends.rst, 1.2.4)
        if ($r['code'] !== 0 && !isset($d['archive'])) {
            throw new CliError('borg create failed: ' . ToolText::tail($r['err']), 'see the error; `stats` checks the repository');
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
