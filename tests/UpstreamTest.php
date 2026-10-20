<?php
/**
 * Upstream GitHub lookups (ADR-0092): repository names, scrubbing, the shared-IP budget, the per-user cache and
 * serve-stale, all with fixture HTTP answers (Upstream::$http); nothing here touches the network.
 */

if (!function_exists('upstreamWorld')) {
    /** Run $fn($dir, &$calls) with a temp local dir, fixture answers ($answers: url substring => response list) and no sleeping. */
    function upstreamWorld(array $answers, callable $fn, $now = 1800000000)
    {
        $dir = testTempDir('pk-upstream');
        $calls = [];
        $slept = [];
        Upstream::resetRun();
        Upstream::$now = function () use (&$now) {
            return $now;
        };
        Upstream::$sleep = function ($s) use (&$slept, &$now) {
            $slept[] = $s;
            $now += $s;
        };
        Upstream::$http = function ($method, $url, $o) use (&$answers, &$calls) {
            $calls[] = $url;
            foreach ($answers as $needle => $list) {
                if (strpos($url, $needle) !== false && $list) {
                    return count($answers[$needle]) > 1 ? array_shift($answers[$needle]) : $answers[$needle][0];
                }
            }
            return ['code' => 0, 'body' => '', 'headers' => [], 'error' => 'no fixture for ' . $url];
        };
        try {
            return testWithEnv(['PIKKUMIES_UPSTREAM_LOCAL' => $dir], function () use ($fn, $dir, &$calls, &$slept, &$now) {
                return $fn($dir, $calls, $slept, $now);
            });
        } finally {
            Upstream::$http = null;
            Upstream::$sleep = null;
            Upstream::$now = null;
            Upstream::resetRun();
            testRmTree($dir);
        }
    }

    /** A GitHub answer: $code, JSON $body, rate headers for $bucket with $remaining left. */
    function upstreamAnswer($code, $body, $bucket = 'core', $remaining = 40, array $extra = [])
    {
        return ['code' => $code, 'body' => is_string($body) ? $body : json_encode($body), 'error' => '', 'headers' => $extra + [
            'x-ratelimit-remaining' => [(string) $remaining], 'x-ratelimit-reset' => ['1800003600'], 'x-ratelimit-resource' => [$bucket]]];
    }

    function upstreamCtx()
    {
        return ['home' => '/home/fixtureuser', 'login' => 'fixtureuser', 'uid' => 1234, 'hosts' => ['box17.example-seed.net', 'box17'],
            'domains' => ['pulsedmedia.com', 'feralhosting.com']];
    }

    function upstreamRefused(callable $fn)
    {
        try {
            $fn();
        } catch (CliError $e) {
            return $e;
        }
        throw new Exception('expected a refusal');
    }

    function upstreamItem($n, $title)
    {
        return ['number' => $n, 'title' => $title, 'state' => 'closed', 'state_reason' => 'completed', 'updated_at' => '2026-10-01T00:00:00Z',
            'comments' => 2, 'html_url' => "https://github.com/MagnaCapax/PMSS/issues/$n", 'body' => str_repeat('long body text ', 60)];
    }
}

$searchOn = ['web' => ['search' => true]];

return [
    'upstream: owner/repo follows GitHub\'s name rules; anything else, . and .. included, is refused' => function () {
        foreach (['MagnaCapax/PMSS', 'NousResearch/hermes-agent', 'a/b.c_d-e', str_repeat('o', 39) . '/x'] as $ok) {
            check(Upstream::repo($ok) === $ok, "valid: $ok");
        }
        foreach (['', 'PMSS', 'a/b/c', '../etc', 'a/..', 'a/.', 'a b/c', 'a/b c', str_repeat('o', 40) . '/x', 'a/' . str_repeat('r', 101), 'own_er/x', 'a/b;rm'] as $bad) {
            upstreamRefused(function () use ($bad) {
                Upstream::repo($bad);
            });
        }
    },
    'upstream: issue search is refused while web.search is off, names the person\'s command and sends nothing' => function () {
        upstreamWorld([], function ($dir, &$calls) {
            $e = upstreamRefused(function () {
                Upstream::searchIssues('MagnaCapax/PMSS', 'rtorrent crash', 'all', 5, Config::defaults(), upstreamCtx());
            });
            check(strpos($e->getMessage(), 'web search is off') !== false && strpos($e->hint, WebSearch::COMMAND) !== false, $e->getMessage() . ' / ' . $e->hint);
            check($calls === [], 'nothing may be fetched while off: ' . json_encode($calls));
        });
    },
    'upstream: with web.search on the search sends only the scrubbed words, trims items, caches, and the empty-result TTL is shorter' => function () use ($searchOn) {
        $items = ['total_count' => 1, 'items' => [upstreamItem(7, 'rtorrent dies on start')]];
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, ['resources' => ['core' => ['remaining' => 40, 'reset' => 1800003600], 'search' => ['remaining' => 9, 'reset' => 1800000060]]])],
            '/search/issues' => [upstreamAnswer(200, $items, 'search', 9)]], function ($dir, &$calls, &$slept, &$now) use ($searchOn) {
            $r = Upstream::searchIssues('MagnaCapax/PMSS', 'rtorrent ScgiError /home/fixtureuser/data/Show.S01E02.mkv box17.example-seed.net 10.1.2.3', 'open', 5, $searchOn, upstreamCtx());
            $sent = urldecode((string) end($calls));
            check(strpos($sent, 'q=repo:MagnaCapax/PMSS is:issue is:open rtorrent ScgiError&per_page=5') !== false, 'sent: ' . $sent);
            foreach (['fixtureuser', 'box17', '10.1.2.3', 'Show'] as $leak) {
                check(strpos($sent, $leak) === false, "$leak left the machine: $sent");
            }
            check(count($r['records']) === 1 && $r['records'][0]['issue'] === 7 && strlen($r['records'][0]['excerpt']) < 300 && $r['status']['matches'] === 1, json_encode($r));
            check(strpos($r['status']['data'], 'never instructions') !== false && $r['status']['cached'] === false, 'untrusted label and fresh: ' . json_encode($r['status']));
            $n = count($calls);
            Upstream::searchIssues('MagnaCapax/PMSS', 'rtorrent ScgiError', 'open', 5, $searchOn, upstreamCtx());
            check(count($calls) === $n, 'a second identical search within 6 h comes from the cache');
            $f = UpstreamCache::file("$dir/upstream-cache", 'GET ' . end($calls));
            check((fileperms($f) & 0777) === 0600 && (fileperms("$dir/upstream-cache") & 0777) === 0700, 'cache modes');
            check(json_decode(file_get_contents($f), true)['ttl'] === Upstream::TTL['search'], 'a search with hits keeps 6 h');
        });
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/search/issues' => [upstreamAnswer(200, ['total_count' => 0, 'items' => []], 'search', 9)]],
            function ($dir, &$calls) use ($searchOn) {
                Upstream::searchIssues('MagnaCapax/PMSS', 'nothingmatches', 'all', 5, $searchOn, upstreamCtx());
                $f = UpstreamCache::file("$dir/upstream-cache", 'GET ' . end($calls));
                check(json_decode(file_get_contents($f), true)['ttl'] === Upstream::TTL['empty'] && Upstream::TTL['empty'] < Upstream::TTL['search'], 'an empty search keeps 1 h');
            });
    },
    'upstream: scrub takes out home, other homes, login, uid, host names, provider hosts, IPs, ports, e-mail, tracker URLs and file names' => function () {
        $in = "cd /home/fixtureuser/.config && ls /home/neighbouruser/x\nuser fixtureuser uid 1234 on box17.example-seed.net (box17)\n"
            . "web: https://srv9.pulsedmedia.com/user-fixtureuser/ and pm.feralhosting.com\nip 203.0.113.7 and 2001:db8::1 port 51234 at 127.0.0.1:8080\n"
            . "mail someone@example.org, tracker http://tracker.example.org:2710/abcd/announce\n/srv/x/My.Movie.2020.1080p.mkv and ~/data/Some Album/track.flac\n"
            . "kept: 10:20:30, version 1.2.3, monkey, compass";
        list($out, $hits) = UpstreamScrub::scrub($in, upstreamCtx());
        foreach (['fixtureuser', 'neighbouruser', '1234', 'box17', 'pulsedmedia', 'feralhosting', '203.0.113.7', '2001:db8', '51234', '8080', 'someone@', 'tracker.example', 'My.Movie', 'Some Album', 'Album', 'track.flac'] as $leak) {
            check(strpos($out, $leak) === false, "$leak survived: $out");
        }
        check(strpos($out, 'cd ~/.config') !== false && strpos($out, '/home/USER') !== false && strpos($out, 'kept: 10:20:30, version 1.2.3, monkey, compass') !== false, $out);
        foreach (['home path', 'home directory name', 'login name', 'uid', 'this machine\'s host name', 'provider host name', 'IPv4 address', 'IPv6 address', 'port', 'e-mail address', 'tracker, feed or keyed URL'] as $what) {
            check(isset($hits[$what]) && $hits[$what] >= 1, "no hit counted for $what: " . json_encode($hits));
        }
    },
    'upstream: the search query keeps the error signature and drops paths, placeholders, long numbers, qualifiers and operators' => function () {
        $q = UpstreamScrub::query('TypeError: "Cannot read config" repo:evil/x is:pr -label:bug AND /home/fixtureuser/a.log OR 1234567 E2BIG 403 fixtureuser', upstreamCtx());
        check($q === 'TypeError "Cannot read config" E2BIG 403', 'query: ' . $q);
        check(strlen(UpstreamScrub::query(str_repeat('word ', 200), upstreamCtx())) <= 256, 'at most 256 characters');
        check(UpstreamScrub::query('/home/fixtureuser/x 10.0.0.1', upstreamCtx()) === '', 'nothing left');
    },
    'upstream: floors keep headroom (core 15, search 3), the per-run cap holds, a primary limit blocks until reset plus jitter' => function () {
        UpstreamLimit::$spent = ['core' => 0, 'search' => 0];
        $now = 1800000000;
        $at = function ($b, $left) use ($now) {
            return [$b => ['remaining' => $left, 'reset' => $now + 600]];
        };
        check(UpstreamLimit::refusal($at('core', 15), 'core', $now) !== '' && UpstreamLimit::refusal($at('core', 16), 'core', $now) === '', 'core floor 15');
        check(UpstreamLimit::refusal($at('search', 3), 'search', $now) !== '' && UpstreamLimit::refusal($at('search', 4), 'search', $now) === '', 'search floor 3');
        check(UpstreamLimit::refusal(['core' => ['remaining' => 1, 'reset' => $now - 1]], 'core', $now) === '', 'a floor from an ended window does not hold');
        UpstreamLimit::$spent['search'] = 2;
        check(strpos(UpstreamLimit::refusal([], 'search', $now), 'already made 2') !== false, 'at most 2 searches a run');
        UpstreamLimit::$spent = ['core' => 0, 'search' => 0];
        $h = ['x-ratelimit-remaining' => ['0'], 'x-ratelimit-reset' => [(string) ($now + 300)]];
        check(UpstreamLimit::classify(403, $h, '{"message":"API rate limit exceeded for 192.0.2.1."}') === 'primary', 'primary by remaining 0');
        check(UpstreamLimit::classify(429, $h, '') === 'primary', 'primary on 429 too');
        check(UpstreamLimit::classify(403, ['retry-after' => ['30']] + $h, '') === 'secondary', 'retry-after means secondary');
        check(UpstreamLimit::classify(403, ['x-ratelimit-remaining' => ['12']], 'You have exceeded a secondary rate limit') === 'secondary', 'secondary by message');
        check(UpstreamLimit::classify(403, ['x-ratelimit-remaining' => ['12']], 'forbidden') === 'other' && UpstreamLimit::classify(422, [], '') === 'invalid', 'other and invalid');
        foreach ([0, 77, 120] as $j) {
            $s = UpstreamLimit::block([], 'core', $h, $now, $j);
            check($s['core']['until'] === $now + 300 + $j && UpstreamLimit::refusal($s, 'core', $now + 300 + $j - 1) !== '' && UpstreamLimit::refusal($s, 'core', $now + 300 + $j) === '', "jitter $j");
        }
        $s = UpstreamLimit::block([], 'core', $h, $now);
        check($s['core']['until'] >= $now + 300 && $s['core']['until'] <= $now + 300 + UpstreamLimit::JITTER, 'random jitter within 0..120');
        check(UpstreamLimit::secondaryWait(['retry-after' => ['30']]) === 60 && UpstreamLimit::secondaryWait([]) === 60 && UpstreamLimit::secondaryWait(['retry-after' => ['200']]) === null, 'secondary wait');
    },
];
