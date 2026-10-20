<?php
/**
 * MoveStepCopy: the copy step of MoveRun and the checks it shares with `add`. The file list comes from the saved .torrent
 * (checked against the id again), never from the move state; the target folder from the state is checked again against
 * the data roots (MoveRoots::targetDir). rsync pulls into the staging folder only; MovePlace puts each file in place
 * without overwriting, and what it placed is saved in the move state at once, also when it stops half way.
 */
class MoveStepCopy
{
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

    public function copy(array &$move, array $item)
    {
        $files = $this->saved($move, $item)['files']; // from the saved .torrent, not from the state file
        $dir = $this->dir($move, $item);
        MoveCopy::folder($item['source_root']); // from the state: checked again (serve checks it too)
        $placed = isset($item['placed']) ? (array) $item['placed'] : [];
        MovePlace::free($dir, $files, $placed);
        $todo = MovePlace::todo($files, $placed, !empty($item['checksum']));
        $stage = MovePlace::staging($dir, $move['id'], $item['n']);
        MoveCopy::makeDir($stage);
        $left = MovePlace::remaining($stage, $todo);
        list($ok, $free) = MoveCopy::room($this->home, $dir, $left);
        if (!$ok) {
            throw new CliError("not copied: $left bytes still to copy and $free free here", 'free space here first; nothing on either box was changed');
        }
        if ($todo) {
            $this->rsync($move, $item, $stage, $todo);
        }
        try {
            MovePlace::place($dir, $stage, $todo, $placed);
        } finally {
            $move['items'][$item['n']]['placed'] = $placed; // what is in place now, also after a failed placement
            $this->state->save($move);
        }
        $bad = MoveCopy::check($dir, $files);
        if ($bad) {
            throw new CliError('copy incomplete: ' . count($bad) . ' files missing or of another size here (first: ' . $bad[0] . ')', "run $move[id] again");
        }
        return ['bytes_copied' => $left];
    }

    private function rsync(array $move, array $item, $stage, array $todo)
    {
        $list = "{$this->tmp}/move-{$move['id']}-{$item['n']}.list";
        if (!is_dir($this->tmp) && !@mkdir($this->tmp, 0700, true)) {
            throw new CliError("cannot create {$this->tmp}", 'check the permissions of ~/pikkumies/local/state');
        }
        file_put_contents($list, MoveCopy::listing($todo));
        @chmod($list, 0600);
        try {
            $r = $this->run->rsync(MoveCopy::argv($this->run->ssh($move['from']), $item['source_root'], $stage, !empty($item['checksum'])), $list);
        } finally {
            @unlink($list);
        }
        if ($r['code'] !== 0) {
            throw new CliError("copy stopped: rsync exit {$r['code']}" . ($r['err'] !== '' ? ': ' . substr($r['err'], -300) : ''), "run $move[id] again: it continues the files it has (--partial)");
        }
        $bad = MoveCopy::check($stage, $todo);
        if ($bad) {
            throw new CliError('copy incomplete: ' . count($bad) . ' files missing or of another size (first: ' . $bad[0] . ')', "run $move[id] again");
        }
    }

    /** The saved .torrent's meta, checked against the id again (the state names the file; the file must be this torrent). */
    public function saved(array $move, array $item)
    {
        $meta = MoveMeta::parse((string) @file_get_contents($item['torrent']));
        if (MoveMeta::idFor($meta, $move['source_client']) !== $item['id']) {
            throw new CliError("the saved .torrent of {$item['id']} is not that torrent", 'plan the move again');
        }
        return $meta;
    }

    /** The target folder from the state, checked again: inside a data root here, no dot-folder. */
    public function dir(array $move, array $item)
    {
        return MoveRoots::targetDir($item['target_dir'], $this->home, MoveRoots::roots($this->run, $move['target_client'], $this->home));
    }
}
