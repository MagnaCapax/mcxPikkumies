<?php
/**
 * MoveTarget: the steps of MoveRun on this box's client after the copy. recheck: asks the client to hash the copied
 * data (unless it is checking already) and polls until it is done: 100 % moves on; less stops with MoveBadData, so the
 * next run copies again with checksums and checks again; a check that outlasts 300 s plus the size at HASH_RATE stops
 * too (the next run waits for the same check). Each poll is a line in the move log. resume: starts it and waits up
 * to two minutes for it to seed, complete, under the id the .torrent gives. The source is never touched here.
 */
class MoveTarget
{
    const HASH_RATE = 20971520; // bytes/s a busy disk still hashes

    private $run;
    private $state;

    public function __construct(MoveRunner $run, MoveState $state)
    {
        $this->run = $run;
        $this->state = $state;
    }

    public function recheck(array $move, array $item)
    {
        $tc = $move['target_client'];
        $wait = 300 + (int) ceil($item['bytes'] / self::HASH_RATE);
        $every = max(5, min(60, (int) ceil($wait / 200)));
        $t = $this->live($move, $item);
        if ($t['state'] !== 'checking') {
            $this->run->unit('local', $tc, ['recheck', $item['target_id']]);
        }
        $start = $this->run->now();
        $seen = $t['state'] === 'checking';
        while (true) {
            $this->run->sleep($every);
            $t = $this->live($move, $item);
            $seen = $seen || $t['state'] === 'checking';
            $this->state->log($move['id'], 'recheck-poll', $item['id'], $t['state'], ['progress' => $t['progress']]);
            if ($t['state'] !== 'checking' && (float) $t['progress'] >= 1) {
                return ['progress' => 1];
            }
            $elapsed = $this->run->now() - $start;
            if ($t['state'] !== 'checking' && ($seen || $elapsed >= 60)) {
                throw new MoveBadData("recheck ended at " . round($t['progress'] * 100, 2) . " %: the copy here is not complete; the source is kept and the torrent stays paused here",
                    "run $move[id] again: it copies the differences with checksums, then checks again");
            }
            if ($elapsed >= $wait) {
                throw new CliError("still checking after {$elapsed}s; the source is kept", "run $move[id] again later: it waits for the same check");
            }
        }
    }

    public function resume(array $move, array $item)
    {
        $this->run->unit('local', $move['target_client'], ['resume', $item['target_id']]);
        for ($n = 0; $n < 12; $n++) {
            $t = $this->live($move, $item);
            if ($t['state'] === 'seeding' && (float) $t['progress'] >= 1) {
                return ['state' => 'seeding'];
            }
            $this->run->sleep(10);
        }
        throw new CliError("{$item['name']} is {$t['state']} here, not seeding; the source is kept", "check it with `{$move['target_client']} show {$item['target_id']}`, then run $move[id] again");
    }

    /** The target client's live record of the torrent, by the id its .torrent gives. */
    public function live(array $move, array $item)
    {
        $r = $this->run->unit('local', $move['target_client'], ['show', $item['target_id'], '--fields', 'id,state,progress,done,size']);
        $t = isset($r['records'][0]) ? $r['records'][0] : [];
        if (!isset($t['id']) || $t['id'] !== $item['target_id']) {
            throw new CliError("{$item['name']} is not listed by {$move['target_client']} here under {$item['target_id']}", "run $move[id] again");
        }
        return $t;
    }
}
