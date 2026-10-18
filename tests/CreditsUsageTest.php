<?php
/**
 * tools/system/credits `usage` and `models` (ADR-0050): where the credits went, per model and per day, and the models
 * the endpoint offers with only what it sends. The real script runs against tests/fixtures/credits/harness.php, whose
 * router serves /<prefix>/<route> from <prefix>-<route>.json when that file exists.
 */
require_once __DIR__ . '/fixtures/credits/harness.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsUsage.php';

if (!function_exists('creditsUsageAnswer')) {
    /** A GET /usage answer: three days, three models (one with no prompt tokens), counters as the proxy sends them. */
    function creditsUsageAnswer()
    {
        $row = function ($name, $value, $requests, $prompt, $cached, $completion, $credits) {
            return [$name => $value, 'requests' => $requests, 'prompt_tokens' => $prompt, 'cached_tokens' => $cached,
                'completion_tokens' => $completion, 'reasoning_tokens' => 0, 'credits' => $credits];
        };
        return ['balance' => 4000000, 'days' => 7, 'since' => 1760227200, 'grants' => [],
            'daily' => [$row('day', '2026-10-12', 2, 1000, 250, 100, 5000), $row('day', '2026-10-14', 5, 4000, 3000, 400, 20000), $row('day', '2026-10-13', 1, 0, 0, 0, 0)],
            'models' => [$row('model', 'small', 2, 1000, 250, 100, 5000), $row('model', 'zero-prompt', 1, 0, 0, 0, 0), $row('model', 'big', 5, 4000, 3000, 400, 20000),
                $row('model', 'also-5000', 1, 300, 0, 10, 5000)]];
    }

    /** Runs a credits verb against the fixture with <prefix>-usage.json / <prefix>-models.json written first. Returns [code, records, status, raw]. */
    function creditsUsageRun($base, $dir, $prefix, array $args, array $files = [])
    {
        foreach ($files as $route => $data) {
            file_put_contents("$dir/$prefix-$route.json", json_encode($data));
        }
        list($code, $out) = creditsToolRun($dir, "$base/$prefix", $args);
        $lines = cliLines($out);
        $status = $lines ? array_pop($lines) : null;
        return [$code, $lines, $status, $out];
    }
}

return [
    'credits usage: models sorted by credits (ties by name), cache_share is cached/prompt with null for no prompt tokens, days newest first' => function () {
        $r = CreditsUsage::usageRecords(creditsUsageAnswer(), 7);
        check($r[0] === ['balance' => 4000000, 'period' => ['days' => 7, 'since' => '2025-10-12'],
            'totals' => ['requests' => 8, 'credits' => 25000, 'prompt_tokens' => 5000, 'cached_tokens' => 3250, 'completion_tokens' => 500, 'reasoning_tokens' => 0, 'cache_share' => 0.65]],
            'summary: ' . json_encode($r[0]));
        check(array_column(array_slice($r, 1, 4), 'model') === ['big', 'also-5000', 'small', 'zero-prompt'], 'model order: ' . json_encode($r));
        check($r[1] === ['model' => 'big', 'requests' => 5, 'credits' => 20000, 'prompt_tokens' => 4000, 'completion_tokens' => 400, 'cache_share' => 0.75], 'big: ' . json_encode($r[1]));
        check($r[3]['cache_share'] === 0.25 && $r[2]['cache_share'] === 0.0, 'cache shares: ' . json_encode($r));
        check($r[4]['cache_share'] === null, 'no prompt tokens must be null, not a division by zero: ' . json_encode($r[4]));
        check(array_slice($r, 5) === [['day' => '2026-10-14', 'requests' => 5, 'credits' => 20000], ['day' => '2026-10-13', 'requests' => 1, 'credits' => 0],
            ['day' => '2026-10-12', 'requests' => 2, 'credits' => 5000]], 'days: ' . json_encode(array_slice($r, 5)));
        $empty = CreditsUsage::usageRecords(['balance' => 5], 30);
        check($empty === [['balance' => 5, 'period' => ['days' => 30, 'since' => null], 'totals' => array_fill_keys(CreditsUsage::COUNTS, 0) + ['cache_share' => null]]], 'no rows: ' . json_encode($empty));
    },
    'credits usage: the script asks GET /usage?days=N once and prints summary, models and days as records, paged' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, $recs, $status, $out) = creditsUsageRun($base, $dir, 'u', ['usage', '--days', '7', '--limit', '0'], ['usage' => creditsUsageAnswer()]);
            check($code === 0 && $status['ok'] === true && count($recs) === 8, "usage failed ($code): $out");
            check(creditsLineRequests($dir) === ['/u/usage?days=7'], 'requests: ' . json_encode(creditsLineRequests($dir)));
            check($recs[0]['balance'] === 4000000 && $recs[1]['model'] === 'big' && $recs[4]['cache_share'] === null && $recs[7]['day'] === '2026-10-12', 'records: ' . $out);
            list($code, $recs, $status) = creditsUsageRun($base, $dir, 'u', ['usage', '--limit', '3']);
            check($code === 0 && count($recs) === 3 && $status['truncated'] === true && $status['next_offset'] === 3, 'paging: ' . json_encode($status));
            $asked = creditsLineRequests($dir);
            check(end($asked) === '/u/usage?days=30', 'the default window is 30 days: ' . json_encode(creditsLineRequests($dir)));
            list($code, , , $out) = creditsUsageRun($base, $dir, 'u', ['usage', '--human']);
            check($code === 0 && strpos($out, 'balance: 4000000') !== false && strpos($out, 'big') !== false && strpos($out, '{') === false, '--human: ' . $out);
        });
    },
    'credits usage: --days takes 1 to 400 only, and a bad value makes no request' => function () {
        creditsLineWithServer(function ($base, $dir) {
            foreach (['0', '401', '-3', 'abc', '7.5', '1000', ''] as $bad) {
                list($code, , $status, $out) = creditsUsageRun($base, $dir, 'u', ['usage', "--days=$bad"], ['usage' => creditsUsageAnswer()]);
                check($code === 1 && $status['ok'] === false && strpos($status['error'], '--days takes a whole number of days from 1 to 400') === 0, "--days=$bad must be refused: $out");
            }
            list($code, , $status) = creditsUsageRun($base, $dir, 'u', ['usage', '--days']);
            check($code === 1 && strpos($status['error'], '--days') === 0, '--days without a value: ' . json_encode($status));
            check(creditsLineRequests($dir) === [], 'a refused --days made a request: ' . json_encode(creditsLineRequests($dir)));
            foreach (['1', '400'] as $ok) {
                list($code) = creditsUsageRun($base, $dir, 'u', ['usage', '--days', $ok]);
                check($code === 0, "--days $ok must be accepted");
            }
            check(creditsLineRequests($dir) === ['/u/usage?days=1', '/u/usage?days=400'], 'requests: ' . json_encode(creditsLineRequests($dir)));
        });
    },
    'credits usage: an endpoint without a usage route (404) gets the same error and hint as balance gives for its route, never the body' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, , $usage, $out) = creditsUsageRun($base, $dir, 'none', ['usage']);
            list(, , $balance) = creditsUsageRun($base, $dir, 'none', ['balance']);
            check($code === 1 && $usage['ok'] === false && strpos($usage['error'], 'has no usage route, so it cannot report credits') !== false, "404: $out");
            check($usage['error'] === str_replace('balance route', 'usage route', $balance['error']) && $usage['hint'] === $balance['hint'], 'usage and balance must say the same: ' . json_encode([$usage, $balance]));
            check(strpos($out, 'S3CRET') === false && strpos($out, 'not found') === false, 'the 404 body (it echoes the key) must not be quoted: ' . $out);
            list($code, $line) = creditsLineRun($dir, "$base/none", []);
            check($code === 0 && $line === '', 'the line stays silent on the same endpoint: ' . $line);
        });
    },
    'credits models: lists id, the rates and the description the endpoint sends, in its order, and nothing it did not send' => function () {
        creditsLineWithServer(function ($base, $dir) {
            $models = ['object' => 'list', 'data' => [
                ['id' => 'one', 'object' => 'model', 'owned_by' => 'x', 'context_length' => 1000, 'description' => ' Good at long files. ',
                    'credits_per_million_tokens' => ['input' => 1000000, 'cached_input' => 250000, 'output' => 4000000, 'extra' => 1]],
                ['id' => 'two', 'object' => 'model'],
                ['id' => 'three', 'credits_per_million_tokens' => ['input' => '5', 'output' => 9], 'description' => ''],
                ['object' => 'model', 'description' => 'no id'],
            ]];
            list($code, $recs, $status, $out) = creditsUsageRun($base, $dir, 'm', ['models'], ['models' => $models]);
            check($code === 0 && $status['ok'] === true, "models failed ($code): $out");
            check($recs === [
                ['id' => 'one', 'credits_per_million_tokens' => ['input' => 1000000, 'output' => 4000000, 'cached_input' => 250000], 'description' => 'Good at long files.'],
                ['id' => 'two'],
                ['id' => 'three'],
            ], 'records: ' . $out);
            check(creditsLineRequests($dir) === ['/m/models'], 'one GET /models: ' . json_encode(creditsLineRequests($dir)));
        });
    },
];
