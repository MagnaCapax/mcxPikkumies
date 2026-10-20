<?php
/**
 * FindStatus: the status line `find` adds after its candidates (tools/torrent-clients/find/README.md) --
 * `unique` (true only for one info-hash match, or exactly one exact-name match -- never for a substring or
 * word-token match, however few candidates there are), `ambiguous_reason`, and `next`: the one thing to
 * check before asking the user, so the same query is never asked about twice.
 */
class FindStatus
{
    const NEXT = 'compare size/files/tracker host on each candidate (`show <id> --files --trackers`) and ask the user once only if still ambiguous';

    public static function build($query, array $candidates)
    {
        if (FindMatch::isInfohash($query)) {
            $n = count($candidates); // every candidate here is an infohash match, by construction
            if ($n === 1) {
                return ['unique' => true, 'ambiguous_reason' => null, 'next' => null];
            }
            return ['unique' => false, 'next' => $n > 1 ? self::NEXT : null,
                'ambiguous_reason' => $n > 1 ? "that info-hash was found in $n places" : 'no torrent with that info-hash was found'];
        }
        $exact = array_values(array_filter($candidates, function ($c) {
            return $c['match'] === 'exact';
        }));
        if (count($exact) === 1) {
            return ['unique' => true, 'ambiguous_reason' => null, 'next' => null];
        }
        if (!$candidates) {
            return ['unique' => false, 'ambiguous_reason' => 'no candidate torrent matched that query', 'next' => null];
        }
        $reason = count($exact) > 1
            ? count($exact) . ' exact-name matches were found in different places'
            : 'no exact name match; ' . count($candidates) . ' candidate(s) by substring or word match';
        return ['unique' => false, 'ambiguous_reason' => $reason, 'next' => self::NEXT];
    }
}
