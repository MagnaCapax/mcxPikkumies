<?php
/**
 * DrivesPass: a write pass after it started. state() says whether one runs (HOLD) and how far it got; settle() turns
 * a pass that has ended into its verdict, once, with a SMART sample taken now as the "after" side, and records it as
 * a verify-end event. A drive that is no longer listed when its pass ended is settled as gone (INCOMPLETE, link).
 */
class DrivesPass
{
    private $store;

    public function __construct(DrivesStore $store)
    {
        $this->store = $store;
    }

    /** The counters a verdict compares before and after a pass. */
    public static function sample(array $d)
    {
        return ['realloc' => $d['realloc'], 'pending' => $d['pending'], 'uncorrectable' => $d['uncorrectable'], 'reported_unc' => $d['reported_unc']];
    }

    /** The open pass's start event (started and not yet settled), or null. */
    public function open($key)
    {
        $start = $this->store->last($key, ['verify-start']);
        $end = $this->store->last($key, ['verify-end']);
        return $start && !($end && isset($end['start'], $start['id']) && $end['start'] === $start['id']) ? $start : null;
    }

    /** ['running' => bool, 'verify' => text or null] for the record. */
    public function state($key)
    {
        $dir = $this->store->dir($key);
        if (DrivesVerify::running($dir)) {
            $p = DrivesVerify::read($dir);
            return ['running' => true, 'verify' => 'HOLD, write pass running, recoverability undetermined'
                . ($p['progress'] !== null ? ', ' . round($p['progress'] * 100, 1) . '%' : '') . " ({$p['r']}/{$p['w']}/{$p['c']} read/write/compare errors)"];
        }
        if ($this->open($key)) {
            return ['running' => false, 'verify' => 'the pass has ended; its verdict waits for a SMART reading of the drive'];
        }
        $end = $this->store->last($key, ['verify-end']);
        return ['running' => false, 'verify' => $end ? 'last verify ' . $end['verdict'] . ' on ' . gmdate('Y-m-d', (int) $end['ts']) : null];
    }

    /**
     * Decides an ended pass. $d: the drive's parsed SMART now, or null when it is not listed (gone). True when a
     * verdict was recorded by this call.
     */
    public function settle($key, array $d = null)
    {
        $start = $this->open($key);
        $dir = $this->store->dir($key);
        if ($start === null || DrivesVerify::running($dir)) {
            return false;
        }
        $post = $d !== null && array_filter(self::sample($d), 'is_int') ? self::sample($d) : null;
        if ($d !== null && $post === null) {
            return false; // listed but not read (asleep, or smartctl failed): the verdict waits for a real after-sample
        }
        $run = DrivesVerify::read($dir) + ['gone' => $d === null];
        $pre = isset($start['pre']) && is_array($start['pre']) ? $start['pre'] : null;
        $prev = $this->store->last($key, ['verify-end']);
        if (($start['mode'] ?? 'full') === 'fix') {
            $v = (int) $run['bad'] > 0 || $run['w'] > 0 || $run['c'] > 0
                ? ['FAILED', 'media: blocks the drive could not write (a write-failure list)', 'fix-failed']
                : ($run['completed'] && !$run['gone'] ? ['HOLD', 'the unreadable blocks were rewritten; run a full verify pass again', 'fixed']
                    : ['INCOMPLETE', 'no verdict, the rewrite ended early (' . $run['last_line'] . ')', 'incomplete']);
        } else {
            $v = DrivesRules::verdict($run, $pre, $post, $prev, time());
        }
        if ($v[2] === 'read-errors') {
            @copy("$dir/pass.bb", "$dir/unreadable.list");
        }
        $this->store->event($key, ['event' => 'verify-end', 'start' => $start['id'] ?? null, 'mode' => $start['mode'] ?? 'full',
            'verdict' => $v[0], 'reason' => $v[1], 'code' => $v[2], 'read_errors' => $run['r'], 'write_errors' => $run['w'],
            'compare_errors' => $run['c'], 'bad_blocks' => $run['bad'], 'bb_bytes' => $run['bb_bytes'], 'exit' => $run['exit'],
            'surfaced' => DrivesRules::surfaced($run, $pre, $post), 'pre' => $pre, 'post' => $post, 'block_size' => $start['block_size'] ?? null]);
        return true;
    }
}
