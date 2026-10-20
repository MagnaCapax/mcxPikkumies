<?php
/**
 * The upstream tool unit (ADR-0092): limits handled per kind, serve-stale, the per-run cap, the draft (0600, scrubbed,
 * link at most 6000 characters), the web.search refusal through the real command line, and commits from a fake git
 * or the feed. Fixture HTTP answers and a fake git only: no network.
 */
if (!function_exists('upstreamWorld')) {
    require __DIR__ . '/UpstreamTest.php';
}
require_once dirname(__DIR__) . '/pikkumies/tools/development/upstream/UpstreamGit.php';
require_once dirname(__DIR__) . '/pikkumies/tools/development/upstream/UpstreamDraft.php';

if (!function_exists('upstreamIssue')) {
    function upstreamIssue($n, $comments = 0)
    {
        return ['number' => $n, 'title' => "issue $n", 'state' => 'open', 'created_at' => '2026-09-01T00:00:00Z', 'updated_at' => '2026-09-02T00:00:00Z',
            'labels' => [['name' => 'bug']], 'comments' => $comments, 'html_url' => "https://github.com/o/r/issues/$n", 'body' => str_repeat('x', 5000)];
    }

    function upstreamFeed(array $titles, $kind = 'commit')
    {
        $x = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>t</title>';
        foreach ($titles as $i => $t) {
            $x .= '<entry><id>x</id><link type="text/html" rel="alternate" href="https://github.com/o/r/' . $kind . '/' . str_repeat((string) $i, 40) . '"/>'
                . "<title>\n  " . htmlspecialchars($t) . "\n</title><updated>2026-10-1{$i}T00:00:00Z</updated><content type=\"html\">&lt;p&gt;notes &amp;amp; more&lt;/p&gt;</content></entry>";
        }
        return ['code' => 200, 'body' => $x . '</feed>', 'headers' => [], 'error' => ''];
    }

    /** A fake git in a temp PATH directory: clone makes the target directory (or fails with $cloneCode), log prints $log; argv lines go to $dir/git.log. */
    function upstreamFakeGit($dir, $log, $cloneCode = 0)
    {
        @mkdir("$dir/bin", 0700, true);
        file_put_contents("$dir/bin/git", '#!' . PHP_BINARY . "\n<?php\n\$a = array_slice(\$argv, 1);\nfile_put_contents(" . var_export("$dir/git.log", true)
            . ", implode(' ', \$a) . \"\\n\", FILE_APPEND);\nif (in_array('clone', \$a, true)) { if ($cloneCode) { exit($cloneCode); } mkdir(end(\$a), 0700, true); exit(0); }\n"
            . 'echo ' . var_export($log, true) . ";\n");
        chmod("$dir/bin/git", 0700);
        return "$dir/bin:" . getenv('PATH');
    }
}

return [
    'upstream tool: a primary limit serves the stale copy, blocks the bucket until reset plus jitter, and never prints GitHub\'s message' => function () {
        $limit = upstreamAnswer(403, ['message' => 'API rate limit exceeded for 192.0.2.44.'], 'core', 0, ['x-ratelimit-reset' => ['1800009000']]);
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/5' => [upstreamAnswer(200, upstreamIssue(5)), $limit]],
            function ($dir, &$calls, &$slept, &$now) {
                $first = Upstream::issue('o/r', 5, false);
                check($first['status']['cached'] === false && strlen($first['records'][0]['text']) <= 2004, 'fresh, trimmed: ' . strlen($first['records'][0]['text']));
                $now += Upstream::TTL['issue'] + 1;
                Upstream::resetRun();
                $r = Upstream::issue('o/r', 5, false);
                check($r['status']['stale'] === true && $r['records'][0]['issue'] === 5 && strpos($r['status']['note'], 'used up until') !== false, json_encode($r['status']));
                $state = json_decode(file_get_contents("$dir/upstream-cache/ratelimit.json"), true);
                check($state['core']['until'] >= 1800009000 && $state['core']['until'] <= 1800009000 + UpstreamLimit::JITTER, 'blocked until reset + jitter: ' . json_encode($state));
                $n = count($calls);
                Upstream::resetRun();
                $again = Upstream::issue('o/r', 5, false);
                check($again['status']['stale'] === true, 'still stale');
                check(!array_filter(array_slice($calls, $n), function ($u) {
                    return strpos($u, '/issues/5') !== false;
                }), 'no call to a blocked bucket: ' . json_encode(array_slice($calls, $n)));
                $e = upstreamRefused(function () {
                    Upstream::issue('o/r', 6, false);
                });
                check(strpos($e->getMessage() . $e->hint, '192.0.2.44') === false && strpos($e->getMessage(), 'used up until') !== false, $e->getMessage());
            });
    },
    'upstream tool: a secondary limit is waited out once (at least 60 s) and retried; a second one, or a long retry-after, stops' => function () {
        $sec = upstreamAnswer(403, ['message' => 'You have exceeded a secondary rate limit'], 'core', 30, ['retry-after' => ['30']]);
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/1' => [$sec, upstreamAnswer(200, upstreamIssue(1))]], function ($dir, &$calls, &$slept) {
            $r = Upstream::issue('o/r', 1, false);
            check($slept === [60] && $r['records'][0]['issue'] === 1, 'waited 60 s once, then answered: ' . json_encode($slept));
        });
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/2' => [$sec]], function ($dir, &$calls, &$slept) {
            $e = upstreamRefused(function () {
                Upstream::issue('o/r', 2, false);
            });
            check($slept === [60] && strpos($e->getMessage(), 'secondary') !== false, 'one retry only: ' . json_encode($slept) . ' ' . $e->getMessage());
            check(count(array_filter($calls, function ($u) {
                return strpos($u, '/issues/2') !== false;
            })) === 2, 'exactly two calls');
        });
        $long = upstreamAnswer(429, '{}', 'core', 30, ['retry-after' => ['600']]);
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/3' => [$long]], function ($dir, &$calls, &$slept) {
            upstreamRefused(function () {
                Upstream::issue('o/r', 3, false);
            });
            check($slept === [], 'a retry-after past 90 s is not waited for in a call');
        });
    },
    'upstream tool: a run makes at most 3 core calls, the floor read from /rate_limit holds, 422 simplifies once, no answer retries after 5 s' => function () {
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/' => [upstreamAnswer(200, upstreamIssue(9))]], function ($dir, &$calls) {
            foreach ([1, 2, 3] as $n) {
                Upstream::issue('o/r', $n, false);
            }
            $before = count($calls);
            upstreamRefused(function () {
                Upstream::issue('o/r', 4, false);
            });
            check(count($calls) === $before, 'the fourth core call is never sent');
        });
        $low = ['resources' => ['core' => ['remaining' => 15, 'reset' => 1800003600], 'search' => ['remaining' => 9, 'reset' => 1800000060]]];
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, $low)]], function ($dir, &$calls) {
            $e = upstreamRefused(function () {
                Upstream::issue('o/r', 1, false);
            });
            check(count($calls) === 1 && strpos($e->getMessage(), 'low (15 left') !== false, 'only the free /rate_limit read: ' . json_encode($calls) . ' ' . $e->getMessage());
        });
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/search/issues' => [upstreamAnswer(422, '{}', 'search', 8), upstreamAnswer(200, ['total_count' => 0, 'items' => []], 'search', 7)]],
            function ($dir, &$calls) {
                Upstream::searchIssues('o/r', 'one two three four five six seven', 'all', 5, ['web' => ['search' => true]], upstreamCtx());
                $s = array_values(array_filter($calls, function ($u) {
                    return strpos($u, '/search/') !== false;
                }));
                check(count($s) === 2 && strpos(urldecode($s[1]), 'five&per_page') !== false && strpos(urldecode($s[1]), 'six') === false, 'simplified once: ' . json_encode($s));
            });
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/7' => [['code' => 0, 'body' => '', 'headers' => [], 'error' => 'timeout'], upstreamAnswer(200, upstreamIssue(7))]],
            function ($dir, &$calls, &$slept) {
                Upstream::issue('o/r', 7, false);
                check($slept === [5], 'one retry after 5 s: ' . json_encode($slept));
            });
    },
    'upstream tool: an issue with --comments adds at most 10 trimmed comments' => function () {
        $comments = array_map(function ($i) {
            return ['id' => $i, 'created_at' => 'd', 'html_url' => "u$i", 'body' => str_repeat('c', 900)];
        }, range(1, 10));
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/comments' => [upstreamAnswer(200, $comments)], '/issues/8' => [upstreamAnswer(200, upstreamIssue(8, 12))]],
            function ($dir, &$calls) {
                $r = Upstream::issue('o/r', 8, true);
                check(count($r['records']) === 11 && strlen($r['records'][1]['text']) <= 604 && $r['records'][0]['labels'] === ['bug'], count($r['records']) . ' records');
                check(strpos(end($calls), '/comments?per_page=10') !== false, 'per_page 10: ' . end($calls));
            });
    },
    'upstream tool: the prefilled link stays within 6000 encoded characters and says when the text was cut' => function () {
        list($url, $complete) = UpstreamDraft::url('o/r', 'Short title', "line one\nline two\n");
        check($complete && strpos($url, 'https://github.com/o/r/issues/new?title=Short%20title&body=line%20one%0Aline%20two') === 0, $url);
        foreach (["first line\n" . str_repeat("ä ö long line\n", 600), str_repeat('ääää', 3000)] as $body) {
            list($url, $complete) = UpstreamDraft::url('o/r', str_repeat('T', 256), $body);
            check(!$complete && strlen($url) <= 6000 && strlen($url) > 5000, 'cut to fit: ' . strlen($url));
            check(preg_match('//u', rawurldecode(substr($url, strpos($url, '&body=') + 6))) === 1, 'cut on a whole character');
        }
    },
    'upstream tool: draft writes a 0600 file in a 0700 directory with secrets and personal details replaced, and prints the link and the gh command' => function () {
        $dir = testTempDir('pk-upstream-draft');
        try {
            $body = "rtorrent fails for fixtureuserq at /home/fixtureuserq/.config/x on srv3.pulsedmedia.com (198.51.100.9)\npassword=hunter2secret\n";
            file_put_contents("$dir/report.md", $body);
            list($code, $out) = testWithEnv(['PIKKUMIES_UPSTREAM_LOCAL' => $dir, 'HOME' => '/home/fixtureuserq'], function () use ($dir) {
                return runPhp(dirname(__DIR__) . '/pikkumies/tools/development/upstream/upstream.php', ['draft', 'MagnaCapax/PMSS', '--title', "crash at /home/fixtureuserq", '--body-file', "$dir/report.md"]);
            });
            $lines = cliLines($out);
            check($code === 0 && end($lines)['ok'] === true && end($lines)['sent'] === false, $out);
            $file = $lines[0]['draft'];
            check(strpos($file, "$dir/upstream/drafts/") === 0 && (fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'modes: ' . $file);
            $text = file_get_contents($file);
            foreach (['/home/fixtureuserq', 'pulsedmedia', '198.51.100.9', 'hunter2secret'] as $leak) {
                check(strpos($text . $lines[0]['title'] . $lines[1]['open'], $leak) === false, "$leak in the draft: $text");
            }
            check($lines[0]['text'] === $text && isset($lines[0]['replaced']['home path'], $lines[0]['replaced']['secrets (passwords, keys, tokens)'], $lines[0]['replaced']['IPv4 address']), json_encode($lines[0]));
            check($lines[1]['complete'] === true && strpos($lines[1]['open'], 'https://github.com/MagnaCapax/PMSS/issues/new?title=') === 0, json_encode($lines[1]));
            check($lines[2]['gh'] === "gh issue create -R MagnaCapax/PMSS --title 'crash at ~' --body-file " . escapeshellarg($file), $lines[2]['gh']);
        } finally {
            testRmTree($dir);
        }
    },
    'upstream tool: search-issues through the command line refuses with the stop field while web.search is off' => function () {
        $dir = testTempDir('pk-upstream-gate');
        try {
            file_put_contents("$dir/overlay.php", "<?php return ['web' => ['search' => false]];\n");
            chmod("$dir/overlay.php", 0600);
            list($code, $out) = testWithEnv(['PIKKUMIES_UPSTREAM_LOCAL' => $dir, 'PIKKUMIES_CONFIG' => "$dir/overlay.php"], function () {
                return runPhp(dirname(__DIR__) . '/pikkumies/tools/development/upstream/upstream.php', ['search-issues', 'MagnaCapax/PMSS', 'rtorrent']);
            });
            $lines = cliLines($out);
            $last = $lines ? $lines[count($lines) - 1] : [];
            check($code === 1 && $last['ok'] === false && $last['stop'] === Cli::STOP && strpos($last['hint'], 'pikkumies websearch on') !== false, $out);
            check(!is_dir("$dir/upstream-cache"), 'nothing cached, nothing fetched');
        } finally {
            testRmTree($dir);
        }
    },
    'upstream tool: commits come from a treeless shallow clone reused for 6 h, fall back to the feed when the clone fails, and old mirrors are pruned' => function () {
        upstreamWorld([], function ($dir) {
            $log = str_repeat('a', 40) . "\x1f2026-10-19T10:00:00+00:00\x1ffix rtorrent restart\n" . str_repeat('b', 40) . "\x1f2026-10-18T10:00:00+00:00\x1fdocs\n";
            testWithEnv(['PATH' => upstreamFakeGit($dir, $log)], function () use ($dir) {
                $r = UpstreamGit::commits('o/r', 'Restart', 30);
                check($r['source'] === 'git' && count($r['records']) === 2 && $r['records'][0]['url'] === 'https://github.com/o/r/commit/' . str_repeat('a', 12) && Cli::cleanString($r['records'][0]['url']) === $r['records'][0]['url'], json_encode($r));
                $git = file_get_contents("$dir/git.log");
                check(strpos($git, '-c credential.helper= -c core.askPass= clone -q --bare --filter=tree:0 --shallow-since=90.days --single-branch -- https://github.com/o/r.git') !== false, $git);
                check(strpos($git, "log -n 20 --format=%H%x1f%cI%x1f%s --since=30.days.ago -i --fixed-strings --grep=Restart") !== false, $git);
                check(is_dir("$dir/upstream/o__r.git") && (fileperms("$dir/upstream") & 0777) === 0700, 'mirror in local/upstream');
                UpstreamGit::commits('o/r', '', 90);
                check(substr_count(file_get_contents("$dir/git.log"), ' clone ') === 1, 'reused within 6 h');
                touch("$dir/upstream/o__r.git/pikkumies-fetched", Upstream::now() - UpstreamGit::FRESH - 1);
                UpstreamGit::commits('o/r', '', 90);
                check(substr_count(file_get_contents("$dir/git.log"), ' clone ') === 2 && is_dir("$dir/upstream/o__r.git"), 'cloned again after 6 h');
                mkdir("$dir/upstream/old__gone.git");
                touch("$dir/upstream/old__gone.git", Upstream::now() - UpstreamGit::UNUSED - 1);
                mkdir("$dir/upstream/keep-me");
                touch("$dir/upstream/keep-me", Upstream::now() - UpstreamGit::UNUSED - 1);
                UpstreamGit::prune("$dir/upstream", Upstream::now());
                check(!is_dir("$dir/upstream/old__gone.git") && is_dir("$dir/upstream/keep-me") && is_dir("$dir/upstream/o__r.git"), 'only the unused mirror is pruned');
            });
        }, time());
        upstreamWorld(['/o/r/commits.atom' => [upstreamFeed(['fix rtorrent restart', 'docs only'])]], function ($dir) {
            testWithEnv(['PATH' => upstreamFakeGit($dir, '', 128)], function () {
                $r = UpstreamGit::commits('o/r', 'RTORRENT', 90);
                check($r['source'] === 'commits.atom' && count($r['records']) === 1 && $r['records'][0]['subject'] === 'fix rtorrent restart', json_encode($r));
            });
        }, strtotime('2026-10-20T00:00:00Z'));
    },
    'upstream tool: releases read releases.atom, then tags.atom when a project makes no releases' => function () {
        upstreamWorld(['/releases.atom' => [upstreamFeed(['v1.2.0', 'v1.1.0'], 'releases/tag')]], function () {
            $f = UpstreamFeed::feed('o/r/releases.atom');
            check(count($f['entries']) === 2 && $f['entries'][0]['title'] === 'v1.2.0' && $f['entries'][0]['excerpt'] === 'notes & more', json_encode($f));
        });
        upstreamWorld(['/releases.atom' => [upstreamFeed(['v1.2.0'], 'releases/tag')]], function () {
            list($records, $feed) = UpstreamFeed::releases('o/r');
            check($feed === 'releases.atom' && $records[0]['release'] === 'v1.2.0' && isset($records[0]['excerpt']), json_encode($records));
        });
        upstreamWorld(['/releases.atom' => [upstreamFeed([])], '/tags.atom' => [upstreamFeed(['v0.9'], 'releases/tag')]], function () {
            list($records, $feed) = UpstreamFeed::releases('o/r');
            check($feed === 'tags.atom' && $records === [['tag' => 'v0.9', 'date' => '2026-10-10T00:00:00Z', 'url' => 'https://github.com/o/r/releases/tag/' . str_repeat('0', 40)]], json_encode($records));
        });
        upstreamWorld([], function () {
            upstreamRefused(function () {
                UpstreamFeed::releases('o/r');
            });
        });
    },
];
