<?php
/**
 * tools/system/credits `line` (ADR-0040): what is cached, for whom and how long. A 404 on /usage is remembered a day,
 * any other failure 10 minutes; the line is for one endpoint, model and key; a cache from the future is not trusted;
 * each request is bounded by curl's short timeout. Runs the real script against tests/fixtures/credits/harness.php.
 */
require_once __DIR__ . '/fixtures/credits/harness.php';

return [
    'credits line cache: a 404 on /usage prints nothing, exits 0 and is not asked again for a day (still quiet after 10 minutes)' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, $out, $err) = creditsLineRun($dir, "$base/none", []);
            check($code === 0 && $out === '' && $err === '', "404 must be silent ($code): $out $err");
            check(creditsLineRequests($dir) === ['/none/usage?days=400&grants=10'], 'one request: ' . json_encode(creditsLineRequests($dir)));
            creditsLineShift($dir, 'usage-failed.json', -601);
            list($code, $out) = creditsLineRun($dir, "$base/none", ['--tmux']);
            check($code === 0 && $out === '' && count(creditsLineRequests($dir)) === 1, 'a 404 must be remembered past 10 minutes: ' . json_encode(creditsLineRequests($dir)));
            creditsLineShift($dir, 'usage-failed.json', -86400);
            creditsLineRun($dir, "$base/none", []);
            check(count(creditsLineRequests($dir)) === 2, 'after a day the 404 is asked again: ' . json_encode(creditsLineRequests($dir)));
        });
    },
    'credits line cache: any other failed /usage answer is remembered 10 minutes, then asked again' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, $out) = creditsLineRun($dir, "$base/err", []);
            check($code === 0 && $out === '' && count(creditsLineRequests($dir)) === 1, "a 500 must be silent ($code): $out");
            creditsLineRun($dir, "$base/err", []);
            check(count(creditsLineRequests($dir)) === 1, 'a 500 must not be asked again within 10 minutes: ' . json_encode(creditsLineRequests($dir)));
            creditsLineShift($dir, 'usage-failed.json', -601);
            creditsLineRun($dir, "$base/err", []);
            check(count(creditsLineRequests($dir)) === 2, 'after 10 minutes a failure is asked again: ' . json_encode(creditsLineRequests($dir)));
        });
    },
    'credits line cache: failed model rates leave the tokens out and are remembered 10 minutes, not a day' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list(, $out) = creditsLineRun($dir, "$base/merr", []);
            check($out === "💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · today 35k\n", 'no rates, no token part: ' . $out);
            creditsLineShift($dir, 'line.json', -61);
            creditsLineRun($dir, "$base/merr", []);
            check(array_slice(creditsLineRequests($dir), 2) === ['/merr/usage?days=400&grants=10'], 'failed rates are not asked within 10 minutes: ' . json_encode(creditsLineRequests($dir)));
            creditsLineShift($dir, 'line.json', -61);
            creditsLineShift($dir, 'rates.json', -601);
            creditsLineRun($dir, "$base/merr", []);
            check(array_slice(creditsLineRequests($dir), 3) === ['/merr/usage?days=400&grants=10', '/merr/models'], 'after 10 minutes the rates are asked again: ' . json_encode(creditsLineRequests($dir)));
        });
    },
    'credits line cache: the cached line is for one model and one key; a line dated in the future is fetched again' => function () {
        creditsLineWithServer(function ($base, $dir) {
            creditsLineRun($dir, "$base/v", []);
            list(, $out) = creditsLineRun($dir, "$base/v", [], 'C.UTF-8', 'second');
            check(strpos($out, '≥2.3M tokens') !== false && count(creditsLineRequests($dir)) === 3, 'another model must not get the cached line: ' . $out . json_encode(creditsLineRequests($dir)));
            creditsLineShift($dir, 'line.json', 3600); // the clock was set back an hour after the line was written
            creditsLineRun($dir, "$base/v", [], 'C.UTF-8', 'second');
            check(count(creditsLineRequests($dir)) === 4, 'a line from the future must not count as fresh: ' . json_encode(creditsLineRequests($dir)));
            list($code, $out) = creditsLineRun($dir, "$base/v", [], 'C.UTF-8', 'second', 'vsk-another-account-key');
            check($code === 0 && $out === '' && count(creditsLineRequests($dir)) === 5, 'another key must not see this account\'s line: ' . $out . json_encode(creditsLineRequests($dir)));
            foreach (glob("$dir/state/*.json") as $f) {
                $c = json_decode((string) file_get_contents($f), true);
                check(preg_match('/^[0-9a-f]{12}$/', $c['key_sha256']) === 1 && strpos((string) file_get_contents($f), 'another-account') === false, basename($f) . ': ' . json_encode($c));
            }
        });
    },
    'credits line cache: no key means no request; a hanging /usage or /models is given up after curl\'s 2 s, and remembered' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, $out, $err) = creditsLineRun($dir, "$base/v", [], 'C.UTF-8', '', '');
            check($code === 0 && $out === '' && $err === '' && creditsLineRequests($dir) === [], "no key must be silent, with no request ($code): $out $err");
            check(CreditsLine::TIMEOUT === 2, 'the timeout is 2 s');
            $t = microtime(true);
            list($code, $out) = creditsLineRun($dir, "$base/slowm", []); // usage answers at once, models sleeps 4 s
            $took = microtime(true) - $t;
            check($code === 0 && $out === "💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · today 35k\n" && $took < 3.5, sprintf('a hanging /models must be given up in about 2 s (took %.1f s): %s', $took, $out));
        });
        creditsLineWithServer(function ($base, $dir) {
            $t = microtime(true);
            list($code, $out) = creditsLineRun($dir, "$base/slow", []);
            $took = microtime(true) - $t;
            check($code === 0 && $out === '' && $took < 3.5, sprintf('a hanging /usage must time out silently in about 2 s (took %.1f s): %s', $took, $out));
            $failed = json_decode((string) file_get_contents("$dir/state/usage-failed.json"), true);
            check($failed['code'] === 0, 'a timeout is remembered as no answer (code 0): ' . json_encode($failed));
            creditsLineRun($dir, "$base/slow", []);
            check(count(creditsLineRequests($dir)) === 1, 'a timeout must not be asked again within 10 minutes: ' . json_encode(creditsLineRequests($dir)));
        });
    },
];
