<?php
/**
 * DrivesSmart: one `smartctl -j -x` document (smartmontools 7.x JSON) turned into the fixed fields the drives tool
 * reports, for SATA (ATA attributes), SAS (SCSI logs) and NVMe. A field the drive does not report is null (unknown),
 * never zero: "unknown" and "measured zero" lead to different grades. SAS maps its grown defect list to `realloc`
 * and its total uncorrected read+write+verify errors to `uncorrectable`; it has no pending or CRC counter (null).
 */
class DrivesSmart
{
    /** ATA attribute ids read: reallocated, reported uncorrectable, pending, offline uncorrectable, interface CRC. */
    const ATTRS = [5 => 'realloc', 187 => 'reported_unc', 197 => 'pending', 198 => 'uncorrectable', 199 => 'crc'];

    public static function parse(array $j)
    {
        $proto = strtolower((string) self::get($j, 'device.protocol', ''));
        $r = [
            'protocol' => $proto === 'ata' ? 'sata' : ($proto === 'scsi' ? 'sas' : ($proto === 'nvme' ? 'nvme' : ($proto ?: null))),
            'serial' => self::str(self::get($j, 'serial_number')),
            'wwn' => self::wwn($j),
            'model' => self::str(self::get($j, 'model_name', self::get($j, 'scsi_model_name'))),
            'bytes' => self::int(self::get($j, 'user_capacity.bytes')),
            'rotational' => self::rotational($j, $proto),
            'health' => self::health($j),
            'poh' => self::int(self::get($j, 'power_on_time.hours')),
            'realloc' => null, 'pending' => null, 'uncorrectable' => null, 'reported_unc' => null, 'crc' => null,
            'error_log' => null, 'failing_now' => [], 'standby' => self::standby($j),
        ];
        if ($proto === 'ata') {
            foreach ((array) self::get($j, 'ata_smart_attributes.table', []) as $a) {
                if (isset($a['id']) && array_key_exists((int) $a['id'], self::ATTRS)) {
                    $r[self::ATTRS[(int) $a['id']]] = self::raw($a);
                }
                if (isset($a['when_failed']) && $a['when_failed'] === 'now') {
                    $r['failing_now'][] = isset($a['name']) ? (string) $a['name'] : 'attribute ' . (int) $a['id'];
                }
            }
            $r['error_log'] = self::int(self::get($j, 'ata_smart_error_log.extended.count', self::get($j, 'ata_smart_error_log.summary.count')));
        } elseif ($proto === 'scsi') {
            $r['realloc'] = self::int(self::get($j, 'scsi_grown_defect_list'));
            $unc = null;
            foreach (['read', 'write', 'verify'] as $k) {
                $v = self::int(self::get($j, "scsi_error_counter_log.$k.total_uncorrected_errors"));
                $unc = $v === null ? $unc : (int) $unc + $v;
            }
            $r['uncorrectable'] = $unc;
        } elseif ($proto === 'nvme') {
            $r['uncorrectable'] = self::int(self::get($j, 'nvme_smart_health_information_log.media_errors'));
            $r['critical_warning'] = self::int(self::get($j, 'nvme_smart_health_information_log.critical_warning'));
        }
        return $r + DrivesSelftest::parse($j, $proto);
    }

    /** smart_status.passed: PASSED / FAILED, null when the drive gave none (SAS "OK" is passed=true in smartctl JSON). */
    private static function health(array $j)
    {
        $p = self::get($j, 'smart_status.passed');
        return is_bool($p) ? ($p ? 'PASSED' : 'FAILED') : null;
    }

    private static function rotational(array $j, $proto)
    {
        if ($proto === 'nvme') {
            return false;
        }
        $rate = self::get($j, 'rotation_rate');
        return is_numeric($rate) ? (int) $rate > 0 : null; // 0 = "Solid State Device"
    }

    /** smartctl -n standby skipped a sleeping disk: it says so in its messages and reports no identity. */
    private static function standby(array $j)
    {
        foreach ((array) self::get($j, 'smartctl.messages', []) as $m) {
            if (isset($m['string']) && stripos((string) $m['string'], 'standby') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function wwn(array $j)
    {
        $w = self::get($j, 'wwn');
        if (!is_array($w) || !isset($w['naa'], $w['oui'], $w['id'])) {
            return null;
        }
        return sprintf('0x%x%06x%09x', (int) $w['naa'], (int) $w['oui'], (int) $w['id']);
    }

    /** The leading integer of the attribute's raw string (smartctl applies the vendor's raw format there), else raw.value. */
    private static function raw(array $a)
    {
        if (isset($a['raw']['string']) && preg_match('/^\s*(\d+)/', (string) $a['raw']['string'], $m)) {
            return (int) $m[1];
        }
        return isset($a['raw']['value']) ? self::int($a['raw']['value']) : null;
    }

    /** A dotted path into the document, or $default. */
    public static function get(array $j, $path, $default = null)
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($j) || !array_key_exists($k, $j)) {
                return $default;
            }
            $j = $j[$k];
        }
        return $j;
    }

    public static function int($v)
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function str($v)
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }
}
