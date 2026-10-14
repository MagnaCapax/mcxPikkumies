<?php
/**
 * QbitExtras: the part of QbittorrentClient behind recheck, reannounce, limit and `show --trackers`, plus the newer
 * list fields and the 5.2 add answer, over the WebUI API v2 through QbitApi (the owner-checked port and the one-login session).
 * Sources in README.md.
 */
trait QbitExtras
{
    /**
     * List fields: completion_on, seeding_time (API 2.8.1+), private, uploaded (qbt-wiki-5.md:1241, 1251, 1279;
     * qbt-wiki-4.1.md:215). `private` is the torrents/info key in 5.0.0, 5.1.4 and 5.2.4 (serialize_torrent.h KEY_TORRENT_PRIVATE,
     * null without metadata; live 5.1.4 and 5.2.4 2026-10-14); the wiki's `isPrivate` and properties' `is_private` are
     * read when `private` is missing. A completion time of 0 or below, or more than a day ahead (4294967295 is reported
     * for unfinished torrents by some versions [UNVERIFIED]), is not a time: null.
     */
    public static function listFields(array $t)
    {
        $g = function ($k) use ($t) {
            return isset($t[$k]) ? $t[$k] : null;
        };
        $done = (int) $g('completion_on');
        $private = $g('private') !== null ? $g('private') : ($g('isPrivate') !== null ? $g('isPrivate') : $g('is_private'));
        return ['completed_at' => $done > 0 && $done <= time() + 86400 ? $done : null,
            'seeding_time' => $g('seeding_time') === null ? null : (int) $g('seeding_time'),
            'private' => $private === null ? null : (bool) $private,
            'uploaded' => $g('uploaded') === null ? null : (int) $g('uploaded')];
    }

    /** POST torrents/recheck with the checked hashes, never "all" (tc-5.0.0.cpp:1260-1266; qbt-wiki-5.md:1744-1766). */
    public function recheck(array $natives)
    {
        $this->post200('torrents/recheck', ['hashes' => self::hashes($natives)]);
    }

    /** POST torrents/reannounce (tc-5.0.0.cpp:1268-1274; qbt-wiki-5.md:1768-1790). */
    public function reannounce(array $natives)
    {
        $this->post200('torrents/reannounce', ['hashes' => self::hashes($natives)]);
    }

    /**
     * POST transfer/setDownloadLimit / setUploadLimit, `limit` in bytes/s, 0 = none (qbt-wiki-5.md:1134-1180).
     * qBittorrent keeps whole KiB/s (sessionimpl-5.0.0.cpp:3359-3373), so the read-back can differ by up to 1 KiB/s.
     * With alternative speed limits on, the same calls change the alternative limits (:3459-3464), so the
     * record says which ones changed (transfer/speedLimitsMode, qbt-wiki-5.md:1088-1102).
     */
    public function setLimits($down, $up)
    {
        $alt = $this->api->text('transfer/speedLimitsMode') === '1';
        foreach (['transfer/setDownloadLimit' => $down, 'transfer/setUploadLimit' => $up] as $path => $v) {
            if ($v !== null) {
                $this->post200($path, ['limit' => (int) $v]);
            }
        }
        if (!$alt) {
            return ['alt_speed' => false];
        }
        return ['alt_speed' => true, 'note' => 'alternative speed limits are on, so the alternative limits changed;'
            . ' the normal ones apply again when the user turns them off'];
    }

    /** GET torrents/trackers (tc-5.0.0.cpp:506-530; qbt-wiki-5.md:1444-1490), without the DHT/PeX/LSD rows (:175-245). */
    public function trackers($native)
    {
        $rows = $this->api->json('torrents/trackers', ['hash' => $native]);
        if ($rows === null) {
            throw new CliError('torrent ' . strtolower($native) . ' is gone from qBittorrent', 'run `list` again');
        }
        $words = [0 => 'disabled', 1 => 'not contacted', 2 => 'working', 3 => 'updating', 4 => 'failing'];
        $out = [];
        foreach ($rows as $t) {
            if (!isset($t['url']) || strpos((string) $t['url'], '** [') === 0) {
                continue;
            }
            $st = isset($t['status']) ? (int) $t['status'] : -1;
            $out[] = ['url' => (string) $t['url'], 'tier' => isset($t['tier']) ? $t['tier'] : null,
                'status' => isset($words[$st]) ? $words[$st] : null,
                'message' => isset($t['msg']) ? (string) $t['msg'] : '', 'seeds' => isset($t['num_seeds']) ? $t['num_seeds'] : null,
                'leechers' => isset($t['num_leeches']) ? $t['num_leeches'] : null];
        }
        return $out;
    }

    /**
     * [result, ids] from the torrents/add answer: up to 5.1 "Ok." / "Fails."; 5.2 JSON success_count, failure_count,
     * pending_count, added_torrent_ids (200; 202 while a URL is fetched), 409 when nothing was added
     * (release-5.2.4 torrentscontroller.cpp:1264-1282, webapplication.cpp:396-401; live 5.2.4 2026-10-14).
     */
    public static function addAnswer($code, $body)
    {
        $j = json_decode($body, true);
        $j = is_array($j) ? $j + ['success_count' => 0, 'failure_count' => 0, 'pending_count' => 0, 'added_torrent_ids' => []] : null;
        $ids = $j && is_array($j['added_torrent_ids']) ? $j['added_torrent_ids'] : [];
        $added = $j && in_array($code, [200, 202], true) && ($ids || $j['success_count'] + $j['pending_count'] > 0);
        if ($added || ($code === 200 && $body === 'Ok.')) {
            return ['sent', $ids];
        }
        $why = $j ? "added {$j['success_count']}, failed {$j['failure_count']}" : ($body !== '' ? $body : "HTTP $code");
        return [$code === 415 ? 'error: qBittorrent says this is not a valid torrent file' : "error: qBittorrent refused it ($why)", []];
    }

    private function post200($path, array $fields)
    {
        list($code) = $this->api->post($path, $fields);
        if ($code !== 200 && $code !== 204) { // 204: an answer without a body (webapplication.cpp:368-373 in 5.2.4)
            throw new CliError("qBittorrent answered $path with HTTP $code", 'check the ids with `list` and the client with `stats`');
        }
    }
}
