<?php
/**
 * GoalReviewWindow: what the goal review looks at (ADR-0071), built from an interval unit's records (ADR-0067): the
 * user's requests in the window (substantive, not a row Hermes added, not Pikkumies' own resume text: lib/UserTurn.php),
 * the agent's latest texts, its tool calls one line each, and free candidates the model may point to: up to 8 pages
 * from the memory and documentation search (DocSources, offline) as D1..D8 and up to 8 tool units whose name and
 * description share words with the requests as T1..T8. A candidate the session's tool calls already used is left out.
 * skip(): why no review is due ('' = due), and whether the records should come again with the next ones.
 */
require_once __DIR__ . '/UserTurn.php';
require_once __DIR__ . '/DocSources.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/Generate.php';

class GoalReviewWindow
{
    const REQUESTS = 8;
    const CANDIDATES = 8;
    const BUDGET = ['requests' => 6000, 'replies' => 4000, 'tools' => 3000];

    /** ['requests' => [texts], 'firstOnly' => bool, 'scheduled' => bool, 'replies' => [texts], 'tools' => [lines], 'used' => args text]. */
    public static function read(array $records)
    {
        $w = ['requests' => [], 'firstOnly' => true, 'scheduled' => false, 'replies' => [], 'tools' => [], 'used' => ''];
        foreach ($records as $r) {
            $kind = is_array($r) && isset($r['kind']) ? $r['kind'] : '';
            $text = isset($r['text']) && is_string($r['text']) ? $r['text'] : '';
            $w['scheduled'] = $w['scheduled'] || !empty($r['scheduled']);
            if ($kind === 'user' && empty($r['synthetic']) && UserTurn::substantive($text)) {
                $w['requests'][] = $text;
                $w['firstOnly'] = $w['firstOnly'] && !empty($r['first_turn']);
            } elseif ($kind === 'assistant' || $kind === 'reply') {
                $w['replies'][] = $text;
            } elseif ($kind === 'tool') {
                $args = isset($r['args']) ? (string) $r['args'] : '';
                $w['used'] .= "\n" . $args;
                $w['tools'][] = self::line((isset($r['tool']) ? $r['tool'] : '?') . ' ' . $args . (isset($r['status']) && $r['status'] !== 'ok' ? " ({$r['status']})" : ''), 160);
            }
        }
        $w['requests'] = array_slice($w['requests'], -self::REQUESTS);
        return $w;
    }

    /** [reason, keep]: '' when a review is due; keep true means the records come again with the next ones. */
    public static function skip(array $w, array $config)
    {
        if ($w['scheduled']) {
            return ['scheduled', false];
        }
        if (!empty($config['hooks']['llm']['disabled'])) {
            return ['llm-disabled', false];
        }
        if (!$w['requests']) {
            return ['no-request', false];
        }
        return $w['firstOnly'] ? ['first-turn', true] : ['', false];
    }

    /** [D-id => [title, path]] and [T-id => [name, script]]: free candidates, none the session already used. */
    public static function candidates(array $w, array $config, $toolsDir = null)
    {
        $query = self::cut(implode(' ', $w['requests']), 1000);
        $docs = [];
        list($hits) = DocSources::search($query, self::CANDIDATES, true, 0.3); // free text: pages sharing a third of its words
        foreach ($hits as $h) {
            if (count($docs) < self::CANDIDATES && strpos($w['used'], $h[0]) === false) {
                $docs['D' . (count($docs) + 1)] = [self::line($h[1], 120), Config::shown($h[0])];
            }
        }
        $want = array_diff(array_unique(array_filter(UserTurn::words($query), function ($x) {
            return strlen($x) >= 4;
        })), UserTurn::FILLER);
        $scored = [];
        $off = Generate::disabledCategories($config);
        foreach (Units::all($toolsDir) as $u) {
            if ($u['errors'] || !is_file($u['script']) || array_filter($off, function ($c) use ($u) { return Tree::within($u['category'], $c); })
                || strpos($w['used'], $u['name'] . '.php') !== false) {
                continue;
            }
            $score = count(array_intersect($want, UserTurn::words($u['name'] . ' ' . $u['meta']['description'])));
            if ($score > 0) {
                $scored[] = [$score, $u['name'], Config::shown($u['script'])];
            }
        }
        usort($scored, function ($a, $b) {
            return $b[0] - $a[0] ?: strcmp($a[1], $b[1]);
        });
        $tools = [];
        foreach (array_slice($scored, 0, self::CANDIDATES) as $i => $s) {
            $tools['T' . ($i + 1)] = [$s[1], $s[2]];
        }
        return [$docs, $tools];
    }

    /** Items from the end (the newest) while they fit in $budget characters, kept in their order. */
    public static function fit(array $items, $budget, $each)
    {
        $out = [];
        for ($i = count($items) - 1; $i >= 0 && $budget > 0; $i--) {
            $t = self::cut($items[$i], min($each, $budget));
            $budget -= strlen($t) + 1;
            array_unshift($out, $t);
        }
        return $out;
    }

    /** One line, at most $max characters. */
    public static function line($text, $max)
    {
        $flat = preg_replace('/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]+/u', ' ', (string) $text);
        return self::cut(trim(preg_replace('/ {2,}/', ' ', $flat === null ? str_replace(["\r", "\n"], ' ', (string) $text) : $flat)), $max);
    }

    public static function cut($s, $max)
    {
        return strlen($s) <= $max ? $s : (preg_match('/^.{0,' . (int) $max . '}/su', $s, $m) ? $m[0] : substr($s, 0, $max));
    }
}
