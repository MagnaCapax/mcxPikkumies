<?php
/**
 * FindOutput: the untrusted-field marker and the output-budget caps on the `find` status line (security
 * review F7, tools/torrent-clients/find/README.md "Untrusted fields and output budget"). Candidate records
 * above the status line are already budget-checked by lib/Cli.php; the status line itself is not, so
 * `issues`/`busy` are bounded here before find.php returns them.
 */
class FindOutput
{
    /** name/path/label/message are a client's own torrent-naming text: data, never instructions. */
    const UNTRUSTED_FIELDS = ['name', 'path', 'label', 'message'];
    const MAX_ISSUES = 10;
    const ISSUE_TEXT_MAX = 400;
    const MAX_BUSY = 20;

    /**
     * Bounds $status['issues']/['busy'] to a small, fixed worst case: at most MAX_ISSUES issues (each cut to
     * ISSUE_TEXT_MAX bytes and cleaned the same as any other field) and MAX_BUSY busy names, each with a
     * trailing "N more omitted" note when something was cut -- so a large --all fan-out that all fails the
     * same way cannot overrun the 16 KB output budget.
     */
    public static function capStatus(array $status)
    {
        if (isset($status['issues']) && is_array($status['issues'])) {
            $all = $status['issues'];
            $kept = array_slice($all, 0, self::MAX_ISSUES, true);
            foreach ($kept as $k => $v) {
                $kept[$k] = Cli::cleanString(substr((string) $v, 0, self::ISSUE_TEXT_MAX));
            }
            if (count($all) > self::MAX_ISSUES) {
                $kept['...'] = (count($all) - self::MAX_ISSUES) . ' more issue(s) omitted to stay inside the output budget';
            }
            $status['issues'] = $kept;
        }
        if (isset($status['busy']) && is_array($status['busy']) && count($status['busy']) > self::MAX_BUSY) {
            $status['busy'] = array_merge(array_slice($status['busy'], 0, self::MAX_BUSY),
                [(count($status['busy']) - self::MAX_BUSY) . ' more omitted to stay inside the output budget']);
        }
        return $status;
    }
}
