<?php
/**
 * drives, phase 1 (ADR-0094): the smartctl JSON parser for SATA, SAS and NVMe, missing fields kept unknown, the screen
 * glyph, and the `smart` verb end to end on a fake machine (fake lsblk and smartctl; no disk is touched): worst first,
 * the array role, the --human table, a sleeping disk not woken, history samples, and the refusal without root.
 */
require_once __DIR__ . '/fixtures/drives/harness.php';
drRequireUnit();

return [
    'drives smart: SATA clean parses every counter as a measured zero, health and the self-test log apart, screen green' => function () {
        $d = drParse('sata-clean');
        check($d['protocol'] === 'sata' && $d['serial'] === 'TSATA0001' && $d['bytes'] === 8001563222016 && $d['rotational'] === true, json_encode($d));
        foreach (['realloc', 'pending', 'uncorrectable', 'reported_unc', 'crc', 'error_log', 'selftest_failures'] as $k) {
            check($d[$k] === 0, "$k must be a measured 0, got " . var_export($d[$k], true));
        }
        check($d['health'] === 'PASSED' && $d['selftest_last'] === 'Short offline: Completed without error' && $d['selftest_short_passed'] === true, json_encode($d));
        check($d['wwn'] === '0x5000c5000012d687' && $d['poh'] === 1200, 'wwn or poh: ' . $d['wwn'] . ' ' . $d['poh']);
        check(DrivesRules::screen($d)[0] === '🟢', 'clean SATA must screen green');
    },
    'drives smart: SATA with pending, reallocated and a FAILING_NOW flag is a heavy or red screen, never a verdict' => function () {
        $d = drParse('sata-pending');
        check($d['pending'] === 140 && $d['realloc'] === 8 && $d['uncorrectable'] === 140 && $d['reported_unc'] === 3, json_encode($d));
        check($d['failing_now'] === ['Reallocated_Sector_Ct'] && $d['health'] === 'FAILED' && $d['error_log'] === 12, json_encode($d));
        check($d['selftest_last'] === 'Short offline: Completed without error', 'the self-test result is not the health flag');
        check(DrivesRules::screen($d)[0] === '🔴', 'health FAILED screens red');
        $d['health'] = 'PASSED';
        $d['uncorrectable'] = 0;
        check(DrivesRules::screen($d)[0] === '🟠', 'pending 140 alone screens heavy');
        $d['pending'] = 99;
        check(DrivesRules::screen($d)[0] === '🟡', 'pending 99 is below the heavy line');
        $d['realloc'] = 1000;
        check(DrivesRules::screen($d)[0] === '🟠', 'realloc 1000 screens heavy');
        list($grade) = DrivesRules::grade($d, null);
        check($grade === 'untested', 'a screen never grades');
    },
    'drives smart: SAS maps grown defects to realloc and uncorrected errors to uncorrectable; pending and CRC stay unknown' => function () {
        $d = drParse('sas');
        check($d['protocol'] === 'sas' && $d['serial'] === 'tsas0001' && $d['model'] === 'TEST SAS10T', json_encode($d));
        check($d['realloc'] === 12 && $d['uncorrectable'] === 3 && $d['pending'] === null && $d['crc'] === null, json_encode($d));
        check($d['selftest_last'] === 'Background short: Completed' && $d['selftest_failures'] === 1 && $d['selftest_short_passed'] === true, json_encode($d));
        check(DrivesRules::screen($d)[0] === '🟡', 'SAS with defects screens yellow');
    },
    'drives smart: NVMe reports media errors as uncorrectable, is not rotational and has its own self-test log' => function () {
        $d = drParse('nvme');
        check($d['protocol'] === 'nvme' && $d['rotational'] === false && $d['uncorrectable'] === 0 && $d['critical_warning'] === 0, json_encode($d));
        check($d['realloc'] === null && $d['pending'] === null && $d['selftest_short_passed'] === true && $d['selftest_last'] === 'Short: Completed without error', json_encode($d));
        check(DrivesRules::screen($d)[0] === '🟢', 'clean NVMe screens green');
    },
    'drives smart: a field the drive does not report is null, never zero, and keeps a qualified drive from Grade A' => function () {
        $d = drParse('sata-missing');
        check($d['reported_unc'] === null && $d['crc'] === null && $d['realloc'] === 0, json_encode($d));
        list($grade, $why) = DrivesRules::grade($d, 'RECOVERED');
        check($grade === 'B' && strpos($why, 'not every counter') !== false, "$grade: $why");
        list($grade) = DrivesRules::grade(drParse('sata-clean'), 'RECOVERED');
        check($grade === 'A', "fully measured clean drive: $grade");
    },
    'drives smart: a sleeping disk is reported as standby, not woken, and screens white with no counters' => function () {
        $d = drParse('standby');
        check($d['standby'] === true && $d['serial'] === null && $d['realloc'] === null, json_encode($d));
        check(DrivesRules::screen($d)[0] === '⚪', 'nothing measured screens white');
    },
    'drives smart: the verb lists every disk worst first with its role, keeps state per serial, and says it is a screen' => function () {
        $fx = drFixture();
        list($code, $recs, $status) = drRun($fx, ['smart']);
        check($code === 0 && $status['ok'] === true && strpos($status['note'], 'not a verdict') !== false, json_encode($status));
        $disks = array_values(array_filter($recs, function ($r) {
            return isset($r['screen']);
        }));
        check(array_column($disks, 'serial') === ['TSATA0002', 'tsas0001', 'TSTBY0001', 'TNVME0001', 'TSATA0001'], 'order: ' . json_encode(array_column($disks, 'serial')));
        $roles = array_combine(array_column($disks, 'device'), array_column($disks, 'role'));
        check($roles === ['/dev/sdd' => 'data', '/dev/sdb' => 'array-member', '/dev/sdc' => 'blank', '/dev/nvme0n1' => 'mounted', '/dev/sda' => 'blank'], json_encode($roles));
        check(strpos(drRecord($recs, 'TSAS0001')['next'], 'check scrub') !== false, 'an array member is routed to its own array');
        check(strpos(drRecord($recs, 'TSATA0002')['next'], 'verify TSATA0002 --with-data') !== false, 'a disk with data is routed to verify --with-data');
        check(is_file($fx['local'] . '/drives/TSAS0001/smart.json') && !is_file($fx['local'] . '/drives/TSTBY0001/smart.json'), 'state is per upper-cased serial; nothing for a sleeping disk');
        check(substr(sprintf('%o', fileperms($fx['local'] . '/drives/TSAS0001')), -3) === '700', 'drive directories are 0700');
        foreach (drCalls($fx, 'smartctl') as $call) {
            check(strpos($call, '-j -x -n standby /dev/') === 0, "every read is -n standby: $call");
        }
        testRmTree($fx['dir']);
    },
    'drives smart: --human prints the table with the glyph column aligned; --serial picks one drive in any case' => function () {
        $fx = drFixture();
        list($code, , , $out) = drRun($fx, ['smart', '--human']);
        $lines = explode("\n", $out);
        check($code === 0 && strpos($lines[0], 'screen  device') === 0 && strpos($out, 'not a verdict') !== false, $out);
        check(strpos($lines[1], '🔴      /dev/sdd') === 0, 'the glyph counts two columns: ' . $lines[1]);
        check(strpos($out, "\033") === false, 'no ANSI codes: the glyph is the colour');
        list($code, $recs) = drRun($fx, ['smart', '--serial', 'tsata0001']);
        check($code === 0 && count(array_filter($recs, function ($r) {
            return isset($r['screen']);
        })) === 1, 'one drive');
        list($code, , $status) = drRun($fx, ['smart', '--serial', 'NOPE']);
        check($code === 1 && strpos($status['error'], 'no disk with serial') !== false, json_encode($status));
        testRmTree($fx['dir']);
    },
    'drives smart: without root every verb refuses with a hint and runs nothing' => function () {
        $fx = drFixture();
        foreach ([['smart'], ['selftest', 'TSATA0001', 'short'], ['verify', 'TSATA0001']] as $args) {
            list($code, , $status) = drRun($fx, $args, ['root' => ['available' => false, 'via' => 'none']]);
            check($code === 1 && strpos($status['error'], 'needs root') !== false && strpos($status['hint'], 'root.available') !== false, json_encode($status));
        }
        check(drCalls($fx, 'smartctl') === [] && drCalls($fx, 'badblocks') === [], 'nothing ran');
        testRmTree($fx['dir']);
    },
    'drives smart: history samples go to the telemetry seam under a stable source name; a symlinked state directory is refused' => function () {
        $dir = testTempDir('pk-drives-store');
        $got = [];
        $store = new DrivesStore($dir, ['record' => function ($source, $fields) use (&$got) {
            $got[] = [$source, $fields];
        }, 'series' => function () {
            return [['ts' => 1000, 'min' => 0, 'max' => 0]];
        }]);
        $store->sample('TSATA0001', drParse('sata-pending'));
        check($got[0][0] === 'drive-tsata0001' && $got[0][1] === ['poh' => 40000, 'realloc' => 8, 'pending' => 140, 'uncorrectable' => 140, 'reported_unc' => 3, 'crc' => 0], json_encode($got));
        check($store->uncPerHour('TSATA0001', 0) === null, 'one sample is insufficient history');
        check(DrivesStore::source(str_repeat('A', 40)) === 'drive-' . substr(sha1(str_repeat('A', 40)), 0, 16), 'a long serial hashes');
        check(DrivesStore::key(' ab/c..d ') === 'ABC..D', 'key filter');
        mkdir("$dir/elsewhere");
        symlink("$dir/elsewhere", "$dir/drives");
        $refused = false;
        try {
            $store->dir('X1');
        } catch (CliError $e) {
            $refused = strpos($e->getMessage(), 'symlink') !== false;
        }
        check($refused, 'a symlinked drives directory is refused');
        testRmTree($dir);
    },
];
