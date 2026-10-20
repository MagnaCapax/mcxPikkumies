<?php
/**
 * DrivesSelftest: the drive's own self-test state and log, read from the same `smartctl -j -x` document. A self-test
 * result is a separate fact from the SMART health flag (`smartctl -H`), and the two are never reported as one.
 * selftest_last: the newest logged result ("Short offline: Completed without error"); selftest_running and
 * selftest_remaining (percent) while one runs; selftest_failures: failed entries in the log; selftest_short_passed:
 * the newest short test passed; same_lba_long: the run of newest consecutive failed long tests at one LBA and the
 * power-on hours they span (the drive's own evidence of a persistent bad spot).
 */
class DrivesSelftest
{
    public static function parse(array $j, $proto)
    {
        $out = ['selftest_last' => null, 'selftest_running' => false, 'selftest_remaining' => null, 'selftest_failures' => null,
            'selftest_short_passed' => null, 'same_lba_long' => ['count' => 0, 'lba' => null, 'hours' => 0]];
        if ($proto === 'ata') {
            return self::ata($j, $out);
        }
        if ($proto === 'scsi') {
            return self::scsi($j, $out);
        }
        if ($proto === 'nvme') {
            return self::nvme($j, $out);
        }
        return $out;
    }

    private static function ata(array $j, array $out)
    {
        $status = DrivesSmart::int(DrivesSmart::get($j, 'ata_smart_data.self_test.status.value'));
        if ($status !== null && ($status >> 4) === 15) { // ATA: status 0xF_ = in progress, low nibble = tenths left
            $out['selftest_running'] = true;
            $left = DrivesSmart::get($j, 'ata_smart_data.self_test.status.remaining_percent');
            $out['selftest_remaining'] = is_numeric($left) ? (int) $left : ($status & 15) * 10;
        }
        $log = DrivesSmart::get($j, 'ata_smart_self_test_log.extended', DrivesSmart::get($j, 'ata_smart_self_test_log.standard'));
        if (!is_array($log)) {
            return $out;
        }
        $table = isset($log['table']) && is_array($log['table']) ? $log['table'] : [];
        $out['selftest_failures'] = isset($log['error_count_total']) ? (int) $log['error_count_total'] : count(array_filter($table, function ($t) {
            return isset($t['status']['passed']) && $t['status']['passed'] === false;
        }));
        if ($table) {
            $t = $table[0];
            $out['selftest_last'] = (isset($t['type']['string']) ? $t['type']['string'] . ': ' : '') . (isset($t['status']['string']) ? $t['status']['string'] : '?');
        }
        foreach ($table as $t) {
            if (isset($t['type']['value']) && (int) $t['type']['value'] === 1) { // newest short offline test
                $out['selftest_short_passed'] = !empty($t['status']['passed']);
                break;
            }
        }
        $out['same_lba_long'] = self::sameLba($table);
        return $out;
    }

    /** Newest-first run of failed extended (long) tests at the same non-zero LBA, ignoring short tests in between. */
    private static function sameLba(array $table)
    {
        $run = ['count' => 0, 'lba' => null, 'hours' => 0];
        $newest = null;
        foreach ($table as $t) {
            if (!isset($t['type']['value']) || (int) $t['type']['value'] !== 2) {
                continue;
            }
            $lba = isset($t['lba']) ? (int) $t['lba'] : 0;
            if (!empty($t['status']['passed']) || $lba === 0 || ($run['lba'] !== null && $lba !== $run['lba'])) {
                break;
            }
            $hours = isset($t['lifetime_hours']) ? (int) $t['lifetime_hours'] : 0;
            $newest = $newest === null ? $hours : $newest;
            $run = ['count' => $run['count'] + 1, 'lba' => $lba, 'hours' => max(0, $newest - $hours)];
        }
        return $run;
    }

    private static function scsi(array $j, array $out)
    {
        $failures = 0;
        for ($i = 0; $i < 20 && is_array($t = DrivesSmart::get($j, "scsi_self_test_$i")); $i++) {
            $code = isset($t['result']['value']) ? (int) $t['result']['value'] : -1;
            if ($i === 0) {
                $out['selftest_last'] = (isset($t['code']['string']) ? $t['code']['string'] . ': ' : '') . (isset($t['result']['string']) ? $t['result']['string'] : '?');
                $out['selftest_running'] = $code === 15; // SPC "self-test in progress"
            }
            if ($out['selftest_short_passed'] === null && isset($t['code']['value']) && in_array((int) $t['code']['value'], [1, 5], true) && $code !== 15) {
                $out['selftest_short_passed'] = $code === 0; // background or foreground short, 0 = completed
            }
            $failures += $code >= 4 && $code <= 7 ? 1 : 0; // SPC result codes 4-7: a segment failed
            $out['selftest_failures'] = $failures;
        }
        return $out;
    }

    private static function nvme(array $j, array $out)
    {
        $op = DrivesSmart::int(DrivesSmart::get($j, 'nvme_self_test_log.current_self_test_operation.value'));
        $out['selftest_running'] = $op !== null && $op !== 0;
        $done = DrivesSmart::int(DrivesSmart::get($j, 'nvme_self_test_log.current_self_test_completion_percent'));
        $out['selftest_remaining'] = $out['selftest_running'] && $done !== null ? 100 - $done : null;
        $table = (array) DrivesSmart::get($j, 'nvme_self_test_log.table', []);
        if (!$table) {
            return $out;
        }
        $out['selftest_failures'] = 0;
        foreach ($table as $n => $t) {
            $res = isset($t['self_test_result']['value']) ? (int) $t['self_test_result']['value'] : -1;
            if ($n === 0) {
                $out['selftest_last'] = (isset($t['self_test_code']['string']) ? $t['self_test_code']['string'] . ': ' : '') . (isset($t['self_test_result']['string']) ? $t['self_test_result']['string'] : '?');
            }
            if ($out['selftest_short_passed'] === null && isset($t['self_test_code']['value']) && (int) $t['self_test_code']['value'] === 1) {
                $out['selftest_short_passed'] = $res === 0;
            }
            $out['selftest_failures'] += $res >= 5 && $res <= 7 ? 1 : 0; // NVMe: 5 segment failed, 6 unknown segment, 7 failed
        }
        return $out;
    }
}
