<?php
/**
 * tools/memory/pikkumies/Lessons.php: the save/append side of the memory unit (ADR-0018). Not
 * auto-loaded by run.php's lib/*.php glob (it lives under tools/, not lib/), so every other test
 * file left it, and its own rules (kind routing, cross-link enforcement, duplicate-slug detection,
 * path-traversal/README protection, the excerpt picked for a search hit), with zero direct coverage.
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

return [
    'lessons: body() reads --body-file verbatim and normalizes to exactly one trailing newline regardless of trailing blank lines' => function () {
        $tmp = testTempDir('pk-lessons-body');
        try {
            file_put_contents("$tmp/bf.txt", "hello world  \n\n\n");
            check(Lessons::body(['body-file' => "$tmp/bf.txt"]) === "hello world\n", 'body: ' . var_export(Lessons::body(['body-file' => "$tmp/bf.txt"]), true));
        } finally {
            testRmTree($tmp);
        }
    },
    'lessons: body() throws the exact "lesson text is empty" CliError with a --body-file/stdin hint when the file content is blank' => function () {
        $tmp = testTempDir('pk-lessons-blank');
        try {
            file_put_contents("$tmp/bf.txt", "   \n\n ");
            try {
                Lessons::body(['body-file' => "$tmp/bf.txt"]);
                check(false, 'blank body-file must throw');
            } catch (CliError $e) {
                check($e->getMessage() === 'the lesson text is empty', 'message: ' . $e->getMessage());
                check(strpos($e->hint, '--body-file') !== false && strpos($e->hint, 'stdin') !== false, 'hint: ' . $e->hint);
            }
        } finally {
            testRmTree($tmp);
        }
    },
    'lessons: body() treats a nonexistent --body-file path as empty content (not a PHP warning/fatal) and still throws the empty-text CliError' => function () {
        $tmp = testTempDir('pk-lessons-missing');
        try {
            try {
                Lessons::body(['body-file' => "$tmp/does-not-exist.txt"]);
                check(false, 'a missing body-file must throw the empty-text error');
            } catch (CliError $e) {
                check($e->getMessage() === 'the lesson text is empty', 'message: ' . $e->getMessage());
            }
        } finally {
            testRmTree($tmp);
        }
    },
    'lessons: save() refuses before touching disk when --topic is not lowercase-alnum-dash, --title is empty, or --sources is missing — no file is created in any of the three cases' => function () {
        $tmp = lessonsFreshTree();
        try {
            foreach ([
                ['topic' => 'Not Lower', 'title' => 'x', 'sources' => 'y'],
                ['topic' => 'ok-topic', 'title' => '', 'sources' => 'y'],
                ['topic' => 'ok-topic', 'title' => 'x'],
            ] as $i => $opt) {
                try {
                    Lessons::save($opt, "body [link](x.md)\n");
                    check(false, "case $i must refuse");
                } catch (CliError $e) {
                    check($e->getMessage() === 'save needs --topic, --title and --sources', "case $i message: " . $e->getMessage());
                }
            }
            check(!is_dir("$tmp/memory/lessons"), 'no lessons directory must be created for any of the three refused cases');
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    // Mutation-tested: deleting the "unknown --kind" in_array check lets save() proceed with a bogus
    // kind, so the CliError this test expects is never thrown.
    'lessons: save() refuses an unknown --kind with the exact allowed-kinds hint, and a valid --kind reference/research writes under memory/reference or memory/research instead of memory/lessons/<topic>' => function () {
        $tmp = lessonsFreshTree();
        try {
            try {
                Lessons::save(['topic' => 'rtorrent', 'title' => 'x', 'sources' => 'y', 'kind' => 'bogus'], "b\n");
                check(false, 'an unknown --kind must refuse');
            } catch (CliError $e) {
                check($e->getMessage() === 'unknown --kind bogus', 'message: ' . $e->getMessage());
                check($e->hint === 'kinds: lesson (default), reference, research', 'hint: ' . $e->hint);
            }
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'a reference fact', 'sources' => 'y', 'kind' => 'reference', 'no-related' => 'none yet'], "b\n");
            check(strpos($r['records'][0]['path'], "$tmp/memory/reference/") === 0, 'reference kind path: ' . $r['records'][0]['path']);
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save() refuses a body with no markdown link to an existing memory file and no --no-related, and lists the nearest found-by-search paths in the hint' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/lessons/rtorrent", 0700, true);
            $existing = "$tmp/memory/lessons/rtorrent/20261001-disk-full-notes.md";
            file_put_contents($existing, "---\ndate: 2026-10-01\ntopic: rtorrent\nsources: [\"old\"]\n---\n# Disk full causes rtorrent to stop\n\nSome existing notes about full disks and rtorrent stopping.\n");
            try {
                Lessons::save(['topic' => 'rtorrent', 'title' => 'Disk full causes rtorrent to stop again', 'sources' => 'ticket 123'], "no link in this body at all.\n");
                check(false, 'a linkless body with no --no-related must refuse');
            } catch (CliError $e) {
                check($e->getMessage() === 'a lesson must link to at least one related memory file', 'message: ' . $e->getMessage());
                check(strpos($e->hint, realpath($existing)) !== false, 'hint must name the related file: ' . $e->hint);
            }
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save() --no-related bypasses the link requirement and the exact reason string is returned verbatim in status.no_related' => function () {
        $tmp = lessonsFreshTree();
        try {
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'brand new unrelated topic', 'sources' => 'x', 'no-related' => 'no related memory yet'], "body text\n");
            check($r['status']['no_related'] === 'no related memory yet', 'no_related: ' . json_encode($r['status']));
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save() with a body containing a real markdown link to an existing memory file succeeds, and that file\'s real path appears in records[0].links' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/reference", 0700, true);
            $target = "$tmp/memory/reference/x.md";
            file_put_contents($target, "# X\n\nsome reference fact.\n");
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'a new lesson linking x', 'sources' => 'y'], "see [x](reference/x.md) for details.\n");
            check(in_array(realpath($target), $r['records'][0]['links'], true), 'links: ' . json_encode($r['records'][0]['links']));
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save() with the same title on the same UTC day throws CliError naming the exact existing file path and an "append --path <path>" hint' => function () {
        $tmp = lessonsFreshTree();
        try {
            $opt = ['topic' => 'rtorrent', 'title' => 'duplicate title case', 'sources' => 'y', 'no-related' => 'na'];
            $r1 = Lessons::save($opt, "first body\n");
            try {
                Lessons::save($opt, "second body\n");
                check(false, 'a same-day same-title save must refuse');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), $r1['records'][0]['path']) !== false, 'message: ' . $e->getMessage());
                check(strpos($e->hint, 'append --path ' . $r1['records'][0]['path']) !== false, 'hint: ' . $e->hint);
            }
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save()\'s written frontmatter is exactly date/topic/sources with sources as a JSON array of the trimmed, non-empty, semicolon-split parts in original order' => function () {
        $tmp = lessonsFreshTree();
        try {
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'sources format test', 'sources' => 'a; ;b ;', 'no-related' => 'na'], "body\n");
            $content = file_get_contents($r['records'][0]['path']);
            check((bool) preg_match('/^sources: (.*)$/m', $content, $m), 'no sources line found');
            check($m[1] === '["a","b"]', 'sources line: ' . $m[1]);
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save()\'s duplicate-slug warning fires only when an existing related hit\'s slug (date prefix stripped) equals today\'s new slug, pointing at that exact path; a near-miss slug produces no warning' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/lessons/rtorrent", 0700, true);
            $todaySlug = TextSearch::slug('disk full causes rtorrent crash');
            $sameSlugPath = "$tmp/memory/lessons/rtorrent/20260101-$todaySlug.md";
            file_put_contents($sameSlugPath, "---\ndate: 2026-01-01\ntopic: rtorrent\nsources: [\"x\"]\n---\n# Disk full causes rtorrent crash\n\nOld notes about disk full causes rtorrent crash issue from way back.\n");
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'disk full causes rtorrent crash', 'sources' => 'y'], "[old notes](20260101-$todaySlug.md) still relevant, linking it.\n");
            check(isset($r['status']['warning']) && strpos($r['status']['warning'], realpath($sameSlugPath)) !== false, 'warning: ' . json_encode($r['status']));
        } finally {
            lessonsCloseTree($tmp);
        }
        $tmp2 = lessonsFreshTree();
        try {
            mkdir("$tmp2/memory/lessons/rtorrent", 0700, true);
            $diffSlugPath = "$tmp2/memory/lessons/rtorrent/20260101-totally-different-slug.md";
            file_put_contents($diffSlugPath, "---\ndate: 2026-01-01\ntopic: rtorrent\nsources: [\"x\"]\n---\n# Totally different slug\n\nMentions disk full causes rtorrent crash anyway for search overlap.\n");
            $r2 = Lessons::save(['topic' => 'rtorrent', 'title' => 'disk full causes rtorrent crash', 'sources' => 'y'], "[old notes](20260101-totally-different-slug.md) still relevant.\n");
            check(!isset($r2['status']['warning']), 'a near-miss slug must not warn: ' . json_encode($r2['status']));
        } finally {
            lessonsCloseTree($tmp2);
        }
    },
    'lessons: save() surfaces "could not write ... disk full?" when the target directory path collides with an existing plain file (mkdir fails)' => function () {
        $tmp = lessonsFreshTree();
        try {
            file_put_contents("$tmp/memory/lessons", "I am a file, not a directory\n");
            try {
                Lessons::save(['topic' => 'rtorrent', 'title' => 'cannot write this', 'sources' => 'y', 'no-related' => 'na'], "body\n");
                check(false, 'a directory-path collision must refuse');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'could not write') === 0, 'message: ' . $e->getMessage());
                check($e->hint === 'is the disk full?', 'hint: ' . $e->hint);
            }
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: append() refuses a path outside memory/, a non-.md path, a path that does not exist, and README.md specifically — four exact CliError cases, each with the "pass --path <a path printed by search or save>" hint' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/lessons/rtorrent", 0700, true);
            file_put_contents("$tmp/outside.md", "outside content\n");
            file_put_contents("$tmp/memory/lessons/rtorrent/not-markdown.txt", "txt\n");
            file_put_contents("$tmp/memory/README.md", "# README\n");
            foreach ([
                $tmp . '/outside.md',
                "$tmp/memory/lessons/rtorrent/not-markdown.txt",
                "$tmp/memory/lessons/rtorrent/does-not-exist.md",
                "$tmp/memory/README.md",
            ] as $path) {
                try {
                    Lessons::append($path, "x\n");
                    check(false, "append must refuse $path");
                } catch (CliError $e) {
                    check($e->getMessage() === 'append works only on an existing memory file', "message for $path: " . $e->getMessage());
                    check($e->hint === 'pass --path <a path printed by search or save>', "hint for $path: " . $e->hint);
                }
            }
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: append() adds a dated "## Update YYYY-MM-DD" section (today\'s UTC date) after existing content, leaves every existing byte unchanged, and status.verified is exactly true only when the new size matches old+added' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/lessons/rtorrent", 0700, true);
            $path = "$tmp/memory/lessons/rtorrent/20261016-ok.md";
            $before = "---\ndate: 2026-10-16\ntopic: rtorrent\nsources: [\"x\"]\n---\n# OK\n\noriginal body text.\n";
            file_put_contents($path, $before);
            $r = Lessons::append($path, "extra detail line.\n");
            $after = file_get_contents($path);
            check(strpos($after, $before) === 0, 'append must leave the existing bytes unchanged at the start');
            check((bool) preg_match('/\n## Update \d{4}-\d{2}-\d{2}\n\nextra detail line\.\n$/', $after), 'append: ' . $after);
            check($r['status']['verified'] === true, 'verified: ' . json_encode($r['status']));
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: relink() resolves a link relative to the file first, then to the memory root, rejects a path-traversal target that resolves outside memory/, rejects README.md, de-duplicates a repeated target, and writes each accepted link relative to the file' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/lessons/rtorrent", 0700, true);
            mkdir("$tmp/memory/reference", 0700, true);
            file_put_contents("$tmp/memory/README.md", "# memory\n");
            $dirRelative = "$tmp/memory/lessons/rtorrent/sibling.md"; // resolved relative to the file
            file_put_contents($dirRelative, "# Sibling\n\nx\n");
            $rootRelative = "$tmp/memory/reference/x.md"; // resolved relative to the memory root
            file_put_contents($rootRelative, "# X\n\nx\n");
            $body = 'see [a](sibling.md) and [b](reference/x.md) and [bad](../../../../etc/passwd.md) and [readme](README.md) and [a-again](sibling.md)' . "\n";
            list($out, $links) = Lessons::relink($body, realpath("$tmp/memory/lessons/rtorrent") . '/new.md');
            check($links === [realpath($dirRelative), realpath($rootRelative)], 'links: ' . json_encode($links));
            check($out === 'see [a](sibling.md) and [b](../../reference/x.md) and [bad](../../../../etc/passwd.md) and [readme](README.md) and [a-again](sibling.md)' . "\n", 'body: ' . $out);
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save() accepts every link form it accepted before ADR-0060 (absolute, relative to the current directory, to the topic directory, to the memory root, root-relative with a leading slash) and writes each one relative to the new file; a target outside memory/ and README.md are still refused' => function () {
        $tmp = lessonsFreshTree();
        $cwd = getcwd();
        try {
            $mem = realpath("$tmp/memory");
            mkdir("$mem/lessons/rtorrent", 0700, true);
            mkdir("$mem/reference", 0700, true);
            file_put_contents("$mem/README.md", "# memory\n");
            file_put_contents("$mem/lessons/rtorrent/topic.md", "# T\n");
            file_put_contents("$mem/reference/ref.md", "# R\n");
            file_put_contents("$tmp/outside.md", "# O\n");
            chdir("$mem/reference");
            $forms = ['absolute' => ["$mem/reference/ref.md", '../../reference/ref.md'], 'current directory' => ['ref.md', '../../reference/ref.md'],
                'topic directory' => ['topic.md', 'topic.md'], 'memory root' => ['reference/ref.md', '../../reference/ref.md'],
                'leading slash' => ['/lessons/rtorrent/topic.md', 'topic.md']];
            foreach ($forms as $form => $pair) {
                list($target, $written) = $pair;
                $r = Lessons::save(['topic' => 'rtorrent', 'title' => "link form $form", 'sources' => 'test'], "see [it]($target).\n");
                $saved = (string) file_get_contents($r['records'][0]['path']);
                check(count($r['records'][0]['links']) === 1 && strpos($saved, "see [it]($written).\n") !== false, "$form: " . json_encode($r['records'][0]['links']) . ' ' . $saved);
            }
            foreach (['absolute outside' => "$tmp/outside.md", 'relative outside' => '../../../outside.md', 'README' => 'README.md', 'root README' => '../../README.md'] as $form => $target) {
                try {
                    Lessons::save(['topic' => 'rtorrent', 'title' => "refused $form", 'sources' => 'test'], "see [it]($target).\n");
                    check(false, "$form must be refused");
                } catch (CliError $e) {
                    check($e->getMessage() === 'a lesson must link to at least one related memory file', "$form: " . $e->getMessage());
                }
            }
        } finally {
            chdir($cwd);
            lessonsCloseTree($tmp);
        }
    },
    'lessons: save() treats a --- block at the start of the body as text, not front matter: its link counts and is written relative to the file, and the block is kept' => function () {
        $tmp = lessonsFreshTree();
        try {
            mkdir("$tmp/memory/reference", 0700, true);
            file_put_contents("$tmp/memory/reference/ref.md", "# R\n");
            $r = Lessons::save(['topic' => 'rtorrent', 'title' => 'body starting with a rule', 'sources' => 'test'], "---\nsee [it](reference/ref.md).\n---\nmore.\n");
            $saved = (string) file_get_contents($r['records'][0]['path']);
            check(count($r['records'][0]['links']) === 1 && strpos($saved, "\n\n---\nsee [it](../../reference/ref.md).\n---\nmore.\n") !== false, 'saved: ' . $saved);
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: append() writes the links of the new text relative to the file and leaves every byte already in the file, absolute links included, unchanged' => function () {
        $tmp = lessonsFreshTree();
        try {
            $mem = realpath("$tmp/memory");
            mkdir("$mem/lessons/rtorrent", 0700, true);
            mkdir("$mem/reference", 0700, true);
            file_put_contents("$mem/reference/ref.md", "# R\n");
            $path = "$mem/lessons/rtorrent/20261001-old.md";
            $before = "# Old\n\nan old absolute link: [r]($mem/reference/ref.md)\n";
            file_put_contents($path, $before);
            Lessons::append($path, "a new one: [r]($mem/reference/ref.md) and [gone](gone.md)\n");
            $after = (string) file_get_contents($path);
            check(strpos($after, $before) === 0, 'existing bytes changed: ' . $after);
            check(substr($after, strlen($before)) === "\n## Update " . gmdate('Y-m-d') . "\n\na new one: [r](../../reference/ref.md) and [gone](gone.md)\n", 'appended: ' . $after);
        } finally {
            lessonsCloseTree($tmp);
        }
    },
    'lessons: excerpt() strips the YAML frontmatter block first, then picks the blank-line-separated paragraph with the most distinct query terms, and never returns a heading-only paragraph even if it shares query words' => function () {
        $tmp = testTempDir('pk-lessons-excerpt');
        try {
            $path = "$tmp/doc.md";
            file_put_contents($path, "---\ndate: 2026-10-16\ntopic: rtorrent\nsources: [\"x\"]\n---\n# Disk full notes\n\nAn unrelated paragraph about something else entirely with no overlap words.\n\nThe best paragraph mentions disk full and rtorrent stopping together clearly.\n");
            $best = Lessons::excerpt($path, 'disk full rtorrent');
            check($best === 'The best paragraph mentions disk full and rtorrent stopping together clearly.', 'excerpt: ' . $best);
            check(strpos($best, 'sources:') === false, 'frontmatter leaked into the excerpt: ' . $best);
        } finally {
            testRmTree($tmp);
        }
    },
    'lessons: excerpt() truncates a paragraph longer than EXCERPT (1500) bytes to exactly 1500 characters plus a trailing ellipsis, never more' => function () {
        $tmp = testTempDir('pk-lessons-excerpt-long');
        try {
            $path = "$tmp/doc.md";
            file_put_contents($path, "# Title\n\nquery " . str_repeat('x', 2000) . " end.\n");
            $ex = Lessons::excerpt($path, 'query');
            check(strlen($ex) === 1500 + strlen('…'), 'length: ' . strlen($ex));
            check(substr($ex, -3) === '…', 'must end with the ellipsis: ' . substr($ex, -6));
        } finally {
            testRmTree($tmp);
        }
    },
];
