<?php
/**
 * DrivesActions: the verbs that start something on a drive. `selftest` asks the drive to test itself (smartctl -t);
 * the result is read from the drive's own log by a later `smart`. `verify` runs the gates (DrivesVerify) and starts a
 * detached write pass. Both take a serial that must equal one this tool lists, and re-resolve the device path from it
 * immediately before acting (device letters move between boots).
 */
class DrivesActions
{
    private $host;
    private $store;

    public function __construct(DrivesHost $host, DrivesStore $store)
    {
        $this->host = $host;
        $this->store = $store;
    }

    /** [disk, parsed SMART, raw document] for exactly one listed disk whose serial equals $serial (any case). */
    private function resolve($serial)
    {
        DrivesRun::needRoot($this->host);
        $found = array_values(array_filter($this->host->disks(), function ($d) use ($serial) {
            return $d['serial'] !== null && strcasecmp($d['serial'], (string) $serial) === 0;
        }));
        if (count($found) !== 1) {
            throw new CliError($found ? "two disks report serial $serial (multipath?)" : "no disk with serial $serial",
                'use a serial exactly as smart lists it; nothing was started');
        }
        $j = $this->host->smart($found[0]['device'], ['-x']);
        if (isset($j['error'])) {
            throw new CliError('smartctl could not read ' . $found[0]['device'] . ': ' . $j['error'], 'nothing was started');
        }
        $d = DrivesSmart::parse($j);
        if ($d['serial'] !== null && strcasecmp($d['serial'], (string) $serial) !== 0) {
            throw new CliError("the device now reports serial {$d['serial']}, not $serial", 'run smart again; nothing was started');
        }
        return [$found[0], $d, $j];
    }

    public function selftest(array $args)
    {
        if (count($args) !== 2 || !in_array($args[1], ['short', 'long'], true)) {
            throw new CliError('selftest takes a serial and short or long', 'selftest <serial> short|long');
        }
        list($disk, $d) = $this->resolve($args[0]);
        $virt = $this->host->virt();
        if ($virt !== 'none') {
            throw new CliError($virt === null ? 'cannot tell whether this machine is a VM' : "this is a VM or container ($virt): its disks belong to the host", 'nothing was started');
        }
        $busy = $this->host->mdBusy();
        if ($busy !== '') {
            throw new CliError("an md array is busy ($busy); a self-test now would slow it and be slowed by it", 'wait until it finishes; nothing was started');
        }
        if ($d['selftest_running']) {
            throw new CliError('a self-test already runs on this drive (' . (int) $d['selftest_remaining'] . '% left)', 'run smart later for its result');
        }
        $r = ToolProc::run($this->host->asRoot(['smartctl', '-t', $args[1], $disk['device']]));
        $j = $this->host->smart($disk['device'], ['-x']);
        $after = DrivesSmart::parse(isset($j['error']) ? [] : $j);
        $key = DrivesStore::key($args[0]);
        $this->store->event($key, ['event' => 'selftest-start', 'test' => $args[1], 'device' => $disk['device'], 'exit' => $r['code']]);
        if (($r['code'] & 7) !== 0 && !$after['selftest_running']) { // smartctl exit bits 0-2: usage, open, command failed
            throw new CliError('smartctl did not start the test: ' . Cli::cleanString(trim($r['out'] . ' ' . $r['err'])), 'run smart for the drive\'s state');
        }
        return ['records' => [['serial' => $d['serial'], 'device' => $disk['device'], 'test' => $args[1], 'running' => $after['selftest_running'],
            'next' => 'run smart later: selftest_last comes from the drive\'s own log; it is not the health flag']],
            'status' => ['verified' => $after['selftest_running']]];
    }

    public function verify(array $args, array $opt)
    {
        if (count($args) !== 1) {
            throw new CliError('verify takes one serial', 'verify <serial> [--with-data] [--fix-unreadable]');
        }
        list($disk, $d) = $this->resolve($args[0]);
        $key = DrivesStore::key($args[0]);
        $dir = $this->store->dir($key);
        if (DrivesVerify::running($dir)) {
            throw new CliError('a write pass already runs on this drive', 'run smart to follow it; one pass per disk');
        }
        $role = DrivesVerify::gates($this->host, $disk, $d, $opt);
        $bs = DrivesVerify::blockSize((int) $d['bytes'] ?: (int) $disk['bytes']);
        $fix = !empty($opt['fix-unreadable']);
        $list = null;
        if ($fix) {
            $end = $this->store->last($key, ['verify-end']);
            if (!$end || ($end['code'] ?? '') !== 'read-errors') {
                throw new CliError('--fix-unreadable follows a completed pass that ended with read errors only', 'run verify first; nothing was started');
            }
            $bs = isset($end['block_size']) ? (int) $end['block_size'] : 1024;
            $list = "$dir/fix.list";
            file_put_contents($list, implode("\n", DrivesVerify::unreadable("$dir/unreadable.list")) . "\n");
        }
        // Re-resolve the device from the serial at the last moment: letters move when a drive drops and returns.
        list($again) = $this->resolve($args[0]);
        if ($again['device'] !== $disk['device']) {
            throw new CliError("the drive moved from {$disk['device']} to {$again['device']} while checking", 'run verify again; nothing was started');
        }
        DrivesVerify::gates($this->host, $again, $d, $opt); // the role again: a mount or an array may have appeared meanwhile
        DrivesVerify::notInUse($this->host, $again);
        $badblocks = $fix ? ['badblocks', '-wv', '-b', (string) $bs, $disk['device']]
            : array_merge(['badblocks', '-nsv'], $bs ? ['-b', (string) $bs] : [], [$disk['device']]);
        list($pid, $started) = DrivesVerify::launch($dir, $this->host->asRoot($badblocks), $list);
        $running = DrivesVerify::running($dir) || is_file("$dir/pass.exit"); // started: still running, or already ended (settled by smart)
        // id links the end event to this start, even when two passes start within one second.
        $this->store->event($key, ['event' => 'verify-start', 'id' => sprintf('%.6f', microtime(true)), 'mode' => $fix ? 'fix' : 'full',
            'device' => $disk['device'], 'role' => $role,
            'with_data' => !empty($opt['with-data']), 'block_size' => $bs ?: 1024, 'pid' => $pid, 'pid_started' => $started, 'pre' => DrivesPass::sample($d)]);
        return ['records' => [['serial' => $d['serial'], 'device' => $disk['device'], 'mode' => $fix ? 'fix-unreadable' : 'full',
            'state' => 'HOLD, write pass running, recoverability undetermined', 'block_size' => $bs ?: 1024, 'pid' => $pid,
            'next' => 'run smart to follow progress; there is no verdict before the pass completes']],
            'status' => ['verified' => $running]];
    }
}
