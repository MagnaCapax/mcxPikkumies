<?php
/**
 * Fixes from the security review of the upstream unit (2026-10-20, U-1..U-10 and the Class::method query fix): each test
 * here failed on the code before the fix. Fixture HTTP answers and a fake git only: no network.
 */
if (!function_exists('upstreamWorld')) {
    require __DIR__ . '/UpstreamTest.php';
}
require_once dirname(__DIR__) . '/pikkumies/tools/development/upstream/UpstreamGit.php';

if (!function_exists('upstreamReviewGit')) {
    /** A fake git that logs its argv and GIT_ASKPASS to $dir/git.log and makes the clone target. */
    function upstreamReviewGit($dir)
    {
        @mkdir("$dir/bin", 0700, true);
        file_put_contents("$dir/bin/git", '#!' . PHP_BINARY . "\n<?php\n\$a = array_slice(\$argv, 1);\n\$p = getenv('GIT_ASKPASS');\n"
            . 'file_put_contents(' . var_export("$dir/git.log", true) . ", implode(' ', \$a) . ' ASKPASS=' . (\$p === false ? 'unset' : '[' . \$p . ']') . \"\\n\", FILE_APPEND);\n"
            . "if (in_array('clone', \$a, true)) { mkdir(end(\$a), 0700, true); exit(0); }\necho str_repeat('c', 40) . \"\\x1f2026-10-19T10:00:00+00:00\\x1f\" . str_repeat('s', 500) . \"\\n\";\n");
        chmod("$dir/bin/git", 0700);
        return "$dir/bin:" . getenv('PATH');
    }
}

return [
    'upstream review U-1: the issue-search words go through Redact::secrets, so passwords and tokens never reach GitHub' => function () {
        $q = UpstreamScrub::query('lighttpd auth fail password=hunter2secret Authorization: Bearer abcdef0123456789xyz git push ghp_' . str_repeat('a', 36) . ' denied', upstreamCtx());
        foreach (['hunter2', 'abcdef0123456789xyz', 'ghp_', 'REDACTED'] as $leak) {
            check(strpos($q, $leak) === false, "$leak in the query: $q");
        }
        check(strpos($q, 'lighttpd auth fail') === 0 && strpos($q, 'denied') !== false, 'the error words stay: ' . $q);
    },
    'upstream review U-2: release names without a path, info hashes and tracker host:port leave neither the query nor the draft' => function () {
        $in = 'rtorrent crash on Some.Show.S01E02.1080p.WEB-DL.x264-GROUP.mkv torrent 3f786850e387550fdab836ed7e6dc881de23001b refused tracker.privatehd.example:2710 Movie.Title.2023.2160p.UHD.BluRay.REMUX-FGT';
        $q = UpstreamScrub::query($in, upstreamCtx());
        check($q === 'rtorrent crash on torrent refused', 'query: ' . $q);
        list($d, $hits) = UpstreamScrub::scrub($in . "\nfeed https://tracker-less.example/t.rss?u=123;tp=abcdef0123456789abcdef;download", upstreamCtx());
        foreach (['Some.Show', '3f786850', 'privatehd', '2710', 'Movie.Title', 'abcdef0123456789abcdef', 'tracker-less'] as $leak) {
            check(strpos($d, $leak) === false, "$leak in the draft: $d");
        }
        check(strpos($d, 'HASH') !== false && strpos($d, 'HOST:PORT') !== false && isset($hits['release name'], $hits['torrent info hash'], $hits['host and port']), $d . json_encode($hits));
    },
    'upstream review U-3: the login is found next to _ and in any case' => function () {
        list($d) = UpstreamScrub::scrub('unit fixtureuser_rtorrent failed for Fixtureuser and FIXTUREUSER-plex; malfixtureuserx stays', upstreamCtx());
        check($d === 'unit USER_rtorrent failed for USER and USER-plex; malfixtureuserx stays', $d);
    },
    'upstream review U-4: a one-run PIKKUMIES_CONFIG overlay cannot turn issue search on; only the person\'s own file can' => function () {
        if (!empty(Config::fileConfig()['web']['search'])) {
            skip('this checkout\'s own local/config.php has web search on');
        }
        $dir = testTempDir('pk-upstream-overlay');
        try {
            file_put_contents("$dir/overlay.php", "<?php return ['web' => ['search' => true]];\n");
            chmod("$dir/overlay.php", 0600);
            // A used-up search bucket: should the overlay ever count, the call stops here, never on the network.
            UpstreamCache::writeFile("$dir/upstream-cache/ratelimit.json", json_encode(['search' => ['remaining' => 0, 'reset' => time() + 3600, 'until' => time() + 3600]]));
            list($code, $out) = testWithEnv(['PIKKUMIES_UPSTREAM_LOCAL' => $dir, 'PIKKUMIES_CONFIG' => "$dir/overlay.php"], function () {
                return runPhp(dirname(__DIR__) . '/pikkumies/tools/development/upstream/upstream.php', ['search-issues', 'MagnaCapax/PMSS', 'rtorrent']);
            });
            $lines = cliLines($out);
            $last = $lines ? $lines[count($lines) - 1] : [];
            check($code === 1 && isset($last['error']) && strpos($last['error'], 'web search is off') !== false, $out);
        } finally {
            testRmTree($dir);
        }
    },
    'upstream review U-5: one account makes at most 10 core calls an hour across runs; the next hour starts again' => function () {
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/' => [upstreamAnswer(200, upstreamItem(1, 't') + ['created_at' => 'c'])]], function ($dir, &$calls, &$slept, &$now) {
            for ($n = 1; $n <= 10; $n++) {
                if ($n % 3 === 1) {
                    Upstream::resetRun(); // a new run every three calls: the per-run cap never binds
                }
                Upstream::issue('o/r', $n, false);
            }
            Upstream::resetRun();
            $before = count($calls);
            $e = upstreamRefused(function () {
                Upstream::issue('o/r', 11, false);
            });
            check(strpos($e->getMessage(), 'this hour') !== false && count(array_filter(array_slice($calls, $before), function ($u) {
                return strpos($u, '/issues/11') !== false;
            })) === 0, 'the 11th call this hour is never sent: ' . $e->getMessage());
            $now += 3601;
            Upstream::resetRun();
            check(Upstream::issue('o/r', 11, false)['records'][0]['issue'] === 1, 'a new hour');
        });
    },
    'upstream review U-6: an unclosed quote does not carry qualifiers or AND/OR/NOT into the query' => function () {
        check(UpstreamScrub::query('ScgiError "user:torvalds', upstreamCtx()) === 'ScgiError', 'qualifier behind an open quote');
        check(UpstreamScrub::query('ScgiError "author:x', upstreamCtx()) === 'ScgiError', 'author qualifier');
        $q = UpstreamScrub::query('a "OR b "OR c "NOT d', upstreamCtx());
        check(!preg_match('/\b(OR|NOT)\b/', $q), 'operators: ' . $q);
        check(UpstreamScrub::query('"connection refused by peer" x', upstreamCtx()) === '"connection refused by peer" x', 'a closed phrase stays a phrase');
    },
    'upstream review U-7, U-9: the clone offers no askpass program, and commit subjects are capped at 200 characters' => function () {
        upstreamWorld([], function ($dir) {
            testWithEnv(['PATH' => upstreamReviewGit($dir), 'GIT_ASKPASS' => '/bin/echo'], function () use ($dir) {
                $r = UpstreamGit::commits('o/r', '', 90);
                $log = file_get_contents("$dir/git.log");
                $clone = (string) strtok($log, "\n"); // proc_open drops an empty variable: unset or empty, never the inherited program
                check(strpos($clone, '-c core.askPass= clone') !== false && preg_match('/ASKPASS=(unset|\[\])$/', $clone), $log);
                check(strlen($r['records'][0]['subject']) <= 203, 'subject: ' . strlen($r['records'][0]['subject']));
            });
        }, time());
    },
    'upstream review U-9: Atom and issue titles are capped at 200 characters' => function () {
        $x = '<feed><entry><title>' . str_repeat('t', 900) . '</title><updated>u</updated><link href="h"/></entry></feed>';
        check(strlen(UpstreamFeed::atom($x)[0]['title']) <= 203, 'atom title');
        $long = upstreamItem(4, str_repeat('T', 900));
        upstreamWorld(['/rate_limit' => [upstreamAnswer(200, [])], '/issues/4' => [upstreamAnswer(200, $long + ['created_at' => 'c'])]], function () {
            check(strlen(Upstream::issue('o/r', 4, false)['records'][0]['title']) <= 203, 'issue title');
        });
    },
    'upstream review U-8: at most 10 mirrors and 100 MB of mirrors are kept, least recently used removed first' => function () {
        $dir = testTempDir('pk-upstream-mirrors');
        try {
            $now = time();
            for ($i = 1; $i <= 12; $i++) {
                mkdir("$dir/o__r$i.git");
                touch("$dir/o__r$i.git/pikkumies-used", $now - $i * 60);
            }
            UpstreamGit::prune($dir, $now);
            check(is_dir("$dir/o__r10.git") && !is_dir("$dir/o__r11.git") && !is_dir("$dir/o__r12.git"), 'the two oldest of 12 go: ' . implode(',', array_map('basename', glob("$dir/*"))));
            $f = fopen("$dir/o__r10.git/pack", 'w');
            ftruncate($f, UpstreamGit::MAX_BYTES + 1); // sparse: no real disk used
            fclose($f);
            UpstreamGit::prune($dir, $now);
            check(!is_dir("$dir/o__r10.git") && is_dir("$dir/o__r1.git") && is_dir("$dir/o__r9.git"), 'the oldest mirror past 100 MB goes');
        } finally {
            testRmTree($dir);
        }
    },
    'upstream review U-10: PIKKUMIES_UPSTREAM_LOCAL is honoured only for this account\'s own 0700 directory' => function () {
        $dir = testTempDir('pk-upstream-local');
        try {
            testWithEnv(['PIKKUMIES_UPSTREAM_LOCAL' => $dir], function () use ($dir) {
                check(Upstream::localDir() === $dir, 'own 0700 directory');
                chmod($dir, 0755);
                check(Upstream::localDir() === Config::localDir(), 'readable by others: ignored');
                chmod($dir, 0700);
            });
            symlink($dir, "$dir-link");
            testWithEnv(['PIKKUMIES_UPSTREAM_LOCAL' => "$dir-link"], function () {
                check(Upstream::localDir() === Config::localDir(), 'a symlink: ignored');
            });
        } finally {
            @unlink("$dir-link");
            testRmTree($dir);
        }
    },
    'upstream review: query keeps Class::method and file names, drops host names' => function () {
        $q = UpstreamScrub::query('PHP Fatal error: Uncaught TypeError in XmlRpc::call reading php.ini from flacsfor.me', upstreamCtx());
        check($q === 'PHP Fatal error Uncaught TypeError in XmlRpc::call reading php.ini from', 'query: ' . $q);
    },
];
