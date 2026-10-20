<?php
/**
 * DrivesNext: the `next` field of a drive record, the step the screen and the role call for. A counter or a health
 * flag routes to a write pass, never to a verdict, and the drive's array role decides which write pass: an array
 * member gets the array's own check scrub or rebuild, a standalone spinning disk gets `verify`, flash gets a long
 * self-test, a disk in use or carrying another array's signature gets none until that is sorted out.
 */
class DrivesNext
{
    public static function of(array $rec, array $d, array $state)
    {
        $serial = $rec['serial'];
        if ($state['running']) {
            return 'wait: HOLD until the pass completes; run smart to follow it';
        }
        if ($rec['verdict'] === 'FAILED') {
            return 'none: the verdict comes from a completed test; save the lesson record';
        }
        if ($rec['verdict'] === 'HOLD' || $rec['verdict'] === 'INCOMPLETE') {
            return 'see verdict_reason; ' . self::pass($rec, $d, $serial);
        }
        $route = (int) $d['pending'] > 0 || (int) $d['uncorrectable'] > 0 || (int) $d['reported_unc'] > 0
            || $d['health'] === 'FAILED' || !empty($d['failing_now']) || (int) ($d['critical_warning'] ?? 0) > 0;
        if ($route && $rec['verdict'] !== 'RECOVERED') {
            return 'a screen, not a verdict: ' . self::pass($rec, $d, $serial);
        }
        if ((int) $d['crc'] > 0 && (int) $d['realloc'] + (int) $d['pending'] + (int) $d['uncorrectable'] === 0) {
            return 'interface CRC errors count link trouble (cable, port, backplane), not the media; reseat or swap the cable if it keeps rising';
        }
        if ($rec['grade'] === 'untested' && $d['rotational'] === true && in_array($rec['role'], ['blank', 'data'], true)) {
            return 'none needed; for a grade (A, B, C) it needs a completed write pass: ' . self::pass($rec, $d, $serial);
        }
        return 'none';
    }

    /** The write pass this drive's role allows. */
    private static function pass(array $rec, array $d, $serial)
    {
        switch ($rec['role']) {
            case 'array-member':
                return 'its array is the write pass: a check scrub of the array (echo check > /sys/block/mdN/md/sync_action) or a finished rebuild; never repair, never a targeted write';
            case 'holder':
            case 'mounted':
            case 'swap':
                return 'it is in use (' . $rec['role_detail'] . '); no write pass while it is';
            case 'array-signature':
                return 'it carries an array signature with no running array: find its array (mdadm --examine) before anything writes to it';
        }
        if ($d['rotational'] !== true) {
            return "flash gets no write pass: selftest $serial long";
        }
        return $rec['role'] === 'data' ? "copy off anything needed (a lone disk is the only copy), then verify $serial --with-data" : "verify $serial";
    }
}
