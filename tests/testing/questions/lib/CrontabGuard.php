<?php
/**
 * CrontabGuard: the question suite's watch on the account's REAL crontab (ADR-0039 RCA 2026-10-18), separate from
 * Sandbox's fake one inside the sandbox. Root cause: Hermes' terminal tool snapshots a login bash, which re-reads
 * /etc/profile and can lose the fake crontab's place on PATH (fixed by Sandbox::ensureBashProfile), so a question
 * might still reach the real crontab through that terminal. realTab() reads it (injectable, for tests); check()
 * compares the current copy against the last known-good one, and on a change saves it, prints a masked diff and
 * remembers the label, so run.php can stop before more questions compound the damage.
 */
class CrontabGuard
{
    /** The last known-good copy (what the account's crontab was at construction time, or after the last change). */
    public $prev;
    /** Every label a change was caught during, in order. */
    public $changedBy = [];
    private $out;
    private $report;
    private $realHome;
    private $account;
    private $reader;

    /** $reader is a 0-arg callable returning realTab()'s format; injected by tests, defaults to the real crontab. */
    public function __construct($out, Report $report, $realHome, $account, $reader = null)
    {
        $this->out = $out;
        $this->report = $report;
        $this->realHome = $realHome;
        $this->account = $account;
        $this->reader = $reader ?: ['CrontabGuard', 'realTab'];
        $this->prev = call_user_func($this->reader);
    }

    /** The real account's crontab right now: its exit code and lines, joined ("<code>\n<lines>"). */
    public static function realTab()
    {
        exec('crontab -l 2>&1', $l, $c);
        return "$c\n" . implode("\n", $l);
    }

    /** $text with the real home replaced by ~, the account name replaced by USER, then the report's own key mask. */
    public function mask($text)
    {
        return $this->report->mask(str_replace($this->account, 'USER', str_replace($this->realHome, '~', $text)));
    }

    /**
     * If the real crontab changed since the last check: save "$out/crontab-after-$label.txt", print a masked line
     * diff naming $label (and $target, when given) and remember $label in changedBy. Returns whether it changed;
     * the caller must stop asking on a true return, because more questions would only compound the damage.
     */
    public function check($label, $target = '')
    {
        $now = call_user_func($this->reader);
        if ($now === $this->prev) {
            return false;
        }
        file_put_contents("{$this->out}/crontab-after-$label.txt", $now . "\n");
        $where = $label . ($target !== '' ? " ($target)" : '');
        fwrite(STDERR, "!!! real crontab changed during $where !!!\n" . implode("\n", $this->maskedDiff($this->prev, $now)) . "\n");
        $this->changedBy[] = $where;
        $this->prev = $now;
        return true;
    }

    /**
     * The lines of $before missing from $after ("- ") and the lines added ("+ "), each through mask(): what is shown of a
     * change. The copies on disk stay verbatim, because crontab-before.txt is the only copy to restore from.
     */
    public function maskedDiff($before, $after)
    {
        $before = explode("\n", $before);
        $after = explode("\n", $after);
        $diff = array_merge(array_map(function ($l) {
            return "- $l";
        }, array_diff($before, $after)), array_map(function ($l) {
            return "+ $l";
        }, array_diff($after, $before)));
        return array_map([$this, 'mask'], $diff);
    }
}
