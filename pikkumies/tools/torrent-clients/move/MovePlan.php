<?php
/**
 * MovePlan: `plan` (read-only on both boxes; writes only this box's move state). Moves run on the box that receives
 * the data (it pulls), so --to must be this box: on the source box `plan --to <nick>` prints what to run on the
 * destination instead. One source client per plan; every torrent complete there; its .torrent exported and checked;
 * its files laid out as the .torrent names them; not yet in the target client; and the free space here at least the
 * data plus 5 %.
 */
class MovePlan
{
    const TARGETS = ['rtorrent', 'qbittorrent', 'deluge', 'transmission'];

    private $run;
    private $state;
    private $home;
    private $tmp;

    public function __construct(MoveRunner $run, MoveState $state, $home, $tmp)
    {
        $this->tmp = $tmp;
        $this->run = $run;
        $this->state = $state;
        $this->home = rtrim($home, '/');
    }

    public function plan(array $args, array $opt)
    {
        $to = isset($opt['to']) && is_string($opt['to']) ? $opt['to'] : 'local';
        if (!$args) {
            throw new CliError('plan needs one or more torrent ids or names', 'plan <id|name>... --from <service> [--client <c>] [--dir <folder>]');
        }
        if ($to !== 'local') {
            return ['records' => [['run_on' => $to, 'command' => 'php ~/pikkumies/tools/torrent-clients/move/move.php plan ' . implode(' ', array_map('escapeshellarg', $args))
                . ' --from <this box\'s name in the services list of ' . $to . '>']], 'status' => ['planned' => false,
                'why' => 'a move runs on the box that receives the data (it pulls the files); ask Pikkumies there (`services remote ' . $to . '`) or run the command on that box']];
        }
        $from = isset($opt['from']) && is_string($opt['from']) ? $opt['from'] : '';
        if ($from === '' || $from === 'local') {
            throw new CliError('plan needs --from <service>: the box the torrents are on now', 'the services list names the boxes (`services list`); a move within this box is the client units\' job');
        }
        $svc = $this->run->service($from);
        $src = new MoveSource($this->run);
        $picked = [];
        foreach ($args as $q) {
            $t = $src->find($from, MoveSource::clients($svc), $q);
            $picked[$t['id']] = $t;
        }
        $picked = array_values($picked);
        $clients = array_unique(array_column($picked, 'client'));
        if (count($clients) !== 1) {
            throw new CliError('nothing planned: the torrents are in different clients on ' . $from . ' (' . implode(', ', $clients) . ')', 'plan each client\'s torrents separately');
        }
        $sc = $clients[0];
        foreach ($picked as $t) {
            if ((float) $t['progress'] < 1) {
                throw new CliError("nothing planned: {$t['name']} is not complete on $from (" . round($t['progress'] * 100, 1) . ' %)', 'move it once it has finished downloading');
            }
        }
        $tc = $this->targetClient($opt, $sc);
        $files = $src->files($from, $sc, array_column($picked, 'id'));
        $id = MoveState::newId();
        $roots = MoveRoots::roots($this->run, $tc, $this->home);
        $probed = [];
        $items = [];
        foreach ($picked as $n => $t) {
            list($raw, $meta) = $src->export($from, $sc, $t['id']);
            if ($meta['private'] && !$meta['trackers']) {
                throw new CliError("nothing planned: the saved .torrent of {$t['name']} names no tracker", 'this client keeps trackers apart from the .torrent; update it (qBittorrent 4.5 and later export the full file)');
            }
            $root = MoveMeta::root($meta, isset($files['files'][$t['id']]) ? $files['files'][$t['id']] : [], isset($svc['home']) ? $svc['home'] : '', $t['id']);
            if (!isset($probed[$root])) {
                MoveCopy::probe($this->run, $from, MoveCopy::folder($root), $this->tmp); // the source's serve must send this folder
                $probed[$root] = true;
            }
            $dir = MoveRoots::targetDir(isset($opt['dir']) && is_string($opt['dir']) ? $opt['dir'] : "{$this->home}/$root", $this->home, $roots);
            MovePlace::free($dir, $meta['files']); // nothing here is ever overwritten
            $tid = MoveMeta::idFor($meta, $tc);
            $this->notInTarget($tc, $tid, $t['name']);
            $items[] = ['n' => $n, 'id' => $t['id'], 'target_id' => $tid, 'name' => $meta['name'], 'bytes' => $meta['bytes'], 'label' => (string) $t['label'],
                'private' => $meta['private'], 'source_root' => $root, 'target_dir' => $dir, 'files' => $meta['files'],
                'torrent' => $this->state->saveTorrent($id, $n, $raw), 'done' => []];
        }
        $total = array_sum(array_column($items, 'bytes'));
        list($ok, $free) = MoveCopy::room($this->home, $this->home, $total);
        if (!$ok) {
            throw new CliError("nothing planned: $total bytes need " . (int) ceil($total * MoveCopy::MARGIN) . " free here, and $free are free", 'free space here first, or move fewer torrents');
        }
        $move = ['id' => $id, 'created_at' => gmdate('c'), 'from' => $from, 'source_client' => $sc, 'target_client' => $tc,
            'bytes' => $total, 'expect' => isset($files['status']['expect']) ? (string) $files['status']['expect'] : '', 'items' => $items];
        $this->state->save($move);
        $this->state->log($id, 'plan', '', 'planned', ['torrents' => count($items), 'bytes' => $total]);
        return ['records' => array_map(function ($i) use ($from, $sc, $tc) {
            return ['id' => $i['id'], 'name' => $i['name'], 'bytes' => $i['bytes'], 'from' => "$from $sc", 'to' => "here $tc",
                'target_dir' => $i['target_dir'], 'label' => $i['label'], 'private' => $i['private']];
        }, $items), 'status' => ['move' => $id, 'bytes' => $total, 'free_here' => $free, 'expect' => $move['expect'],
            'next' => "run $id (copies, checks and starts it here; the source stays as it is)"]];
    }

    /** --client, else the source's kind when it runs here, else the first client that runs here. */
    private function targetClient(array $opt, $sc)
    {
        if (isset($opt['client'])) {
            $c = (string) $opt['client'];
            if (!in_array($c, self::TARGETS, true) || !$this->runs($c)) {
                throw new CliError("nothing planned: --client $c is not a torrent client running here", 'clients: ' . implode(', ', self::TARGETS));
            }
            return $c;
        }
        foreach (array_unique(array_merge([$sc], self::TARGETS)) as $c) {
            if ($this->runs($c)) {
                return $c;
            }
        }
        throw new CliError('nothing planned: no torrent client answers here', 'start one first (each client unit\'s `stats` says why it cannot be reached)');
    }

    private function runs($c)
    {
        if (!is_file("{$this->run->unitsDir}/$c/$c.php")) {
            return false;
        }
        try {
            $this->run->unit('local', $c, ['stats']);
            return true;
        } catch (CliError $e) {
            return false;
        }
    }

    private function notInTarget($tc, $tid, $name)
    {
        try {
            $this->run->unit('local', $tc, ['show', $tid, '--fields', 'id']);
        } catch (CliError $e) {
            if (strpos($e->getMessage(), 'not found:') !== false) {
                return;
            }
            throw $e;
        }
        throw new CliError("nothing planned: $name is already in $tc here ($tid)", 'it was moved before; check it with the client\'s `show`');
    }
}
