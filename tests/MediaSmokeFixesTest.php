<?php
/**
 * Defects a live smoke run found on 2026-10-14 (Jellyfin 12.2.0, Emby 4.10.1.0), each against the fake: `recent --limit`
 * paging, `stats.items` from the libraries instead of ItemCounts.ItemCount, the foreign-port refusal before the credential,
 * and no local state written by a read that sends nothing.
 */
require_once __DIR__ . '/fixtures/media/units.php';
require_once __DIR__ . '/fixtures/media/jellyfin.php';

return [
    'media: recent --limit 0 reads as many as fit, --limit N marks truncated with next_offset, the cap is noted' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '0']);
            check($code === 0 && count($l) === 3 && end($l)['count'] === 2 && empty(end($l)['truncated']), '--limit 0: ' . $out);
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '1']);
            check($code === 0 && $l[0]['title'] === 'A Show - Pilot' && end($l)['truncated'] === true && end($l)['next_offset'] === 1,
                '--limit 1: ' . $out);
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '1', '--offset', '1']);
            check($code === 0 && $l[0]['title'] === 'New Film' && end($l)['count'] === 1, '--offset 1: ' . $out);
            mediaSetState($fx, ['latest_many' => 600]);
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '0']);
            check($code === 0 && strpos((string) end($l)['note'], 'read the 500 most recently added') === 0
                && (bool) preg_grep('#^GET /Items/Latest\?.*limit=500\b#', mediaCalls($fx)), 'cap: ' . $out);
            list($code, $l, $out) = jfRun($fx, ['recent', '--limit', '500']);
            check($code === 0 && end($l)['truncated'] === true && isset(end($l)['note']),
                '--limit 500, more than the output holds: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'media: stats items is the libraries\' own count, not ItemCount (live: 8 on Jellyfin, 0 on Emby for two movies)' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfRun($fx, ['stats']);
            check($code === 0 && $l[0]['items'] === 7 && $l[0]['item_counts'] === ['movie' => 2, 'series' => 1, 'episode' => 4],
                'jellyfin: ' . $out);
        } finally {
            mediaDone($fx);
        }
        $fx = embyFixture();
        try {
            list($code, $l, $out) = embyRun($fx, ['stats']);
            check($code === 0 && $l[0]['items'] === 7 && $l[0]['item_counts'] === ['movie' => 2], 'emby: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'media: --url to another account\'s port is refused before the credential, and a refused read writes no local state' => function () {
        $fx = jfFixture();
        try {
            $url = 'http://127.0.0.1:' . $fx['port'];
            $other = ['pmss_dir' => $fx['dir'] . '/no-pmss', 'tables' => mediaNetTable($fx['port'], getmyuid() + 1)];
            list($code, , $out) = jfRun($fx, ['stats', '--url', $url], $other);
            check($code === 1 && strpos($out, 'belongs to another account') !== false, 'foreign port: ' . $out);
            check(!file_exists($fx['dir'] . '/secrets/jellyfin'), 'a refused read created local/secrets/jellyfin');
            list($code, , $out) = jfRun($fx, ['stats', '--url', $url], ['pmss_dir' => $fx['dir'] . '/no-pmss']);
            check($code === 1 && strpos($out, 'no Jellyfin credential') !== false && !file_exists($fx['dir'] . '/secrets/jellyfin'),
                'no credential: nothing sent, nothing stored: ' . $out);
            list($code, , $out) = jfRun($fx, ['stats']);
            check($code === 0 && preg_match('/^pikkumies-[0-9a-f]{16}$/', (string) jfSecret($fx)['device_id']),
                'a sent request keeps its device id');
        } finally {
            mediaDone($fx);
        }
        $fx = embyFixture([], false);
        try {
            $other = ['tables' => mediaNetTable($fx['port'], getmyuid() + 1)];
            list($code, , $out) = embyRun($fx, ['stats', '--url', 'http://127.0.0.1:' . $fx['port']], $other);
            check($code === 1 && strpos($out, 'belongs to another account') !== false && !file_exists($fx['dir'] . '/secrets/emby'),
                'emby: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
];
