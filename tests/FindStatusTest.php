<?php
/** FindStatus::build() (tools/torrent-clients/find/FindStatus.php): unique/ambiguous_reason/next. */
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/find/FindMatch.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/find/FindStatus.php';

return [
    'findstatus: exactly one info-hash candidate is unique with no reason and nothing left to do' => function () {
        $hash = str_repeat('a', 40);
        check(FindStatus::build($hash, [['match' => 'infohash', 'score' => 1.0]]) === ['unique' => true, 'ambiguous_reason' => null, 'next' => null], 'one hash hit');
    },
    'findstatus: zero or several info-hash candidates are both not unique, but only several carry a next step' => function () {
        $hash = str_repeat('a', 40);
        $zero = FindStatus::build($hash, []);
        check($zero['unique'] === false && $zero['next'] === null && strpos($zero['ambiguous_reason'], 'no torrent') === 0, 'zero: ' . json_encode($zero));
        $two = FindStatus::build($hash, [['match' => 'infohash', 'score' => 1.0], ['match' => 'infohash', 'score' => 1.0]]);
        check($two['unique'] === false && $two['next'] !== null && strpos($two['ambiguous_reason'], '2 places') !== false, 'two: ' . json_encode($two));
    },
    'findstatus: exactly one exact-name candidate is unique even next to several weaker ones' => function () {
        $c = [['match' => 'exact', 'score' => 1.0], ['match' => 'substring', 'score' => 0.8], ['match' => 'words', 'score' => 0.3]];
        check(FindStatus::build('debian', $c) === ['unique' => true, 'ambiguous_reason' => null, 'next' => null], 'one exact among several');
    },
    'findstatus: a single substring or word match alone is never unique' => function () {
        $s = FindStatus::build('debian', [['match' => 'substring', 'score' => 0.8]]);
        check($s['unique'] === false && $s['next'] !== null, 'a lone substring match must still need a next step: ' . json_encode($s));
    },
    'findstatus: no candidates at all is not unique and has no next step' => function () {
        check(FindStatus::build('nothing like it', []) === ['unique' => false, 'ambiguous_reason' => 'no candidate torrent matched that query', 'next' => null], 'zero candidates');
    },
    'findstatus: several exact-name candidates are reported distinctly from "no exact match"' => function () {
        $two = FindStatus::build('debian', [['match' => 'exact', 'score' => 1.0], ['match' => 'exact', 'score' => 1.0]]);
        check(strpos($two['ambiguous_reason'], '2 exact-name') === 0, 'two exact: ' . json_encode($two));
    },
];
