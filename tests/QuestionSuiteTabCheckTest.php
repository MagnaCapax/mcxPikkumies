<?php
/**
 * tests/testing/questions/lib/CrontabGuard.php (ADR-0039 RCA 2026-10-18): run.php's watch on the account's REAL
 * crontab, separate from Sandbox's fake one. A $reader callable is injected so this never touches a real crontab:
 * the suite must stay deterministic in CI without one (AGENTS.md "Tests: php tests/run.php must pass").
 */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/CrontabGuard.php';
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Report.php';

/** A CrontabGuard backed by a queue of canned readings instead of a real `crontab -l`. */
function tabGuardFake($out, array $readings, array $keys = [])
{
    $report = new Report($out, $keys);
    $i = 0;
    $reader = function () use (&$i, $readings) {
        $v = $readings[min($i, count($readings) - 1)];
        $i++;
        return $v;
    };
    return new CrontabGuard($out, $report, '/home/qa', 'qa', $reader);
}

return [
    'questions: CrontabGuard::check() is a no-op while the real crontab has not changed' => function () {
        $out = testTempDir('pk-tabguard');
        try {
            $guard = tabGuardFake($out, ["0\nold line"]);
            check($guard->check('q1') === false, 'an unchanged crontab must not be reported as changed');
            check($guard->changedBy === [], 'nothing must be recorded when nothing changed');
            check(!is_file("$out/crontab-after-q1.txt"), 'no file must be written when nothing changed');
        } finally {
            testRmTree($out);
        }
    },
    'questions: CrontabGuard::check() on a change saves the raw copy, remembers the label and target, and advances prev' => function () {
        $out = testTempDir('pk-tabguard');
        try {
            $report = new Report($out, []);
            $before = "0\n0 3 * * * /real/job";
            $after = "0\n0 3 * * * /real/job\n* * * * * /home/qa/injected";
            $guard = new CrontabGuard($out, $report, '/home/qa', 'qa', function () use ($after) {
                return $after;
            });
            $guard->prev = $before; // the "before" the question ran
            $changed = $guard->check('nightly-backup', 'target-a');
            check($changed === true, 'a real change must be reported as changed');
            check($guard->changedBy === ['nightly-backup (target-a)'], 'the label and target must be recorded together: ' . implode(',', $guard->changedBy));
            check($guard->prev === $after, 'prev must advance to the new reading, or the next check would re-report the same change');
            $saved = file_get_contents("$out/crontab-after-nightly-backup.txt");
            check(strpos($saved, '/home/qa/injected') !== false, 'the saved copy must be the raw (unmasked) new crontab: ' . $saved);
        } finally {
            testRmTree($out);
        }
    },
    'questions: CrontabGuard::mask() replaces the real home with ~, the account with USER, then masks provider keys' => function () {
        $out = testTempDir('pk-tabguard');
        try {
            $report = new Report($out, ['SECRETKEY123']);
            $guard = new CrontabGuard($out, $report, '/home/qa', 'qa', function () {
                return "0\n";
            });
            $masked = $guard->mask('+ * * * * * /home/qa/.mcxPikkumies/bin/pikkumies run cron --as=qa KEY=SECRETKEY123');
            check(strpos($masked, '/home/qa') === false, 'the real home must not appear in a masked line: ' . $masked);
            check(strpos($masked, '~/.mcxPikkumies') !== false, 'the real home must become ~: ' . $masked);
            check(strpos($masked, 'SECRETKEY123') === false, 'a provider key must be masked: ' . $masked);
            check(strpos($masked, '--as=USER') !== false, 'the account name must become USER: ' . $masked);
        } finally {
            testRmTree($out);
        }
    },
    'questions: CrontabGuard::check() diffs both ways: a removed line and an added line are both reported' => function () {
        $out = testTempDir('pk-tabguard');
        try {
            $report = new Report($out, []);
            $after = "0\nkept line\nadded line";
            $guard = new CrontabGuard($out, $report, '/home/qa', 'qa', function () use ($after) {
                return $after;
            });
            $guard->prev = "0\nkept line\nremoved line";
            check($guard->check('q2') === true, 'a change must be reported');
            $saved = file_get_contents("$out/crontab-after-q2.txt");
            check($saved === $after . "\n", 'the saved copy must be the new reading verbatim: ' . $saved);
        } finally {
            testRmTree($out);
        }
    },
];
