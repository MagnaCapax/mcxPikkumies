<?php
/**
 * TorrentTrackers: the records of `show <id> --trackers`. A tracker URL carries the user's private-tracker
 * passkey in its path or query, under many names (passkey=, pid=, uk=, torrent_pass=, /<key>/announce...),
 * and Cli's text redaction is a list that cannot know them all. So the URL never leaves this class: a record
 * names only the scheme, host and port, which is what diagnosis needs (ADR-0019).
 */
class TorrentTrackers
{
    /** The shared status words; null when the client gives no per-tracker status. */
    const STATUS = ['working', 'failing', 'not contacted', 'updating', 'disabled'];

    /** One record per tracker of each target: torrent, tracker, tier, status, message, seeds, leechers. */
    public static function records(TorrentClient $c, array $targets)
    {
        $out = [];
        foreach ($targets as $t) {
            foreach ((array) $c->trackers($t['native']) as $tr) {
                $r = ['torrent' => $t['id'], 'tracker' => self::host(isset($tr['url']) ? (string) $tr['url'] : '')];
                foreach (['tier', 'status', 'message', 'seeds', 'leechers'] as $k) {
                    $v = isset($tr[$k]) ? $tr[$k] : null;
                    $r[$k] = $v === null || $k === 'status' ? $v : ($k === 'message' ? self::message((string) $v) : self::count($v));
                }
                if ($r['status'] !== null && !in_array($r['status'], self::STATUS, true)) {
                    $r['status'] = null;
                }
                $out[] = $r;
            }
        }
        return $out;
    }

    /** scheme://host[:port] of a URL; never the user, path or query. A host label that looks like a key is removed too. */
    public static function host($url)
    {
        $p = @parse_url(trim($url));
        if (!is_array($p) || !isset($p['host']) || $p['host'] === '') {
            return 'unknown';
        }
        $labels = array_map(function ($l) {
            return preg_match('/^(?=[0-9a-z]*[0-9])[0-9a-z]{16,}$/i', $l) ? 'REDACTED' : $l;
        }, explode('.', strtolower($p['host'])));
        $scheme = isset($p['scheme']) ? preg_replace('/[^a-z0-9+.-]/', '', strtolower($p['scheme'])) : '';
        return ($scheme !== '' ? "$scheme://" : '') . implode('.', $labels) . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    }

    /**
     * A tracker's own text with key-like tokens removed (16+ letters and digits with a digit), as in host labels:
     * a tracker may echo the passkey in prose ("invalid passkey 0123..."), where Cli's URL rules do not look.
     */
    public static function message($text)
    {
        return (string) preg_replace('/(?<![0-9a-z])(?=[0-9a-z]*[0-9])[0-9a-z]{16,}(?![0-9a-z])/i', 'REDACTED', $text);
    }

    /** A count or tier from the client, null when it says unknown (below 0, e.g. a tracker that sent no scrape). */
    public static function count($v)
    {
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : null;
    }
}
