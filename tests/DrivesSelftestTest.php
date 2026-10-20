<?php
/**
 * drives, phase 2 (ADR-0094): `selftest` starts the drive's own test through smartctl -t, refuses in a VM, while an md
 * array resyncs or checks, while a test already runs, and for an unknown serial; the running state and the result come
 * from the drive's own self-test log, never from the health flag. Root through sudo -n when not uid 0.
 */
require_once __DIR__ . '/fixtures/drives/harness.php';
drRequireUnit();

return [
    'drives selftest: starts smartctl -t on the device resolved from the serial (any case) and reads the running test back' => function () {
        $fx = drFixture();
        list($code, $recs, $status) = drRun($fx, ['selftest', 'tsata0001', 'long']);
        check($code === 0 && $status['verified'] === true && $recs[0]['running'] === true && $recs[0]['device'] === '/dev/sda', json_encode([$recs, $status]));
        check(in_array('-t long /dev/sda', drCalls($fx, 'smartctl'), true), 'smartctl -t long on /dev/sda: ' . json_encode(drCalls($fx, 'smartctl')));
        list(, $recs) = drRun($fx, ['smart', '--serial', 'TSATA0001']);
        check(drRecord($recs, 'TSATA0001')['selftest_last'] === 'running, 90% left', 'smart shows the running test: ' . json_encode(drRecord($recs, 'TSATA0001')));
        list($code, , $status) = drRun($fx, ['selftest', 'TSATA0001', 'short']);
        check($code === 1 && strpos($status['error'], 'already runs') !== false, 'a second test is refused: ' . json_encode($status));
        testRmTree($fx['dir']);
    },
    'drives selftest: refused in a VM, when the VM check cannot answer, while an md array resyncs or checks, and for bad arguments' => function () {
        $fx = drFixture();
        foreach (['kvm' => 'VM or container (kvm)', 'lxc' => 'VM or container (lxc)'] as $virt => $want) {
            list($code, , $status) = drRun($fx, ['selftest', 'TSATA0001', 'short'], ['virt' => $virt]);
            check($code === 1 && strpos($status['error'], $want) !== false, json_encode($status));
        }
        foreach (['check', 'resync', 'recovery'] as $action) {
            file_put_contents($fx['proc'] . '/mdstat', "md0 : active raid1 sdb1[0] sde1[1]\n      976 blocks [2/2] [UU]\n      [==>.......]  $action = 12.5% (1/8) finish=60min\n");
            list($code, , $status) = drRun($fx, ['selftest', 'TSATA0001', 'short']);
            check($code === 1 && strpos($status['error'], "md0 $action") !== false, json_encode($status));
        }
        foreach ([['selftest', 'TSATA0001'], ['selftest', 'TSATA0001', 'conveyance'], ['selftest', 'NOPE', 'short']] as $args) {
            list($code) = drRun($fx, $args);
            check($code === 1, 'refused: ' . implode(' ', $args));
        }
        check(array_filter(drCalls($fx, 'smartctl'), function ($c) {
            return strpos($c, '-t ') === 0;
        }) === [], 'no test was started');
        testRmTree($fx['dir']);
    },
    'drives selftest: a drive that refuses the test is an error with smartctl\'s words, not a started test' => function () {
        $st = drState();
        $st['refuse_test'] = true;
        $fx = drFixture($st);
        list($code, , $status) = drRun($fx, ['selftest', 'TSATA0001', 'short']);
        check($code === 1 && strpos($status['error'], 'Self-test not supported') !== false, json_encode($status));
        testRmTree($fx['dir']);
    },
    'drives selftest: with root through sudo, only smartctl runs under sudo -n; lsblk runs as the account' => function () {
        $fx = drFixture();
        list($code) = drRun($fx, ['selftest', 'TSATA0001', 'short'], ['root' => ['available' => true, 'via' => 'sudo']]);
        check($code === 0, 'selftest via sudo');
        $sudo = drCalls($fx, 'sudo');
        check($sudo && !array_filter($sudo, function ($c) {
            return strpos($c, '-n smartctl ') !== 0;
        }), 'every sudo call is -n smartctl: ' . json_encode($sudo));
        check(drCalls($fx, 'lsblk') !== [], 'lsblk ran as the account');
        testRmTree($fx['dir']);
    },
    'drives selftest: the health flag and the self-test result are separate fields and never stand in for each other' => function () {
        $d = drParse('sata-pending');
        check($d['health'] === 'FAILED' && $d['selftest_short_passed'] === true, 'FAILED health with a passed self-test stays two facts');
        $run = DrivesSelftest::parse(['ata_smart_data' => ['self_test' => ['status' => ['value' => 244]]]], 'ata');
        check($run['selftest_running'] === true && $run['selftest_remaining'] === 40 && $run['selftest_last'] === null, json_encode($run));
        $lba = drParse('sata-samelba');
        check($lba['same_lba_long'] === ['count' => 5, 'lba' => 4242, 'hours' => 600], json_encode($lba['same_lba_long']));
        check(strpos((string) DrivesRules::standingFailure($lba, null), '5 consecutive long self-tests failed at the same LBA') !== false, 'five failures over 600 hours condemn');
        $lba['same_lba_long']['hours'] = 100;
        check(DrivesRules::standingFailure($lba, null) === null, 'five failures in 100 hours are not "over weeks"');
        check(DrivesRules::standingFailure(drParse('sata-clean'), 12.0) !== null && DrivesRules::standingFailure(drParse('sata-clean'), 9.0) === null, 'reported-uncorrectable rate line is 10 per hour');
    },
    'drives root: as uid 0 a privileged step runs directly, through sudo it gets sudo -n, without root it is refused; the live rootAccess check decides' => function () {
        $argv = ['smartctl', '-j', '-x', '/dev/sda'];
        check((new DrivesHost(['root' => ['available' => true, 'via' => 'uid0']]))->asRoot($argv) === $argv, 'uid0: no sudo');
        check((new DrivesHost(['root' => ['available' => true, 'via' => 'sudo']]))->asRoot($argv) === array_merge(['sudo', '-n'], $argv), 'sudo: sudo -n');
        foreach ([['available' => false, 'via' => 'none'], ['available' => false, 'via' => 'uid0']] as $root) {
            $refused = false;
            try {
                (new DrivesHost(['root' => $root]))->asRoot($argv);
            } catch (CliError $e) {
                $refused = strpos($e->getMessage(), 'smartctl needs root') === 0;
            }
            check($refused, 'no root: refused, never run unprivileged: ' . json_encode($root));
        }
        $live = (new DrivesHost())->root();
        if (empty(Config::load()['root']['disabled'])) {
            check($live === Detect::rootAccess(posix_geteuid()), 'without a seam the live rootAccess() check is used: ' . json_encode($live));
        }
        $fx = drFixture();
        drRun($fx, ['verify', 'TSATA0001']); // uid0 seam: badblocks, blkid and fuser run directly
        drWait($fx, 'TSATA0001');
        check(drCalls($fx, 'sudo') === [] && drCalls($fx, 'badblocks') && drCalls($fx, 'blkid') && drCalls($fx, 'fuser'), 'uid0 ran every step without sudo: ' . json_encode(drCalls($fx, 'sudo')));
        testRmTree($fx['dir']);
        $fx = drFixture();
        drRun($fx, ['verify', 'TSATA0001'], ['root' => ['available' => true, 'via' => 'sudo']]);
        drWait($fx, 'TSATA0001');
        $sudo = drCalls($fx, 'sudo');
        foreach (['-n smartctl ', '-n blkid -p ', '-n fuser -s ', '-n badblocks -nsv '] as $want) {
            check((bool) array_filter($sudo, function ($c) use ($want) {
                return strpos($c, $want) === 0;
            }), "sudo path: $want missing in " . json_encode($sudo));
        }
        testRmTree($fx['dir']);
    },
];
