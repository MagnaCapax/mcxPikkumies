<?php
/**
 * DrivesRules: the screen, the verdict and the grade (tools/hardware/DOCTRINE.md). Three separate things:
 * - screen: a glyph from counters and the health flag. It routes the next test; it never says good or bad.
 * - verdict: RECOVERED / HOLD / FAILED / INCOMPLETE, only from a write pass the tool watched complete (with a SMART
 *   sample before and after it), or from the drive's own repeated long-test failures at one spot.
 * - grade: A / B / C / BIN, only after a completed write pass (RECOVERED or FAILED); otherwise untested or undetermined.
 * Thresholds are defaults, chosen to match common practice; see README.md. Pure functions: no I/O.
 */
class DrivesRules
{
    const HEAVY_REALLOC = 1000;   // reallocated sectors / SAS grown defects: the heavy screen line and Grade C
    const HEAVY_COUNT = 100;      // pending or uncorrectable: the heavy screen line (uncorrectable also Grade C)
    const REGROW = 100;           // reallocations during one pass that need a second pass (or condemn with pending stuck)
    const UNC_PER_HOUR = 10;      // reported-uncorrectable growth per hour in service after a pass that condemns
    const SAME_LBA_TESTS = 5;     // consecutive failed long tests at one LBA...
    const SAME_LBA_HOURS = 336;   // ...spread over at least two weeks of power-on time
    const PAIR_SECONDS = 2592000; // two passes surfacing defects count as consecutive within 30 days

    /** [glyph, reason]. Worst first: red, orange, yellow, green; white when nothing was measured (standby, no SMART). */
    public static function screen(array $d)
    {
        if ($d['health'] === 'FAILED') {
            return ['🔴', 'health flag FAILED: a screen; it routes to a write pass'];
        }
        $unc = max((int) $d['uncorrectable'], (int) $d['reported_unc']);
        if ((int) $d['realloc'] >= self::HEAVY_REALLOC || (int) $d['pending'] >= self::HEAVY_COUNT || $unc >= self::HEAVY_COUNT) {
            return ['🟠', 'heavy counters'];
        }
        $known = array_filter([$d['realloc'], $d['pending'], $d['uncorrectable'], $d['reported_unc'], $d['crc'],
            isset($d['critical_warning']) ? $d['critical_warning'] : null], 'is_int'); // NVMe critical_warning: a flag, a screen
        if (!$known) {
            return ['⚪', $d['standby'] ? 'in standby: not woken, nothing read' : 'no counters reported'];
        }
        if (array_sum($known) > 0 || !empty($d['failing_now'])) {
            return ['🟡', 'a counter is not zero'];
        }
        return ['🟢', 'every reported counter is zero'];
    }

    public static function rank($glyph)
    {
        $order = ['🔴' => 0, '🟠' => 1, '🟡' => 2, '⚪' => 3, '🟢' => 4];
        return isset($order[$glyph]) ? $order[$glyph] : 5;
    }

    /**
     * The verdict of one finished full pass. $pass: completed (bool), r/w/c error counts, bb_over (bool), gone (the
     * drive is no longer listed), last_line. $pre/$post: SMART samples (realloc, pending, uncorrectable) around it.
     * $prev: the previous finished pass's end event, or null. Returns [verdict, reason, code]; the codes are stored
     * with the end event (read-errors enables --fix-unreadable, regrow asks for the second pass).
     */
    public static function verdict(array $pass, array $pre = null, array $post = null, array $prev = null, $now = 0)
    {
        if (empty($pass['completed'])) {
            if (!empty($pass['bb_over']) || !empty($pass['gone'])) {
                return ['INCOMPLETE', 'no verdict: the drive dropped or its bad-block list ran away, which points at the link or cable; re-test once the drive answers', 'link'];
            }
            if ((int) $pass['w'] > 0) {
                return ['FAILED', 'media: the pass stalled on write errors with the drive still present (a stalled failing pass counts as completed)', 'stalled-write'];
            }
            return ['INCOMPLETE', 'no verdict: the pass ended without completing: ' . $pass['last_line'], 'incomplete'];
        }
        if ((int) $pass['w'] > 0 || (int) $pass['c'] > 0) {
            return ['FAILED', 'media: the pass found blocks the drive could not write or read back correctly', 'write-errors'];
        }
        if ($pre === null || $post === null) {
            return ['HOLD', 'no SMART sample around the pass; run another pass', 'no-sample'];
        }
        $grew = self::delta($pre, $post, 'realloc');
        $pendingPre = $pre['pending'];
        $pendingPost = $post['pending'];
        if (is_int($pendingPre) && is_int($pendingPost) && $pendingPre > 0 && $pendingPost >= $pendingPre && $grew !== null && $grew > self::REGROW) {
            return ['FAILED', "media: pending did not fall across a full pass while $grew sectors were reallocated", 'pending-stuck'];
        }
        if (self::surfaced($pass, $pre, $post) && $prev !== null && !empty($prev['surfaced']) && $now - (int) $prev['ts'] <= self::PAIR_SECONDS) {
            return ['FAILED', 'media: two consecutive passes each surfaced new defects', 'surfaced-twice'];
        }
        if ($prev !== null && isset($prev['code']) && $prev['code'] === 'regrow' && $grew !== null && $grew > 0) {
            return ['FAILED', "media: reallocations grew again ($grew) on the second pass", 'regrew'];
        }
        if ((int) $pass['r'] > 0) {
            return ['HOLD', 'read errors only: -n skips blocks it cannot read, so they were never written; next: verify --fix-unreadable --with-data', 'read-errors'];
        }
        if ($grew !== null && $grew >= self::REGROW) {
            return ['HOLD', "pending cleared but $grew sectors were reallocated during the pass; run one more pass", 'regrow'];
        }
        if ($pendingPost === null || $pendingPost === 0 || (is_int($pendingPre) && $pendingPost < $pendingPre)) {
            return ['RECOVERED', 'the write pass completed with no errors' . ($grew ? " ($grew reallocated during it)" : ''), 'recovered'];
        }
        return ['HOLD', 'pending did not move; run another pass', 'pending-unmoved'];
    }

    /** The pass consumed spares on defects that were there (pending or uncorrectable before it, or read errors in it). */
    public static function surfaced(array $pass, array $pre = null, array $post = null)
    {
        $grew = $pre !== null && $post !== null ? self::delta($pre, $post, 'realloc') : null;
        return $grew !== null && $grew > 0 && ((int) $pre['pending'] + (int) $pre['uncorrectable'] > 0 || (int) $pass['r'] > 0);
    }

    /** FAILED from the drive's own log or from history, independent of any pass; null when neither applies. */
    public static function standingFailure(array $d, $uncPerHour)
    {
        $run = $d['same_lba_long'];
        if ($run['count'] >= self::SAME_LBA_TESTS && $run['hours'] >= self::SAME_LBA_HOURS) {
            return "media: {$run['count']} consecutive long self-tests failed at the same LBA over {$run['hours']} power-on hours";
        }
        if ($uncPerHour !== null && $uncPerHour > self::UNC_PER_HOUR) {
            return 'media: reported uncorrectable errors rising ' . round($uncPerHour, 1) . ' per hour in service after a pass';
        }
        return null;
    }

    /**
     * [grade, reason]. A letter only after a completed write pass: RECOVERED gives A, B or C, FAILED gives BIN. HOLD and
     * INCOMPLETE are 'undetermined'; a drive with no verdict is 'untested', however clean its SMART looks or however
     * new it is (counters and self-tests are reported by the drive itself and never stand in for the pass).
     */
    public static function grade(array $d, $verdict)
    {
        if ($verdict === 'FAILED') {
            return ['BIN', 'verdict FAILED'];
        }
        if ($verdict === 'HOLD' || $verdict === 'INCOMPLETE') {
            return ['undetermined', 'no letter grade while the verdict is ' . $verdict];
        }
        if ($verdict !== 'RECOVERED') {
            return ['untested', 'no completed write pass yet: a letter grade comes only after one (verify)'];
        }
        $fields = self::gradeFields($d);
        $measured = !in_array(null, $fields, true);
        $unc = max((int) $d['uncorrectable'], (int) $d['reported_unc']);
        if ((int) $d['realloc'] >= self::HEAVY_REALLOC || $unc >= self::HEAVY_COUNT) {
            return ['C', 'write pass RECOVERED, heavy wear'];
        }
        if ($measured && array_sum($fields) === 0) {
            return ['A', 'write pass RECOVERED; every counter, the error log and the self-test log are clean'];
        }
        return ['B', $measured ? 'write pass RECOVERED; something is on the SMART log' : 'write pass RECOVERED, but not every counter is reported, so A cannot be shown'];
    }

    /** True for a letter grade (A, B, C, BIN); false for untested, undetermined and none. */
    public static function letter($grade)
    {
        return in_array($grade, ['A', 'B', 'C', 'BIN'], true);
    }
    /** The fields that must all be measured zero for A, per protocol. */
    private static function gradeFields(array $d)
    {
        if ($d['protocol'] === 'sas') {
            return [$d['realloc'], $d['uncorrectable'], $d['selftest_failures']];
        }
        if ($d['protocol'] === 'nvme') {
            return [$d['uncorrectable'], isset($d['critical_warning']) ? $d['critical_warning'] : null, $d['selftest_failures']];
        }
        return [$d['realloc'], $d['pending'], $d['uncorrectable'], $d['reported_unc'], $d['crc'], $d['error_log'], $d['selftest_failures']];
    }

    private static function delta(array $pre, array $post, $field)
    {
        return is_int($pre[$field]) && is_int($post[$field]) ? $post[$field] - $pre[$field] : null;
    }
}
