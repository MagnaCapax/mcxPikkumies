<?php
/**
 * tools/backup/borg: times read right under a non-UTC host zone, archive names that do not collide within a second, and
 * error messages that keep the cause of a borg traceback (live smoke run 2026-10-14, borg 1.2.4 and 1.4.0).
 */
require_once __DIR__ . '/fixtures/backup/harness.php';

const BB_EET = 'EET-2EEST,M3.5.0/3,M10.5.0/4'; // the zone of the smoke run that shifted every borg time by +10800 s

return [
    'borg times: list, show, --files and the prune plan give the true unix time when the host zone is not UTC' => function () {
        $fx = bkFixture('borg', ['local_offset' => 10800]); // borg prints local time without a zone; the fake does the same
        try {
            $day1 = gmmktime(3, 0, 0, 10, 1, 2026);
            list($code, $l, $out) = bkRun($fx, 'borg', ['list', '--limit', '0'], ['TZ' => BB_EET]);
            check($code === 0 && $l[2]['id'] === 'box-1' && $l[2]['time'] === $day1, 'list: ' . $out);
            list($code, $l, $out) = bkRun($fx, 'borg', ['show', 'box-1'], ['TZ' => BB_EET]);
            check($code === 0 && $l[0]['time'] === $day1 && $l[0]['end'] === $day1, 'show: ' . $out);
            list($code, $l, $out) = bkRun($fx, 'borg', ['show', 'box-1', '--files'], ['TZ' => BB_EET]);
            check($code === 0 && $l[0]['modified_at'] === gmmktime(2, 0, 0, 10, 1, 2026), 'files: ' . $out);
            list($code, $l, $out) = bkRun($fx, 'borg', ['show', '--prune-plan', '--keep-daily', '1'], ['TZ' => BB_EET]);
            check($code === 0 && $l[0]['id'] === 'box-1' && $l[0]['time'] === $day1, 'prune plan: ' . $out);
            foreach (stLog($fx) as $c) {
                check($c['bin'] !== 'borg' || $c['argv'] === ['--version'] || (isset($c['env']['TZ']) && $c['env']['TZ'] === 'UTC0'),
                    'borg ran without TZ=UTC0: ' . json_encode($c));
            }
        } finally {
            stDone($fx);
        }
    },
    'borg backup: two backups in the same second get different readable archive names, and both succeed' => function () {
        $fx = bkFixture('borg', ['now' => '2026-10-14T12:25:58']); // borg's own {now} would be this second both times
        try {
            $names = [];
            foreach ([1, 2] as $n) {
                list($code, $l, $out) = bkRun($fx, 'borg', ['backup', $fx['home'] . '/data']);
                check($code === 0, "backup $n: $out");
                $names[] = $l[0]['id'];
            }
            check($names[0] !== $names[1], 'same name twice: ' . json_encode($names));
            foreach ($names as $name) {
                check(preg_match('/^[A-Za-z0-9_-]+-\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $name) === 1, "name: $name");
            }
            check(count(stState($fx)['archives']) === 5, 'both archives were not made');
        } finally {
            stDone($fx);
        }
    },
    'borg list: archives in the same second come newest first (borg lists the older first and its start is whole seconds)'
        => function () {
            // Live run 2026-10-14, borg 1.2.4: two backups 0.24 s apart both had start "...T13:10:50.000000", the older listed first.
            $arch = function ($c, $name, $start) {
                return ['id' => str_repeat($c, 64), 'name' => $name, 'start' => $start];
            };
            $at = '2026-10-14T13:10:50.000000';
            $fx = bkFixture('borg', ['archives' => [$arch('c', 'box-oldest', '2026-10-13T09:00:00.000000'),
                $arch('a', 'box-older', $at), $arch('b', 'box-newer', $at)]]);
            try {
                list($code, $l, $out) = bkRun($fx, 'borg', ['list']);
                array_pop($l);
                check($code === 0 && array_column($l, 'id') === ['box-newer', 'box-older', 'box-oldest'], 'order: ' . $out);
            } finally {
                stDone($fx);
            }
        },
    'borg errors: a traceback is reported by its exception line, not by the Platform / PID / sys.argv footer; passwords stay redacted'
        => function () {
            $tb = (string) file_get_contents(__DIR__ . '/fixtures/backup/borg-create-traceback.txt'); // verbatim borg 1.2.4 stderr
            $fx = bkFixture('borg', ['create_err' => $tb]);
            try {
                list($code, $l) = bkRun($fx, 'borg', ['backup', $fx['home'] . '/data']);
                $err = end($l)['error'];
                check($code === 1 && strpos($err, "KeyError: 'getpwuid(): uid not found: 1000'") !== false && strlen($err) < 500,
                    'cause lost: ' . $err);
            } finally {
                stDone($fx);
            }
            $fx = bkFixture('borg', ['create_err' => "Remote: Error: cannot reach sftp://me:Pw-9xQ@box/repo\nfooter 1\nfooter 2\n"
                . "footer 3\nfooter 4\n"]);
            try {
                list(, $l, $out) = bkRun($fx, 'borg', ['backup', $fx['home'] . '/data']);
                check(strpos(end($l)['error'], 'cannot reach') !== false && strpos($out, 'Pw-9xQ') === false, 'redaction: ' . $out);
            } finally {
                stDone($fx);
            }
        },
];
