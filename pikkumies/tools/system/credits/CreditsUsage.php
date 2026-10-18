<?php
/**
 * CreditsUsage: the credits tool's requests and its `usage` and `models` verbs (ADR-0050). Every verb asks through
 * get(): no key or no endpoint is an error and no request; a 404, a refused key, no answer or an answer that is not
 * JSON is an error that never quotes the answer's body (a server could echo the Authorization header back). The
 * turning of answers into records is pure (usageRecords, modelRecords), so the tests check it without a server.
 */
require_once __DIR__ . '/CreditsLine.php';

class CreditsUsage
{
    const DAYS_DEFAULT = 30;
    /** The counters GET /usage gives per day and per model. */
    const COUNTS = ['requests', 'credits', 'prompt_tokens', 'cached_tokens', 'completion_tokens', 'reasoning_tokens'];

    /** GET {endpoint}{$route} decoded to an array, or a CliError. $name: the route in messages ('balance', 'usage', 'models'). */
    public static function get(array $prov, $route, $name, $keyHint, $max = 65536)
    {
        if ($prov['key'] === '') {
            throw new CliError('no AI key found for this account; no request was made', $keyHint);
        }
        if ($prov['endpoint'] === '') {
            throw new CliError('the AI key has no endpoint; no request was made', 'the key file needs an endpoint= line, or set provider.endpoint in ~/pikkumies/local/config.php');
        }
        $host = (string) parse_url($prov['endpoint'], PHP_URL_HOST);
        $r = Provider::routeAnswer($prov, $route, 10, $max);
        if ($r['code'] === 404) {
            throw new CliError("the AI endpoint ($host) has no $name route, so it cannot report credits", 'only the Pulsed Media AI key reports credits here; ' . $keyHint . '. With your own provider, its own account page shows the balance');
        }
        if ($r['code'] === 401 || $r['code'] === 403) {
            throw new CliError("the AI endpoint ($host) refused the key (HTTP {$r['code']}): it may have been replaced or removed", $keyHint);
        }
        if ($r['code'] === 0) {
            throw new CliError("could not reach the AI endpoint ($host): " . $r['error'], 'try again in a minute; if it persists, the provider or this server\'s network is down');
        }
        $data = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($data) || ($name !== 'models' && (!isset($data['balance']) || !is_int($data['balance'])))) {
            throw new CliError("the AI endpoint ($host) gave no $name (HTTP {$r['code']})", 'try again in a minute');
        }
        return $data;
    }

    /** The --days value: a whole number from 1 to CreditsLine::DAYS (the longest window the API gives); absent = DAYS_DEFAULT. */
    public static function days(array $opt)
    {
        if (!isset($opt['days'])) {
            return self::DAYS_DEFAULT;
        }
        $d = $opt['days'];
        if (!is_string($d) || !preg_match('/^[0-9]{1,3}$/', $d) || (int) $d < 1 || (int) $d > CreditsLine::DAYS) {
            throw new CliError('--days takes a whole number of days from 1 to ' . CreditsLine::DAYS . '; no request was made', 'for example: usage --days 7');
        }
        return (int) $d;
    }

    /**
     * Records from a GET /usage answer: first the summary (balance, period, totals over the daily rows), then one row
     * per model, most credits first, then one row per day, newest first. cache_share is cached / prompt tokens.
     */
    public static function usageRecords(array $data, $days)
    {
        $totals = array_fill_keys(self::COUNTS, 0);
        $dayRows = [];
        foreach (self::rows($data, 'daily', 'day') as $d) {
            foreach (self::COUNTS as $k) {
                $totals[$k] += $d[$k];
            }
            $dayRows[] = ['day' => $d['day'], 'requests' => $d['requests'], 'credits' => $d['credits']];
        }
        usort($dayRows, function ($a, $b) {
            return strcmp($b['day'], $a['day']);
        });
        $modelRows = [];
        foreach (self::rows($data, 'models', 'model') as $m) {
            $modelRows[] = ['model' => $m['model'], 'requests' => $m['requests'], 'credits' => $m['credits'], 'prompt_tokens' => $m['prompt_tokens'],
                'completion_tokens' => $m['completion_tokens'], 'cache_share' => self::cacheShare($m['cached_tokens'], $m['prompt_tokens'])];
        }
        usort($modelRows, function ($a, $b) {
            return ($b['credits'] - $a['credits']) ?: strcmp($a['model'], $b['model']);
        });
        $since = isset($data['since']) && is_int($data['since']) ? gmdate('Y-m-d', $data['since']) : null;
        $summary = ['balance' => $data['balance'], 'period' => ['days' => isset($data['days']) && is_int($data['days']) ? $data['days'] : $days, 'since' => $since],
            'totals' => $totals + ['cache_share' => self::cacheShare($totals['cached_tokens'], $totals['prompt_tokens'])]];
        return array_merge([$summary], $modelRows, $dayRows);
    }

    /** Cached input tokens as a share of prompt tokens, two decimals; null when there were no prompt tokens. */
    public static function cacheShare($cached, $prompt)
    {
        return $prompt > 0 ? round($cached / $prompt, 2) : null;
    }

    /**
     * Records from a GET /models answer, in the endpoint's order: id, credits_per_million_tokens when it sends rates
     * (read like the credits line reads them) and description when it sends one. Nothing else and nothing invented.
     */
    public static function modelRecords(array $data)
    {
        $out = [];
        foreach (isset($data['data']) && is_array($data['data']) ? $data['data'] : [] as $m) {
            if (!isset($m['id']) || !is_string($m['id']) || $m['id'] === '') {
                continue;
            }
            $row = ['id' => $m['id']];
            $rate = CreditsFormat::rate($m);
            if ($rate !== null) {
                $row['credits_per_million_tokens'] = $rate;
            }
            if (isset($m['description']) && is_string($m['description']) && trim($m['description']) !== '') {
                $row['description'] = trim($m['description']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** The $list rows of a /usage answer that have a string $name, every counter an int (0 when missing). */
    private static function rows(array $data, $list, $name)
    {
        $out = [];
        foreach (isset($data[$list]) && is_array($data[$list]) ? $data[$list] : [] as $r) {
            if (!is_array($r) || !isset($r[$name]) || !is_string($r[$name])) {
                continue;
            }
            $row = [$name => $r[$name]];
            foreach (self::COUNTS as $k) {
                $row[$k] = isset($r[$k]) && is_int($r[$k]) ? $r[$k] : 0;
            }
            $out[] = $row;
        }
        return $out;
    }
}
