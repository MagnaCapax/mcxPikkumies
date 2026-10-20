<?php
/**
 * MoveFree: `run <move> --free-source --expect <value> --intent "<the user's words>"`, after every torrent of the move
 * is seeding here. The move state is written by the agent's own account, so nothing in it authorizes the delete
 * (catalog: agent-writable state as authorization input). Each removal is decided from the live boxes only:
 *   1. the source exports each torrent again (it is still there; the ids it should have here are computed from that
 *      fresh .torrent, not read from the state), and its delete preview must total exactly --expect, with no file
 *      shared with a torrent outside the move;
 *   2. the user's words go to local/state/intents.jsonl (ts, move, ids, sizes);
 *   3. immediately before the delete, this box's client must list each of them under that id, seeding, at 100 %,
 *      and every file must be here at its size;
 *   4. the source's own unit deletes: `remove <ids> --with-data --expect <value>` through the service's serve, which
 *      allows it only when the source key was set up with --allow-delete. Without that nothing on the source changes
 *      and the record gives the exact command for the source box.
 */
class MoveFree
{
    private $run;
    private $state;

    public function __construct(MoveRunner $run, MoveState $state)
    {
        $this->run = $run;
        $this->state = $state;
    }

    public function free(array $move, $expect, $intent)
    {
        $from = $move['from'];
        $sc = $move['source_client'];
        $ids = array_column($move['items'], 'id');
        $src = new MoveSource($this->run);
        $live = [];
        foreach ($move['items'] as $item) {
            list(, $meta) = $src->export($from, $sc, $item['id']);
            $live[$item['id']] = $meta;
        }
        $files = $src->files($from, $sc, $ids)['status'];
        $now = isset($files['expect']) ? (string) $files['expect'] : '';
        if ($now !== $expect) {
            throw new CliError("source not freed: its files now total $now, not $expect", "find why it changed (`show <ids> --files` on the source); run {$move['id']} --free-source --expect $now --intent \"...\" only while the move's torrents still seed complete here");
        }
        if (!empty($files['refused']) || !empty($files['unchecked'])) {
            throw new CliError('source not freed: a file is shared with another torrent on the source, or a torrent\'s files could not be listed there',
                'the source keeps its copy; say in your answer which torrents share files (`show <ids> --files` on the source)');
        }
        $command = "php ~/pikkumies/tools/torrent-clients/$sc/$sc.php remove " . implode(' ', $ids) . " --with-data --expect $expect";
        $this->state->intent(['move' => $move['id'], 'from' => $from, 'ids' => $ids, 'sizes' => array_map(function ($m) {
            return $m['bytes'];
        }, $live), 'expect' => $expect, 'intent' => substr(preg_replace('/\s+/', ' ', trim($intent)), 0, 1000)]);
        foreach ($move['items'] as $item) {
            $this->seedingHere($move, $item, $live[$item['id']]);
        }
        $svc = $this->run->service($from);
        if (empty($svc['allow_delete'])) {
            $this->state->log($move['id'], 'free', '', 'not allowed by the source key', ['command' => $command]);
            return ['records' => [['run_on' => $from, 'command' => $command]], 'status' => ['move' => $move['id'], 'freed' => false,
                'why' => "the key for $from was not set up with --allow-delete, so this box cannot delete there; the source is untouched",
                'next' => "the old copy stays on $from: say so in the answer, and that its key has no --allow-delete; do not reach it another way"]];
        }
        try {
            $r = $this->run->unit($from, $sc, array_merge(['remove'], $ids, ['--with-data', '--expect', $expect]), null, 600);
        } catch (CliError $e) {
            $this->state->log($move['id'], 'free', '', 'failed', ['error' => $e->getMessage()]);
            throw new CliError('source not freed: ' . $e->getMessage(), "nothing else was done; the command for $from is: $command");
        }
        $gone = $this->gone($from, $sc, $ids);
        $this->state->log($move['id'], 'free', '', $gone ? 'removed' : 'not verified', ['records' => count($r['records'])]);
        return ['records' => $r['records'], 'status' => ['move' => $move['id'], 'freed' => true, 'verified' => $gone]];
    }

    /** Refuses unless this box's client lists the torrent under the id its fresh .torrent gives, seeding, complete, files here. */
    private function seedingHere(array $move, array $item, array $meta)
    {
        $tid = MoveMeta::idFor($meta, $move['target_client']);
        try {
            $r = $this->run->unit('local', $move['target_client'], ['show', $tid, '--fields', 'id,state,progress,done,size']);
            $t = isset($r['records'][0]) ? $r['records'][0] : [];
        } catch (CliError $e) {
            $t = []; // not listed, or the client does not answer: either way not proven seeding
        }
        $bad = MoveCopy::check($item['target_dir'], $meta['files']);
        if (!isset($t['id']) || $t['id'] !== $tid || $t['state'] !== 'seeding' || (float) $t['progress'] < 1 || $bad) {
            throw new CliError("source not freed: {$item['name']} is not seeding complete here under $tid right now"
                . ($bad ? ' (' . count($bad) . ' files missing or of another size)' : ''), "the source keeps its copy; check it here with `{$move['target_client']} show $tid`");
        }
    }

    private function gone($from, $sc, array $ids)
    {
        foreach ($ids as $id) {
            try {
                $this->run->unit($from, $sc, ['show', $id, '--fields', 'id']);
                return false;
            } catch (CliError $e) {
                if (strpos($e->getMessage(), 'not found:') === false) {
                    return false;
                }
            }
        }
        return true;
    }
}
