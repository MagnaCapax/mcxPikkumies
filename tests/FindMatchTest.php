<?php
/** FindMatch::isInfohash() and classify() (tools/torrent-clients/find/FindMatch.php). */
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/find/FindMatch.php';

return [
    'findmatch: a 40-hex query only ever compares against the id, never the name, and is case-insensitive' => function () {
        $hash = str_repeat('AB', 20);
        check(FindMatch::isInfohash($hash), 'a mixed-case 40-hex string must still count as an info-hash');
        check(FindMatch::classify($hash, 'completely unrelated name', str_repeat('ab', 20)) === ['match' => 'infohash', 'score' => 1.0],
            'a matching id (any case) must score 1.0 regardless of the name');
        check(FindMatch::classify($hash, $hash, str_repeat('cd', 20)) === null,
            'the hash must never be compared against the name, even when the name happens to equal it');
    },
    'findmatch: a 39- or 41-character hex string is not an info-hash' => function () {
        check(!FindMatch::isInfohash(str_repeat('a', 39)), '39 hex characters must not count as an info-hash');
        check(!FindMatch::isInfohash(str_repeat('a', 41)), '41 hex characters must not count as an info-hash');
        check(!FindMatch::isInfohash(str_repeat('g', 40)), 'non-hex characters must not count as an info-hash');
    },
    'findmatch: exact beats substring beats word-token, in that score order' => function () {
        $id = str_repeat('1', 40);
        check(FindMatch::classify('Debian 13.7.0', 'Debian 13.7.0', $id) === ['match' => 'exact', 'score' => 1.0], 'exact, case-insensitive');
        check(FindMatch::classify('debian', 'Debian 13.7.0 netinst', $id) === ['match' => 'substring', 'score' => 0.8], 'substring, case-insensitive');
        $words = FindMatch::classify('debian netinst extra', 'Debian 13.7.0 netinst', $id);
        check($words['match'] === 'words' && $words['score'] < 0.8, 'word-token score must stay below every substring score: ' . json_encode($words));
    },
    'findmatch: word-token score is a simple, deterministic fraction of the query\'s own words that appear in the name' => function () {
        $id = str_repeat('2', 40);
        $two = FindMatch::classify('ubuntu desktop', 'The Ubuntu Server ISO', $id); // 1 of 2 query words
        $one = FindMatch::classify('ubuntu server desktop', 'The Ubuntu Server ISO', $id); // 2 of 3 query words
        check($two !== null && $one !== null, 'both must still be candidates: ' . json_encode([$two, $one]));
        check($two['score'] === round(1 / 2 * 0.6, 2), 'two: ' . json_encode($two));
        check($one['score'] === round(2 / 3 * 0.6, 2), 'one: ' . json_encode($one));
        check(FindMatch::classify('completely unrelated query', 'The Ubuntu Server ISO', $id) === null,
            'zero shared words must not be a candidate at all');
    },
    'findmatch: an empty query never matches anything' => function () {
        check(FindMatch::classify('', 'Anything', str_repeat('3', 40)) === null, 'an empty query must not match an empty-ish name either');
    },
];
