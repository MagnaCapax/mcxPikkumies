<?php
/**
 * BalancePlan: the pure algorithm behind `balance plan` (README.md, Balance section of the shared
 * spec). Takes the normalized per-service records BalanceGather produces (or, in a test, a plain
 * array -- this class never does IO, never touches Services, never spawns anything) and proposes a
 * deterministic, explainable list of moves. It never runs a move: every record it returns is a
 * proposal and the exact `move plan ...` command a person (or the move unit, owner E, when it lands)
 * would run to act on it.
 *
 * Rules (operator, SPEC.md "Balance"):
 * - only completed torrents (progress >= 0.999) are ever proposed;
 * - goal "free" moves the largest, least currently active torrent from the box with the lowest free
 *   ratio to the box with the highest, until the spread is within tolerance or --max-moves is reached;
 * - goal "upload" moves the most actively seeding torrent from the box with the highest total up_rate
 *   to the one with the lowest, to even out upload load;
 * - a move is never proposed if it would push the target over 90% of its own quota;
 * - a torrent's name is untrusted data (SPEC.md): it is carried through every record as an opaque
 *   string field and never read, matched or branched on by this class.
 */
class BalancePlan
{
    /** goal "free": stop once every bounded service's free ratio is within this many percentage points. */
    const TOLERANCE_FREE_RATIO = 0.05;

    /** goal "upload": stop once the up_rate spread is within this fraction of the average... */
    const TOLERANCE_UPLOAD_FRACTION = 0.10;

    /** ...or this many bytes/s, whichever is larger (keeps near-idle boxes from being "balanced" forever). */
    const TOLERANCE_UPLOAD_FLOOR_BPS = 65536;

    /** A move is never proposed if it would leave the target at or above this fraction of its quota. */
    const QUOTA_CAP_FRACTION = 0.90;

    /**
     * A termination guarantee, not a feature: proposeOne() returning null, the tolerance check, and
     * --max-moves already end the loop in every real case. This is defense-in-depth against a logic
     * bug looping forever, not the mechanism relied on to stop (accuracy doctrine: a dedup/cap is
     * never the root-cause fix).
     */
    const SAFETY_MAX_ITERATIONS = 200;

    /**
     * $services: [nick => ['quota'=>['used','limit','free'], 'torrents'=>[...], 'up_rate_total'=>float, ...], ...]
     * (BalanceGather::services() shape; a test may build this by hand). $opt: 'goal' ('free'|'upload',
     * default 'free'), 'max_moves' (int|null). Returns ['moves'=>[...], 'status'=>[...]].
     */
    public static function build(array $services, array $opt)
    {
        $goal = isset($opt['goal']) && $opt['goal'] === 'upload' ? 'upload' : 'free';
        $maxMoves = isset($opt['max_moves']) && is_numeric($opt['max_moves']) && (int) $opt['max_moves'] > 0
            ? (int) $opt['max_moves'] : null;

        $excluded = array();
        $work = array();
        foreach ($services as $nick => $s) {
            $used = isset($s['quota']['used']) ? $s['quota']['used'] : null;
            if ($used === null) {
                $excluded[$nick] = !empty($s['notes']) ? implode('; ', (array) $s['notes']) : 'quota could not be read';
                continue;
            }
            $limit = isset($s['quota']['limit']) && $s['quota']['limit'] !== null ? (int) $s['quota']['limit'] : null;
            $work[$nick] = array(
                'nick' => $nick,
                'used' => (int) $used,
                'limit' => $limit,
                'torrents' => self::eligible(isset($s['torrents']) ? $s['torrents'] : array()),
                'up_rate_total' => isset($s['up_rate_total']) ? (float) $s['up_rate_total'] : 0.0,
            );
        }

        $moves = array();
        $stopped = null;
        if (count($work) < 2) {
            $stopped = 'fewer than two services have a readable quota; nothing to balance';
        } else {
            for ($i = 0; $i < self::SAFETY_MAX_ITERATIONS; $i++) {
                if ($maxMoves !== null && count($moves) >= $maxMoves) {
                    $stopped = "reached --max-moves $maxMoves";
                    break;
                }
                if (self::withinTolerance($work, $goal)) {
                    $stopped = 'free-space and upload ratios are already within tolerance';
                    break;
                }
                $move = self::proposeOne($work, $goal, count($moves) + 1);
                if ($move === null) {
                    $stopped = 'no remaining completed torrent can move without pushing a target over '
                        . (int) (self::QUOTA_CAP_FRACTION * 100) . '% of its quota';
                    break;
                }
                $moves[] = $move['record'];
                $work = $move['work'];
            }
            if ($stopped === null) {
                $stopped = 'reached the planner\'s internal safety limit (' . self::SAFETY_MAX_ITERATIONS . ' iterations)';
            }
        }

        return array('moves' => $moves, 'status' => array(
            'goal' => $goal,
            'services_considered' => count($services),
            'services_usable' => count($work),
            'excluded' => $excluded,
            'moves_proposed' => count($moves),
            'stopped_because' => $stopped,
        ));
    }

    /** Only completed torrents are ever candidates (operator rule); anything else never appears in a plan. */
    private static function eligible(array $torrents)
    {
        $out = array();
        foreach ($torrents as $t) {
            if (is_array($t) && isset($t['progress']) && is_numeric($t['progress']) && $t['progress'] >= 0.999
                && isset($t['id']) && isset($t['client']) && isset($t['size']) && is_numeric($t['size'])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Bytes free / quota limit, or null when unlimited (limit === null) or unknown -- never a guessed number. */
    public static function freeRatio(array $w)
    {
        if ($w['limit'] === null || $w['limit'] <= 0) {
            return null;
        }
        return max(0.0, min(1.0, ($w['limit'] - $w['used']) / $w['limit']));
    }

    /** True once every service that CAN be measured for this goal is within tolerance of each other. */
    private static function withinTolerance(array $work, $goal)
    {
        if ($goal === 'upload') {
            $rates = array();
            foreach ($work as $w) {
                $rates[] = $w['up_rate_total'];
            }
            if (count($rates) < 2) {
                return true;
            }
            $spread = max($rates) - min($rates);
            $avg = array_sum($rates) / count($rates);
            return $spread <= max(self::TOLERANCE_UPLOAD_FLOOR_BPS, self::TOLERANCE_UPLOAD_FRACTION * $avg);
        }
        // An unlimited service (null ratio) stands in as "fully free" here too (the same convention
        // targetOrder() uses): a very full bounded box next to an unlimited one is still a real
        // imbalance worth relieving, so it must not be filtered out of the spread calculation.
        $ratios = array();
        foreach ($work as $w) {
            $r = self::freeRatio($w);
            $ratios[] = $r === null ? 1.0 : $r;
        }
        if (count($ratios) < 2) {
            return true; // fewer than two services at all: nothing comparable, so nothing to balance
        }
        return (max($ratios) - min($ratios)) <= self::TOLERANCE_FREE_RATIO;
    }

    /**
     * One move: the worst-ranked source that still has an eligible, un-moved torrent that fits some
     * target without pushing it past the quota cap, picking the best-ranked target that fits first.
     * Returns null when no (source, target, torrent) triple exists. Deterministic: the ranking and
     * the candidate sort are total orders (ties broken by client then id), so the same input always
     * proposes the same move.
     */
    private static function proposeOne(array $work, $goal, $moveNumber)
    {
        $sources = self::sourceOrder($work, $goal);
        foreach ($sources as $fromNick) {
            $from = $work[$fromNick];
            if (!$from['torrents']) {
                continue;
            }
            $candidates = $from['torrents'];
            usort($candidates, self::candidateComparator($goal));
            $targets = self::targetOrder($work, $goal, $fromNick);
            foreach ($candidates as $t) {
                foreach ($targets as $toNick) {
                    $to = $work[$toNick];
                    // A target must be strictly better off than the source, or a source that ran out of its
                    // own candidates would fall through to moving data FROM a box that is already doing fine
                    // TO one that is doing even better -- sideways or backwards, and with only one torrent in
                    // play, straight back where it came from next iteration (an infinite oscillation; caught
                    // in review: a two-service, one-torrent fixture looped until the safety cap).
                    if (!self::isImprovement($from, $to, $goal)) {
                        continue;
                    }
                    // A candidate that is itself bigger than half the gap would leave the source better off
                    // than the target it just gave data to -- not a step toward balance but a step past it,
                    // which the next iteration would then try to undo (the same oscillation, one move later;
                    // caught in review: a two-candidate fixture swapped its largest torrent back and forth
                    // until the safety cap). Rejected here, never proposed in the first place.
                    if (self::overshoots($from, $to, $t, $goal)) {
                        continue;
                    }
                    $sizeFits = $to['limit'] === null || $to['limit'] <= 0
                        || ($to['used'] + $t['size']) <= (self::QUOTA_CAP_FRACTION * $to['limit']);
                    if (!$sizeFits) {
                        continue;
                    }
                    return self::applyMove($work, $fromNick, $toNick, $t, $goal, $moveNumber);
                }
            }
        }
        return null;
    }

    /**
     * True when moving a torrent from $from to $to is a step in the right direction: $to must be
     * strictly better off than $from (goal "free": a strictly higher free ratio; goal "upload": a
     * strictly lower total up_rate). An unlimited $to is always an improvement over any bounded
     * $from; sourceOrder() never offers an unlimited service as $from in the first place.
     */
    private static function isImprovement(array $from, array $to, $goal)
    {
        if ($goal === 'upload') {
            return $to['up_rate_total'] < $from['up_rate_total'];
        }
        $toRatio = self::freeRatio($to);
        if ($toRatio === null) {
            return true;
        }
        $fromRatio = self::freeRatio($from);
        return $fromRatio !== null && $toRatio > $fromRatio;
    }

    /**
     * True when moving $t from $from to $to would leave $from strictly better off than $to -- past the
     * balance point, not toward it. An unlimited $to can never be overshot (there is no cap to invert
     * against); $from is always bounded here (sourceOrder() never offers an unlimited source).
     */
    private static function overshoots(array $from, array $to, array $t, $goal)
    {
        if ($goal === 'upload') {
            return ($from['up_rate_total'] - $t['up_rate']) < ($to['up_rate_total'] + $t['up_rate']);
        }
        if ($to['limit'] === null || $to['limit'] <= 0) {
            return false;
        }
        $fromAfter = ($from['limit'] - ($from['used'] - $t['size'])) / $from['limit'];
        $toAfter = ($to['limit'] - ($to['used'] + $t['size'])) / $to['limit'];
        return $fromAfter > $toAfter;
    }

    /** Candidates sorted worst-ratio-first (goal "free") or busiest-first (goal "upload"). Ties: nick A-Z, deterministic. */
    private static function sourceOrder(array $work, $goal)
    {
        $nicks = array_keys($work);
        if ($goal === 'upload') {
            usort($nicks, function ($a, $b) use ($work) {
                $d = $work[$b]['up_rate_total'] - $work[$a]['up_rate_total'];
                return $d > 0 ? 1 : ($d < 0 ? -1 : strcmp($a, $b));
            });
            return $nicks;
        }
        // goal "free": a service with no bounded quota can never be "the fullest" (there is nothing to measure);
        // it is excluded as a source entirely, never ranked by a fabricated number.
        $bounded = array_values(array_filter($nicks, function ($n) use ($work) {
            return self::freeRatio($work[$n]) !== null;
        }));
        usort($bounded, function ($a, $b) use ($work) {
            $ra = self::freeRatio($work[$a]);
            $rb = self::freeRatio($work[$b]);
            return $ra < $rb ? -1 : ($ra > $rb ? 1 : strcmp($a, $b));
        });
        return $bounded;
    }

    /** Every other service, best-ratio-first (goal "free") or idlest-first (goal "upload"); unlimited quota always sorts best. */
    private static function targetOrder(array $work, $goal, $excludeNick)
    {
        $nicks = array_values(array_filter(array_keys($work), function ($n) use ($excludeNick) {
            return $n !== $excludeNick;
        }));
        if ($goal === 'upload') {
            usort($nicks, function ($a, $b) use ($work) {
                $d = $work[$a]['up_rate_total'] - $work[$b]['up_rate_total'];
                return $d > 0 ? 1 : ($d < 0 ? -1 : strcmp($a, $b));
            });
            return $nicks;
        }
        usort($nicks, function ($a, $b) use ($work) {
            $ra = self::freeRatio($work[$a]);
            $rb = self::freeRatio($work[$b]);
            $ka = $ra === null ? 2.0 : $ra; // unlimited: always the best possible target
            $kb = $rb === null ? 2.0 : $rb;
            return $ka < $kb ? 1 : ($ka > $kb ? -1 : strcmp($a, $b));
        });
        return $nicks;
    }

    /** goal "free": largest first, then lowest current upload (least disruptive to move). goal "upload": busiest first, then largest. */
    private static function candidateComparator($goal)
    {
        if ($goal === 'upload') {
            return function ($a, $b) {
                if ($a['up_rate'] !== $b['up_rate']) {
                    return $a['up_rate'] < $b['up_rate'] ? 1 : -1;
                }
                if ($a['size'] !== $b['size']) {
                    return $a['size'] < $b['size'] ? 1 : -1;
                }
                return strcmp($a['client'] . $a['id'], $b['client'] . $b['id']);
            };
        }
        return function ($a, $b) {
            if ($a['size'] !== $b['size']) {
                return $a['size'] < $b['size'] ? 1 : -1;
            }
            if ($a['up_rate'] !== $b['up_rate']) {
                return $a['up_rate'] < $b['up_rate'] ? -1 : 1;
            }
            return strcmp($a['client'] . $a['id'], $b['client'] . $b['id']);
        };
    }

    /** Builds the move record and the updated $work (the torrent moves from source to target in the working copy too, so a later move in the same plan can chain off it). */
    private static function applyMove(array $work, $fromNick, $toNick, array $t, $goal, $moveNumber)
    {
        $from = $work[$fromNick];
        $to = $work[$toNick];
        // "Before" figures for the reason text, captured ahead of the mutation below.
        $fromRateBefore = $from['up_rate_total'];
        $toRateBefore = $to['up_rate_total'];
        $fromRatioBefore = self::freeRatio($from);
        $toRatioBefore = self::freeRatio($to);

        $from['used'] -= $t['size'];
        $to['used'] += $t['size'];
        $from['torrents'] = array_values(array_filter($from['torrents'], function ($c) use ($t) {
            return !($c['client'] === $t['client'] && $c['id'] === $t['id']);
        }));
        $to['torrents'][] = $t;
        $from['up_rate_total'] = max(0.0, $from['up_rate_total'] - $t['up_rate']);
        $to['up_rate_total'] += $t['up_rate'];
        $work[$fromNick] = $from;
        $work[$toNick] = $to;

        if ($goal === 'upload') {
            $reason = sprintf(
                'upload: %s is seeding at a total of %s B/s, %s at %s B/s; moving the most actively seeding completed torrent to spread upload load',
                $fromNick, number_format($fromRateBefore, 0), $toNick, number_format($toRateBefore, 0)
            );
        } else {
            $reason = sprintf(
                '%s is %s free, %s is %s free; moving the largest completed torrent with the lowest current upload rate to even out free space',
                $fromNick, $fromRatioBefore === null ? 'unbounded' : round($fromRatioBefore * 100, 1) . '%',
                $toNick, $toRatioBefore === null ? 'unbounded' : round($toRatioBefore * 100, 1) . '%'
            );
        }

        $record = array(
            'move' => $moveNumber,
            'from' => $fromNick,
            'to' => $toNick,
            'client' => $t['client'],
            'id' => $t['id'],
            'name' => $t['name'], // untrusted data, passed through opaque -- never read by this class's own logic
            'size' => $t['size'],
            'size_human' => class_exists('CliHuman', false) ? CliHuman::bytes($t['size']) : $t['size'] . ' B',
            'reason' => $reason,
            // escapeshellarg on every piece, defense in depth: BalanceGather already drops any torrent
            // whose id does not match the client's own hex id shape (security review 2026-10-20, H1,
            // tested -- an unvalidated id let "x; touch /tmp/pwn #" reach this string verbatim), but
            // this class's own contract says it also takes a plain array built by hand (its own
            // docblock, "or, in a test, a plain array") -- the quoting has to hold even when nothing
            // upstream has validated the id. The result is still one valid, copy-pasteable shell command.
            'command' => 'move plan ' . escapeshellarg($t['id']) . ' --to ' . escapeshellarg($toNick)
                . ' --from ' . escapeshellarg($fromNick) . ' --client ' . escapeshellarg($t['client']),
        );
        return array('record' => $record, 'work' => $work);
    }
}
