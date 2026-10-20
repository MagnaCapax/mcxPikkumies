<?php
/**
 * UpstreamLimit: GitHub's unauthenticated API allowance is per IP address (ADR-0092): 60 core calls an hour and 10
 * searches a minute for the whole server, shared with every other account and with Hermes itself. This keeps headroom
 * for the neighbours: no core call while 15 or fewer are left, no search while 3 or fewer are left, at most 3 core calls
 * and 2 searches per run, and per account at most 10 core calls and 5 searches an hour across runs, one call at a
 * time. A primary limit (remaining 0) stops that bucket until its reset plus up to two minutes of jitter, so every
 * account on the box does not come back in the same second; a secondary limit
 * (retry-after, or the message says so) is waited out once and then reported. The state lives in the user's own
 * local/upstream-cache/ratelimit.json, refreshed from every response's x-ratelimit-* headers.
 */
class UpstreamLimit
{
    const FLOOR = ['core' => 15, 'search' => 3];
    const PER_RUN = ['core' => 3, 'search' => 2];
    const HOURLY = ['core' => 10, 'search' => 5]; // per account, across runs: one user never spends the server's hour
    const JITTER = 120;
    const SECONDARY_MIN = 60;  // seconds to wait out a secondary limit before the one retry
    const SECONDARY_MAX = 90;  // longer than this and the call is refused instead of waited for

    /** Calls spent by this process, per bucket. */
    public static $spent = ['core' => 0, 'search' => 0];

    /** The saved state: [bucket => [remaining, reset, until]]. */
    public static function load($file)
    {
        $s = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return is_array($s) ? $s : [];
    }

    public static function save($file, array $state)
    {
        UpstreamCache::writeFile($file, (string) json_encode($state));
    }

    /** True when the saved state for $bucket is missing or its window has ended: read the free /rate_limit first. */
    public static function stale(array $state, $bucket, $now)
    {
        return !isset($state[$bucket]['reset']) || (int) $state[$bucket]['reset'] <= $now;
    }

    /** '' when a call to $bucket may go now, otherwise why not (for the user, with the local reset time). */
    public static function refusal(array $state, $bucket, $now)
    {
        $b = isset($state[$bucket]) ? $state[$bucket] : [];
        if (isset($b['until']) && (int) $b['until'] > $now) {
            return "GitHub's shared limit for this server is used up until " . date('H:i', (int) $b['until']);
        }
        if (self::$spent[$bucket] >= self::PER_RUN[$bucket]) {
            return 'this run already made ' . self::PER_RUN[$bucket] . " $bucket calls to GitHub, the most one run makes";
        }
        $u = isset($state['user']) ? $state['user'] : [];
        if (isset($u['since'], $u[$bucket]) && (int) $u['since'] + 3600 > $now && (int) $u[$bucket] >= self::HOURLY[$bucket]) {
            return 'this account already made ' . self::HOURLY[$bucket] . " $bucket calls to GitHub this hour, its share of the server's allowance; again at "
                . date('H:i', (int) $u['since'] + 3600);
        }
        if (isset($b['remaining'], $b['reset']) && (int) $b['reset'] > $now && (int) $b['remaining'] <= self::FLOOR[$bucket]) {
            return "GitHub's shared $bucket limit for this server is low ({$b['remaining']} left until " . date('H:i', (int) $b['reset'])
                . '); the rest is left for other accounts';
        }
        return '';
    }

    /** The state with one more call of $bucket counted for this account in the current hour (a new hour starts at 0). */
    public static function spend(array $state, $bucket, $now)
    {
        $u = isset($state['user']['since']) && (int) $state['user']['since'] + 3600 > $now ? $state['user'] : ['since' => $now, 'core' => 0, 'search' => 0];
        $u[$bucket] = (isset($u[$bucket]) ? (int) $u[$bucket] : 0) + 1;
        $state['user'] = $u;
        return $state;
    }

    /** The state after a response: remaining and reset from its headers, for the bucket the response names. */
    public static function record(array $state, $bucket, array $headers, $now)
    {
        $h = function ($name) use ($headers) {
            return isset($headers[$name][0]) ? (string) $headers[$name][0] : null;
        };
        $bucket = in_array($h('x-ratelimit-resource'), ['core', 'search'], true) ? $h('x-ratelimit-resource') : $bucket;
        if ($h('x-ratelimit-remaining') !== null && $h('x-ratelimit-reset') !== null) {
            $state[$bucket] = ['remaining' => (int) $h('x-ratelimit-remaining'), 'reset' => (int) $h('x-ratelimit-reset')]
                + (isset($state[$bucket]['until']) && (int) $state[$bucket]['until'] > $now ? ['until' => (int) $state[$bucket]['until']] : []);
        }
        return $state;
    }

    /** The state from the free GET /rate_limit body (it does not count against the limit). */
    public static function fromRateLimit(array $state, $body)
    {
        $j = json_decode((string) $body, true);
        foreach (['core', 'search'] as $b) {
            if (isset($j['resources'][$b]['remaining'], $j['resources'][$b]['reset'])) {
                $state[$b] = ['remaining' => (int) $j['resources'][$b]['remaining'], 'reset' => (int) $j['resources'][$b]['reset']]
                    + (isset($state[$b]['until']) ? ['until' => (int) $state[$b]['until']] : []);
            }
        }
        return $state;
    }

    /**
     * What a response means: ok, primary (remaining 0: wait for the reset), secondary (retry-after, or the message
     * says so), invalid (422: the query), missing (404: also a private repository), transient (no answer or 5xx),
     * other. By headers and message, never by the status code alone: GitHub answers both limits with 403 or 429.
     */
    public static function classify($code, array $headers, $body)
    {
        if ($code >= 200 && $code < 300) {
            return 'ok';
        }
        if ($code === 403 || $code === 429) {
            if (isset($headers['retry-after'][0]) || stripos((string) $body, 'secondary rate limit') !== false) {
                return 'secondary';
            }
            if (isset($headers['x-ratelimit-remaining'][0]) && (string) $headers['x-ratelimit-remaining'][0] === '0') {
                return 'primary';
            }
            return 'other';
        }
        if ($code === 422) {
            return 'invalid';
        }
        if ($code === 404) {
            return 'missing';
        }
        return $code === 0 || $code >= 500 ? 'transient' : 'other';
    }

    /** The state after a primary limit: no call to $bucket until its reset plus random jitter. */
    public static function block(array $state, $bucket, array $headers, $now, $jitter = null)
    {
        $reset = isset($headers['x-ratelimit-reset'][0]) ? (int) $headers['x-ratelimit-reset'][0] : $now + 60;
        $jitter = $jitter === null ? random_int(0, self::JITTER) : (int) $jitter;
        $state[$bucket] = ['remaining' => 0, 'reset' => $reset, 'until' => max($reset, $now) + $jitter];
        return $state;
    }

    /** Seconds to wait before the one retry after a secondary limit, or null when that is too long to wait in a call. */
    public static function secondaryWait(array $headers)
    {
        $after = isset($headers['retry-after'][0]) ? (int) $headers['retry-after'][0] : 0;
        $wait = max(self::SECONDARY_MIN, $after);
        return $wait > self::SECONDARY_MAX ? null : $wait;
    }
}
