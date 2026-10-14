<?php
/**
 * `total` on a truncated call is the app's own count of the list, not the number of records read (a unit reads one record
 * more than it prints). Live 2026-10-14: Plex `recent --limit 1` printed total 2 while /library/recentlyAdded said 3.
 * Cli::emit in process, then Jellyfin (no total in Items/Latest) and one Servarr unit (totalRecords).
 */
require_once __DIR__ . '/fixtures/media/units.php';
require_once __DIR__ . '/fixtures/media/jellyfin.php';
require_once __DIR__ . '/fixtures/arr/harness.php';

/** [status line, --human output] of Cli::emit for $n records. */
function pagingEmit($n, array $opt, array $extra)
{
    $records = [];
    for ($i = 0; $i < $n; $i++) {
        $records[] = ['id' => $i];
    }
    ob_start();
    Cli::emit($records, $opt, false, $extra);
    $lines = array_values(array_filter(explode("\n", (string) ob_get_clean()), 'strlen'));
    ob_start();
    Cli::emit($records, $opt, true, $extra);
    return [json_decode(end($lines), true), (string) ob_get_clean()];
}

return [
    'cli: a status `total` from the unit is printed when truncated (never fewer than were read); null says "more exist"' => function () {
        list($s, $h) = pagingEmit(2, ['limit' => '1'], ['total' => 3]);
        check($s === ['ok' => true, 'count' => 1, 'truncated' => true, 'total' => 3, 'next_offset' => 1]
            && strpos($h, '1 shown of 3') !== false,
            'app total: ' . json_encode($s) . $h);
        list($s, $h) = pagingEmit(2, ['limit' => '1'], ['total' => null]);
        check(array_key_exists('total', $s) && $s['total'] === null && strpos($h, '1 shown, more exist') !== false,
            'unknown: ' . json_encode($s) . $h);
        list($s) = pagingEmit(5, ['limit' => '1'], ['total' => 2]);
        check($s['total'] === 5, 'fewer than were read: ' . json_encode($s));
        list($s) = pagingEmit(2, ['limit' => '1'], []);
        check($s['total'] === 2, 'no total from the unit: the old count: ' . json_encode($s));
        list($s) = pagingEmit(2, ['limit' => '1'], ['total' => '9']);
        check($s['total'] === 2, 'a non-integer total was taken: ' . json_encode($s));
        list($s) = pagingEmit(1, ['limit' => '5'], ['total' => 7]);
        check($s === ['ok' => true, 'count' => 1], 'not truncated: no total: ' . json_encode($s));
    },
    'jellyfin: recent total is the merged count when no library was cut, else unknown (Items/Latest sends no total)' => function () {
        $fx = jfFixture(['latest_many' => 3]);
        try {
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '4']);
            $st = end($l);
            check($code === 0 && $st['count'] === 4 && $st['truncated'] === true && $st['total'] === 6, '--limit 4: ' . $out);
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '1']);
            $st = end($l);
            check($code === 0 && $st['truncated'] === true && array_key_exists('total', $st) && $st['total'] === null,
                '--limit 1: ' . $out);
            list($code, , $out) = jfRun($fx, ['recent', '--limit', '1', '--human']);
            check($code === 0 && strpos($out, '1 shown, more exist') !== false, 'human: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'radarr: history reports the app\'s totalRecords, not the records read (queue and wanted pass it the same way)' => function () {
        $fx = arrFixture('radarr');
        try {
            $history = [];
            for ($i = 1; $i <= 450; $i++) {
                $history[] = ['id' => $i, 'movieId' => 1, 'sourceTitle' => "Release.$i", 'date' => '2026-10-13T10:00:00Z',
                    'eventType' => 'grabbed'];
            }
            arrSet($fx, ['history' => $history]);
            list($code, $l, $out) = arrRun($fx, ['history', '--limit', '5']);
            $st = end($l);
            check($code === 0 && $st['count'] === 5 && $st['truncated'] === true && $st['total'] === 450 && $st['next_offset'] === 5,
                'history: ' . $out);
            list($code, , $out) = arrRun($fx, ['history', '--limit', '5', '--human']);
            check($code === 0 && strpos($out, '5 shown of 450') !== false, 'human: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
];
