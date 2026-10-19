<?php
/**
 * lib/TextSearch.php: terms/slug tokenizing, the memory directory layout, reading markdown files,
 * and rank()'s own documented ranking rules (all-terms-required, the 25%-miss floor at 5+ terms,
 * title-weight doubling, sqrt-length normalization, idf-dropping in free-text mode, and the
 * already-matched-only link boost). None of these rules had a test of their own before this file;
 * the only prior call (DocsTest.php) uses rank() as a fixture precondition, not to assert its rules.
 */
return [
    'textsearch: terms() drops stopwords, lowercases and dedupes, in a literal fixed-size result' => function () {
        check(TextSearch::terms('The Disk Is Full disk') === ['disk', 'full'], 'terms: ' . json_encode(TextSearch::terms('The Disk Is Full disk')));
    },
    'textsearch: terms() trims a trailing dot/underscore/dash but keeps inner dots (rtorrent.rc, version numbers)' => function () {
        $t = TextSearch::terms('check rtorrent.rc version 1.2.3.');
        check($t === ['check', 'rtorrent.rc', 'version', '1.2.3'], 'terms: ' . json_encode($t));
    },
    'textsearch: terms() splits on all non-alnum punctuation (quotes, slashes, parens) into separate words' => function () {
        $q = "disk/quota (full) 'now'";
        $t = TextSearch::terms($q);
        check($t === ['disk', 'quota', 'full', 'now'], 'terms: ' . json_encode($t));
    },
    'textsearch: slug() collapses non-alnum runs to one dash, trims ends, and falls back only when nothing alnum remains' => function () {
        check(TextSearch::slug('  Refund Policy!! ') === 'refund-policy', 'slug: ' . TextSearch::slug('  Refund Policy!! '));
        check(TextSearch::slug('ÄÖÜ???', 'lesson') === 'lesson', 'fallback slug: ' . TextSearch::slug('ÄÖÜ???', 'lesson'));
    },
    'textsearch: lessonDirs() returns memory/, memory/reference, memory/research, plus every memory/lessons/* directory, and ignores a stray non-directory file under lessons/' => function () {
        $tmp = testTempDir('pk-ts-dirs');
        try {
            mkdir("$tmp/memory/lessons/billing", 0700, true);
            mkdir("$tmp/memory/lessons/rtorrent", 0700, true);
            touch("$tmp/memory/lessons/stray.txt");
            $dirs = TextSearch::lessonDirs($tmp);
            sort($dirs);
            check($dirs === ["$tmp/memory", "$tmp/memory/lessons/billing", "$tmp/memory/lessons/rtorrent", "$tmp/memory/reference", "$tmp/memory/research"], 'dirs: ' . json_encode($dirs));
        } finally {
            testRmTree($tmp);
        }
    },
    'textsearch: readDirs() reads every *.md in each dir keyed by path, skips README.md, skips non-.md files, and merges across multiple dirs' => function () {
        $tmp = testTempDir('pk-ts-read');
        try {
            mkdir("$tmp/d1", 0700, true);
            mkdir("$tmp/d2", 0700, true);
            file_put_contents("$tmp/d1/a.md", 'A content');
            file_put_contents("$tmp/d1/README.md", 'readme 1');
            file_put_contents("$tmp/d1/b.txt", 'not markdown');
            file_put_contents("$tmp/d2/c.md", 'C content');
            file_put_contents("$tmp/d2/README.md", 'readme 2');
            file_put_contents("$tmp/d2/d.txt", 'also not markdown');
            $files = TextSearch::readDirs(["$tmp/d1", "$tmp/d2"]);
            check($files === ["$tmp/d1/a.md" => 'A content', "$tmp/d2/c.md" => 'C content'], 'files: ' . json_encode($files));
        } finally {
            testRmTree($tmp);
        }
    },
    'textsearch: readDirs() silently contributes nothing for a directory that does not exist, without raising a warning that fails the test runner' => function () {
        $tmp = testTempDir('pk-ts-missing');
        try {
            mkdir("$tmp/real", 0700, true);
            file_put_contents("$tmp/real/a.md", 'real content');
            $files = TextSearch::readDirs(["$tmp/real", "$tmp/does-not-exist"]);
            check($files === ["$tmp/real/a.md" => 'real content'], 'files: ' . json_encode($files));
        } finally {
            testRmTree($tmp);
        }
    },
    'textsearch: rank() returns no hits when every query word is a stopword or the query is empty' => function () {
        $files = ['/x/a.md' => 'the a an content here'];
        check(TextSearch::rank($files, 'the a an', 10) === [], 'all-stopword query must return nothing');
        check(TextSearch::rank($files, '', 10) === [], 'empty query must return nothing');
    },
    // Mutation-tested: deleting the "$need = count($want) - ..." line (replacing it with $need = 1)
    // makes fileMiss (3/4 terms) pass this test too, so the result is no longer exactly [fileAll].
    'textsearch: rank() keyword mode (share=null) requires every one of 4 terms — a file missing even one term of four is excluded' => function () {
        $fileAll = 'alpha beta gamma delta all four terms present';
        $fileMiss = 'alpha beta gamma only three of four terms';
        $files = ['/x/all.md' => $fileAll, '/x/miss.md' => $fileMiss];
        $r = TextSearch::rank($files, 'alpha beta gamma delta', 10);
        check($r === ['/x/all.md'], 'rank: ' . json_encode($r));
    },
    'textsearch: rank() keyword mode allows exactly one missing term once the query reaches 5 terms (25%-miss floor)' => function () {
        $fileFour = 'alpha beta gamma delta four of five epsilon-missing-ok';
        $fileThree = 'alpha beta gamma only three of five present here';
        $files = ['/x/four.md' => $fileFour, '/x/three.md' => $fileThree];
        $r = TextSearch::rank($files, 'alpha beta gamma delta epsilon', 10);
        check($r === ['/x/four.md'], 'rank: ' . json_encode($r));
    },
    // Mutation-tested: zeroing the title-bonus addition makes the two files tie on score, so the
    // stable sort keeps insertion order (body.md first) and this ordering check fails.
    'textsearch: rank() title match doubles a term\'s weight, enough to re-order two files that tie on body hits' => function () {
        $body = 'widget shows up here and ' . str_repeat('filler ', 20);
        $files = ['/x/body.md' => "# Notes about zzz\n\n" . $body, '/x/title.md' => "# Notes about widget\n\n" . $body];
        $r = TextSearch::rank($files, 'widget', 10);
        check(array_search('/x/title.md', $r, true) < array_search('/x/body.md', $r, true), 'title match must outrank body-only match: ' . json_encode($r));
    },
    'textsearch: rank() divides by sqrt(word count), so the shorter of two files with an identical matching term set outranks the padded long one' => function () {
        $files = ['/x/short.md' => "# Gizmo\n\ngizmo appears once here.\n", '/x/long.md' => "# Gizmo\n\ngizmo appears once here. " . str_repeat('pad ', 500) . "\n"];
        $r = TextSearch::rank($files, 'gizmo', 10);
        check(array_search('/x/short.md', $r, true) < array_search('/x/long.md', $r, true), 'shorter file must outrank the padded one: ' . json_encode($r));
    },
    'textsearch: rank() free-text mode (share set) drops a term seen in over a quarter of 20+ files, so an everyday word does not gate the match' => function () {
        $files = [];
        for ($i = 1; $i <= 6; $i++) {
            $files["/x/common$i.md"] = "common word filler$i text here";
        }
        $files['/x/target.md'] = 'rare word filler text here';
        for ($i = 1; $i <= 14; $i++) {
            $files["/x/filler$i.md"] = "neutral filler$i words only here";
        }
        check(count($files) === 21, 'fixture must have 21 files for the n>=20 drop rule to apply: ' . count($files));
        $r = TextSearch::rank($files, 'common rare', 10, 0.5);
        check(in_array('/x/target.md', $r, true), 'the file with only the rare word must still match: ' . json_encode($r));
    },
    'textsearch: rank() free-text mode with a term matching zero files in the corpus is simply dropped, not zeroing every file\'s score' => function () {
        $files = [];
        for ($i = 1; $i <= 6; $i++) {
            $files["/x/common$i.md"] = "common word filler$i text here";
        }
        $files['/x/target.md'] = 'rare word filler text here';
        for ($i = 1; $i <= 14; $i++) {
            $files["/x/filler$i.md"] = "neutral filler$i words only here";
        }
        $r = TextSearch::rank($files, 'ghostword rare', 10, 0.5);
        check(in_array('/x/target.md', $r, true), 'a zero-df term must not blank the result: ' . json_encode($r));
    },
    'textsearch: rank()\'s inline-link boost lifts only a file that already matched on its own terms; a linked-but-non-matching file is never pulled in, and a dangling/outside-root link target is ignored without error' => function () {
        $tmpLink = testTempDir('pk-ts-link');
        $tmpNoLink = testTempDir('pk-ts-nolink');
        try {
            // fileA links to fileB (matches too) and to fileC.md, which is never written to disk at
            // all: a dangling link must be ignored silently, never crash rank() with a bad realpath.
            file_put_contents("$tmpLink/A.md", "# Lighthouse notes\n\nlighthouse keeper story continues. [see B](B.md) [dead](C.md)\n");
            file_put_contents("$tmpLink/B.md", "# B page\n\nlighthouse mention here only once in this short page.\n");
            file_put_contents("$tmpLink/M.md", "# M\n\nlighthouse appears briefly somewhere here now.\n");
            file_put_contents("$tmpNoLink/A.md", "# Lighthouse notes\n\nlighthouse keeper story continues.\n");
            file_put_contents("$tmpNoLink/B.md", "# B page\n\nlighthouse mention here only once in this short page.\n");
            file_put_contents("$tmpNoLink/M.md", "# M\n\nlighthouse appears briefly somewhere here now.\n");
            $withLink = TextSearch::rank(TextSearch::readDirs([$tmpLink]), 'lighthouse', 10);
            $noLink = TextSearch::rank(TextSearch::readDirs([$tmpNoLink]), 'lighthouse', 10);
            check(!in_array("$tmpLink/C.md", $withLink, true) && count($withLink) === 3, 'a dangling link target must never appear: ' . json_encode($withLink));
            // M scores between B's un-boosted and boosted score by construction: without the link B
            // ranks behind M; with the link (boosted from A, which already matched) B ranks ahead of M.
            check(array_search("$tmpNoLink/M.md", $noLink, true) < array_search("$tmpNoLink/B.md", $noLink, true), 'fixture: without the link B must rank behind M: ' . json_encode($noLink));
            check(array_search("$tmpLink/B.md", $withLink, true) < array_search("$tmpLink/M.md", $withLink, true), 'the link boost must move B ahead of M: ' . json_encode($withLink));
        } finally {
            testRmTree($tmpLink);
            testRmTree($tmpNoLink);
        }
    },
    'textsearch: rank()\'s link boost also follows an absolute link target, the form a memory search prints and USAGE.md tells the agent to link' => function () {
        $dir = testTempDir('pk-ts-abslink');
        try {
            $real = realpath($dir);
            file_put_contents("$dir/A.md", "# Lighthouse notes\n\nlighthouse keeper story continues. [see B]($real/B.md)\n");
            file_put_contents("$dir/B.md", "# B page\n\nlighthouse mention here only once in this short page.\n");
            file_put_contents("$dir/M.md", "# M\n\nlighthouse appears briefly somewhere here now.\n");
            $r = TextSearch::rank(TextSearch::readDirs([$real]), 'lighthouse', 10);
            check(array_search("$real/B.md", $r, true) < array_search("$real/M.md", $r, true), 'an absolute link must boost B ahead of M: ' . json_encode($r));
        } finally {
            testRmTree($dir);
        }
    },
    'textsearch: the memory search\'s link boost also follows a link relative to the memory root, the form save accepts (ADR-0060), and rank() follows it only when that base is given' => function () {
        $tmp = testTempDir('pk-ts-rootlink');
        try {
            $mem = realpath($tmp) . '/memory';
            mkdir("$mem/lessons/a", 0700, true);
            file_put_contents("$mem/lessons/a/A.md", "# Lighthouse notes\n\nlighthouse keeper story continues. [see B](lessons/a/B.md)\n");
            file_put_contents("$mem/lessons/a/B.md", "# B page\n\nlighthouse mention here only once in this short page.\n");
            file_put_contents("$mem/lessons/a/M.md", "# M\n\nlighthouse appears briefly somewhere here now.\n");
            $files = TextSearch::readDirs(["$mem/lessons/a"]);
            $plain = TextSearch::rank($files, 'lighthouse', 10);
            check(array_search("$mem/lessons/a/M.md", $plain, true) < array_search("$mem/lessons/a/B.md", $plain, true), 'fixture: without the base B must rank behind M: ' . json_encode($plain));
            DocSources::$root = realpath($tmp);
            DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
            list($hits) = DocSources::search('lighthouse', 10, true);
            $paths = array_column($hits, 0);
            check(array_search("$mem/lessons/a/B.md", $paths, true) < array_search("$mem/lessons/a/M.md", $paths, true), 'a memory-root link must boost B ahead of M: ' . json_encode($paths));
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            testRmTree($tmp);
        }
    },
];
