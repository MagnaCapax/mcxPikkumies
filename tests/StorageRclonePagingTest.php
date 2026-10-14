<?php
/** tools/storage/rclone show: pages like every list (--limit 0, --offset, next_offset), `modified` in UTC (smoke run 2026-10-14). */
require_once __DIR__ . '/fixtures/storage/rclone.php';

return [
    'rclone show: --limit 0 lists the whole folder; --offset pages on with next_offset; the last page is not truncated' => function () {
        $rows = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $n) {
            $rows[] = ['2026-10-0' . ($i + 1) . ' 10:00:00', (string) ($i + 1), "$n.mkv"];
        }
        $fx = rcFixture(['lsf' => ['gdrive:tv' => $rows]]);
        try {
            $show = function (array $more) use ($fx) {
                list($code, $l, $out) = rcRun($fx, array_merge(['show', 'gdrive:tv'], $more));
                check($code === 0, implode(' ', $more) . ": $out");
                $st = array_pop($l);
                return [array_column($l, 'name'), $st];
            };
            list($n, $st) = $show(['--limit', '0']);
            check(count($n) === 5 && $st['listed_all'] === true && empty($st['truncated']), '--limit 0: ' . json_encode([$n, $st]));
            list($n, $st) = $show(['--limit', '2', '--offset', '2']);
            check($n === ['c.mkv', 'd.mkv'] && $st['truncated'] === true && $st['next_offset'] === 4,
                '--offset 2: ' . json_encode([$n, $st]));
            list($n, $st) = $show(['--limit', '2', '--offset', '4']);
            check($n === ['e.mkv'] && empty($st['truncated']) && $st['listed_all'] === true, 'last page: ' . json_encode([$n, $st]));
        } finally {
            stDone($fx);
        }
    },
    'rclone show: modified is ISO 8601 UTC also when the host zone is not UTC (lsf prints local time without a zone)' => function () {
        $fx = rcFixture(['local_offset' => 10800]); // like rclone 1.75.0 under TZ=Europe/Helsinki: 12:00 UTC printed as 15:00
        try {
            list($code, $l, $out) = rcRun($fx, ['show', 'gdrive:tv'], 'generic', ['TZ' => 'EET-2EEST,M3.5.0/3,M10.5.0/4']);
            check($code === 0 && $l[0]['modified'] === '2026-10-01T10:00:00Z' && $l[2]['modified'] === '2026-10-03T12:00:00Z',
                'modified: ' . $out);
        } finally {
            stDone($fx);
        }
    },
];
