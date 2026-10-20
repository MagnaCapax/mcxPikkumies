<?php
/**
 * drives, phase 3 (ADR-0094): the badblocks summary read back (0/0/0, read errors only, write errors, a pass that never
 * completed), the verdict table, grades only with a qualification, every refusal of `verify` (mounted, holders, swap,
 * md member, an array signature, a serial mismatch, flash, a VM, data without --with-data, a pass already running),
 * and a detached pass end to end with fake badblocks: RECOVERED with a lesson record, a dropped drive, a stall.
 */
require_once __DIR__ . '/fixtures/drives/harness.php';
drRequireUnit();

/** A pass directory holding $log as pass.log, $bb as pass.bb and $exit as pass.exit (null: none). */
function drPassDir($log, $bb = '', $exit = 0)
{
    $dir = testTempDir('pk-drives-pass');
    file_put_contents("$dir/pass.log", $log);
    file_put_contents("$dir/pass.bb", $bb);
    if ($exit !== null) {
        file_put_contents("$dir/pass.exit", "$exit\n");
    }
    return $dir;
}

return [
    'drives verify: the badblocks log is read back: progress, the summary triple, read-only errors, a truncated pass' => function () {
        $bs = str_repeat("\x08", 44);
        $cases = [
            ["Checking...\n  12.50% done, 3:00:00 elapsed. (0/0/0 errors)$bs 99.99% done, 9:00:00 elapsed. (0/0/0 errors)$bs" . "done\nPass completed, 0 bad blocks found. (0/0/0 errors)\n", true, [0, 0, 0], 0],
            ["  50.00% done, 1:00:00 elapsed. (2/0/0 errors)$bs" . "Pass completed, 2 bad blocks found. (2/0/0 errors)\n", true, [2, 0, 0], 2],
            ["Pass completed, 1 bad blocks found. (0/1/0 errors)\n", true, [0, 1, 0], 1],
            ["  33.30% done, 2:00:00 elapsed. (0/0/0 errors)$bs", false, [0, 0, 0], 0],
            ["Pass completed, 3 bad blocks found.\n", true, [3, 0, 0], 3],
        ];
        foreach ($cases as $i => $c) {
            $dir = drPassDir($c[0], '', $c[1] ? 0 : null);
            $p = DrivesVerify::read($dir);
            check($p['completed'] === $c[1] && [$p['r'], $p['w'], $p['c']] === $c[2] && $p['bad'] === $c[3], "case $i: " . json_encode($p));
            testRmTree($dir);
        }
        $dir = drPassDir("  33.30% done, 2:00:00 elapsed. (0/0/0 errors)$bs", str_repeat("123456\n", 160000), 141);
        $p = DrivesVerify::read($dir);
        check($p['bb_over'] === true && $p['progress'] === 0.333 && $p['exit'] === 141 && strpos($p['last_line'], '33.30% done') === 0, json_encode($p));
        testRmTree($dir);
    },
    'drives verify: the verdict table, row by row' => function () {
        $done = ['completed' => true, 'r' => 0, 'w' => 0, 'c' => 0, 'bad' => 0, 'bb_over' => false, 'gone' => false, 'last_line' => ''];
        $s = function ($realloc, $pending, $unc = 0) {
            return ['realloc' => $realloc, 'pending' => $pending, 'uncorrectable' => $unc, 'reported_unc' => 0];
        };
        $rows = [
            'clean, pending cleared' => [$done, $s(0, 140), $s(40, 0), null, 'RECOVERED', 'recovered'],
            'clean, pending falling' => [$done, $s(0, 140), $s(10, 20), null, 'RECOVERED', 'recovered'],
            'write errors' => [['w' => 1] + $done, $s(0, 0), $s(0, 0), null, 'FAILED', 'write-errors'],
            'compare errors' => [['c' => 2] + $done, $s(0, 0), $s(0, 0), null, 'FAILED', 'write-errors'],
            'pending stuck, realloc > 100' => [$done, $s(0, 140), $s(150, 140), null, 'FAILED', 'pending-stuck'],
            'pending cleared, realloc 100+' => [$done, $s(0, 140), $s(120, 0), null, 'HOLD', 'regrow'],
            'second pass after regrow grows again' => [$done, $s(120, 0), $s(125, 0), ['code' => 'regrow', 'ts' => 1, 'surfaced' => false], 'FAILED', 'regrew'],
            'second pass after regrow is flat' => [$done, $s(120, 0), $s(120, 0), ['code' => 'regrow', 'ts' => 1, 'surfaced' => false], 'RECOVERED', 'recovered'],
            'read errors only' => [['r' => 2, 'bad' => 2] + $done, $s(0, 8), $s(0, 8), null, 'HOLD', 'read-errors'],
            'new defects two passes running' => [$done, $s(10, 5), $s(15, 0), ['code' => 'recovered', 'ts' => 1000, 'surfaced' => true], 'FAILED', 'surfaced-twice'],
            'pending unmoved' => [$done, $s(0, 8), $s(0, 8), null, 'HOLD', 'pending-unmoved'],
            'no sample' => [$done, null, null, null, 'HOLD', 'no-sample'],
            'never completed' => [['completed' => false, 'last_line' => 'Too big to test.'] + $done, $s(0, 0), $s(0, 0), null, 'INCOMPLETE', 'incomplete'],
            'list ran away' => [['completed' => false, 'bb_over' => true] + $done, $s(0, 0), null, null, 'INCOMPLETE', 'link'],
            'drive gone' => [['completed' => false, 'gone' => true] + $done, $s(0, 0), null, null, 'INCOMPLETE', 'link'],
            'stalled on write errors, drive present' => [['completed' => false, 'w' => 5] + $done, $s(0, 0), $s(0, 0), null, 'FAILED', 'stalled-write'],
            'SAS: no pending counter' => [$done, $s(12, null), $s(12, null), null, 'RECOVERED', 'recovered'],
        ];
        foreach ($rows as $name => $r) {
            $v = DrivesRules::verdict($r[0], $r[1], $r[2], $r[3], 2000);
            check($v[0] === $r[4] && $v[2] === $r[5], "$name: got $v[0]/$v[2] ($v[1])");
            check($v[0] === 'FAILED' || !preg_match('/\b(dead|replace)\b/i', $v[1]), "$name: HOLD words only: $v[1]");
        }
        $v = DrivesRules::verdict($done, $s(10, 5), $s(15, 0), ['code' => 'recovered', 'ts' => 1000, 'surfaced' => true], 1000 + 31 * 86400);
        check($v[0] === 'RECOVERED', 'two surfacing passes more than 30 days apart are not consecutive: ' . $v[0]);
    },
    'drives verify: a letter grade only after a completed write pass; no pass is "untested" whatever SMART says; FAILED is BIN; C is heavy wear' => function () {
        $clean = drParse('sata-clean');
        foreach ([$clean, ['poh' => 12] + $clean, ['poh' => 5000] + $clean] as $d) {
            list($grade, $why) = DrivesRules::grade($d, null);
            check($grade === 'untested' && strpos($why, 'completed write pass') !== false, "no write pass, no letter (poh {$d['poh']}): $grade $why");
        }
        check(DrivesRules::grade($clean, 'RECOVERED')[0] === 'A', 'a RECOVERED clean drive is A');
        check(DrivesRules::grade(['crc' => 3] + $clean, 'RECOVERED')[0] === 'B', 'CRC-only is B');
        check(DrivesRules::grade(['realloc' => 1500] + $clean, 'RECOVERED')[0] === 'C', 'realloc 1500 is C');
        check(DrivesRules::grade(['uncorrectable' => 100] + $clean, 'RECOVERED')[0] === 'C', 'uncorrectable 100 is C');
        check(DrivesRules::grade($clean, 'FAILED')[0] === 'BIN', 'FAILED is BIN');
        check(DrivesRules::grade($clean, 'HOLD')[0] === 'undetermined' && DrivesRules::grade($clean, 'INCOMPLETE')[0] === 'undetermined', 'no letter while undecided');
        check(DrivesRules::grade(drParse('sas'), 'RECOVERED')[0] === 'B', 'SAS with grown defects is B');
        check(DrivesRules::letter('A') && DrivesRules::letter('BIN') && !DrivesRules::letter('untested') && !DrivesRules::letter(null), 'letters are A, B, C, BIN');
    },
    'drives verify: a new drive with a passed short self-test and zero counters stays untested in smart: no grade event, no lesson' => function () {
        $fx = drFixture();
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        $r = drRecord($recs, 'TSATA0001');
        check($r['grade'] === 'untested' && strpos($r['next'], 'verify TSATA0001') !== false, json_encode($r));
        check(!array_filter($recs, function ($x) {
            return isset($x['lesson']);
        }), 'no lesson without a write pass');
        check(!is_file($fx['local'] . '/drives/TSATA0001/events.jsonl') || strpos(file_get_contents($fx['local'] . '/drives/TSATA0001/events.jsonl'), '"grade"') === false, 'no grade event');
        list($code, , $status) = drRun($fx, ['smart', '--new-hours', '5000']);
        check($code === 2 && strpos($status['error'], 'unknown option') !== false, 'the new-drive line is gone: ' . json_encode($status));
        testRmTree($fx['dir']);
    },
    'drives verify: a RECOVERED drive read while asleep keeps its grade; no grade flapping, one lesson, unique lesson files' => function () {
        $fx = drFixture();
        drRun($fx, ['verify', 'TSATA0001']);
        drWait($fx, 'TSATA0001');
        $grades = [];
        $lessons = 0;
        foreach (['sata-clean', 'standby', 'sata-clean', 'standby', 'sata-clean'] as $doc) {
            $st = drState2($fx);
            $st['smart']['/dev/sda'] = $doc;
            file_put_contents($fx['dir'] . '/state.json', json_encode($st));
            list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
            $grades[] = drRecord($recs, 'TSATA0001')['grade'];
            $lessons += count(array_filter($recs, function ($x) {
                return isset($x['lesson']);
            }));
        }
        check($grades === ['A', 'A', 'A', 'A', 'A'] && $lessons === 1, 'grades ' . json_encode($grades) . ", lessons $lessons");
        $events = substr_count(file_get_contents($fx['local'] . '/drives/TSATA0001/events.jsonl'), '"event":"grade"');
        check($events === 1, "grade events: $events");
        $store = new DrivesStore($fx['local'], []);
        $a = DrivesLesson::record($store, 'TSATA0001', ['verdict' => 'RECOVERED', 'grade' => 'A']);
        $b = DrivesLesson::record($store, 'TSATA0001', ['verdict' => 'RECOVERED', 'grade' => 'B']);
        check($a['body_file'] !== $b['body_file'], 'two lessons in one second get two body files');
        testRmTree($fx['dir']);
    },
    'drives verify: block size 4096 above 2 TB, 8192 from 16 TB, the default below; too large is refused' => function () {
        check(DrivesVerify::blockSize(1000204886016) === null && DrivesVerify::blockSize(4000787030016) === 4096 && DrivesVerify::blockSize(18000207937536) === 8192, 'sizes');
        $refused = false;
        try {
            DrivesVerify::blockSize(40e12);
        } catch (CliError $e) {
            $refused = true;
        }
        check($refused, '40 TB does not fit a 32-bit block counter at 8192');
    },
    'drives verify: refused for an md member, an unassembled array signature, mounted, swap, holders, flash, a VM, a serial mismatch, data without --with-data' => function () {
        $st = drState();
        $st['disks'][] = ['name' => 'sde', 'serial' => 'TSIG0001', 'size' => 8001563222016, 'rota' => '1', 'type' => 'disk', 'fstype' => 'linux_raid_member', 'pttype' => null, 'mountpoint' => null];
        $st['smart']['/dev/sde'] = 'sata-clean';
        $st['serial']['/dev/sde'] = 'TSIG0001';
        $st['blkid']['/dev/sde'] = 'TYPE=linux_raid_member';
        $fx = drFixture($st);
        file_put_contents($fx['proc'] . '/mdstat', "md0 : active raid1 sdb1[0]\n      976 blocks [2/1] [U_]\n");
        $cases = [
            [['verify', 'TSAS0001'], [], 'live array member (sdb1 is in md0)', 'check scrub'],
            [['verify', 'TSIG0001', '--with-data'], [], 'linux_raid_member signature', 'mdadm --examine'],
            [['verify', 'TNVME0001'], [], 'not a spinning disk', 'smart and selftest only'],
            [['verify', 'TSATA0002'], [], 'holds data (signature: ext4)', '--with-data'],
            [['verify', 'TSATA0001'], ['virt' => 'kvm'], 'VM or container (kvm)', 'nothing was started'],
            [['verify', 'TSATA0001', '--fix-unreadable'], [], 'writes over the unreadable blocks', '--with-data'],
        ];
        foreach ($cases as $c) {
            list($code, , $status) = drRun($fx, $c[0], $c[1]);
            check($code === 1 && strpos($status['error'], $c[2]) !== false && strpos($status['error'] . ' ' . $status['hint'], $c[3]) !== false && isset($status['stop']), implode(' ', $c[0]) . ': ' . json_encode($status));
        }
        file_put_contents($fx['proc'] . '/self/mounts', "/dev/sda /mnt ext4 rw 0 0\n");
        list(, , $status) = drRun($fx, ['verify', 'TSATA0001']);
        check(strpos($status['error'], 'in use (/dev/sda is mounted)') !== false, json_encode($status));
        file_put_contents($fx['proc'] . '/self/mounts', '');
        file_put_contents($fx['proc'] . '/swaps', "Filename Type Size Used Priority\n/dev/sda partition 1 0 -2\n");
        list(, , $status) = drRun($fx, ['verify', 'TSATA0001']);
        check(strpos($status['error'], 'in use (/dev/sda is swap)') !== false, json_encode($status));
        file_put_contents($fx['proc'] . '/swaps', '');
        mkdir($fx['sys'] . '/block/sda/holders/dm-0', 0700, true);
        list(, , $status) = drRun($fx, ['verify', 'TSATA0001']);
        check(strpos($status['error'], 'sda is held by dm-0') !== false, json_encode($status));
        rmdir($fx['sys'] . '/block/sda/holders/dm-0');
        $state = drState2($fx);
        $state['smart']['/dev/sda'] = 'sata-pending'; // the device now answers with another drive's serial
        file_put_contents($fx['dir'] . '/state.json', json_encode($state));
        list(, , $status) = drRun($fx, ['verify', 'TSATA0001']);
        check(strpos($status['error'], 'now reports serial TSATA0002') !== false, json_encode($status));
        check(drCalls($fx, 'badblocks') === [], 'no pass was started by any refusal');
        testRmTree($fx['dir']);
    },
    'drives verify: a pass runs detached as HOLD, one per disk, then the next smart gives RECOVERED, a grade and a lesson record' => function () {
        $st = drState();
        $st['badblocks'] = ['mode' => 'clean', 'sleep' => 1.5, 'after' => ['5' => 3]];
        $fx = drFixture($st);
        list($code, $recs, $status) = drRun($fx, ['verify', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        check($code === 0 && $status['verified'] === true && $recs[0]['block_size'] === 4096 && strpos($recs[0]['state'], 'HOLD') === 0, json_encode([$recs, $status]));
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        check($code === 1 && strpos($status['error'], 'already runs') !== false, 'one pass per disk: ' . json_encode($status));
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        $r = drRecord($recs, 'TSATA0001');
        check($r['verdict'] === 'HOLD' && strpos($r['verify'], 'recoverability undetermined') !== false && $r['grade'] === 'undetermined', 'HOLD while running: ' . json_encode($r));
        drWait($fx, 'TSATA0001');
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        $r = drRecord($recs, 'TSATA0001');
        check($r['verdict'] === 'RECOVERED' && $r['realloc'] === 3 && $r['grade'] === 'B', json_encode($r));
        $lesson = array_values(array_filter($recs, function ($x) {
            return isset($x['lesson']);
        }));
        check(count($lesson) === 1 && strpos($lesson[0]['lesson'], 'verify RECOVERED') !== false && strpos($lesson[0]['save'], "'--topic' 'hardware'") !== false
            && strpos($lesson[0]['save'], 'drives.php') !== false, json_encode($lesson));
        check(drCalls($fx, 'badblocks') === ['-nsv -b 4096 /dev/sda'], 'badblocks -nsv -b 4096, never -w or -f or -o: ' . json_encode(drCalls($fx, 'badblocks')));
        check(in_array('-n badblocks -nsv -b 4096 /dev/sda', drCalls($fx, 'sudo'), true), 'badblocks runs under sudo -n');
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        check(!array_filter($recs, function ($x) {
            return isset($x['lesson']);
        }), 'the lesson record comes once, when the verdict lands');
        $events = array_column(array_map('json_decode', file($fx['local'] . '/drives/TSATA0001/events.jsonl'), array_fill(0, 3, true)), 'event');
        check($events === ['verify-start', 'verify-end', 'grade'], json_encode($events));
        testRmTree($fx['dir']);
    },
    'drives verify: a drive that drops (the list runs past 1 MiB) is INCOMPLETE; a pass stalled on write errors with the drive present is FAILED' => function () {
        foreach (['drop' => 'INCOMPLETE', 'stall' => 'FAILED', 'write' => 'FAILED', 'read' => 'HOLD'] as $mode => $want) {
            $st = drState();
            $st['badblocks'] = ['mode' => $mode, 'sleep' => 0];
            $fx = drFixture($st);
            list($code) = drRun($fx, ['verify', 'TSATA0001']);
            check($code === 0, "$mode: started");
            drWait($fx, 'TSATA0001');
            list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
            $r = drRecord($recs, 'TSATA0001');
            check($r['verdict'] === $want, "$mode: " . json_encode($r));
            check($mode !== 'drop' || filesize($fx['local'] . '/drives/TSATA0001/pass.bb') <= DrivesVerify::BB_MAX + 1, 'the list is capped');
            check($mode !== 'read' || is_file($fx['local'] . '/drives/TSATA0001/unreadable.list'), 'read errors keep the unreadable list');
            testRmTree($fx['dir']);
        }
    },
    'drives verify: a drive missing from the listing when its pass ended is settled INCOMPLETE (link), with a record and a lesson' => function () {
        $st = drState();
        $st['badblocks'] = ['mode' => 'drop', 'sleep' => 0];
        $fx = drFixture($st);
        drRun($fx, ['verify', 'TSATA0001']);
        drWait($fx, 'TSATA0001');
        $state = drState2($fx);
        array_shift($state['disks']); // sda dropped off the bus
        file_put_contents($fx['dir'] . '/state.json', json_encode($state));
        list(, $recs) = drRun($fx, ['smart']);
        $r = drRecord($recs, 'TSATA0001');
        check($r !== null && $r['verdict'] === 'INCOMPLETE' && strpos($r['next'], 'cable') !== false, json_encode($r));
        testRmTree($fx['dir']);
    },
    'drives verify: a drive asleep when its pass has ended keeps HOLD; the verdict waits for a real SMART reading' => function () {
        $fx = drFixture();
        drRun($fx, ['verify', 'TSATA0001']);
        drWait($fx, 'TSATA0001');
        $state = drState2($fx);
        $state['smart']['/dev/sda'] = 'standby';
        file_put_contents($fx['dir'] . '/state.json', json_encode($state));
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        $r = drRecord($recs, 'TSATA0001');
        check($r['verdict'] === 'HOLD' && strpos($r['verify'], 'waits for a SMART reading') !== false, 'asleep: ' . json_encode($r));
        $state['smart']['/dev/sda'] = 'sata-clean';
        file_put_contents($fx['dir'] . '/state.json', json_encode($state));
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        check(drRecord($recs, 'TSATA0001')['verdict'] === 'RECOVERED', 'awake: ' . json_encode(drRecord($recs, 'TSATA0001')));
        testRmTree($fx['dir']);
    },
    'drives verify: a process holding the raw disk (fuser, as root) stops the pass; the check covers the disk and its partitions' => function () {
        $st = drState();
        $st['busy'] = ['/dev/sda'];
        $fx = drFixture($st);
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        check($code === 1 && strpos($status['error'], 'a process has /dev/sda or one of its partitions open') !== false, json_encode($status));
        check(in_array('-n fuser -s /dev/sda', drCalls($fx, 'sudo'), true), 'fuser runs as root: ' . json_encode(drCalls($fx, 'sudo')));
        check(drCalls($fx, 'badblocks') === [], 'no pass');
        $st = drState();
        $st['disks'][0]['children'] = [['name' => 'sda1', 'fstype' => null, 'mountpoint' => null]];
        $st['busy'] = ['/dev/sda1'];
        $fx2 = drFixture($st);
        list($code, , $status) = drRun($fx2, ['verify', 'TSATA0001', '--with-data']);
        check($code === 1 && strpos($status['error'], 'open') !== false && in_array('-s /dev/sda /dev/sda1', drCalls($fx2, 'fuser'), true), json_encode([$status, drCalls($fx2, 'fuser')]));
        unlink($fx['bin'] . '/fuser');
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001']);
        check($code === 1 && strpos($status['error'], 'fuser is not installed') !== false, 'no check, no pass: ' . json_encode($status));
        testRmTree($fx['dir']);
        testRmTree($fx2['dir']);
    },
    'drives verify: signatures are probed as root with blkid -p, so a member the udev view calls blank is still refused' => function () {
        $st = drState();
        $st['blkid']['/dev/sda'] = 'TYPE=linux_raid_member';
        $fx = drFixture($st);
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        check(drRecord($recs, 'TSATA0001')['role'] === 'blank', 'smart keeps the udev view (it never wakes a disk to probe it)');
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001', '--with-data']);
        check($code === 1 && strpos($status['error'], 'linux_raid_member') !== false, json_encode($status));
        check(in_array('-p -o export /dev/sda', drCalls($fx, 'blkid'), true), 'blkid -p ran: ' . json_encode(drCalls($fx, 'blkid')));
        $st = drState();
        $st['blkid']['/dev/sda'] = 8;
        $fx2 = drFixture($st);
        list($code, , $status) = drRun($fx2, ['verify', 'TSATA0001', '--with-data']);
        check($code === 1 && strpos($status['error'], 'blkid') !== false, 'an ambivalent or failed probe refuses: ' . json_encode($status));
        testRmTree($fx['dir']);
        testRmTree($fx2['dir']);
    },
    'drives verify: the role is checked again at the last re-resolve; a disk that gained a mount in between is refused' => function () {
        $st = drState();
        $flip = $st['disks'];
        $flip[0]['mountpoint'] = '/mnt/new';
        $st['flip'] = ['call' => 2, 'disks' => $flip];
        $fx = drFixture($st);
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0001']);
        check($code === 1 && strpos($status['error'], 'in use') !== false, json_encode($status));
        check(drCalls($fx, 'badblocks') === [], 'no pass');
        testRmTree($fx['dir']);
    },
    'drives verify: the --with-data question names the risk of a program still writing to the disk' => function () {
        $fx = drFixture();
        list($code, , $status) = drRun($fx, ['verify', 'TSATA0002']);
        check($code === 1 && strpos($status['error'] . $status['hint'], 'still writes') !== false, json_encode($status));
        testRmTree($fx['dir']);
    },
];
