<?php
/**
 * PlexRecords: Plex MediaContainer objects as the category's records (README.md of this unit, "Plex calls";
 * field names from the community spec 1.1.1, [UNVERIFIED-spec]). PlexServer.php has the verbs.
 */
class PlexRecords
{
    /** A Directory of /library/sections/all; $items: its item count or null. */
    public static function library(array $d, $items)
    {
        $paths = array_map(function ($l) {
            return isset($l['path']) ? (string) $l['path'] : '';
        }, isset($d['Location']) ? (array) $d['Location'] : []);
        return ['id' => (string) (isset($d['key']) ? $d['key'] : ''), 'name' => (string) (isset($d['title']) ? $d['title'] : ''),
            'type' => (string) (isset($d['type']) ? $d['type'] : ''), 'paths' => array_values(array_filter($paths)), 'items' => $items,
            'scanning' => !empty($d['refreshing']), 'progress' => null];
    }

    /** A Metadata of /status/sessions. */
    public static function session(array $m)
    {
        $sess = isset($m['Session']) ? (array) $m['Session'] : [];
        $p = isset($m['Player']) ? (array) $m['Player'] : [];
        $tc = isset($m['TranscodeSession']) ? (array) $m['TranscodeSession'] : null;
        $decisions = $tc === null ? [] : array_intersect_key($tc, ['videoDecision' => 1, 'audioDecision' => 1]);
        return [
            'id' => (string) (isset($sess['id']) ? $sess['id'] : ''),
            'user' => (string) (isset($m['User']['title']) ? $m['User']['title'] : ''),
            'client' => (string) (isset($p['product']) ? $p['product'] : ''),
            'device' => (string) (isset($p['title']) ? $p['title'] : ''),
            'title' => self::title($m),
            'progress' => !empty($m['duration']) && isset($m['viewOffset']) ? round($m['viewOffset'] / $m['duration'], 3) : null,
            'paused' => isset($p['state']) && $p['state'] === 'paused',
            'play' => $tc !== null && (!$decisions || in_array('transcode', $decisions, true)) ? 'transcode' : 'direct',
            'transcode_reasons' => [],
            'hardware' => $tc === null ? null : !empty($tc['transcodeHwFullPipeline']) || !empty($tc['transcodeHwRequested']),
            'bitrate' => null,
            'remote' => isset($p['local']) ? !$p['local'] : null,
            'address' => (string) (isset($p['address']) ? $p['address'] : ''),
            'public_address' => (string) (isset($p['remotePublicAddress']) ? $p['remotePublicAddress'] : ''),
        ];
    }

    /** A Metadata of /library/recentlyAdded. */
    public static function item(array $m)
    {
        return ['title' => self::title($m), 'type' => (string) (isset($m['type']) ? $m['type'] : ''),
            'library' => (string) (isset($m['librarySectionTitle']) ? $m['librarySectionTitle'] : ''),
            'added_at' => isset($m['addedAt']) ? (int) $m['addedAt'] : null];
    }

    public static function title(array $m)
    {
        return (!empty($m['grandparentTitle']) ? $m['grandparentTitle'] . ' - ' : '') . (isset($m['title']) ? $m['title'] : '');
    }
}
