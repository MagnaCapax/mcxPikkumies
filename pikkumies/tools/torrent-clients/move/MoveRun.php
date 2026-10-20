<?php
/**
 * MoveRun: `run <move>` here, on the box that receives the data. Per torrent, in order, each step logged and marked
 * done in the move state so a second run continues at the first unfinished one:
 *   export (the saved .torrent still hashes to the id), copy (MoveCopy), add (paused, into the target folder),
 *   recheck (poll until the client has checked it: 100 % or stop), resume (then seeding, with the same id).
 * The source is not touched by any of these. Only `--free-source` goes on to MoveFree, after every torrent is seeding
 * here, so a private tracker never sees a gap.
 */
class MoveRun
{
    const STEPS = ['export', 'copy', 'add', 'recheck', 'resume'];

    private $run;
    private $state;
    private $home;
    private $tmp;

    public function __construct(MoveRunner $run, MoveState $state, $home, $tmp)
    {
        $this->run = $run;
        $this->state = $state;
        $this->home = rtrim($home, '/');
        $this->tmp = $tmp;
    }

    public function run($id, array $opt)
    {
        $free = !empty($opt['free-source']);
        $str = function ($k) use ($opt) { return isset($opt[$k]) && is_string($opt[$k]) ? trim($opt[$k]) : ''; };
        if ($free !== isset($opt['expect']) || $free !== isset($opt['ids']) || ($free && ($str('expect') === '' || $str('intent') === ''))) {
            throw new CliError('nothing done: freeing the source needs all of --free-source, --ids <id,id...>, --expect <value> and --intent "<the user\'s own words>"',
                'run <move> alone copies and starts the torrents here; add them only when the user asked for the old copy gone');
        }
        $lock = $this->state->lock($id); // one run of a move at a time: never two copies into the same files
        $move = $this->state->load($id);
        $ids = implode(',', array_column($move['items'], 'id'));
        if ($free && $str('ids') !== $ids) { // the delete review sees which torrents, not only a size
            throw new CliError("nothing done: --ids must name exactly this move's torrents, in order: $ids", 'copy the ids from `status`');
        }
        foreach ($move['items'] as $i => $item) {
            foreach (self::STEPS as $step) {
                if (empty($move['items'][$i]['done'][$step])) {
                    $this->step($move, $i, $step);
                }
            }
        }
        if (!$free) {
            return ['records' => self::summary($move), 'status' => ['move' => $id, 'result' => 'copied and seeding on both boxes; the source is untouched',
                'expect' => $move['expect'], 'next' => "to free the source when the user asks: run $id --free-source --ids $ids --expect {$move['expect']} --intent \"<their words>\""]];
        }
        $out = (new MoveFree($this->run, $this->state))->free($move, $str('expect'), $str('intent'));
        if (!empty($out['status']['freed'])) {
            $move['freed_at'] = gmdate('c');
            $this->state->save($move);
        }
        return $out;
    }

    public static function summary(array $move)
    {
        return array_map(function ($i) use ($move) {
            $next = empty($move['freed_at']) ? 'free-source' : 'done';
            foreach (self::STEPS as $s) {
                if (empty($i['done'][$s])) {
                    $next = $s;
                    break;
                }
            }
            return ['id' => $i['id'], 'name' => $i['name'], 'bytes' => $i['bytes'], 'target_dir' => $i['target_dir'],
                'done' => array_keys(array_filter($i['done'])), 'next' => $next];
        }, $move['items']);
    }

    private function step(array &$move, $i, $step)
    {
        $item = $move['items'][$i];
        try {
            $detail = $this->{'do' . ucfirst($step)}($move, $item);
        } catch (CliError $e) {
            $this->state->log($move['id'], $step, $item['id'], 'failed', ['error' => $e->getMessage()]);
            if ($e instanceof MoveBadData) { // bad data: copy again with checksums, then check again
                $move['items'][$i]['done']['copy'] = null;
                $move['items'][$i]['checksum'] = true;
                $this->state->save($move);
            }
            throw $e;
        }
        $move['items'][$i]['done'][$step] = gmdate('c');
        $this->state->save($move);
        $this->state->log($move['id'], $step, $item['id'], 'done', (array) $detail);
    }

    private function doExport(array $move, array $item)
    {
        $raw = (string) @file_get_contents($item['torrent']);
        $meta = $raw === '' ? null : MoveMeta::parse($raw);
        if ($meta === null || MoveMeta::idFor($meta, $move['source_client']) !== $item['id']) {
            list($raw, $meta) = (new MoveSource($this->run))->export($move['from'], $move['source_client'], $item['id']);
            $this->state->saveTorrent($move['id'], $item['n'], $raw);
        }
        return ['torrent_bytes' => strlen($raw)];
    }

    private function doCopy(array &$move, array $item)
    {
        return $this->copy()->copy($move, $item);
    }

    private function copy()
    {
        return new MoveStepCopy($this->run, $this->state, $this->home, $this->tmp);
    }

    private function doRecheck(array $move, array $item)
    {
        return (new MoveTarget($this->run, $this->state))->recheck($move, $item);
    }

    private function doResume(array $move, array $item)
    {
        return (new MoveTarget($this->run, $this->state))->resume($move, $item);
    }

    private function doAdd(array $move, array $item)
    {
        $this->copy()->saved($move, $item);
        $r = $this->run->unit('local', $move['target_client'], ['add', $item['torrent'], '--paused', '--dir', $this->copy()->dir($move, $item)]);
        (new MoveTarget($this->run, $this->state))->live($move, $item); // listed here now, under the id the .torrent gives
        return ['result' => isset($r['records'][0]['result']) ? $r['records'][0]['result'] : '', 'label' => $item['label'],
            'label_set' => false]; // the client units' add takes no label yet: the user sets it in the target client
    }

}

/** A recheck that ended below 100 %: the next run copies again with checksums before it checks again. */
class MoveBadData extends CliError
{
}
