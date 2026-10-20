<?php
/**
 * FindMatch: how a torrent's id/name scores against a `find` query (tools/torrent-clients/find/README.md).
 * A lower-case 40-hex query is an info-hash: only the id is compared, exactly, never the name. Any other
 * query is matched against the name: exact (case-insensitive) first, then substring, then a word-token
 * overlap with a simple, deterministic score; a query that shares no word with the name is not a candidate.
 */
class FindMatch
{
    public static function isInfohash($query)
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/', strtolower((string) $query));
    }

    /**
     * ['match' => infohash|exact|substring|words, 'score' => 0..1], or null when $query does not match
     * $name/$id at all. $id and $name are untrusted data (a client's own strings): compared, never parsed.
     */
    public static function classify($query, $name, $id)
    {
        if (self::isInfohash($query)) {
            return strtolower((string) $id) === strtolower((string) $query) ? ['match' => 'infohash', 'score' => 1.0] : null;
        }
        $q = strtolower(trim((string) $query));
        $n = strtolower(trim((string) $name));
        if ($q === '') {
            return null;
        }
        if ($q === $n) {
            return ['match' => 'exact', 'score' => 1.0];
        }
        if (strpos($n, $q) !== false) {
            return ['match' => 'substring', 'score' => 0.8];
        }
        $qt = self::tokens($q);
        if (!$qt) {
            return null;
        }
        $hits = count(array_intersect($qt, self::tokens($n)));
        return $hits > 0 ? ['match' => 'words', 'score' => round($hits / count($qt) * 0.6, 2)] : null;
    }

    /** Lower-case alphanumeric words, de-duplicated; punctuation, dots and brackets never count as a word. */
    private static function tokens($s)
    {
        return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', $s))));
    }
}
