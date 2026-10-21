<?php
/**
 * tools/memory/pikkumies/Lessons.php's --service and --tags options (ADR-0104): a memory's frontmatter
 * `service` links it to services-list nicks (format-checked only, existence not required), and
 * `categories` is the union of those nicks' current tags (Services::all()) and any given --tags
 * (format-checked the same way a service's own tag is, via the shared Services::NICK_RE). Without either
 * option, save()'s output must stay byte-for-byte what it was before this ADR (LessonsTest.php covers that
 * baseline directly; the last case here is the --service/--tags-specific regression check).
 */
if (!function_exists('lessonsFreshTree')) {
    /** A throwaway pikkumies memory root; DocSources::$root/$host point here so nothing touches real local/. */
    function lessonsFreshTree()
    {
        $tmp = testTempDir('pk-lessons');
        mkdir("$tmp/memory", 0700, true);
        DocSources::$root = $tmp;
        DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
        return $tmp;
    }

    function lessonsCloseTree($tmp)
    {
        DocSources::$root = null;
        DocSources::$host = null;
        testRmTree($tmp);
    }
}

require_once dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/Lessons.php';

if (!function_exists('lstFreshServices')) {
    /** A throwaway services.jsonl directory; Services::$localDir points here so nothing touches real local/. */
    function lstFreshServices()
    {
        $tmp = testTempDir('pk-lessons-services');
        Services::$localDir = $tmp;
        return $tmp;
    }

    function lstCloseServices($tmp)
    {
        Services::$localDir = null;
        testRmTree($tmp);
    }
}

return [
    'lessons save --service: with no --tags, categories is the union of the named nicks\' current tags from Services::all(), and both lines are written in the tools json_encode style' => function () {
        $tmp = lessonsFreshTree();
        $svc = lstFreshServices();
        try {
            Services::put(['nick' => 'pm1', 'host' => 'box1.example.com', 'user' => 'xyzjohn', 'tags' => ['seedbox', 'backup']]);
            Services::put(['nick' => 'pm2', 'host' => 'box2.example.com', 'user' => 'xyzjohn', 'tags' => ['webhosting']]);
            $r = Lessons::save(['topic' => 'services', 'title' => 'pm1 and pm2 notes', 'sources' => 'test', 'service' => 'pm1,pm2', 'no-related' => 'na'], "body\n");
            $content = (string) file_get_contents($r['records'][0]['path']);
            check((bool) preg_match('/^service: (.*)$/m', $content, $m) && json_decode($m[1], true) === ['pm1', 'pm2'], 'service line: ' . $content);
            check((bool) preg_match('/^categories: (.*)$/m', $content, $m2), 'no categories line: ' . $content);
            $cats = json_decode($m2[1], true);
            sort($cats);
            check($cats === ['backup', 'seedbox', 'webhosting'], 'categories: ' . $m2[1]);
            check($r['records'][0]['service'] === ['pm1', 'pm2'], 'records service: ' . json_encode($r['records'][0]));
            sort($r['records'][0]['categories']);
            check($r['records'][0]['categories'] === ['backup', 'seedbox', 'webhosting'], 'records categories: ' . json_encode($r['records'][0]));
        } finally {
            lstCloseServices($svc);
            lessonsCloseTree($tmp);
        }
    },
    'lessons save --service with --tags: the given tags extend (not replace) the named nicks\' current-tag union' => function () {
        $tmp = lessonsFreshTree();
        $svc = lstFreshServices();
        try {
            Services::put(['nick' => 'pm1', 'host' => 'box1.example.com', 'user' => 'xyzjohn', 'tags' => ['seedbox']]);
            $r = Lessons::save(['topic' => 'services', 'title' => 'pm1 with extra tags', 'sources' => 'test', 'service' => 'pm1', 'tags' => 'nas,media', 'no-related' => 'na'], "body\n");
            $cats = $r['records'][0]['categories'];
            sort($cats);
            check($cats === ['media', 'nas', 'seedbox'], 'categories: ' . json_encode($r['records'][0]));
        } finally {
            lstCloseServices($svc);
            lessonsCloseTree($tmp);
        }
    },
    'lessons save --service/--tags: an all-digit nick or tag (e.g. "42") is written back as the JSON string "42", not the number 42 — PHP turns an all-digit array key into an integer, so without array_map(\'strval\', ...) the frontmatter would silently hold [42] and ServicesList\'s strict in_array would never match it back' => function () {
        $tmp = lessonsFreshTree();
        $svc = lstFreshServices();
        try {
            $r = Lessons::save(['topic' => 'services', 'title' => 'an all digit nick and tag', 'sources' => 'test', 'service' => '42', 'tags' => '1080', 'no-related' => 'na'], "body\n");
            check($r['records'][0]['service'] === ['42'], 'service must be the string "42": ' . json_encode($r['records'][0]));
            check($r['records'][0]['categories'] === ['1080'], 'categories must be the string "1080": ' . json_encode($r['records'][0]));
            $content = (string) file_get_contents($r['records'][0]['path']);
            check((bool) preg_match('/^service: \["42"\]$/m', $content), 'service frontmatter must be the JSON string "42", not the number 42: ' . $content);
            check((bool) preg_match('/^categories: \["1080"\]$/m', $content), 'categories frontmatter must be the JSON string "1080", not the number 1080: ' . $content);
        } finally {
            lstCloseServices($svc);
            lessonsCloseTree($tmp);
        }
    },
    'lessons save --service/--tags: a bare flag with no value (--service with nothing after it, which Cli::parse turns into the boolean true) must be refused, never silently cast to the string "1" and linked as a nick or tag' => function () {
        $tmp = lessonsFreshTree();
        try {
            foreach (['service', 'tags'] as $k) {
                try {
                    Lessons::save(['topic' => 'services', 'title' => "bare --$k", 'sources' => 'test', $k => true, 'no-related' => 'na'], "body\n");
                    check(false, "bare --$k (true) must be refused");
                } catch (CliError $e) {
                    check($e->getMessage() === "--$k needs a value", "bare --$k message: " . $e->getMessage());
                }
            }
            check(!is_dir("$tmp/memory/lessons/services"), 'no file must be written for either refused case');
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons save --service: a nick not present in Services::all() (not yet set up, removed, or the services file is missing entirely) is still accepted by format and written to service, contributes no tag to categories, and --tags alone still supplies categories in that case' => function () {
        $tmp = lessonsFreshTree();
        $svc = lstFreshServices(); // a fresh directory: services.jsonl does not exist in it (Services::all() must treat this as "no services", not fail)
        try {
            $r = Lessons::save(['topic' => 'services', 'title' => 'a nick with no services entry', 'sources' => 'test', 'service' => 'ghost1', 'no-related' => 'na'], "body\n");
            check($r['records'][0]['service'] === ['ghost1'], 'service: ' . json_encode($r['records'][0]));
            check(!isset($r['records'][0]['categories']), 'categories must be empty/omitted: ' . json_encode($r['records'][0]));
            $content = (string) file_get_contents($r['records'][0]['path']);
            check(strpos($content, 'categories:') === false, 'no categories line expected: ' . $content);

            $r2 = Lessons::save(['topic' => 'services', 'title' => 'a nick with no services entry plus tags', 'sources' => 'test', 'service' => 'ghost1', 'tags' => 'nas', 'no-related' => 'na'], "body\n");
            check($r2['records'][0]['categories'] === ['nas'], 'categories from --tags only: ' . json_encode($r2['records'][0]));
        } finally {
            lstCloseServices($svc);
            lessonsCloseTree($tmp);
        }
    },
    'lessons save --service/--tags: a malformed nick or tag (uppercase, a space, or over 32 characters) is refused with the exact CliError Services already gives for that kind of value, before any file is written' => function () {
        $tmp = lessonsFreshTree();
        $svc = lstFreshServices();
        try {
            foreach (['PM1', 'pm 1', str_repeat('a', 33)] as $badNick) {
                try {
                    Lessons::save(['topic' => 'services', 'title' => 'bad nick ' . $badNick, 'sources' => 'test', 'service' => $badNick, 'no-related' => 'na'], "body\n");
                    check(false, "bad nick accepted: $badNick");
                } catch (CliError $e) {
                    check($e->getMessage() === 'not a service name: ' . $badNick, "nick message for $badNick: " . $e->getMessage());
                    check($e->hint === 'lowercase letters, digits, _ and -, at most 32, e.g. hdd1', "nick hint for $badNick: " . $e->hint);
                }
            }
            foreach (['Bad Tag', 'has space', str_repeat('b', 33)] as $badTag) {
                try {
                    Lessons::save(['topic' => 'services', 'title' => 'bad tag ' . $badTag, 'sources' => 'test', 'tags' => $badTag, 'no-related' => 'na'], "body\n");
                    check(false, "bad tag accepted: $badTag");
                } catch (CliError $e) {
                    check($e->getMessage() === 'not a tag: ' . json_encode(strtolower($badTag)), "tag message for $badTag: " . $e->getMessage());
                }
            }
            check(!is_dir("$tmp/memory/lessons/services"), 'no file must be written for any refused case');
        } finally {
            lstCloseServices($svc);
            lessonsCloseTree($tmp);
        }
    },
    'lessons save --tags alone (no --service): sets categories from the given tags only, and works even when the services file is missing entirely' => function () {
        $tmp = lessonsFreshTree();
        Services::$localDir = $tmp . '/no-such-dir-at-all';
        try {
            $r = Lessons::save(['topic' => 'services', 'title' => 'tags only, no services file', 'sources' => 'test', 'tags' => 'nas,laptop', 'no-related' => 'na'], "body\n");
            check(!isset($r['records'][0]['service']), 'service must be omitted: ' . json_encode($r['records'][0]));
            $cats = $r['records'][0]['categories'];
            sort($cats);
            check($cats === ['laptop', 'nas'], 'categories: ' . json_encode($r['records'][0]));
        } finally {
            Services::$localDir = null;
            lessonsCloseTree($tmp);
        }
    },
    'lessons save() without --service and without --tags writes exactly the same frontmatter as before this ADR: no service or categories line, and no service/categories key in the record' => function () {
        $tmp = lessonsFreshTree();
        try {
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'no service or tags given', 'sources' => 'test', 'no-related' => 'na'], "body\n");
            $content = (string) file_get_contents($r['records'][0]['path']);
            check($content === "---\ndate: " . gmdate('Y-m-d') . "\ntopic: rtorrent\nsources: [\"test\"]\n---\n# no service or tags given\n\nbody\n", 'content: ' . $content);
            check(!isset($r['records'][0]['service']) && !isset($r['records'][0]['categories']), 'record: ' . json_encode($r['records'][0]));
        } finally {
            lessonsCloseTree($tmp);
        }
    },
];
